<?php

/**
 * backup_scheduler — Programador de copias automáticas por cuenta con SPOOL por bloques.
 *
 * Flujo (llamado desde el daemon cada 5 min):
 *   1) enqueueDue(): busca las cuentas cuya programación (x_backup_schedule) está vencida y las
 *      mete en la cola x_backup_queue (evitando duplicados). Recalcula su próxima ejecución.
 *   2) processBatch(N): coge hasta N pendientes de la cola y las ejecuta con sys_backup_runner.
 *      Así 100 copias que "tocan" a la misma hora NO arrancan juntas: se hacen de N en N por tick,
 *      sin colapsar CPU/disco. Un candado (flock) evita que dos ticks se solapen.
 *
 * El interruptor maestro sigue siendo el ajuste 'schedule_bu' (si es false, no se hace nada).
 */
class sys_backup_scheduler
{
    const LOCKFILE = '/var/bulwark/run/backup_scheduler.lock';

    /** Calcula el epoch de la próxima ejecución según frecuencia/hora/día. */
    public static function computeNextRun($freq, $hour, $dow, $dom, $from = null)
    {
        $from = $from ?: time();
        $hour = max(0, min(23, (int)$hour));
        $Y = (int)date('Y', $from); $m = (int)date('n', $from); $d = (int)date('j', $from);

        if ($freq === 'weekly') {
            $dow  = max(0, min(6, (int)$dow));
            $base = mktime($hour, 0, 0, $m, $d, $Y);
            $add  = ($dow - (int)date('w', $base) + 7) % 7;
            $next = strtotime("+$add days", $base);
            if ($next <= $from) $next = strtotime('+7 days', $next);
            return $next;
        }
        if ($freq === 'monthly') {
            $dom  = max(1, min(28, (int)$dom));
            $next = mktime($hour, 0, 0, $m, $dom, $Y);
            if ($next <= $from) $next = mktime($hour, 0, 0, $m + 1, $dom, $Y);
            return $next;
        }
        // daily
        $next = mktime($hour, 0, 0, $m, $d, $Y);
        if ($next <= $from) $next = strtotime('+1 day', $next);
        return $next;
    }

    /** ¿Está el programador maestro activo? */
    private static function masterEnabled()
    {
        return strtolower((string)ctrl_options::GetSystemOption('schedule_bu')) === 'true';
    }

    /** Encola las cuentas vencidas. Devuelve el nº encoladas. */
    public static function enqueueDue()
    {
        global $zdbh;
        if (!self::masterEnabled()) return 0;
        $now = time();
        $due = $zdbh->prepare(
            "SELECT s.* FROM x_backup_schedule s
               JOIN x_accounts a ON a.ac_id_pk = s.bs_acc_fk AND a.ac_enabled_in=1 AND a.ac_deleted_ts IS NULL
              WHERE s.bs_enabled_in=1
                AND s.bs_next_run_ts IS NOT NULL
                AND s.bs_next_run_ts <= :now");
        $due->execute(array(':now' => $now));
        $n = 0;
        while ($s = $due->fetch(PDO::FETCH_ASSOC)) {
            $acc  = (int)$s['bs_acc_fk'];
            $mode = in_array($s['bs_dest_vc'], array('local', 'remote', 'both'), true) ? $s['bs_dest_vc'] : 'local';
            // Evitar duplicados: no encolar si ya hay una pendiente/en curso de esta cuenta.
            $dup = $zdbh->prepare("SELECT COUNT(*) FROM x_backup_queue WHERE bq_acc_fk=:a AND bq_status_vc IN ('pending','running')");
            $dup->execute(array(':a' => $acc));
            if ((int)$dup->fetchColumn() === 0) {
                $ins = $zdbh->prepare("INSERT INTO x_backup_queue (bq_acc_fk,bq_mode_vc,bq_status_vc,bq_enqueued_ts) VALUES (:a,:m,'pending',:t)");
                $ins->execute(array(':a' => $acc, ':m' => $mode, ':t' => $now));
                $n++;
            }
            // Recalcular próxima ejecución (aunque no se haya encolado por duplicado).
            $next = self::computeNextRun($s['bs_freq_vc'], $s['bs_hour_in'], $s['bs_dow_in'], $s['bs_dom_in'], $now);
            $upd  = $zdbh->prepare("UPDATE x_backup_schedule SET bs_next_run_ts=:n WHERE bs_id_pk=:id");
            $upd->execute(array(':n' => $next, ':id' => (int)$s['bs_id_pk']));
        }
        return $n;
    }

    /** Procesa hasta $size copias pendientes. Devuelve el nº procesadas. Candado anti-solape. */
    public static function processBatch($size = null)
    {
        global $zdbh;
        if ($size === null) {
            $size = (int)ctrl_options::GetSystemOption('backup_batch_size');
            if ($size <= 0) $size = 2;
        }
        @mkdir(dirname(self::LOCKFILE), 0750, true);
        $lock = @fopen(self::LOCKFILE, 'c');
        if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            return 0; // otro tick sigue procesando -> no solapar
        }

        $done = 0;
        try {
            $q = $zdbh->prepare("SELECT * FROM x_backup_queue WHERE bq_status_vc='pending' ORDER BY bq_id_pk ASC LIMIT " . (int)$size);
            $q->execute();
            $items = $q->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as $it) {
                $id = (int)$it['bq_id_pk'];
                $zdbh->prepare("UPDATE x_backup_queue SET bq_status_vc='running', bq_started_ts=:t, bq_attempts_in=bq_attempts_in+1 WHERE bq_id_pk=:id")
                     ->execute(array(':t' => time(), ':id' => $id));
                list($ok, $msg) = sys_backup_runner::runAccount((int)$it['bq_acc_fk'], $it['bq_mode_vc']);
                $zdbh->prepare("UPDATE x_backup_queue SET bq_status_vc=:st, bq_finished_ts=:t, bq_message_tx=:m WHERE bq_id_pk=:id")
                     ->execute(array(':st' => $ok ? 'done' : 'error', ':t' => time(), ':m' => substr((string)$msg, 0, 1000), ':id' => $id));
                if ($ok) {
                    $zdbh->prepare("UPDATE x_backup_schedule SET bs_last_run_ts=:t WHERE bs_acc_fk=:a")
                         ->execute(array(':t' => time(), ':a' => (int)$it['bq_acc_fk']));
                }
                $done++;
            }
            // Limpieza: borrar entradas terminadas de hace más de 7 días (mantener la cola pequeña).
            $zdbh->prepare("DELETE FROM x_backup_queue WHERE bq_status_vc IN ('done','error') AND bq_finished_ts < :cut")
                 ->execute(array(':cut' => time() - 7 * 86400));
        } catch (Exception $e) { /* no romper el daemon */ }

        @flock($lock, LOCK_UN);
        @fclose($lock);
        return $done;
    }

    /** Ejecuta un ciclo completo del programador (encolar + procesar). Para el daemon. */
    public static function tick()
    {
        if (!self::masterEnabled()) return array(0, 0);
        $enq  = self::enqueueDue();
        $proc = self::processBatch();
        return array($enq, $proc);
    }
}

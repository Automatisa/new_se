<?php

/**
 * backup_log — Registro persistente de operaciones de copia de seguridad (locales, remotas y
 * pruebas de conexión) por cuenta. Se guarda en la tabla x_backup_log y se muestra en el módulo
 * backupmgr con paginación. Se conservan como máximo los ÚLTIMOS 100 registros por cuenta
 * (los más antiguos se podan automáticamente al insertar).
 *
 * Robustez: todas las operaciones van con try/catch y no rompen nunca el backup si la tabla no
 * existe o la BD falla (el registro es best-effort).
 */
class sys_backup_log
{
    const MAX_PER_ACCOUNT = 100;

    /** Devuelve la conexión PDO ($zdbh global) o null si no está disponible. */
    private static function db()
    {
        global $zdbh;
        return ($zdbh instanceof PDO) ? $zdbh : null;
    }

    /**
     * Registra una operación. $action: 'local' | 'remote' | 'test'. Devuelve true/false.
     * Tras insertar, poda para conservar solo los últimos MAX_PER_ACCOUNT de la cuenta.
     */
    public static function record($userid, $action, $dest, $file, $size, $attempts, $ok, $message, $duration = 0)
    {
        $db = self::db();
        if (!$db) return false;
        try {
            $ins = $db->prepare(
                "INSERT INTO x_backup_log
                 (bl_acc_fk, bl_ts_in, bl_action_vc, bl_dest_vc, bl_file_vc, bl_size_in,
                  bl_attempts_in, bl_result_vc, bl_message_tx, bl_duration_in)
                 VALUES (:u,:ts,:a,:d,:f,:sz,:at,:r,:m,:du)");
            $ins->execute(array(
                ':u'  => (int)$userid,
                ':ts' => time(),
                ':a'  => substr((string)$action, 0, 20),
                ':d'  => substr((string)$dest, 0, 160),
                ':f'  => substr((string)$file, 0, 200),
                ':sz' => (int)$size,
                ':at' => (int)$attempts,
                ':r'  => $ok ? 'ok' : 'error',
                ':m'  => substr((string)$message, 0, 1000),
                ':du' => (int)$duration,
            ));
            self::prune($userid);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /** Conserva solo los últimos MAX_PER_ACCOUNT registros de la cuenta; borra el resto. */
    private static function prune($userid)
    {
        $db = self::db();
        if (!$db) return;
        try {
            // id de corte: el MAX_PER_ACCOUNT-ésimo id más reciente de la cuenta.
            $q = $db->prepare("SELECT bl_id_pk FROM x_backup_log WHERE bl_acc_fk=:u
                               ORDER BY bl_id_pk DESC LIMIT 1 OFFSET :off");
            $q->bindValue(':u', (int)$userid, PDO::PARAM_INT);
            $q->bindValue(':off', self::MAX_PER_ACCOUNT, PDO::PARAM_INT);
            $q->execute();
            $cut = $q->fetchColumn();
            if ($cut !== false && $cut !== null) {
                $del = $db->prepare("DELETE FROM x_backup_log WHERE bl_acc_fk=:u AND bl_id_pk <= :c");
                $del->execute(array(':u' => (int)$userid, ':c' => (int)$cut));
            }
        } catch (Exception $e) { /* best-effort */ }
    }

    /** Nº total de registros de la cuenta (para el paginador). */
    public static function countForUser($userid)
    {
        $db = self::db();
        if (!$db) return 0;
        try {
            $q = $db->prepare("SELECT COUNT(*) FROM x_backup_log WHERE bl_acc_fk=:u");
            $q->execute(array(':u' => (int)$userid));
            return (int)$q->fetchColumn();
        } catch (Exception $e) { return 0; }
    }

    /** Página de registros (más recientes primero). Devuelve array de filas asociativas. */
    public static function listForUser($userid, $page = 1, $perPage = 20)
    {
        $db = self::db();
        if (!$db) return array();
        $page    = max(1, (int)$page);
        $perPage = max(1, (int)$perPage);
        $offset  = ($page - 1) * $perPage;
        try {
            $q = $db->prepare("SELECT * FROM x_backup_log WHERE bl_acc_fk=:u
                               ORDER BY bl_id_pk DESC LIMIT :lim OFFSET :off");
            $q->bindValue(':u', (int)$userid, PDO::PARAM_INT);
            $q->bindValue(':lim', $perPage, PDO::PARAM_INT);
            $q->bindValue(':off', $offset, PDO::PARAM_INT);
            $q->execute();
            return $q->fetchAll(PDO::FETCH_ASSOC) ?: array();
        } catch (Exception $e) { return array(); }
    }

    /** Borra TODOS los registros de la cuenta. Devuelve el nº de filas borradas. */
    public static function clearForUser($userid)
    {
        $db = self::db();
        if (!$db) return 0;
        try {
            $del = $db->prepare("DELETE FROM x_backup_log WHERE bl_acc_fk=:u");
            $del->execute(array(':u' => (int)$userid));
            return $del->rowCount();
        } catch (Exception $e) { return 0; }
    }

    /** Formatea bytes a un tamaño legible. */
    public static function humanSize($bytes)
    {
        $bytes = (float)$bytes;
        if ($bytes <= 0) return '—';
        $u = array('B', 'KB', 'MB', 'GB', 'TB');
        $i = (int)floor(log($bytes, 1024));
        $i = max(0, min($i, count($u) - 1));
        return round($bytes / pow(1024, $i), ($i >= 2 ? 1 : 0)) . ' ' . $u[$i];
    }
}

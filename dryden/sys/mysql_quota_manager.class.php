<?php

if (!class_exists('mysql_quota_manager')) {

/**
 * mysql_quota_manager — Tope de tamaño de BASES DE DATOS por cuenta.
 *
 * MySQL/MariaDB no tienen cuota de disco nativa por schema/usuario y todos los ficheros son
 * del uid `mysql` (no separables por cuota UFS). Por eso el enforcement es a nivel de
 * aplicación, coherente con la lógica de disco: si una cuenta supera su límite de BD
 * (qt_dbquota_in, en MB; 0 = ilimitado), se REVOCA la escritura a sus usuarios MySQL dejando
 * solo lectura (SELECT) → sus webs siguen LEYENDO de la BD pero no pueden escribir más. Al
 * bajar del límite se restauran los grants completos. El estado se guarda en
 * x_mysql_quota_state para no re-aplicar grants en cada ciclo del daemon.
 *
 * Requiere que el usuario del panel tenga GRANT OPTION (lo tiene: bulwark_panel).
 */
class mysql_quota_manager
{
    /** Aplica a todas las cuentas activas. Devuelve el nº evaluadas. */
    static function ApplyAll(): int
    {
        global $zdbh;
        $accs = $zdbh->query(
            "SELECT u.ac_id_pk AS uid, COALESCE(q.qt_dbquota_in,0) AS dbmb
               FROM x_accounts u
               JOIN x_profiles p ON p.ud_user_fk = u.ac_id_pk
               JOIN x_quotas   q ON q.qt_package_fk = p.ud_package_fk
              WHERE u.ac_deleted_ts IS NULL AND u.ac_enabled_in = 1"
        )->fetchAll(PDO::FETCH_ASSOC);

        $n = 0;
        foreach ($accs as $a) {
            $uid = (int)$a['uid'];
            $limitBytes = (int)$a['dbmb'] * 1024 * 1024; // MB -> bytes (0 = ilimitado)
            $size = self::accountDbSize($uid);
            $blocked = self::isBlocked($uid);
            $over = ($limitBytes > 0 && $size > $limitBytes);

            if ($over && !$blocked) {
                self::setWrite($uid, false);
                self::saveState($uid, 1, $size);
                echo "mysql-quota: cuenta $uid SOBRE cuota BD (" . self::hr($size) . " > " . self::hr($limitBytes) . ") -> escritura BLOQUEADA" . fs_filehandler::NewLine();
            } elseif (!$over && $blocked) {
                self::setWrite($uid, true);
                self::saveState($uid, 0, $size);
                echo "mysql-quota: cuenta $uid de nuevo bajo cuota BD -> escritura RESTAURADA" . fs_filehandler::NewLine();
            } else {
                self::saveState($uid, $blocked ? 1 : 0, $size);
            }
            $n++;
        }
        return $n;
    }

    /** Fuerza la reevaluación de una sola cuenta (para el panel al cambiar el paquete). */
    static function ApplyAccount($uid)
    {
        global $zdbh;
        $uid = (int)$uid;
        $q = $zdbh->prepare(
            "SELECT COALESCE(q.qt_dbquota_in,0) AS dbmb
               FROM x_accounts u
               JOIN x_profiles p ON p.ud_user_fk = u.ac_id_pk
               JOIN x_quotas   q ON q.qt_package_fk = p.ud_package_fk
              WHERE u.ac_id_pk = :u LIMIT 1"
        );
        $q->execute(array(':u' => $uid));
        $dbmb = (int)$q->fetchColumn();
        $limitBytes = $dbmb * 1024 * 1024;
        $size = self::accountDbSize($uid);
        $blocked = self::isBlocked($uid);
        $over = ($limitBytes > 0 && $size > $limitBytes);
        if ($over && !$blocked) { self::setWrite($uid, false); self::saveState($uid, 1, $size); }
        elseif (!$over && $blocked) { self::setWrite($uid, true); self::saveState($uid, 0, $size); }
        else { self::saveState($uid, $blocked ? 1 : 0, $size); }
        return $over;
    }

    /** Tamaño total (data+index) de las BD de la cuenta, en bytes. */
    static function accountDbSize($uid)
    {
        global $zdbh;
        $dbs = self::accountDbNames($uid);
        if (!$dbs) return 0;
        $ph = implode(',', array_fill(0, count($dbs), '?'));
        $st = $zdbh->prepare(
            "SELECT COALESCE(SUM(data_length + index_length),0)
               FROM information_schema.tables WHERE table_schema IN ($ph)"
        );
        $st->execute($dbs);
        return (int)$st->fetchColumn();
    }

    private static function accountDbNames($uid)
    {
        global $zdbh;
        $st = $zdbh->prepare("SELECT my_name_vc FROM x_mysql_databases WHERE my_acc_fk = :u AND my_deleted_ts IS NULL");
        $st->execute(array(':u' => (int)$uid));
        $out = array();
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
            if (preg_match('/^[A-Za-z0-9_]+$/', (string)$d)) $out[] = $d;
        }
        return $out;
    }

    /** Pares (usuario, host, bd) de la cuenta a los que aplicar los grants. */
    private static function accountGrantPairs($uid)
    {
        global $zdbh;
        $sql =
            "SELECT DISTINCT u.mu_name_vc AS uname, u.mu_access_vc AS host, d.my_name_vc AS dbname
               FROM x_mysql_dbmap m
               JOIN x_mysql_users     u ON u.mu_id_pk = m.mm_user_fk
               JOIN x_mysql_databases d ON d.my_id_pk = m.mm_database_fk
              WHERE m.mm_acc_fk = :u AND u.mu_deleted_ts IS NULL AND d.my_deleted_ts IS NULL
             UNION
             SELECT DISTINCT u.mu_name_vc, u.mu_access_vc, d.my_name_vc
               FROM x_mysql_users     u
               JOIN x_mysql_databases d ON d.my_id_pk = u.mu_database_fk
              WHERE u.mu_acc_fk = :u2 AND u.mu_deleted_ts IS NULL AND d.my_deleted_ts IS NULL";
        $st = $zdbh->prepare($sql);
        $st->execute(array(':u' => (int)$uid, ':u2' => (int)$uid));
        $pairs = array();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // Validar identificadores antes de interpolarlos (no se pueden parametrizar).
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$r['uname'])) continue;
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$r['dbname'])) continue;
            if (!preg_match('/^[A-Za-z0-9_.:%\-]+$/', (string)$r['host'])) continue;
            $pairs[] = $r;
        }
        return $pairs;
    }

    /** allow=true → GRANT ALL; allow=false → solo lectura (revoca escritura). */
    static function setWrite($uid, $allow)
    {
        global $zdbh;
        foreach (self::accountGrantPairs($uid) as $p) {
            $u = $p['uname']; $h = $p['host']; $db = $p['dbname'];
            try {
                if ($allow) {
                    $zdbh->exec("GRANT ALL PRIVILEGES ON `$db`.* TO '$u'@'$h'");
                } else {
                    $zdbh->exec("REVOKE ALL PRIVILEGES ON `$db`.* FROM '$u'@'$h'");
                    $zdbh->exec("GRANT SELECT, SHOW VIEW, LOCK TABLES ON `$db`.* TO '$u'@'$h'");
                }
            } catch (Exception $e) { /* usuario inexistente en MySQL, etc.: continuar */ }
        }
        try { $zdbh->exec("FLUSH PRIVILEGES"); } catch (Exception $e) {}
    }

    static function isBlocked($uid)
    {
        global $zdbh;
        $st = $zdbh->prepare("SELECT mq_blocked_in FROM x_mysql_quota_state WHERE mq_acc_fk = :u");
        $st->execute(array(':u' => (int)$uid));
        return (int)$st->fetchColumn() === 1;
    }

    private static function saveState($uid, $blocked, $size)
    {
        global $zdbh;
        $st = $zdbh->prepare(
            "INSERT INTO x_mysql_quota_state (mq_acc_fk, mq_blocked_in, mq_size_bi, mq_ts)
             VALUES (:u,:b,:s,:t)
             ON DUPLICATE KEY UPDATE mq_blocked_in=:b2, mq_size_bi=:s2, mq_ts=:t2"
        );
        $t = time();
        $st->execute(array(':u'=>(int)$uid, ':b'=>(int)$blocked, ':s'=>(int)$size, ':t'=>$t,
                           ':b2'=>(int)$blocked, ':s2'=>(int)$size, ':t2'=>$t));
    }

    private static function hr($bytes)
    {
        $u = array('B','KB','MB','GB','TB'); $i = 0; $b = (float)$bytes;
        while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
        return round($b, 1) . $u[$i];
    }
}

}

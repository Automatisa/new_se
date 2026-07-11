<?php

if (!class_exists('disk_quota_manager')) {

/**
 * disk_quota_manager — Aplica cuotas de disco UFS por usuario de hosting (uid h_USER) desde
 * el límite de disco del paquete (qt_diskspace_bi). El kernel deniega los write() por encima
 * del límite (EDQUOT) pero las lecturas/servicio siguen → una web sobre cuota NO se cae, solo
 * no puede escribir más. Requiere cuotas UFS activas en / (userquota en fstab + reboot).
 *
 * Se llama desde el daemon (root), que ejecuta edquota directamente (sin doas). El script
 * bin/disk_quota_apply.sh cubre el camino desde el panel (www) vía privilege::run.
 */
class disk_quota_manager
{
    /** ¿Están activas las cuotas UFS en / ? */
    static function Enabled(): bool
    {
        list($code) = self::run(array('/usr/sbin/repquota', '-u', '/'));
        return $code === 0;
    }

    /** Aplica a todas las cuentas activas. Devuelve el nº procesadas. */
    static function ApplyAll(): int
    {
        global $zdbh;
        if (!self::Enabled()) {
            echo "disk-quota: cuotas UFS no activas en / (userquota en fstab + quota_enable=YES + reboot) — NO aplicadas." . fs_filehandler::NewLine();
            return 0;
        }
        $rows = $zdbh->query(
            "SELECT u.ac_user_vc AS username,
                    COALESCE(q.qt_diskspace_bi,0) AS diskbytes
               FROM x_accounts u
               JOIN x_profiles p ON p.ud_user_fk = u.ac_id_pk
               JOIN x_quotas   q ON q.qt_package_fk = p.ud_package_fk
              WHERE u.ac_deleted_ts IS NULL AND u.ac_enabled_in = 1"
        )->fetchAll(PDO::FETCH_ASSOC);

        $n = 0;
        foreach ($rows as $r) {
            $user = 'h_' . preg_replace('/[^a-z0-9_]/', '', strtolower((string)$r['username']));
            if ($user === 'h_' || !self::sysuserExists($user)) continue;
            // bytes -> bloques de 1 KB. 0 = ilimitado.
            $hardKB = (int)floor(((int)$r['diskbytes']) / 1024);
            self::applyUser($user, $hardKB);
            $n++;
        }
        return $n;
    }

    /** Fija la cuota (soft=hard) de un usuario del sistema. hardKB=0 → ilimitado. */
    static function applyUser(string $sysuser, int $hardKB): void
    {
        $hardKB = max(0, $hardKB);
        self::run(array('/usr/sbin/edquota', '-u', '-e', '/:' . $hardKB . ':' . $hardKB . ':0:0', $sysuser));
        echo "disk-quota: " . $sysuser . " -> hard=" . ($hardKB ? $hardKB . 'KB' : 'ilimitado') . fs_filehandler::NewLine();
    }

    private static function sysuserExists(string $u): bool
    {
        list($code) = self::run(array('/usr/bin/id', '-u', $u));
        return $code === 0;
    }

    /** Ejecuta un binario con argv, sin shell. Devuelve [exitcode, stdout, stderr]. */
    private static function run(array $argv): array
    {
        $desc = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $p = proc_open($argv, $desc, $pipes);
        if (!is_resource($p)) return array(127, '', 'proc_open failed');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        return array(proc_close($p), $out, $err);
    }
}

}

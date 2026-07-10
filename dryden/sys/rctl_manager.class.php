<?php

if (!class_exists('rctl_manager')) {

/**
 * rctl_manager — Aplica límites de recursos por usuario de hosting (FreeBSD RACCT/RCTL)
 * para contener DoS (fork-bombs, agotamiento de RAM/CPU) de un inquilino sin afectar al
 * resto ni al sistema.
 *
 * Los límites salen del paquete asignado a la cuenta (x_quotas: qt_maxproc_in,
 * qt_maxmem_vc, qt_pcpu_in). Un valor 0 = sin límite para esa métrica.
 *
 * Requiere kern.racct.enable=1 (loader.conf + reboot). Se ejecuta desde el daemon (root);
 * usa proc_open con array (bypass_shell) — nunca exec() ni shell.
 */
class rctl_manager
{
    /** ¿Está RACCT activo en el kernel? (si no, no se puede aplicar nada). */
    static function Enabled(): bool
    {
        $argv = array('/sbin/sysctl', '-n', 'kern.racct.enable');
        $out  = self::run($argv, true);
        return trim($out) === '1';
    }

    /**
     * Aplica los límites de TODAS las cuentas de hosting habilitadas. Idempotente.
     * Llamar desde el daemon (root). Devuelve el nº de usuarios a los que se aplicó.
     */
    static function ApplyAll(): int
    {
        global $zdbh;

        if (!self::Enabled()) {
            echo "rctl: RACCT no activo (pon kern.racct.enable=1 en /boot/loader.conf y reinicia) — limites NO aplicados." . fs_filehandler::NewLine();
            return 0;
        }

        $rows = $zdbh->query(
            "SELECT u.ac_user_vc AS username,
                    COALESCE(q.qt_maxproc_in,0) AS maxproc,
                    COALESCE(q.qt_maxmem_vc,'') AS maxmem,
                    COALESCE(q.qt_pcpu_in,0)    AS pcpu
               FROM x_accounts u
               JOIN x_profiles p ON p.ud_user_fk = u.ac_id_pk
               JOIN x_quotas   q ON q.qt_package_fk = p.ud_package_fk
              WHERE u.ac_deleted_ts IS NULL AND u.ac_enabled_in = 1"
        )->fetchAll(PDO::FETCH_ASSOC);

        $n = 0;
        foreach ($rows as $r) {
            $user = 'h_' . preg_replace('/[^a-z0-9_]/', '', strtolower((string)$r['username']));
            if ($user === 'h_' || !self::sysuserExists($user)) {
                continue;
            }
            self::applyUser($user, (int)$r['maxproc'], (string)$r['maxmem'], (int)$r['pcpu']);
            $n++;
        }
        return $n;
    }

    /**
     * Aplica (o limpia y reaplica) los límites de un usuario del sistema h_USERNAME.
     */
    static function applyUser(string $sysuser, int $maxproc, string $maxmem, int $pcpu): void
    {
        // Limpiar cualquier regla previa del usuario para no acumular.
        self::run(array('/usr/bin/rctl', '-r', 'user:' . $sysuser));

        if ($maxproc > 0) {
            self::run(array('/usr/bin/rctl', '-a', 'user:' . $sysuser . ':maxproc:deny=' . $maxproc));
        }
        $membytes = self::parseSize($maxmem);
        if ($membytes > 0) {
            self::run(array('/usr/bin/rctl', '-a', 'user:' . $sysuser . ':memoryuse:deny=' . $membytes));
        }
        if ($pcpu > 0 && $pcpu <= 100) {
            self::run(array('/usr/bin/rctl', '-a', 'user:' . $sysuser . ':pcpu:deny=' . $pcpu));
        }
        echo "rctl: " . $sysuser . " -> maxproc=" . ($maxproc ?: '-') . " memoryuse=" . ($maxmem ?: '-') . " pcpu=" . ($pcpu ?: '-') . fs_filehandler::NewLine();
    }

    private static function sysuserExists(string $sysuser): bool
    {
        $out = self::run(array('/usr/sbin/pw', 'usershow', $sysuser), true);
        return trim($out) !== '';
    }

    /**
     * Ejecuta un comando con array de argumentos (bypass_shell): sin shell, sin inyección.
     * @return string stdout (para lecturas); '' si falla.
     */
    private static function run(array $argv, bool $capture = false): string
    {
        $desc = array(
            0 => array('pipe', 'r'),
            1 => $capture ? array('pipe', 'w') : array('file', '/dev/null', 'w'),
            2 => array('file', '/dev/null', 'w'),
        );
        $proc = @proc_open($argv, $desc, $pipes, null, null, array('bypass_shell' => true));
        if (!is_resource($proc)) {
            return '';
        }
        fclose($pipes[0]);
        $out = '';
        if ($capture) {
            $out = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        proc_close($proc);
        return (string)$out;
    }

    /** Convierte '1G','512M','256000K' a bytes. */
    private static function parseSize(string $s): int
    {
        $s = trim($s);
        if ($s === '' || !preg_match('/^(\d+)\s*([KMGkmg]?)/', $s, $m)) {
            return 0;
        }
        $n = (int)$m[1];
        switch (strtoupper($m[2])) {
            case 'G': return $n * 1024 * 1024 * 1024;
            case 'M': return $n * 1024 * 1024;
            case 'K': return $n * 1024;
            default:  return $n;
        }
    }
}

}

<?php
/**
 * OnDaemonRun — Sincroniza usuarios de sistema con cuentas de hosting.
 *
 * Cada vez que corre el daemon:
 *  - Crea h_USERNAME para cualquier cuenta activa que no lo tenga.
 *  - Elimina h_USERNAME para cuentas marcadas como borradas que aún lo tengan.
 *
 * Actúa como red de seguridad ante fallos del hook OnAfterCreateClient/DeleteClient.
 */

if (!class_exists('privilege')) {
    require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
}

hosting_users_daemon_sync();

function hosting_users_daemon_sync(): void
{
    global $zdbh;

    // ── Crear usuarios que faltan ────────────────────────────────────────────
    $sql = $zdbh->prepare(
        "SELECT ac_user_vc FROM x_accounts WHERE ac_deleted_ts IS NULL"
    );
    $sql->execute();

    while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
        $username = (string)$row['ac_user_vc'];
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $username)) continue;
        if (hosting_users_sysuser_exists('h_' . $username)) continue;

        $req = '/var/bulwark/run/hosting_useradd_req';
        file_put_contents($req, $username);
        @chmod($req, 0660);
        try {
            privilege::run('hosting_user_add');
        } catch (\Throwable $e) {
            error_log("hosting_users daemon add '$username': " . $e->getMessage());
        }
    }

    // ── Eliminar usuarios de cuentas borradas ────────────────────────────────
    $sql2 = $zdbh->prepare(
        "SELECT ac_user_vc FROM x_accounts WHERE ac_deleted_ts IS NOT NULL"
    );
    $sql2->execute();

    while ($row = $sql2->fetch(PDO::FETCH_ASSOC)) {
        $username = (string)$row['ac_user_vc'];
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $username)) continue;
        if (!hosting_users_sysuser_exists('h_' . $username)) continue;

        $req = '/var/bulwark/run/hosting_userdel_req';
        file_put_contents($req, $username);
        @chmod($req, 0660);
        try {
            privilege::run('hosting_user_del');
        } catch (\Throwable $e) {
            error_log("hosting_users daemon del '$username': " . $e->getMessage());
        }
    }
}

function hosting_users_sysuser_exists(string $sysuser): bool
{
    $handle = @fopen('/etc/passwd', 'r');
    if (!$handle) return false;
    while (($line = fgets($handle)) !== false) {
        if (strpos($line, $sysuser . ':') === 0) {
            fclose($handle);
            return true;
        }
    }
    fclose($handle);
    return false;
}

<?php
/**
 * OnAfterDeleteClient — Elimina el usuario de sistema h_USERNAME al borrar una cuenta.
 * Se dispara desde manage_clients tras marcar ac_deleted_ts.
 */

if (!class_exists('privilege')) {
    require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
}

hosting_users_delete_for_removed_accounts();

function hosting_users_delete_for_removed_accounts(): void
{
    global $zdbh;

    $sql = $zdbh->prepare(
        "SELECT ac_user_vc FROM x_accounts WHERE ac_deleted_ts IS NOT NULL"
    );
    $sql->execute();

    while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
        $username = (string)$row['ac_user_vc'];
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $username)) {
            continue;
        }
        // Solo actuar si el usuario de sistema aún existe
        if (!hosting_users_sysuser_exists('h_' . $username)) {
            continue;
        }
        hosting_users_run_del($username);
    }
}

function hosting_users_run_del(string $username): void
{
    $req = '/var/bulwark/run/hosting_userdel_req';
    if (file_put_contents($req, $username) === false) {
        error_log("hosting_users: no se pudo escribir en $req");
        return;
    }
    @chmod($req, 0660);
    try {
        privilege::run('hosting_user_del');
    } catch (\Throwable $e) {
        error_log("hosting_users del '$username': " . $e->getMessage());
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

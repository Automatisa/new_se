<?php
/**
 * OnAfterCreateClient — Crea usuario de sistema h_USERNAME al crear una cuenta de hosting.
 * Se dispara desde manage_clients tras el INSERT en x_accounts.
 */

if (!class_exists('privilege')) {
    require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
}

hosting_users_create_for_new_accounts();

function hosting_users_create_for_new_accounts(): void
{
    global $zdbh;

    // Buscar cuentas creadas en los últimos 5 minutos sin usuario de sistema todavía
    $cutoff = time() - 300;
    $sql = $zdbh->prepare(
        "SELECT ac_user_vc FROM x_accounts
          WHERE ac_deleted_ts IS NULL AND ac_created_ts >= :cutoff
          ORDER BY ac_id_pk DESC"
    );
    $sql->execute([':cutoff' => $cutoff]);

    while ($row = $sql->fetch(PDO::FETCH_ASSOC)) {
        $username = (string)$row['ac_user_vc'];
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $username)) {
            continue;
        }
        if (hosting_users_sysuser_exists('h_' . $username)) {
            continue;
        }
        hosting_users_run_add($username);
    }
}

function hosting_users_run_add(string $username): void
{
    $req = '/var/bulwark/run/hosting_useradd_req';
    if (file_put_contents($req, $username) === false) {
        error_log("hosting_users: no se pudo escribir en $req");
        return;
    }
    @chmod($req, 0660);
    try {
        privilege::run('hosting_user_add');
    } catch (\Throwable $e) {
        error_log("hosting_users add '$username': " . $e->getMessage());
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

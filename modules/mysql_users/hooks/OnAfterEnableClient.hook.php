<?php

UnlockMySQLUsers();

function UnlockMySQLUsers() {
    global $zdbh;

    // Restaurar solo cuentas cuyo estado propio es activo Y cuyo reseller (si existe) también es activo
    $sql = $zdbh->prepare("
        SELECT mu.mu_name_vc
        FROM x_mysql_users mu
        JOIN x_accounts ac ON mu.mu_acc_fk = ac.ac_id_pk
        LEFT JOIN x_accounts res ON ac.ac_reseller_fk = res.ac_id_pk AND res.ac_deleted_ts IS NULL
        WHERE ac.ac_enabled_in = 1 AND ac.ac_deleted_ts IS NULL
          AND (res.ac_id_pk IS NULL OR res.ac_enabled_in = 1)
          AND mu.mu_deleted_ts IS NULL
    ");
    $sql->execute();
    $users = $sql->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) return;

    include('cnf/db.php');
    try {
        $admin_db = new db_driver("mysql:host={$host}", $user, $pass);
    } catch (PDOException $e) {
        return;
    }

    foreach ($users as $u) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $u['mu_name_vc'])) continue;
        try {
            $admin_db->exec("ALTER USER '" . $u['mu_name_vc'] . "'@'%' ACCOUNT UNLOCK");
        } catch (PDOException $e) {
            // User may not exist — skip silently
        }
    }
    $admin_db->exec("FLUSH PRIVILEGES");
}

<?php

LockMySQLUsers();

function LockMySQLUsers() {
    global $zdbh;

    // Directamente deshabilitados + sub-clientes activos de resellers deshabilitados
    $sql = $zdbh->prepare("
        SELECT mu.mu_name_vc
        FROM x_mysql_users mu
        JOIN x_accounts ac ON mu.mu_acc_fk = ac.ac_id_pk
        WHERE ac.ac_enabled_in = 0 AND mu.mu_deleted_ts IS NULL
        UNION
        SELECT mu.mu_name_vc
        FROM x_mysql_users mu
        JOIN x_accounts ac ON mu.mu_acc_fk = ac.ac_id_pk
        JOIN x_accounts res ON ac.ac_reseller_fk = res.ac_id_pk
        WHERE res.ac_enabled_in = 0 AND res.ac_suspended_in = 0
          AND ac.ac_enabled_in = 1 AND ac.ac_deleted_ts IS NULL
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
            $admin_db->exec("ALTER USER '" . $u['mu_name_vc'] . "'@'%' ACCOUNT LOCK");
        } catch (PDOException $e) {
            // User may not exist yet — skip silently
        }
    }
    $admin_db->exec("FLUSH PRIVILEGES");
}

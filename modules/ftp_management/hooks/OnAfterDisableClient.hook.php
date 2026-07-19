<?php

SuspendFTPAccounts();

function SuspendFTPAccounts() {
    global $zdbh;

    // Directamente deshabilitados + sub-clientes activos de resellers deshabilitados
    $sql = $zdbh->prepare("
        SELECT ft.ft_user_vc
        FROM x_ftpaccounts ft
        JOIN x_accounts ac ON ft.ft_acc_fk = ac.ac_id_pk
        WHERE ac.ac_enabled_in = 0 AND ft.ft_deleted_ts IS NULL
        UNION
        SELECT ft.ft_user_vc
        FROM x_ftpaccounts ft
        JOIN x_accounts ac ON ft.ft_acc_fk = ac.ac_id_pk
        JOIN x_accounts res ON ac.ac_reseller_fk = res.ac_id_pk
        WHERE res.ac_enabled_in = 0 AND res.ac_suspended_in = 0
          AND ac.ac_enabled_in = 1 AND ac.ac_deleted_ts IS NULL
          AND ft.ft_deleted_ts IS NULL
    ");
    $sql->execute();
    $accounts = $sql->fetchAll(PDO::FETCH_ASSOC);

    if (empty($accounts)) return;

    include('cnf/db.php');
    try {
        $ftp_db = new db_driver("mysql:host={$host};dbname=bulwark_proftpd", $user, $pass);
    } catch (PDOException $e) {
        return;
    }

    foreach ($accounts as $acct) {
        $upd = $ftp_db->prepare("UPDATE ftpuser SET passwd = 'SUSPENDED' WHERE userid = :user");
        $upd->bindParam(':user', $acct['ft_user_vc']);
        $upd->execute();
    }

    require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
    privilege::run('proftpd_reload');
}

<?php

RestoreFTPAccounts();

function RestoreFTPAccounts() {
    global $zdbh;

    // Restaurar solo cuentas cuyo estado propio es activo Y cuyo reseller (si existe) también es activo
    $sql = $zdbh->prepare("
        SELECT ft.ft_user_vc, ft.ft_password_vc
        FROM x_ftpaccounts ft
        JOIN x_accounts ac ON ft.ft_acc_fk = ac.ac_id_pk
        LEFT JOIN x_accounts res ON ac.ac_reseller_fk = res.ac_id_pk AND res.ac_deleted_ts IS NULL
        WHERE ac.ac_enabled_in = 1 AND ac.ac_deleted_ts IS NULL
          AND (res.ac_id_pk IS NULL OR res.ac_enabled_in = 1)
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
        // Solo restaurar filas que estén marcadas como SUSPENDED — no toca hashes válidos
        $upd = $ftp_db->prepare("UPDATE ftpuser SET passwd = :pass WHERE userid = :user AND passwd = 'SUSPENDED'");
        $upd->bindParam(':pass', $acct['ft_password_vc']);
        $upd->bindParam(':user', $acct['ft_user_vc']);
        $upd->execute();
    }

    require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
    privilege::run('proftpd_reload');
}

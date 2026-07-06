<?php

RestoreMailboxes();

function RestoreMailboxes() {
    global $zdbh;

    $mail_db_name = ctrl_options::GetSystemOption('mailserver_db');
    include('cnf/db.php');
    try {
        $mail_db = new db_driver("mysql:host={$host};dbname={$mail_db_name}", $user, $pass);
    } catch (PDOException $e) {
        return;
    }

    // Restaurar solo cuentas cuyo estado propio es activo Y cuyo reseller (si existe) también es activo
    $sql = $zdbh->prepare("
        SELECT mb.mb_address_vc
        FROM x_mailboxes mb
        JOIN x_accounts ac ON mb.mb_acc_fk = ac.ac_id_pk
        LEFT JOIN x_accounts res ON ac.ac_reseller_fk = res.ac_id_pk AND res.ac_deleted_ts IS NULL
        WHERE ac.ac_enabled_in = 1 AND ac.ac_deleted_ts IS NULL
          AND (res.ac_id_pk IS NULL OR res.ac_enabled_in = 1)
          AND mb.mb_deleted_ts IS NULL
    ");
    $sql->execute();
    $mailboxes = $sql->fetchAll(PDO::FETCH_ASSOC);

    foreach ($mailboxes as $mb) {
        $upd = $mail_db->prepare("UPDATE mailbox SET active = 1 WHERE username = :addr");
        $upd->bindParam(':addr', $mb['mb_address_vc']);
        $upd->execute();

        $upd = $mail_db->prepare("UPDATE alias SET active = 1 WHERE address = :addr");
        $upd->bindParam(':addr', $mb['mb_address_vc']);
        $upd->execute();
    }
}

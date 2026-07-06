<?php

SuspendMailboxes();

function SuspendMailboxes() {
    global $zdbh;

    $mail_db_name = ctrl_options::GetSystemOption('mailserver_db');
    include('cnf/db.php');
    try {
        $mail_db = new db_driver("mysql:host={$host};dbname={$mail_db_name}", $user, $pass);
    } catch (PDOException $e) {
        return;
    }

    // Directamente deshabilitados + sub-clientes activos de resellers deshabilitados
    $sql = $zdbh->prepare("
        SELECT mb.mb_address_vc
        FROM x_mailboxes mb
        JOIN x_accounts ac ON mb.mb_acc_fk = ac.ac_id_pk
        WHERE ac.ac_enabled_in = 0 AND mb.mb_deleted_ts IS NULL
        UNION
        SELECT mb.mb_address_vc
        FROM x_mailboxes mb
        JOIN x_accounts ac ON mb.mb_acc_fk = ac.ac_id_pk
        JOIN x_accounts res ON ac.ac_reseller_fk = res.ac_id_pk
        WHERE res.ac_enabled_in = 0 AND res.ac_suspended_in = 0
          AND ac.ac_enabled_in = 1 AND ac.ac_deleted_ts IS NULL
          AND mb.mb_deleted_ts IS NULL
    ");
    $sql->execute();
    $mailboxes = $sql->fetchAll(PDO::FETCH_ASSOC);

    foreach ($mailboxes as $mb) {
        $upd = $mail_db->prepare("UPDATE mailbox SET active = 0 WHERE username = :addr");
        $upd->bindParam(':addr', $mb['mb_address_vc']);
        $upd->execute();

        $upd = $mail_db->prepare("UPDATE alias SET active = 0 WHERE address = :addr");
        $upd->bindParam(':addr', $mb['mb_address_vc']);
        $upd->execute();
    }
}

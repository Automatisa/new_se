<?php

DeleteMailboxesForDeletedClient();

function DeleteMailboxesForDeletedClient() {
    global $zdbh;
    $deletedclients = array();
    $sql = $zdbh->prepare("SELECT ac_id_pk FROM x_accounts WHERE ac_deleted_ts IS NOT NULL");
    $sql->execute();
    while ($rowclient = $sql->fetch()) {
        $deletedclients[] = $rowclient['ac_id_pk'];
    }

    // Include mail server specific file here.
    $mailserver_file = basename(ctrl_options::GetSystemOption('mailserver_php'));
    if ($mailserver_file !== '' && file_exists("modules/mailboxes/hooks/" . $mailserver_file)) {
        include("modules/mailboxes/hooks/" . $mailserver_file);
    }

    foreach ($deletedclients as $deletedclient) {
//      $result = $zdbh->query("SELECT * FROM x_mailboxes WHERE mb_acc_fk=" . $deletedclient . " AND mb_deleted_ts IS NULL")->Fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_mailboxes WHERE mb_acc_fk=:deletedclient AND mb_deleted_ts IS NULL");
        $numrows->bindParam(':deletedclient', $deletedclient);
        $numrows->execute();
        $result = $numrows->fetch();
        if ($result) {
            $time = time();
            $sql = $zdbh->prepare("UPDATE x_mailboxes SET mb_deleted_ts=:time WHERE mb_acc_fk=:deletedclient");
            $sql->bindParam(':time', $time);
            $sql->bindParam(':deletedclient', $deletedclient);
            $sql->execute();
        }
    }
}

?>
<?php

DeleteForwardersForDeletedClient();

function DeleteForwardersForDeletedClient() {
    global $zdbh;
    $deletedclients = array();
    $sql = $zdbh->prepare("SELECT ac_id_pk FROM x_accounts WHERE ac_deleted_ts IS NOT NULL");
    $sql->execute();
    while ($rowclient = $sql->fetch()) {
        $deletedclients[] = $rowclient['ac_id_pk'];
    }

    // Include mail server specific file here.
    $mailserver_file = basename(ctrl_options::GetSystemOption('mailserver_php'));
    if ($mailserver_file !== '' && file_exists("modules/forwarders/hooks/" . $mailserver_file)) {
        include("modules/forwarders/hooks/" . $mailserver_file);
    }

    foreach ($deletedclients as $deletedclient) {
        //$result = $zdbh->query("SELECT * FROM x_forwarders WHERE fw_acc_fk=:deletedclient AND fw_deleted_ts IS NULL")->Fetch();       
        $numrows = $zdbh->prepare("SELECT * FROM x_forwarders WHERE fw_acc_fk=:deletedclient AND fw_deleted_ts IS NULL");
        $numrows->bindParam(':deletedclient', $deletedclient);
        $numrows->execute();
        $result = $numrows->fetch();

        if ($result) {
            $sql = $zdbh->prepare("UPDATE x_forwarders SET fw_deleted_ts=:time WHERE fw_acc_fk=:deletedclient");
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->bindParam(':deletedclient', $deletedclient);
            $sql->execute();
        }
    }
}

?>
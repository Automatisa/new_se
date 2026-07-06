<?php

DeleteFTPForDeletedClient();

function DeleteFTPForDeletedClient() {
    global $zdbh;
    global $controller;
    $deletedclients = array();
    $sql = $zdbh->prepare("SELECT ac_id_pk FROM x_accounts WHERE ac_deleted_ts IS NOT NULL");
    $sql->execute();
    while ($rowclient = $sql->fetch()) {
        $deletedclients[] = $rowclient['ac_id_pk'];
    }

    // Include FTP server specific file here.
    $ftpHookFile = "modules/ftp_management/hooks/" . basename(ctrl_options::GetSystemOption('ftp_php'));
    if (file_exists($ftpHookFile)) {
        include($ftpHookFile);
    }
}

?>
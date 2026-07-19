<?php

echo fs_filehandler::NewLine() . "START MySQL Databases hook" . fs_filehandler::NewLine();
echo "Calculating the total size of all MySQL databases...." . fs_filehandler::NewLine();
CalculateAllDatabaseSize();
echo "END MySQL Databases hook" . fs_filehandler::NewLine();

/*
 * Calculate the total size of all MySQL database.
 */

function CalculateAllDatabaseSize() {
    global $zdbh;
    include('cnf/db.php');
    $z_db_user = $user;
    $z_db_pass = $pass;
    $mysqlsql = $zdbh->query("SELECT my_id_pk, my_name_vc FROM x_mysql_databases WHERE my_deleted_ts IS NULL");
    while ($database = $mysqlsql->fetch()) {
        // Robustez: si una BD ya no existe (borrada fuera del panel -> registro huérfano), la
        // conexión lanza "Unknown database" y ANTES abortaba TODO el hook cada 5 min (spam del
        // system_log). Ahora se aísla por BD: se salta la que falle y se sigue con las demás.
        try {
            $currentdb = new db_driver("mysql:host=$host;dbname=" . $database['my_name_vc'] . "", $z_db_user, $z_db_pass);
            $currentdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $dbsize = $currentdb->query("SHOW TABLE STATUS");
            $dbgetsize = 0;
            while ($row = $dbsize->fetch()) {
                $dbgetsize = $dbgetsize + ($row['Data_length'] + $row['Index_length']);
            }
            $numrows = $zdbh->prepare("UPDATE x_mysql_databases SET my_usedspace_bi = :dbgetsize WHERE my_id_pk =:my_id_pk");
            $numrows->bindParam(':dbgetsize', $dbgetsize);
            $numrows->bindParam(':my_id_pk', $database['my_id_pk']);
            $numrows->execute();
        } catch (\Exception $e) {
            error_log('[bulwark:mysql_databases] BD inaccesible id=' . (int)$database['my_id_pk']
                      . ' (' . $database['my_name_vc'] . '): ' . $e->getMessage() . ' — se omite.');
            continue;
        }
    }
}

?>
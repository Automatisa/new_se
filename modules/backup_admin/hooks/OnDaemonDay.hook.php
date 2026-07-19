<?php

include('cnf/db.php');
$z_db_user = $user;
$z_db_pass = $pass;
$z_db_host = $host;
$z_db_name = $dbname;
try {
    $zdbh = new db_driver("mysql:host=" . $z_db_host . ";dbname=" . $z_db_name . "", $z_db_user, $z_db_pass);
    // sys_backup_* usan `global $zdbh`; aquí es local, así que lo exponemos.
    $GLOBALS['zdbh'] = $zdbh;
} catch (PDOException $e) {

}

echo fs_filehandler::NewLine() . "START Backup Config." . fs_filehandler::NewLine();
if (ui_module::CheckModuleEnabled('Backup Config')) {
    echo "Backup Config module ENABLED..." . fs_filehandler::NewLine();

// LEGACY: el bucle "copiar TODAS las cuentas de golpe una vez al día" queda DESACTIVADO
// (if (false)). Ahora las copias automáticas las gestiona el PROGRAMADOR por cuenta con spool
// por bloques en backup_admin/OnDaemonRun.hook.php (sys_backup_scheduler), configurable por el
// usuario (x_backup_schedule) y sin colapsar el servidor. El interruptor maestro sigue siendo
// 'schedule_bu'.
    if (false && strtolower(ctrl_options::GetSystemOption('schedule_bu')) == "true") {
        runtime_hook::Execute('OnBeforeScheduleBackup');
        echo "Backup Scheduling enabled - Backing up all enabled client files now..." . fs_filehandler::NewLine();
        // Get all accounts
        $bsql = "SELECT * FROM x_accounts WHERE ac_enabled_in=1 AND ac_deleted_ts IS NULL";
        $numrows = $zdbh->query($bsql);
        if ($numrows->fetchColumn() <> 0) {
            $bsql = $zdbh->prepare($bsql);
            $bsql->execute();
            while ($rowclients = $bsql->fetch()) {
                // Skip accounts with unsafe usernames (path traversal guard)
                if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_\-]{0,63}$/', $rowclients['ac_user_vc'])) {
                    echo "Skipping account with invalid username." . fs_filehandler::NewLine();
                    continue;
                }
                echo "Backing up client folder: " . $rowclients['ac_user_vc'] . "/public_html..." . fs_filehandler::NewLine();
                // User loop
                $username = $rowclients['ac_user_vc'];
                $userid   = $rowclients['ac_id_pk'];
                $homedir  = ctrl_options::GetSystemOption('hosted_dir') . $username;
                $backupname = $username . "_" . date("M-d-Y_His", time());
                $dbstamp    = date("dmy_Gi", time());
                $temp_dir   = ctrl_options::GetSystemOption('temp_dir');
                $zip_exe    = ctrl_options::GetSystemOption('zip_exe');

                // GUARD DE DISCO: no generar la copia si dejaría el disco del servidor sin margen
                // (el .zip temporal va a una carpeta compartida que no cuenta para la cuota).
                if (class_exists('sys_backup_retention') && !sys_backup_retention::tempSpaceGuard($username, $temp_dir)) {
                    echo "Copia OMITIDA para $username: espacio temporal insuficiente en el servidor." . fs_filehandler::NewLine();
                    continue;
                }

                // File backup: proc_open with bypass_shell=true (no shell injection)
                $zip_argv = [
                    $zip_exe, '-r9',
                    $temp_dir . $backupname,
                    $username . '/',
                    '--exclude=' . $username . '/backups/*',
                ];
                $zproc = proc_open($zip_argv, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $zpipes, dirname($homedir), null, ['bypass_shell' => true]);
                if (is_resource($zproc)) {
                    fclose($zpipes[0]);
                    fclose($zpipes[1]);
                    fclose($zpipes[2]);
                    proc_close($zproc);
                }
                @chmod($temp_dir . $backupname . ".zip", 0600); // SEC: era 0777

                // Now lets backup all MySQL databases for the user and add them to the archive...
                $sql = $zdbh->prepare("SELECT COUNT(*) FROM x_mysql_databases WHERE my_acc_fk = :uid AND my_deleted_ts IS NULL");
                $sql->execute([':uid' => (int)$userid]);
                if ($sql->fetchColumn() > 0) {
                    $sql = $zdbh->prepare("SELECT * FROM x_mysql_databases WHERE my_acc_fk = :uid AND my_deleted_ts IS NULL");
                    $sql->execute([':uid' => (int)$userid]);
                    while ($row_mysql = $sql->fetch()) {
                        $db_name = $row_mysql['my_name_vc'];
                        // Validate DB name before using in filesystem paths
                        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_\-]{0,63}$/', $db_name)) {
                            echo "Skipping DB with invalid name." . fs_filehandler::NewLine();
                            continue;
                        }
                        $sql_path = $temp_dir . $db_name . "_" . $dbstamp . ".sql";

                        // Write temporary credentials file to avoid credentials in process list
                        $cnf_path = tempnam(sys_get_temp_dir(), 'bulwark_bu') . '.cnf';
                        file_put_contents($cnf_path, "[mysqldump]\nhost={$host}\nuser={$user}\npassword={$pass}\n");
                        chmod($cnf_path, 0600);

                        $dump_argv = [
                            ctrl_options::GetSystemOption('mysqldump_exe'),
                            '--defaults-extra-file=' . $cnf_path,
                            '--no-create-db',
                            $db_name,
                        ];
                        $dproc = proc_open($dump_argv, [0 => ['pipe','r'], 1 => ['file', $sql_path, 'w'], 2 => ['pipe','w']], $dpipes, null, null, ['bypass_shell' => true]);
                        if (is_resource($dproc)) {
                            fclose($dpipes[0]);
                            fclose($dpipes[2]);
                            proc_close($dproc);
                        }
                        unlink($cnf_path);

                        // Add SQL dump to the ZIP archive
                        $zip2_argv = [
                            $zip_exe,
                            $temp_dir . $backupname,
                            $db_name . "_" . $dbstamp . ".sql",
                        ];
                        $z2proc = proc_open($zip2_argv, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $z2pipes, $temp_dir, null, ['bypass_shell' => true]);
                        if (is_resource($z2proc)) {
                            fclose($z2pipes[0]);
                            fclose($z2pipes[1]);
                            fclose($z2pipes[2]);
                            proc_close($z2proc);
                        }
                        unlink($sql_path);
                    }
                }
                // Añadir la configuración del panel del usuario (panel_config.json) al zip,
                // igual que el backup manual (F1), para poder restaurar la cuenta al momento.
                $zipfull = $temp_dir . $backupname . ".zip";
                if (file_exists($zipfull) && class_exists('sys_backup_export')) {
                    $cfgfile = $temp_dir . "panel_config.json";
                    if (@file_put_contents($cfgfile, sys_backup_export::run($zdbh, $userid)) !== false) {
                        @chmod($cfgfile, 0600);
                        $zc_argv = [$zip_exe, '-j', $temp_dir . $backupname, $cfgfile];
                        $zcp = proc_open($zc_argv, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $zcpipes, $temp_dir, null, ['bypass_shell'=>true]);
                        if (is_resource($zcp)) { fclose($zcpipes[0]); fclose($zcpipes[1]); fclose($zcpipes[2]); proc_close($zcp); }
                        @unlink($cfgfile);
                    }
                }

                // Enviar la copia al destino remoto de la cuenta si está activado (F2).
                if (file_exists($zipfull) && class_exists('sys_backup_remote')) {
                    $dest = sys_backup_remote::getDestination($userid);
                    if ($dest && (int)$dest['bd_enabled_in'] === 1 && !empty($dest['bd_host_vc'])) {
                        $t0 = time();
                        list($rok, $rmsg) = sys_backup_remote::uploadWithRetry($dest, $zipfull);
                        sys_backup_remote::recordStatus($userid, ($rok ? 'OK: ' : 'ERROR: ') . $rmsg);
                        // Retención remota (qt_backups_remote_in): poda las sobrantes del FTP.
                        if ($rok && class_exists('sys_backup_retention')) {
                            $maxRemote = sys_backup_retention::getMaxRemote($userid);
                            if ($maxRemote > 0) {
                                $pr = sys_backup_remote::enforceRemoteRetention($dest, $maxRemote, $username);
                                if ($pr > 0) $rmsg .= " (retención: $pr antiguas borradas)";
                            }
                        }
                        if (class_exists('sys_backup_log')) {
                            sys_backup_log::record(
                                $userid, 'remote',
                                $dest['bd_host_vc'] . ':' . (int)$dest['bd_port_in'] . ' (' . $dest['bd_type_vc'] . ')',
                                $backupname, @filesize($zipfull), 0, $rok, $rmsg, time() - $t0
                            );
                        }
                        echo "Envío remoto: " . ($rok ? 'OK' : 'ERROR') . " - " . $rmsg . fs_filehandler::NewLine();
                    }
                }

                // Guardar en el home del usuario con permisos seguros (0600/0700, no 0777),
                // respetando el límite de copias del paquete (retención) y la cuota de disco.
                if (file_exists($zipfull)) {
                    $backupdir = $homedir . "/backups/";
                    if (!is_dir($backupdir)) { mkdir($backupdir, 0700, TRUE); }
                    @chmod($backupdir, 0700);

                    $zipbytes = @filesize($zipfull);
                    $maxLocal = class_exists('sys_backup_retention') ? sys_backup_retention::getMaxLocal($userid) : 0;
                    if ($maxLocal > 0) sys_backup_retention::enforceLocal($username, $userid, max(0, $maxLocal - 1));

                    if (class_exists('sys_backup_retention') && sys_backup_retention::wouldExceedQuota($username, $userid, $zipbytes)) {
                        echo "Aviso: copia NO guardada en disco (superaría la cuota de la cuenta)." . fs_filehandler::NewLine();
                    } else {
                        copy($zipfull, $backupdir . $backupname . ".zip");
                        fs_director::SetFileSystemPermissions($backupdir . $backupname . ".zip", 0600);
                        if ($maxLocal > 0) sys_backup_retention::enforceLocal($username, $userid, $maxLocal);
                        if (class_exists('sys_backup_log')) {
                            sys_backup_log::record($userid, 'local', 'disco (home)', $backupname . '.zip',
                                $zipbytes, 0, true, 'Copia programada guardada en ' . $username . '/backups/', 0);
                        }
                        echo $backupdir . $backupname . ".zip" . fs_filehandler::NewLine();
                    }
                    @unlink($zipfull);
                }
            }
        }
        runtime_hook::Execute('OnAfterScheduleBackup');
        echo "Backup Schedule COMPLETE..." . fs_filehandler::NewLine();
    }

// Purge backups are enabled....
    if (strtolower(ctrl_options::GetSystemOption('purge_bu')) == "true") {
        echo fs_filehandler::NewLine() . "Backup Purging enabled - Purging backups older than " . ctrl_options::GetSystemOption('purge_date') . " days..." . fs_filehandler::NewLine();
        runtime_hook::Execute('OnBeforePurgeBackup');
        clearstatcache();
        // Get all accounts
        $bsql = "SELECT * FROM x_accounts WHERE ac_enabled_in=1 AND ac_deleted_ts IS NULL";
        $numrows = $zdbh->query($bsql);
        if ($numrows->fetchColumn() <> 0) {
            $purge_date = ctrl_options::GetSystemOption('purge_date');
            $bsql = $zdbh->prepare($bsql);
            $bsql->execute();
            echo "[FILE][PURGE_DATE][FILE_DATE][ACTION]" . fs_filehandler::NewLine();
            while ($rowclients = $bsql->fetch()) {
                $username = $rowclients['ac_user_vc'];
                $backupdir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/backups/";
                if ($handle = @opendir($backupdir)) {
                    while (false !== ($file = readdir($handle))) {
                        if ($file != "." && $file != "..") {
                            $filetime = @filemtime($backupdir . $file);
                            $filetime = floor((time() - $filetime) / 86400);
                            echo "" . $file . " - " . $purge_date . " - " . $filetime . "";
                            if ($purge_date < $filetime) {
                                //delete the file
                                echo " - Deleting file..." . fs_filehandler::NewLine();
                                unlink($backupdir . $file);
                            } else {
                                echo " - Skipping file..." . fs_filehandler::NewLine();
                            }
                        }
                    }
                }
            }
        }
        echo "Backup Purging COMPLETE..." . fs_filehandler::NewLine();
        runtime_hook::Execute('OnAfterPurgeBackup');
    }


    // Clean temp backups....
    echo fs_filehandler::NewLine() . "Purging backups from temp folder..." . fs_filehandler::NewLine();
    clearstatcache();
    echo "[FILE][PURGE_DATE][FILE_DATE][ACTION]" . fs_filehandler::NewLine();
    $temp_dir = ctrl_options::GetSystemOption('bulwark_root') . "/modules/backupmgr/temp/";
    if ($handle = @opendir($temp_dir)) {
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                $filetime = @filemtime($temp_dir . $file);
                $filetime = floor((time() - $filetime) / 86400);
                echo "" . $file . " - " . $purge_date . " - " . $filetime . "";
                if (1 <= $filetime) {
                    //delete the file
                    echo " - Deleting file..." . fs_filehandler::NewLine();
                    unlink($temp_dir . $file);
                } else {
                    echo " - Skipping file..." . fs_filehandler::NewLine();
                }
            }
        }
    }
} else {
    echo "Backup Config module DISABLED...nothing to do." . fs_filehandler::NewLine();
}
echo "END Backup Config." . fs_filehandler::NewLine();
?>

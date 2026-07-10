<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 * 
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
session_start();
if (!isset($_POST['csfr_token']) || !isset($_SESSION['zpcsfr']) || !hash_equals((string)$_SESSION['zpcsfr'], (string)$_POST['csfr_token'])) {
    die("<h1>Application Error: [0204]</h1><p>Invalid CSRF token.</p>");
}
set_time_limit(0);
ini_set('memory_limit', '256M');
require($_SERVER["DOCUMENT_ROOT"] . 'cnf/db.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/db/driver.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/debug/logger.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/runtime/dataobject.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/runtime/hook.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/sys/versions.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/ctrl/options.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/fs/director.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/fs/filehandler.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'inc/dbc.inc.php');
try {
    $zdbh = new db_driver("mysql:host=" . $host . ";dbname=" . $dbname . "", $user, $pass);
} catch (PDOException $e) {
    exit();
}
if (isset($_POST['inDownLoad'])) {
    $download = $_POST['inDownLoad'];
} else {
    $download = 0;
}
if (isset($_GET['id']) && $_GET['id'] != "") {
    if ((int)$_SESSION['zpuid'] === (int)$_GET['id'] && (int)$_GET['id'] > 0) {
        $userid = (int)$_GET['id'];
        $rows = $zdbh->prepare("
	    	SELECT * FROM x_accounts 
	        LEFT JOIN x_profiles ON (x_accounts.ac_id_pk=x_profiles.ud_user_fk) 
	        LEFT JOIN x_groups   ON (x_accounts.ac_group_fk=x_groups.ug_id_pk) 
	        LEFT JOIN x_packages ON (x_accounts.ac_package_fk=x_packages.pk_id_pk) 
	        LEFT JOIN x_quotas   ON (x_accounts.ac_package_fk=x_quotas.qt_package_fk) 
	        WHERE x_accounts.ac_id_pk= :userid
	        ");
        $rows->bindParam(':userid', $userid);
        $rows->execute();
        $dbvals = $rows->fetch();

        if ($backup = ExecuteBackup($userid, $dbvals['ac_user_vc'], $download)) {
            $safe_file = htmlspecialchars(basename($backup), ENT_QUOTES, 'UTF-8');
            echo "<p>Ready to download file: <b>" . $safe_file . "</b></p>";
            echo "<button class=\"fg-button ui-state-default ui-corner-all\" type=\"button\" onclick=\"window.location.href='downloadbackup.php?id=" . $userid . "&amp;file=" . $safe_file . "';return false;\">Download Now</button>";
            echo "<button class=\"fg-button ui-state-default ui-corner-all\" type=\"button\" value=\"Close Window\" onClick=\"return window.close()\">Close Window</button>";
        } else {
            echo "Could not find user!";
        }
    } else {
        echo "<h2>Unauthorized Access!</h2>";
        echo "You have no permission to view this module.";
    }
}

function ExecuteBackup($userid, $username, $download = 0) {
    include($_SERVER["DOCUMENT_ROOT"] . 'cnf/db.php');
    try {
        $zdbh = new db_driver("mysql:host=" . $host . ";dbname=" . $dbname . "", $user, $pass);
    } catch (PDOException $e) {
        exit();
    }
    $basedir = ctrl_options::GetSystemOption('temp_dir');
    if (!is_dir($basedir)) {
        fs_director::CreateDirectory($basedir);
    }
    $basedir = ctrl_options::GetSystemOption('sentora_root') . "etc/tmp/";
    if (!is_dir($basedir)) {
        fs_director::CreateDirectory($basedir);
    }
    $temp_dir = ctrl_options::GetSystemOption('sentora_root') . "etc/tmp/";
    // Lets grab and archive the user's web data....
    $homedir    = ctrl_options::GetSystemOption('hosted_dir') . $username;
    $backupname = $username . "_" . date("M-d-Y_hms", time());
    $dbstamp    = date("dmy_Gi", time());
    $zip_exe    = ctrl_options::GetSystemOption('zip_exe');

    // CRIT-3 FIX: validate username contains only safe characters before shell use
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
        return false;
    }

    $resault = exec(
        "cd " . escapeshellarg(dirname($homedir)) . " && "
        . escapeshellarg($zip_exe) . " -r9 "
        . escapeshellarg($temp_dir . $backupname) . " "
        . escapeshellarg($username . "/")
        . " --exclude=" . escapeshellarg($username . "/backups/*")
    );
    @chmod($temp_dir . $backupname . ".zip", 0777);
    // Now lets backup all MySQL datbases for the user and add them to the archive...
    $sql = "SELECT COUNT(*) FROM x_mysql_databases WHERE my_acc_fk=:userid AND my_deleted_ts IS NULL";
    $numrows = $zdbh->prepare($sql);
    $numrows->bindParam(':userid', $userid);
    $numrows->execute();

    if ($numrows) {
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare("SELECT * FROM x_mysql_databases WHERE my_acc_fk=:userid AND my_deleted_ts IS NULL");
            $sql->bindParam(':userid', $userid);
            $sql->execute();
            while ($row_mysql = $sql->fetch()) {
                $dbname_shell = $row_mysql['my_name_vc'];
                // CRIT-2 FIX: validate DB name before any shell use
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbname_shell)) {
                    continue;
                }
                $sql_outfile = $temp_dir . $dbname_shell . "_" . $dbstamp . ".sql";
                // CRIT-2 FIX: escapeshellarg on ALL variables passed to shell.
                // La contraseña NO va en la línea de comandos (visible por `ps`): se pasa por
                // un fichero de credenciales temporal 0600 con --defaults-extra-file.
                $cnf_path = tempnam(sys_get_temp_dir(), 'sentora_bu') . '.cnf';
                file_put_contents($cnf_path, "[mysqldump]\nhost=\"" . $host . "\"\nuser=\"" . $user . "\"\npassword=\"" . $pass . "\"\n");
                @chmod($cnf_path, 0600);
                $bkcommand = escapeshellarg(ctrl_options::GetSystemOption('mysqldump_exe'))
                    . " --defaults-extra-file=" . escapeshellarg($cnf_path)
                    . " --no-create-db "
                    . escapeshellarg($dbname_shell)
                    . " > " . escapeshellarg($sql_outfile);
                passthru($bkcommand);
                @unlink($cnf_path);
                // Add it to the ZIP archive...
                $resault = exec(
                    "cd " . escapeshellarg($temp_dir) . " && "
                    . escapeshellarg($zip_exe) . " "
                    . escapeshellarg($temp_dir . $backupname) . " "
                    . escapeshellarg($dbname_shell . "_" . $dbstamp . ".sql")
                );
                unlink($sql_outfile);
            }
        }
    }
    // We have the backup now lets output it to disk or download
    if (file_exists($temp_dir . $backupname . ".zip")) {

        // If Disk based backups are allowed in backup config
        if (strtolower(ctrl_options::GetSystemOption('disk_bu')) == "true") {
            // Copy Backup to user home directory...
            $backupdir = $homedir . "/backups/";
            if (!is_dir($backupdir)) {
                fs_director::CreateDirectory($backupdir);
                @chmod($backupdir, 0777);
            }
            copy($temp_dir . $backupname . ".zip", $backupdir . $backupname . ".zip");
            fs_director::SetFileSystemPermissions($backupdir . $backupname . ".zip", 0777);
        } else {
            $backupdir = $temp_dir;
        }

        // If Client has checked to download file
        if ($download <> 0) {
            fs_director::SetFileSystemPermissions($backupdir . $backupname . ".zip", 0777);
            return $temp_dir . $backupname . ".zip";
        }
        unlink($temp_dir . $backupname . ".zip");
		
    } else {
        echo "File not found in temp directory!";
        return FALSE;
    }
    return TRUE;
}

function readfile_chunked($filename) {
    $chunksize = 1 * (1024 * 1024);
    $buffer = '';
    $handle = fopen($filename, 'rb');
    if ($handle === false) {
        return false;
    }
    while (!feof($handle)) {
        $buffer = fread($handle, $chunksize);
        print $buffer;
    }
    return fclose($handle);
}

?>

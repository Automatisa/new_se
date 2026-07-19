<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
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
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/sys/backup_remote.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/sys/backup_log.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/sys/backup_retention.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/sys/backup_export.class.php');
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
// Modo de ejecución manual: 'local' (solo disco), 'remote' (solo FTP), 'both' (compat).
$mode = 'both';
if (isset($_REQUEST['mode']) && in_array($_REQUEST['mode'], array('local', 'remote', 'both'), true)) {
    $mode = $_REQUEST['mode'];
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

        if ($backup = ExecuteBackup($userid, $dbvals['ac_user_vc'], $download, $mode)) {
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

function ExecuteBackup($userid, $username, $download = 0, $mode = 'both') {
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
    $basedir = ctrl_options::GetSystemOption('bulwark_root') . "etc/tmp/";
    if (!is_dir($basedir)) {
        fs_director::CreateDirectory($basedir);
    }
    $temp_dir = ctrl_options::GetSystemOption('bulwark_root') . "etc/tmp/";
    // Lets grab and archive the user's web data....
    $homedir    = ctrl_options::GetSystemOption('hosted_dir') . $username;
    $backupname = $username . "_" . date("M-d-Y_hms", time());
    $dbstamp    = date("dmy_Gi", time());
    $zip_exe    = ctrl_options::GetSystemOption('zip_exe');

    // CRIT-3 FIX: validate username contains only safe characters before shell use
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
        return false;
    }

    // GUARD DE DISCO: el .zip temporal se crea en una carpeta COMPARTIDA del sistema (no cuenta
    // para la cuota del usuario). Sin control, muchas copias simultáneas de cuentas grandes
    // llenarían el HD. Abortar si no queda margen de seguridad en el disco.
    if (!sys_backup_retention::tempSpaceGuard($username, $temp_dir)) {
        echo "<p><b>Aviso:</b> el servidor no tiene espacio temporal suficiente para generar la "
           . "copia en este momento (protección para no llenar el disco). Inténtalo más tarde o "
           . "avisa al administrador.</p>";
        return false;
    }

    $resault = exec(
        "cd " . escapeshellarg(dirname($homedir)) . " && "
        . escapeshellarg($zip_exe) . " -r9 "
        . escapeshellarg($temp_dir . $backupname) . " "
        . escapeshellarg($username . "/")
        . " --exclude=" . escapeshellarg($username . "/backups/*")
    );
    @chmod($temp_dir . $backupname . ".zip", 0600); // SEC: era 0777 (world-readable entre inquilinos)
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
                $cnf_path = tempnam(sys_get_temp_dir(), 'bulwark_bu') . '.cnf';
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
    // Exportar la configuración del panel del usuario e incluirla en el zip, para poder
    // restaurar la cuenta al momento de la copia (dominios, DNS, correo, FTP, BD, cron, etc.).
    if (file_exists($temp_dir . $backupname . ".zip")) {
        $cfg_json = ExportPanelConfig($zdbh, $userid);
        if ($cfg_json !== false) {
            $cfg_file = $temp_dir . "panel_config.json";
            if (@file_put_contents($cfg_file, $cfg_json) !== false) {
                @chmod($cfg_file, 0600);
                exec(
                    "cd " . escapeshellarg($temp_dir) . " && "
                    . escapeshellarg($zip_exe) . " -j "
                    . escapeshellarg($temp_dir . $backupname) . " "
                    . escapeshellarg($cfg_file)
                );
                @unlink($cfg_file);
            }
        }
    }

    // Si el .zip no llegó a crearse, informar una sola vez (cualquier modo) y salir.
    if (!file_exists($temp_dir . $backupname . ".zip")) {
        echo "File not found in temp directory!";
        return FALSE;
    }

    // Fase 2: enviar la copia al destino remoto de la cuenta si está activado (FTPS).
    // Solo en modo 'remote' o 'both'. sys_backup_remote usa `global $zdbh`; aquí es local.
    if (($mode === 'remote' || $mode === 'both') && file_exists($temp_dir . $backupname . ".zip")) {
        $GLOBALS['zdbh'] = $zdbh;
        $dest = sys_backup_remote::getDestination($userid);
        if ($dest && (int)$dest['bd_enabled_in'] === 1 && !empty($dest['bd_host_vc'])) {
            $zipfile = $temp_dir . $backupname . ".zip";
            $t0 = time();
            list($rok, $rmsg) = sys_backup_remote::uploadWithRetry($dest, $zipfile);
            sys_backup_remote::recordStatus($userid, ($rok ? 'OK: ' : 'ERROR: ') . $rmsg);
            // RETENCIÓN REMOTA: conservar solo las qt_backups_remote_in más recientes en el FTP
            // (poda las sobrantes por MDTM). 0 = ilimitado. Solo si la subida fue OK.
            $pruned = 0;
            if ($rok) {
                $maxRemote = sys_backup_retention::getMaxRemote($userid);
                if ($maxRemote > 0) {
                    $pruned = sys_backup_remote::enforceRemoteRetention($dest, $maxRemote, $username);
                }
            }
            sys_backup_log::record(
                $userid, 'remote',
                $dest['bd_host_vc'] . ':' . (int)$dest['bd_port_in'] . ' (' . $dest['bd_type_vc'] . ')',
                $backupname . '.zip', @filesize($zipfile), 0, $rok,
                $rmsg . ($pruned > 0 ? " (retención: $pruned antiguas borradas)" : ''), time() - $t0
            );
            echo "<p>Envío remoto: " . ($rok ? 'OK' : 'ERROR') . " - " . htmlspecialchars($rmsg, ENT_QUOTES) . "</p>";
        } elseif ($mode === 'remote') {
            echo "<p><b>Aviso:</b> no hay destino remoto (FTP) activo configurado.</p>";
        }
    }

    // Guardar en disco: solo en modo 'local' o 'both'.
    if (($mode === 'local' || $mode === 'both') && file_exists($temp_dir . $backupname . ".zip")) {

        // If Disk based backups are allowed in backup config
        if (strtolower(ctrl_options::GetSystemOption('disk_bu')) == "true") {
            // Copy Backup to user home directory...
            $backupdir = $homedir . "/backups/";
            if (!is_dir($backupdir)) {
                fs_director::CreateDirectory($backupdir);
                // SEC: 0700 (no 0777). El directorio de copias no debe ser accesible por
                // otros inquilinos del sistema; una copia contiene credenciales de BD de
                // las apps y datos personales.
                @chmod($backupdir, 0700);
            }
            $newzip   = $temp_dir . $backupname . ".zip";
            $zipbytes = @filesize($newzip);

            // RETENCIÓN: dejar hueco para la nueva copia según el máximo del paquete
            // (qt_backups_in). Rota las más antiguas dejando (max-1) para que, tras copiar,
            // no se supere el límite.
            $maxLocal = sys_backup_retention::getMaxLocal($userid);
            if ($maxLocal > 0) {
                sys_backup_retention::enforceLocal($username, $userid, max(0, $maxLocal - 1));
            }

            // CUOTA: no crear una copia local que deje la cuenta por encima de su cuota de
            // disco (evita que un backup tumbe la web por "disk exceeded"). Si no cabe, se
            // omite la copia en disco (la descarga directa sigue disponible).
            if (sys_backup_retention::wouldExceedQuota($username, $userid, $zipbytes)) {
                echo "<p><b>Aviso:</b> la copia no se ha guardado en el servidor porque superaría "
                   . "la cuota de disco de la cuenta. Descárgala directamente o libera espacio "
                   . "(o sube el límite de disco / de copias del paquete).</p>";
                $backupdir = $temp_dir; // se sirve desde temp para descarga, no se guarda en el home
            } else {
                copy($newzip, $backupdir . $backupname . ".zip");
                fs_director::SetFileSystemPermissions($backupdir . $backupname . ".zip", 0600);
                // Seguridad extra: reforzar el límite exacto tras copiar.
                if ($maxLocal > 0) sys_backup_retention::enforceLocal($username, $userid, $maxLocal);
                sys_backup_log::record($userid, 'local', 'disco (home)', $backupname . '.zip',
                    $zipbytes, 0, true, 'Copia guardada en ' . $username . '/backups/', 0);
            }
        } else {
            $backupdir = $temp_dir;
        }

        // Descarga directa (solo local/both): devolver el zip para servirlo al navegador.
        if ($download <> 0) {
            fs_director::SetFileSystemPermissions($backupdir . $backupname . ".zip", 0600);
            return $temp_dir . $backupname . ".zip";
        }
    }

    // Limpieza del zip temporal en CUALQUIER modo (si no se devolvió para descarga).
    if (file_exists($temp_dir . $backupname . ".zip")) {
        unlink($temp_dir . $backupname . ".zip");
    }
    return TRUE;
}

/**
 * Exporta TODA la configuración del panel de UNA cuenta a JSON, para poder restaurar la
 * cuenta al momento de la copia. Cada consulta se filtra por la columna de propiedad de la
 * cuenta ($fk), de modo que el export queda estrictamente acotado a este usuario: no incluye
 * datos de otros usuarios ni secretos del sistema (root MySQL, TSIG, DKIM privado de otros,
 * etc., que viven fuera de estas tablas).
 *
 * Incluye los hashes de contraseña propios (panel/FTP/MySQL) para un restore idéntico. Son
 * un riesgo contenido SOLO a esta cuenta (crackeo offline) si el zip se filtra; el .zip se
 * genera 0600. Se EXCLUYEN a propósito: x_api_tokens (tokens de API), x_bandwidth y x_logs
 * (estadísticas/registros regenerables) y x_faqs.
 */
function ExportPanelConfig($zdbh, $userid) {
    // Fuente única: sys_backup_export (compartida con el backup programado).
    return sys_backup_export::run($zdbh, $userid);
}

?>

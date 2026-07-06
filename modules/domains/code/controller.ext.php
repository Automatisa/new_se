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
class module_controller extends ctrl_module
{

    static $complete;
    static $error;
    static $writeerror;
    static $nosub;
    static $alreadyexists;
    static $badname;
    static $blank;
    static $ok;

    /**
     * The 'worker' methods.
     */
    static function ListDomains($uid = 0)
    {
        global $zdbh;
        if ($uid == 0) {
            $sql = "SELECT * FROM x_vhosts WHERE vh_deleted_ts IS NULL AND vh_type_in=1 ORDER BY vh_name_vc ASC";
            $numrows = $zdbh->prepare($sql);
        } else {
            $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_deleted_ts IS NULL AND vh_type_in=1 ORDER BY vh_name_vc ASC";
            $numrows = $zdbh->prepare($sql);
            $numrows->bindParam(':uid', $uid);
        }
        //$numrows = $zdbh->query($sql);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            if ($uid == 0) {
                $sql = $zdbh->prepare($sql);
            } else {
                $sql = $zdbh->prepare($sql);
                $sql->bindParam(':uid', $uid);
            }
            $res = array();
            $sql->execute();
            while ($rowdomains = $sql->fetch()) {
                array_push($res, array(
                    'uid' => $rowdomains['vh_acc_fk'],
                    'name' => $rowdomains['vh_name_vc'],
                    'directory' => $rowdomains['vh_directory_vc'],
                    'active' => $rowdomains['vh_active_in'],
                    'enabled' => $rowdomains['vh_enabled_in'],
                    'id' => $rowdomains['vh_id_pk'],
                ));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListDomainDirs($uid)
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $res = array();
        $base = rtrim(ctrl_options::GetSystemOption('hosted_dir'), '/') . '/' . $currentuser['username'];
        $handle = @opendir($base);
        if (!$handle) {
            # Log an error as the folder cannot be opened...
        } else {
            while ($file = @readdir($handle)) {
                if ($file != "." && $file != ".." && $file != "backups" && $file != "tmp" && $file != "ssl") {
                    if (is_dir($base . '/' . $file) && is_dir($base . '/' . $file . '/public_html')) {
                        array_push($res, array('domains' => $file));
                    }
                }
            }
            closedir($handle);
        }
        return $res;
    }

    static function ExecuteDeleteDomain($id)
    {
        global $zdbh;
		
		// NEW - Delete Snuff files for domain
		$sql2 = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_id_pk=:id");
		$sql2->bindParam(':id', $id);
    	$sql2->execute();
    	while ($rowvhost = $sql2->fetch()) {
				
		$vhostuser = ctrl_users::GetUserDetail($rowvhost['vh_acc_fk']);
		$vhostusername = $vhostuser['username'];
		// Eliminar directorio del dominio del disco
		$paths = ctrl_options::GetVhostPaths($vhostusername, $rowvhost['vh_directory_vc']);
		if (is_dir($paths['domain_root'])) {
			fs_filehandler::RemoveDirectory($paths['domain_root'] . "/");
		}

		// Cascade-delete subdomains of this domain
		$domainname = $rowvhost['vh_name_vc'];
		$sqlsubs = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_name_vc LIKE :pattern AND vh_type_in=2 AND vh_deleted_ts IS NULL");
		$subpattern = '%.' . $domainname;
		$sqlsubs->bindParam(':pattern', $subpattern);
		$sqlsubs->execute();
		$now = time();
		while ($rowsub = $sqlsubs->fetch()) {
			$subsnuff = $vh_snuff_path . $vhostusername . "/" . $rowsub['vh_name_vc'] . '.rules';
			if (file_exists($subsnuff)) { unlink($subsnuff); }
			$subpaths = ctrl_options::GetVhostPaths($vhostusername, $rowsub['vh_directory_vc']);
			if (is_dir($subpaths['domain_root'])) {
				fs_filehandler::RemoveDirectory($subpaths['domain_root'] . "/");
			}
			$delsub = $zdbh->prepare("UPDATE x_vhosts SET vh_deleted_ts=:now WHERE vh_id_pk=:subid");
			$delsub->bindParam(':now', $now);
			$delsub->bindParam(':subid', $rowsub['vh_id_pk']);
			$delsub->execute();
		}
		}

		// Delete Domain
        runtime_hook::Execute('OnBeforeDeleteDomain');
        $sql = $zdbh->prepare("UPDATE x_vhosts
							   SET vh_deleted_ts=:time
							   WHERE vh_id_pk=:id");
        $sql->bindParam(':id', $id);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        self::SetWriteApacheConfigTrue();
        $retval = TRUE;
        runtime_hook::Execute('OnAfterDeleteDomain');
        return $retval; 
    }
	
	
	
    static function ExecuteAddDomain($uid, $domain, $destination, $autohome)
    {
        global $zdbh;
        $retval = FALSE;
        runtime_hook::Execute('OnBeforeAddDomain');
        $currentuser = ctrl_users::GetUserDetail($uid);
        $domain = strtolower(str_replace(' ', '', $domain));
        if (!fs_director::CheckForEmptyValue(self::CheckCreateForErrors($domain))) {
            $destination = str_replace(".", "_", $domain);
            $paths = ctrl_options::GetVhostPaths($currentuser['username'], $destination);

            fs_director::CreateDirectory($paths['domain_root']);
            fs_director::CreateDirectory($paths['public_html']);
            fs_director::CreateDirectory($paths['tmp']);
            fs_director::CreateDirectory($paths['logs']);
            foreach (array('-access.log', '-error.log', '-bandwidth.log') as $_logsuffix) {
                $_logfile = $paths['logs'] . '/' . $domain . $_logsuffix;
                if (!file_exists($_logfile)) { @touch($_logfile); }
            }
            fs_director::CreateDirectory($paths['errorpages']);
            fs_director::CreateDirectory($paths['cgibin']);
            fs_director::SetFileSystemPermissions($paths['domain_root'], 0755);
            $vhost_path = $paths['public_html'] . '/';
            // Error documents:- Error pages are added automatically if they are found in the _errorpages directory
            // and if they are a valid error code, and saved in the proper format, i.e. <error_number>.html
            fs_director::CreateDirectory($vhost_path . "/_errorpages/");
            $errorpages = ctrl_options::GetSystemOption('static_dir') . "/errorpages/";
            if (is_dir($errorpages)) {
                if ($handle = @opendir($errorpages)) {
                    while (($file = @readdir($handle)) !== false) {
                        if ($file != "." && $file != "..") {
                            $page = explode(".", $file);
                            if (!fs_director::CheckForEmptyValue(self::CheckErrorDocument($page[0]))) {
                                fs_filehandler::CopyFile($errorpages . $file, $vhost_path . '/_errorpages/' . $file);
                            }
                        }
                    }
                    closedir($handle);
                }
            }
            // Lets copy the default welcome page across...
            if ((!file_exists($vhost_path . "/index.html")) && (!file_exists($vhost_path . "/index.php")) && (!file_exists($vhost_path . "/index.htm"))) {
                fs_filehandler::CopyFileSafe(ctrl_options::GetSystemOption('static_dir') . "pages/welcome.html", $vhost_path . "/index.html");
            }
            // If all has gone well we need to now create the domain in the database...
            $sql = $zdbh->prepare("INSERT INTO x_vhosts (vh_acc_fk,
														 vh_name_vc,
														 vh_directory_vc,
														 vh_type_in,
														 vh_created_ts) VALUES (
														 :userid,
														 :domain,
														 :destination,
														 1,
														 :time)"); //CLEANER FUNCTION ON $domain and $homedirectory_to_use (Think I got it?)
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->bindParam(':userid', $currentuser['userid']);
            $sql->bindParam(':domain', $domain);
            $sql->bindParam(':destination', $destination);
            $sql->execute();
            self::SetWriteApacheConfigTrue();
            $retval = TRUE;
            runtime_hook::Execute('OnAfterAddDomain');
            return $retval;
        }
    }

    static function CheckCreateForErrors($domain)
    {
        global $zdbh;
        // Check for spaces and remove if found...
        $domain = strtolower(str_replace(' ', '', $domain));
        // Check to make sure the domain is not blank before we go any further...
        if ($domain == '') {
            self::$blank = TRUE;
            return FALSE;
        }
        // Check for invalid characters in the domain...
        if (!fs_director::IsValidDomainName($domain)) {
            self::$badname = TRUE;
            return FALSE;
        }
        // Check to make sure the domain is in the correct format before we go any further...
        if (strpos($domain, 'www.') === 0) {
            self::$error = TRUE;
            return FALSE;
        }
        // Check to see if the domain already exists in Sentora somewhere and redirect if it does....
        $sql = "SELECT COUNT(*) FROM x_vhosts WHERE vh_name_vc=:domain AND vh_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':domain', $domain);

        if ($numrows->execute()) {
            if ($numrows->fetchColumn() > 0) {
                self::$alreadyexists = TRUE;
                return FALSE;
            }
        }
        // Check to make sure user not adding a subdomain and blocks stealing of subdomains.
        if (substr_count($domain, ".") > 1) {
            $part = explode('.', $domain);
            foreach ($part as $check) {
                if (strlen($check) > 3) {
                        $sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_name_vc LIKE :check AND vh_type_in !=2 AND vh_deleted_ts IS NULL");
                        $checkSql = '%' . $check . '%';
                        $sql->bindParam(':check', $checkSql);
                        $sql->execute();
                        while ($rowcheckdomains = $sql->fetch()) {
                            $subpart = explode('.', $rowcheckdomains['vh_name_vc']);
                            foreach ($subpart as $subcheck) {
                                if (strlen($subcheck) > 3) {
                                    if ($subcheck == $check) {
                                        if (substr($domain, -7) == substr($rowcheckdomains['vh_name_vc'], -7)) {
                                            self::$nosub = TRUE;
                                            return FALSE;
                                        }
                                    }
                                }
                            }
                        }
                }
            }
        }
        return TRUE;
    }

    static function CheckErrorDocument($error)
    {
        $errordocs = array(100, 101, 102, 200, 201, 202, 203, 204, 205, 206, 207,
            300, 301, 302, 303, 304, 305, 306, 307, 400, 401, 402,
            403, 404, 405, 406, 407, 408, 409, 410, 411, 412, 413,
            414, 415, 416, 417, 418, 419, 420, 421, 422, 423, 424,
            425, 426, 500, 501, 502, 503, 504, 505, 506, 507, 508,
            509, 510);
        return in_array($error, $errordocs);
    }


    static function IsValidEmail($email)
    {
        return preg_match('/^[a-z0-9]+([_\\.-][a-z0-9]+)*@([a-z0-9]+([\.-][a-z0-9]+)*)+\\.[a-z]{2,}$/i', $email) == 1;
    }

    static function SetWriteApacheConfigTrue()
    {
        global $zdbh;
        $sql = $zdbh->prepare("UPDATE x_settings
								SET so_value_tx='true'
								WHERE so_name_vc='apache_changed'");
        $sql->execute();
    }

    static function IsvalidIP($ip)
    {
        return preg_match("^([1-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(\.([0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3}^", $ip) == 1;
    }

    /**
     * End 'worker' methods.
     */

    /**
     * Webinterface sudo methods.
     */
    static function getDomainList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $res = array();
        $domains = self::ListDomains($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($domains)) {
            foreach ($domains as $row) {
                $status = self::getDomainStatusHTML($row['active'], $row['enabled'], $row['id']);
                $res[] = array('name' => $row['name'],
                    'directory' => $row['directory'],
                    'active' => $row['active'],
                    'enabled' => $row['enabled'],
                    'status' => $status,
                    'id' => $row['id']);
            }
            return $res;
        } else {
            return false;
        }
    }

    static function getCreateDomain()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ($currentuser['domainquota'] < 0) or //-1 = unlimited
                ($currentuser['domainquota'] > ctrl_users::GetQuotaUsages('domains', $currentuser['userid']));
    }

    static function getDomainDirsList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $domaindirectories = self::ListDomainDirs($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($domaindirectories)) {
            return $domaindirectories;
        } else {
            return false;
        }
    }

    static function doCreateDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteAddDomain($currentuser['userid'], $formvars['inDomain'], $formvars['inDestination'], $formvars['inAutoHome'])) {
            self::$ok = TRUE;
            return true;
        } else {
            return false;
        }
        return;
    }

    static function doDeleteDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inDelete'])) {
            if (self::ExecuteDeleteDomain($formvars['inDelete'])) {
                self::$ok = TRUE;
                return true;
            }
        }
        return false;
    }

    static function doConfirmDeleteDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListDomains($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['id'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&id=" . $row['id'] . "&domain=" . $row['name'] . "");
                exit;
            }
        }
        return false;
    }

    static function getisDeleteDomain($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Delete user domains.
        // This shall avoid exposing domain based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

    	$sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_name_vc=:editedDomainID AND vh_deleted_ts IS NULL";
    	$numrows = $zdbh->prepare($sql);
    	$numrows->bindParam(':userid', $currentuser['userid']);
		$numrows->bindParam(':editedDomainID', $urlvars['domain']);
    	$numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }

        // Show User Info
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Delete");
    }

    static function getCurrentID()
    {
        global $controller;
        $id = $controller->GetControllerRequest('URL', 'id');
        return ($id) ? $id : '';
    }

    static function getCurrentDomain()
    {
        global $controller;
        $domain = $controller->GetControllerRequest('URL', 'domain');
        return ($domain) ? $domain : '';
    }

    static function getSubDomainsForDelete()
    {
        global $zdbh, $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        $domain = isset($urlvars['domain']) ? $urlvars['domain'] : '';
        if (empty($domain)) return false;
        $sql = $zdbh->prepare("SELECT vh_name_vc FROM x_vhosts WHERE vh_name_vc LIKE :pattern AND vh_type_in=2 AND vh_deleted_ts IS NULL");
        $pattern = '%.' . $domain;
        $sql->bindParam(':pattern', $pattern);
        $sql->execute();
        $res = array();
        while ($row = $sql->fetch()) {
            $res[] = array('subname' => $row['vh_name_vc']);
        }
        return !empty($res) ? $res : false;
    }

    static function getDomainUsagepChart()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $maximum = $currentuser['domainquota'];
        if ($maximum < 0) { //-1 = unlimited
            return '<img src="' . ui_tpl_assetfolderpath::Template() . 'img/misc/unlimited.png" alt="' . ui_language::translate('Unlimited') . '"/>';
        } else {
            $used = ctrl_users::GetQuotaUsages('domains', $currentuser['userid']);
            $free = max($maximum - $used, 0);
            return '<img src="etc/lib/charts/svg_pie.php?score=' . $free . '::' . $used
                    . '&labels=Free:_' . $free . '::Used:_' . $used . '&imagesize=320::200"'
                    . ' alt="' . ui_language::translate('Pie chart') . '"/>';
        }
    }

    static function getDomainStatusHTML($active, $enabled, $id)
    {
        global $controller;
        $mod = $controller->GetControllerRequest('URL', 'module');

        if ((int)$enabled === 0) {
            $statusTd = '<td><span style="color:#e67e22;font-weight:bold">' . ui_language::translate('Suspended') . '</span></td>';
        } elseif ((int)$active === 1) {
            $statusTd = '<td><span style="color:green;font-weight:bold">' . ui_language::translate('Live') . '</span></td>';
        } else {
            $statusTd = '<td><span style="color:orange">' . ui_language::translate('Pending') . '</span></td>';
        }

        $toggleLabel = ((int)$enabled === 0) ? ui_language::translate('Activate') : ui_language::translate('Suspend');
        $toggleClass = ((int)$enabled === 0) ? 'btn-success' : 'btn-warning';

        $actionsTd = '<td style="white-space:nowrap">'
            . '<button class="button-loader btn btn-sm ' . $toggleClass . '" type="submit"'
            . ' name="inToggle_' . (int)$id . '" value="' . (int)$id . '"'
            . ' formaction="./?module=' . htmlspecialchars($mod, ENT_QUOTES) . '&action=ToggleDomain">'
            . $toggleLabel . '</button> '
            . '<a href="./?module=' . htmlspecialchars($mod, ENT_QUOTES) . '&show=PhpSettings&id=' . (int)$id . '"'
            . ' class="btn btn-info btn-sm">PHP</a> '
            . '<button class="delete btn btn-danger btn-sm" type="submit"'
            . ' name="inDelete_' . (int)$id . '" value="inDelete_' . (int)$id . '"><i class="bi bi-trash me-1"></i>'
            . ui_language::translate('Delete') . '</button>'
            . '</td>';

        return $statusTd . $actionsTd;
    }

    static function ExecuteToggleDomain($vhostid, $uid)
    {
        global $zdbh;
        $sql = $zdbh->prepare("SELECT vh_enabled_in FROM x_vhosts WHERE vh_id_pk = :id AND vh_acc_fk = :uid AND vh_deleted_ts IS NULL");
        $sql->bindParam(':id', $vhostid, PDO::PARAM_INT);
        $sql->bindParam(':uid', $uid, PDO::PARAM_INT);
        $sql->execute();
        $row = $sql->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $newState = ((int)$row['vh_enabled_in'] === 1) ? 0 : 1;
        $upd = $zdbh->prepare("UPDATE x_vhosts SET vh_enabled_in = :state WHERE vh_id_pk = :id");
        $upd->bindParam(':state', $newState, PDO::PARAM_INT);
        $upd->bindParam(':id', $vhostid, PDO::PARAM_INT);
        $upd->execute();
        self::SetWriteApacheConfigTrue();
        return true;
    }

    static function doToggleDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        $domains = self::ListDomains($currentuser['userid']);
        if ($domains) {
            foreach ($domains as $row) {
                if (isset($formvars['inToggle_' . $row['id']])) {
                    self::ExecuteToggleDomain($row['id'], $currentuser['userid']);
                    self::$ok = true;
                    return true;
                }
            }
        }
        return false;
    }

    // -----------------------------------------------------------------------
    // PHP settings per-domain (x_domain_php + FPM pools)
    // -----------------------------------------------------------------------

    private static $phpSettingsCache = null;

    private static function loadPhpSettings()
    {
        if (self::$phpSettingsCache !== null) return self::$phpSettingsCache;
        global $controller, $zdbh;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if (!isset($urlvars['show']) || $urlvars['show'] !== 'PhpSettings' || !isset($urlvars['id'])) {
            self::$phpSettingsCache = false;
            return false;
        }
        $vhostid     = (int)$urlvars['id'];
        $currentuser = ctrl_users::GetUserDetail();
        $chk = $zdbh->prepare("SELECT vh_name_vc FROM x_vhosts
                                WHERE vh_id_pk=:id AND vh_acc_fk=:uid AND vh_deleted_ts IS NULL");
        $chk->execute([':id' => $vhostid, ':uid' => $currentuser['userid']]);
        $vhost = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$vhost) { self::$phpSettingsCache = false; return false; }
        $row = $zdbh->prepare("SELECT * FROM x_domain_php WHERE dp_vhost_fk=:id");
        $row->execute([':id' => $vhostid]);
        $s = $row->fetch(PDO::FETCH_ASSOC) ?: [];
        self::$phpSettingsCache = [
            'domain_name'    => $vhost['vh_name_vc'],
            'vhost_id'       => $vhostid,
            'upload_max'     => $s['dp_upload_max_vc']    ?? '50M',
            'post_max'       => $s['dp_post_max_vc']      ?? '50M',
            'memory_limit'   => $s['dp_memory_limit_vc']  ?? '128M',
            'max_exec'       => $s['dp_max_exec_in']      ?? 30,
            'max_input'      => $s['dp_max_input_in']     ?? 60,
            'display_errors' => $s['dp_display_errors_in'] ?? 0,
        ];
        return self::$phpSettingsCache;
    }

    static function getisPhpSettings()
    {
        return self::loadPhpSettings() !== false;
    }

    static function getPhpDomainName()
    {
        $s = self::loadPhpSettings();
        return $s ? htmlspecialchars($s['domain_name'], ENT_QUOTES) : '';
    }

    static function getPhpVhostId()
    {
        $s = self::loadPhpSettings();
        return $s ? (int)$s['vhost_id'] : 0;
    }

    static function getPhpUploadMax()
    {
        $s = self::loadPhpSettings();
        return $s ? htmlspecialchars($s['upload_max'], ENT_QUOTES) : '50M';
    }

    static function getPhpPostMax()
    {
        $s = self::loadPhpSettings();
        return $s ? htmlspecialchars($s['post_max'], ENT_QUOTES) : '50M';
    }

    static function getPhpMemoryLimit()
    {
        $s = self::loadPhpSettings();
        return $s ? htmlspecialchars($s['memory_limit'], ENT_QUOTES) : '128M';
    }

    static function getPhpMaxExec()
    {
        $s = self::loadPhpSettings();
        return $s ? (int)$s['max_exec'] : 30;
    }

    static function getPhpMaxInput()
    {
        $s = self::loadPhpSettings();
        return $s ? (int)$s['max_input'] : 60;
    }

    static function getPhpDisplayErrorsChecked()
    {
        $s = self::loadPhpSettings();
        return ($s && $s['display_errors']) ? 'checked' : '';
    }

    static function doSavePhpSettings()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars    = $controller->GetAllControllerRequests('FORM');
        $vhostid     = (int)($formvars['inVhostId'] ?? 0);
        if (self::ExecuteSavePhpSettings($vhostid, $currentuser['userid'], $formvars)) {
            self::$ok = true;
            // Diferir el reload de FPM a después de enviar la respuesta al cliente.
            // En FreeBSD, service php_fpm reload hace execvp() que mata al worker actual
            // si se llama sincrónicamente → 503. fastcgi_finish_request() envía la
            // respuesta al cliente antes de que el worker muera por el reload.
            register_shutdown_function(function() {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                if (!class_exists('privilege')) {
                    require_once '/usr/local/sentora/dryden/sys/privilege.class.php';
                }
                try {
                    privilege::run('fpm_regenerate');
                } catch (\Throwable $e) {
                    error_log('domains: fpm_regenerate (shutdown) failed: ' . $e->getMessage());
                }
            });
            return true;
        }
        return false;
    }

    static function ExecuteSavePhpSettings($vhostid, $uid, $formvars)
    {
        global $zdbh;
        $chk = $zdbh->prepare("SELECT vh_id_pk, vh_acc_fk FROM x_vhosts
                                WHERE vh_id_pk=:id AND vh_acc_fk=:uid AND vh_deleted_ts IS NULL");
        $chk->execute([':id' => $vhostid, ':uid' => $uid]);
        if (!$chk->fetch()) return false;

        // Obtener límites del paquete del usuario propietario del vhost.
        $pkgLimits = $zdbh->prepare("
            SELECT COALESCE(q.qt_php_memory_vc,  '128M') AS pkg_memory,
                   COALESCE(q.qt_php_upload_vc,  '50M')  AS pkg_upload,
                   COALESCE(q.qt_php_post_vc,    '50M')  AS pkg_post,
                   COALESCE(q.qt_php_exec_in,    30)     AS pkg_exec,
                   COALESCE(q.qt_php_maxinput_in,60)     AS pkg_maxinput
            FROM x_accounts a
            LEFT JOIN x_packages pk ON pk.pk_id_pk = a.ac_package_fk AND pk.pk_deleted_ts IS NULL
            LEFT JOIN x_quotas q ON q.qt_package_fk = pk.pk_id_pk
            WHERE a.ac_id_pk = :uid AND a.ac_deleted_ts IS NULL
        ");
        $pkgLimits->execute([':uid' => $uid]);
        $pkg = $pkgLimits->fetch(PDO::FETCH_ASSOC) ?: [
            'pkg_memory' => '128M', 'pkg_upload' => '50M', 'pkg_post' => '50M',
            'pkg_exec' => 30, 'pkg_maxinput' => 60,
        ];

        $upload_max  = self::capPhpSize(self::sanitizeSizeValue($formvars['inUploadMax']   ?? '50M',  '50M'),  $pkg['pkg_upload']);
        $post_max    = self::capPhpSize(self::sanitizeSizeValue($formvars['inPostMax']     ?? '50M',  '50M'),  $pkg['pkg_post']);
        $memory      = self::capPhpSize(self::sanitizeSizeValue($formvars['inMemoryLimit'] ?? '128M', '128M'), $pkg['pkg_memory']);
        $max_exec    = min(max(1, (int)($formvars['inMaxExec']  ?? 30)),  (int)$pkg['pkg_exec']);
        $max_input   = min(max(1, (int)($formvars['inMaxInput'] ?? 60)),  (int)$pkg['pkg_maxinput']);
        $display_err = isset($formvars['inDisplayErrors']) ? 1 : 0;

        $upd = $zdbh->prepare("INSERT INTO x_domain_php
                (dp_vhost_fk, dp_upload_max_vc, dp_post_max_vc, dp_memory_limit_vc,
                 dp_max_exec_in, dp_max_input_in, dp_display_errors_in)
            VALUES (:vid, :umax, :pmax, :mem, :exec, :input, :err)
            ON DUPLICATE KEY UPDATE
                dp_upload_max_vc=:umax, dp_post_max_vc=:pmax, dp_memory_limit_vc=:mem,
                dp_max_exec_in=:exec, dp_max_input_in=:input, dp_display_errors_in=:err");
        $upd->execute([
            ':vid'   => $vhostid,
            ':umax'  => $upload_max,
            ':pmax'  => $post_max,
            ':mem'   => $memory,
            ':exec'  => $max_exec,
            ':input' => $max_input,
            ':err'   => $display_err,
        ]);
        return true;
    }

    private static function sanitizeSizeValue($val, $default)
    {
        $val = strtoupper(trim((string)$val));
        return preg_match('/^\d+[KMG]?$/', $val) ? $val : $default;
    }

    private static function parsePhpSize(string $s): int
    {
        $s    = trim($s);
        $unit = strtolower(substr($s, -1));
        $val  = (int)$s;
        switch ($unit) {
            case 'g': return $val * 1073741824;
            case 'm': return $val * 1048576;
            case 'k': return $val * 1024;
            default:  return $val;
        }
    }

    private static function capPhpSize(string $domain_val, string $pkg_val): string
    {
        return (self::parsePhpSize($domain_val) <= self::parsePhpSize($pkg_val))
            ? $domain_val
            : $pkg_val;
    }

    // -----------------------------------------------------------------------

    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$blank)) {
            return ui_sysmessage::shout(ui_language::translate("Your Domain can not be empty. Please enter a valid Domain Name and try again."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return ui_sysmessage::shout(ui_language::translate("Your Domain name is not valid. Please enter a valid Domain Name: i.e. 'domain.com'"), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("The domain already appears to exist on this server."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$nosub)) {
            return ui_sysmessage::shout(ui_language::translate("You cannot add a Sub-Domain here. Please use the Subdomain manager to add Sub-Domains."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$error)) {
            return ui_sysmessage::shout(ui_language::translate("Please remove 'www'. The 'www' will automatically work with all Domains / Subdomains."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$writeerror)) {
            return ui_sysmessage::shout(ui_language::translate("There was a problem writting to the virtual host container file. Please contact your administrator and report this error. Your domain will not function until this error is corrected."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your domain web hosting has been saved successfully."), "zannounceok");
        }
        return;
    }

    /**
     * Webinterface sudo methods.
     */
	 

	 
}

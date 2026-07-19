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
	
	
	
    /** Crea el esqueleto de directorios del vhost (web/<dir>/public_html,tmp,logs,_errorpages,
     *  _cgi-bin) por doas como root con ownership h_USERNAME:www. Necesario porque web/ es 2750 y
     *  el panel (www) no puede crear ahí. Idempotente. $vhdir = nombre de carpeta (dominio con '.'
     *  convertido a '_'). Devuelve true si el doas se ejecutó (no garantiza éxito del script). */
    private static function provisionVhostDirs($username, $vhdir) {
        if (!class_exists('privilege')) { require_once '/usr/local/bulwark/dryden/sys/privilege.class.php'; }
        $req = '/var/bulwark/run/vhost_diradd_req';
        if (@file_put_contents($req, $username . '|' . $vhdir) === false) {
            error_log("domains: no se pudo escribir $req");
            return false;
        }
        @chmod($req, 0660);
        try { privilege::run('vhost_dir_add'); return true; }
        catch (\Throwable $e) { error_log("domains vhost_dir_add '$username|$vhdir': " . $e->getMessage()); return false; }
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
            // El esqueleto de directorios (web/<dir>/public_html,tmp,logs,...) se crea por doas como
            // ROOT con ownership h_USERNAME:www: web/ es 2750 y el panel (www) no puede crear ahí.
            self::provisionVhostDirs($currentuser['username'], $destination);
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
        // Check to see if the domain already exists in Bulwark somewhere and redirect if it does....
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
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inDelete'])) {
            // AUTZ (IDOR fix): el dominio debe pertenecer al usuario autenticado. Sin esto,
            // un usuario podía POSTear inDelete=<id ajeno> a action=DeleteDomain (saltándose la
            // página de confirmación) y borrar el dominio de OTRO cliente, incluidos sus
            // ficheros de disco y subdominios en cascada.
            $cu  = ctrl_users::GetUserDetail();
            $own = $zdbh->prepare("SELECT vh_id_pk FROM x_vhosts WHERE vh_id_pk=:id AND vh_acc_fk=:uid AND vh_deleted_ts IS NULL");
            $own->execute(array(':id' => (int) $formvars['inDelete'], ':uid' => (int) $cu['userid']));
            if (!$own->fetch()) {
                return false;
            }
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
        // Se refleja en value="..." de la plantilla; escapar (el parámetro id de la URL no lo
        // valida getisDeleteDomain, que solo comprueba 'domain') -> evita XSS reflejado.
        $id = $controller->GetControllerRequest('URL', 'id');
        return ($id) ? htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') : '';
    }

    static function getCurrentDomain()
    {
        global $controller;
        $domain = $controller->GetControllerRequest('URL', 'domain');
        return ($domain) ? htmlspecialchars((string)$domain, ENT_QUOTES, 'UTF-8') : '';
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
            . '<a href="./?module=' . htmlspecialchars($mod, ENT_QUOTES) . '&show=IpSettings&id=' . (int)$id . '"'
            . ' class="btn btn-secondary btn-sm"><i class="bi bi-hdd-network me-1"></i>IP</a> '
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
            'php_version'    => $s['dp_php_version_vc']   ?? '',
            'timezone'       => $s['dp_timezone_vc']      ?? '',
            'max_input_vars' => $s['dp_max_input_vars_in'] ?? 1000,
            'opcache'        => $s['dp_opcache_in']       ?? 1,
        ];
        return self::$phpSettingsCache;
    }

    /** Etiqueta legible de una versión de PHP: '' => "Versión del sistema"; '84' => "PHP 8.4". */
    private static function phpVersionLabel($v)
    {
        if ($v === '' || $v === null) return 'Versión del sistema (por defecto)';
        return 'PHP ' . substr($v, 0, 1) . '.' . substr($v, 1);
    }

    static function getisPhpSettings()
    {
        return self::loadPhpSettings() !== false;
    }

    /** Vista principal (lista + crear): solo cuando NO se está en una vista de detalle
     *  (ajustes PHP, asignación de IP, o borrado). Así el detalle muestra solo ese dominio. */
    static function getisDomainMain()
    {
        return !self::getisPhpSettings() && !self::getisIpSettings() && !self::getisDeleteDomain();
    }

    // --- Vista de asignación de IP (show=IpSettings) -------------------------------------------
    private static $ipSettingsCache = null;

    private static function loadIpSettings()
    {
        if (self::$ipSettingsCache !== null) return self::$ipSettingsCache;
        global $controller, $zdbh;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if (!isset($urlvars['show']) || $urlvars['show'] !== 'IpSettings' || !isset($urlvars['id'])) {
            self::$ipSettingsCache = false;
            return false;
        }
        $vhostid     = (int)$urlvars['id'];
        $currentuser = ctrl_users::GetUserDetail();
        $chk = $zdbh->prepare("SELECT vh_name_vc FROM x_vhosts
                                WHERE vh_id_pk=:id AND vh_acc_fk=:uid AND vh_deleted_ts IS NULL");
        $chk->execute([':id' => $vhostid, ':uid' => $currentuser['userid']]);
        $vhost = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$vhost) { self::$ipSettingsCache = false; return false; }
        self::$ipSettingsCache = ['domain_name' => $vhost['vh_name_vc'], 'vhost_id' => $vhostid];
        return self::$ipSettingsCache;
    }

    static function getisIpSettings()
    {
        return self::loadIpSettings() !== false;
    }

    static function getIpDomainName()
    {
        $s = self::loadIpSettings();
        return $s ? htmlspecialchars($s['domain_name'], ENT_QUOTES) : '';
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

    static function getPhpTimezone()
    {
        $s = self::loadPhpSettings();
        return $s ? htmlspecialchars((string)$s['timezone'], ENT_QUOTES) : '';
    }

    static function getPhpMaxInputVars()
    {
        $s = self::loadPhpSettings();
        return $s ? (int)$s['max_input_vars'] : 1000;
    }

    static function getPhpOpcacheChecked()
    {
        $s = self::loadPhpSettings();
        return ($s && $s['opcache']) ? 'checked' : '';
    }

    /** <option> del selector de versión de PHP: solo versiones INSTALADAS (autodetectadas). */
    static function getPhpVersionOptions()
    {
        if (!class_exists('fpm_pool_manager')) {
            require_once '/usr/local/bulwark/dryden/sys/fpm_pool_manager.class.php';
        }
        $s   = self::loadPhpSettings();
        $cur = $s ? (string)$s['php_version'] : '';
        $out = '';
        foreach (array_keys(fpm_pool_manager::InstalledVersions()) as $v) {
            $sel = ($v === $cur) ? ' selected' : '';
            $out .= '<option value="' . htmlspecialchars($v, ENT_QUOTES) . '"' . $sel . '>'
                 . htmlspecialchars(self::phpVersionLabel($v), ENT_QUOTES) . '</option>';
        }
        return $out;
    }

    /** true si hay más de una versión disponible (para mostrar u ocultar el selector). */
    static function getHasMultiplePhpVersions()
    {
        if (!class_exists('fpm_pool_manager')) {
            require_once '/usr/local/bulwark/dryden/sys/fpm_pool_manager.class.php';
        }
        return count(fpm_pool_manager::InstalledVersions()) > 1;
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
                    require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
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

        // Versión de PHP: solo se acepta si está INSTALADA (autodetección). Cualquier otro valor
        // (incluida una versión desinstalada) cae a '' = versión del sistema.
        if (!class_exists('fpm_pool_manager')) {
            require_once '/usr/local/bulwark/dryden/sys/fpm_pool_manager.class.php';
        }
        $php_version = (string)($formvars['inPhpVersion'] ?? '');
        if (!array_key_exists($php_version, fpm_pool_manager::InstalledVersions())) {
            $php_version = '';
        }

        // Directivas fijas adicionales: timezone válida (o ''), max_input_vars acotado, opcache bool.
        $timezone = (string)($formvars['inTimezone'] ?? '');
        if ($timezone !== '' && !in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = '';
        }
        $max_input_vars = min(100000, max(1, (int)($formvars['inMaxInputVars'] ?? 1000)));
        $opcache = isset($formvars['inOpcache']) ? 1 : 0;

        $upd = $zdbh->prepare("INSERT INTO x_domain_php
                (dp_vhost_fk, dp_upload_max_vc, dp_post_max_vc, dp_memory_limit_vc,
                 dp_max_exec_in, dp_max_input_in, dp_display_errors_in, dp_php_version_vc,
                 dp_timezone_vc, dp_max_input_vars_in, dp_opcache_in)
            VALUES (:vid, :umax, :pmax, :mem, :exec, :input, :err, :ver, :tz, :miv, :opc)
            ON DUPLICATE KEY UPDATE
                dp_upload_max_vc=:umax, dp_post_max_vc=:pmax, dp_memory_limit_vc=:mem,
                dp_max_exec_in=:exec, dp_max_input_in=:input, dp_display_errors_in=:err,
                dp_php_version_vc=:ver, dp_timezone_vc=:tz, dp_max_input_vars_in=:miv,
                dp_opcache_in=:opc");
        $upd->execute([
            ':vid'   => $vhostid,
            ':umax'  => $upload_max,
            ':pmax'  => $post_max,
            ':mem'   => $memory,
            ':exec'  => $max_exec,
            ':input' => $max_input,
            ':err'   => $display_err,
            ':ver'   => $php_version,
            ':tz'    => $timezone,
            ':miv'   => $max_input_vars,
            ':opc'   => $opcache,
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

    // -----------------------------------------------------------------------
    // Multi-IP (Fase 1c) — asignar una IP del pool a un dominio
    // -----------------------------------------------------------------------

    /** Cuota de IPs dedicadas del paquete del usuario (-1 = ilimitado, 0 = ninguna). */
    private static function userIpQuota($uid) {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COALESCE(qt.qt_dedicatedips_in, 0) FROM x_accounts a
            LEFT JOIN x_packages pk ON pk.pk_id_pk = a.ac_package_fk AND pk.pk_deleted_ts IS NULL
            LEFT JOIN x_quotas  qt ON qt.qt_package_fk = pk.pk_id_pk
            WHERE a.ac_id_pk = :uid AND a.ac_deleted_ts IS NULL");
        $q->execute([':uid' => $uid]);
        $v = $q->fetchColumn();
        return $v === false ? 0 : (int)$v;
    }

    /** IPs dedicadas distintas que YA usa el usuario. */
    private static function userDedicatedIPs($uid) {
        global $zdbh;
        $q = $zdbh->prepare("SELECT DISTINCT vh_custom_ip_vc FROM x_vhosts
            WHERE vh_acc_fk=:uid AND vh_deleted_ts IS NULL AND vh_custom_ip_vc IS NOT NULL AND vh_custom_ip_vc<>''");
        $q->execute([':uid' => $uid]);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Quién POSEE las IPs que puede usar un usuario (Fase 2):
     *   - un reseller (grupo 2) usa su propio pool  -> devuelve su propio id;
     *   - un usuario normal usa el pool de su reseller (si su reseller es grupo 2) -> id del reseller;
     *   - admin o usuario directo del admin -> null (pool del admin, ip_reseller_fk IS NULL). */
    private static function ipOwnerForUser($uid) {
        global $zdbh;
        $q = $zdbh->prepare("SELECT ac_group_fk, ac_reseller_fk FROM x_accounts WHERE ac_id_pk=:id AND ac_deleted_ts IS NULL");
        $q->execute([':id' => $uid]);
        $a = $q->fetch(PDO::FETCH_ASSOC);
        if (!$a) return null;
        if ((int)$a['ac_group_fk'] === 2) return (int)$uid;          // reseller: su propio pool
        $rid = (int)($a['ac_reseller_fk'] ?? 0);
        if ($rid > 0) {
            $r = $zdbh->prepare("SELECT ac_group_fk FROM x_accounts WHERE ac_id_pk=:id AND ac_deleted_ts IS NULL");
            $r->execute([':id' => $rid]);
            if ((int)$r->fetchColumn() === 2) return $rid;           // su reseller (grupo 2)
        }
        return null;                                                  // pool del admin
    }

    /** IPs que el usuario puede elegir como dedicada: activas, no primarias, del pool que le
     *  corresponde (el del admin si no cuelga de un reseller, o el de SU reseller), y no usadas
     *  por dominios de OTRO usuario. */
    private static function assignableIPsForUser($uid, $family = '4') {
        global $zdbh;
        $owner   = self::ipOwnerForUser($uid);
        $famcond = ($family === '6') ? "ip_address_vc LIKE '%:%'" : "ip_address_vc NOT LIKE '%:%'";
        $col     = ($family === '6') ? 'vh_custom_ip6_vc' : 'vh_custom_ip_vc';
        if ($owner === null) {
            $rows = $zdbh->query("SELECT ip_address_vc FROM x_ips
                WHERE ip_enabled_in=1 AND ip_is_primary_in=0 AND ip_reseller_fk IS NULL AND $famcond
                ORDER BY INET6_ATON(ip_address_vc)")->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $st = $zdbh->prepare("SELECT ip_address_vc FROM x_ips
                WHERE ip_enabled_in=1 AND ip_is_primary_in=0 AND ip_reseller_fk=:r AND $famcond
                ORDER BY INET6_ATON(ip_address_vc)");
            $st->execute([':r' => $owner]);
            $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        }
        $out = [];
        $c = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE $col=:ip AND vh_acc_fk<>:uid AND vh_deleted_ts IS NULL");
        foreach ($rows as $ip) {
            $c->execute([':ip' => $ip, ':uid' => $uid]);
            if ((int)$c->fetchColumn() === 0) { $out[] = $ip; }
        }
        return $out;
    }

    /** Actualiza los registros A web (@ y www) del dominio a la IP efectiva y marca rebuild de zona. */
    private static function syncDomainDnsIP($vhostid, $ip) {
        global $zdbh;
        $zdbh->prepare("UPDATE x_dns SET dn_target_vc=:ip
            WHERE dn_vhost_fk=:vid AND dn_type_vc='A' AND dn_host_vc IN ('@','www') AND dn_deleted_ts IS NULL")
            ->execute([':ip' => $ip, ':vid' => $vhostid]);
        $row = $zdbh->query("SELECT so_value_tx FROM x_settings WHERE so_name_vc='dns_hasupdates'")->fetch();
        $ids = array_filter(explode(',', (string)($row['so_value_tx'] ?? '')), 'strlen');
        if (!in_array((string)$vhostid, $ids, true)) { $ids[] = (string)$vhostid; }
        $zdbh->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='dns_hasupdates'")
            ->execute([':v' => implode(',', $ids)]);
    }

    /** Sincroniza los SUBDOMINIOS (vh_type_in=2) de un dominio con las IP dedicadas ACTUALES del
     *  padre (family-agnostic): cada subdominio hereda vh_custom_ip_vc y vh_custom_ip6_vc del padre
     *  (vhost doble pila en Apache) y sus registros A/AAAA se escriben como ETIQUETA dentro de la
     *  ZONA DEL PADRE (dn_vhost_fk=padre, dn_host_vc=<label>). El A siempre existe apuntando a la
     *  IPv4 efectiva del padre (su dedicada o server_ip) para que el subdominio resuelva; la AAAA
     *  existe solo si el padre tiene IPv6 dedicada, y se borra si la pierde. El alias de red ya lo
     *  gestiona el padre (misma IP compartida), así que aquí no se toca la red. */
    private static function propagateIPToSubdomains($parentVhostId, $parentName) {
        global $zdbh;
        $pr = $zdbh->prepare("SELECT vh_custom_ip_vc, vh_custom_ip6_vc FROM x_vhosts WHERE vh_id_pk=:id");
        $pr->execute([':id' => $parentVhostId]);
        $p = $pr->fetch(PDO::FETCH_ASSOC) ?: [];
        $pv4  = trim((string)($p['vh_custom_ip_vc'] ?? ''));
        $pv6  = trim((string)($p['vh_custom_ip6_vc'] ?? ''));
        $eff4 = ($pv4 === '') ? (string)ctrl_options::GetOption('server_ip') : $pv4;

        $q = $zdbh->prepare("SELECT vh_id_pk, vh_name_vc FROM x_vhosts
                             WHERE vh_type_in=2 AND vh_deleted_ts IS NULL AND vh_name_vc LIKE :pat");
        $q->execute([':pat' => '%.' . $parentName]);
        $subs = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$subs) return;
        $acc = (int)$zdbh->query("SELECT dn_acc_fk FROM x_dns WHERE dn_vhost_fk=" . (int)$parentVhostId . " LIMIT 1")->fetchColumn();

        foreach ($subs as $s) {
            $subid = (int)$s['vh_id_pk'];
            $full  = (string)$s['vh_name_vc'];
            if (substr($full, -(strlen($parentName) + 1)) !== '.' . $parentName) continue;
            $label = substr($full, 0, strlen($full) - strlen($parentName) - 1);
            if ($label === '' || $label === false) continue;
            // el vhost del subdominio hereda ambas familias del padre (doble pila en Apache)
            $zdbh->prepare("UPDATE x_vhosts SET vh_custom_ip_vc=:v4, vh_custom_ip6_vc=:v6 WHERE vh_id_pk=:id")
                 ->execute([':v4' => ($pv4 === '' ? null : $pv4), ':v6' => ($pv6 === '' ? null : $pv6), ':id' => $subid]);
            // A de la etiqueta -> IPv4 efectiva (siempre resuelve)
            self::upsertZoneLabel($parentVhostId, $parentName, $acc, $label, 'A', $eff4);
            // AAAA de la etiqueta -> IPv6 dedicada del padre, o borrar si no tiene
            if ($pv6 !== '') {
                self::upsertZoneLabel($parentVhostId, $parentName, $acc, $label, 'AAAA', $pv6);
            } else {
                $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=UNIX_TIMESTAMP()
                    WHERE dn_vhost_fk=:v AND dn_type_vc='AAAA' AND dn_host_vc=:h AND dn_deleted_ts IS NULL")
                    ->execute([':v' => $parentVhostId, ':h' => $label]);
            }
        }
        // marcar rebuild de la zona del padre
        $row = $zdbh->query("SELECT so_value_tx FROM x_settings WHERE so_name_vc='dns_hasupdates'")->fetch();
        $ids = array_filter(explode(',', (string)($row['so_value_tx'] ?? '')), 'strlen');
        if (!in_array((string)$parentVhostId, $ids, true)) { $ids[] = (string)$parentVhostId; }
        $zdbh->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='dns_hasupdates'")->execute([':v' => implode(',', $ids)]);
    }

    /** Upsert de un registro A/AAAA de etiqueta ($host) dentro de la zona del vhost padre. */
    private static function upsertZoneLabel($parentVhostId, $parentName, $acc, $host, $type, $target) {
        global $zdbh;
        $ex = $zdbh->prepare("SELECT dn_id_pk FROM x_dns WHERE dn_vhost_fk=:v AND dn_type_vc=:t AND dn_host_vc=:h AND dn_deleted_ts IS NULL LIMIT 1");
        $ex->execute([':v' => $parentVhostId, ':t' => $type, ':h' => $host]);
        $id = $ex->fetchColumn();
        if ($id) {
            $zdbh->prepare("UPDATE x_dns SET dn_target_vc=:ip WHERE dn_id_pk=:id")->execute([':ip' => $target, ':id' => $id]);
        } else {
            $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,dn_name_vc,dn_vhost_fk,dn_type_vc,dn_host_vc,dn_ttl_in,dn_target_vc,dn_priority_in,dn_created_ts)
                            VALUES (:a,:n,:v,:t,:h,3600,:ip,0,UNIX_TIMESTAMP())")
                 ->execute([':a' => $acc, ':n' => $parentName, ':v' => $parentVhostId, ':t' => $type, ':h' => $host, ':ip' => $target]);
        }
    }

    static function ExecuteAssignDomainIP($vhostid, $uid, $ipchoice) {
        global $zdbh;
        if (!class_exists('privilege')) { require_once '/usr/local/bulwark/dryden/sys/privilege.class.php'; }

        $chk = $zdbh->prepare("SELECT vh_name_vc, vh_custom_ip_vc FROM x_vhosts
                                WHERE vh_id_pk=:id AND vh_acc_fk=:uid AND vh_deleted_ts IS NULL");
        $chk->execute([':id' => $vhostid, ':uid' => $uid]);
        $vh = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$vh) { $_SESSION['domains_ip_flash'] = ['err', 'Dominio no válido.']; return false; }

        $domain  = $vh['vh_name_vc'];
        $current = trim((string)$vh['vh_custom_ip_vc']);
        $choice  = trim((string)$ipchoice);

        if ($choice === '' || $choice === '__shared__') {
            $newip = '';
        } else {
            if (!filter_var($choice, FILTER_VALIDATE_IP)) { $_SESSION['domains_ip_flash'] = ['err', 'IP no válida.']; return false; }
            if ($choice !== $current && !in_array($choice, self::assignableIPsForUser($uid), true)) {
                $_SESSION['domains_ip_flash'] = ['err', 'Esa IP no está disponible para tu cuenta.']; return false;
            }
            // cuota: solo si es una IP NUEVA para el usuario
            $quota   = self::userIpQuota($uid);
            $userIPs = self::userDedicatedIPs($uid);
            if (!in_array($choice, $userIPs, true) && $quota !== -1 && (count($userIPs) + 1) > $quota) {
                $_SESSION['domains_ip_flash'] = ['err', 'Has alcanzado el límite de IPs dedicadas de tu paquete (' . $quota . ').'];
                return false;
            }
            $newip = $choice;
        }

        // BD: fijar (o limpiar) la IP del vhost
        $zdbh->prepare("UPDATE x_vhosts SET vh_custom_ip_vc=:ip WHERE vh_id_pk=:id")
             ->execute([':ip' => ($newip === '' ? null : $newip), ':id' => $vhostid]);

        // SO: asegurar alias de la nueva IP dedicada (IPv4)
        if ($newip !== '' && filter_var($newip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            try { privilege::run('ip_alias_add', array($newip)); } catch (Exception $e) {}
        }
        // SO: si la IP anterior ya no la usa NADIE (y no es primaria), quitar su alias
        if ($current !== '' && $current !== $newip && filter_var($current, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $u = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_custom_ip_vc=:ip AND vh_deleted_ts IS NULL");
            $u->execute([':ip' => $current]);
            $pr = $zdbh->prepare("SELECT COUNT(*) FROM x_ips WHERE ip_address_vc=:ip AND ip_is_primary_in=1");
            $pr->execute([':ip' => $current]);
            if ((int)$u->fetchColumn() === 0 && (int)$pr->fetchColumn() === 0) {
                try { privilege::run('ip_alias_del', array($current)); } catch (Exception $e) {}
            }
        }

        // DNS: registros A del sitio siguen la IP efectiva; Apache: marcar rebuild de vhosts
        $effective = ($newip === '') ? (string)ctrl_options::GetOption('server_ip') : $newip;
        self::syncDomainDnsIP($vhostid, $effective);
        // Herencia: los subdominios de este dominio siguen las IP del padre (A/AAAA en la zona del padre)
        self::propagateIPToSubdomains($vhostid, $domain);
        // El hook de Apache reconstruye los vhosts SOLO si apache_changed == "true" (cadena),
        // no un timestamp — así el <VirtualHost IP:puerto> pasa a atar la IP dedicada.
        ctrl_options::SetSystemOption('apache_changed', 'true');

        // CORREO (Fase 3b): envío saliente desde la IP dedicada + SPF que la autoriza.
        self::syncMailTransport($domain, $vhostid);
        if ($newip !== '')                          { self::syncDomainSpf($vhostid, $newip, true); }
        if ($current !== '' && $current !== $newip) { self::syncDomainSpf($vhostid, $current, false); }
        // Regenerar los transportes de Postfix (master.cf) con 'postfix check' antes de recargar.
        try { privilege::run('mail_ip_sync'); } catch (Exception $e) {}

        $_SESSION['domains_ip_flash'] = ['ok', 'IPv4 del dominio ' . $domain . ' actualizada a '
            . ($newip === '' ? 'compartida (sistema)' : $newip) . ' (sus subdominios la heredan). Los cambios de Apache/DNS se aplican en el próximo ciclo del daemon.'];
        return true;
    }

    /** Asigna (o quita, '__shared__') la IPv6 dedicada de un dominio. IPv6 es abundante -> sin cuota. */
    static function ExecuteAssignDomainIP6($vhostid, $uid, $ipchoice) {
        global $zdbh;
        if (!class_exists('privilege')) { require_once '/usr/local/bulwark/dryden/sys/privilege.class.php'; }

        $chk = $zdbh->prepare("SELECT vh_name_vc, vh_custom_ip6_vc FROM x_vhosts
                                WHERE vh_id_pk=:id AND vh_acc_fk=:uid AND vh_deleted_ts IS NULL");
        $chk->execute([':id' => $vhostid, ':uid' => $uid]);
        $vh = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$vh) { $_SESSION['domains_ip_flash'] = ['err', 'Dominio no válido.']; return false; }

        $domain  = $vh['vh_name_vc'];
        $current = trim((string)$vh['vh_custom_ip6_vc']);
        $choice  = trim((string)$ipchoice);

        if ($choice === '' || $choice === '__shared__') {
            $new6 = '';
        } else {
            if (!filter_var($choice, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { $_SESSION['domains_ip_flash'] = ['err', 'IPv6 no válida.']; return false; }
            if ($choice !== $current && !in_array($choice, self::assignableIPsForUser($uid, '6'), true)) {
                $_SESSION['domains_ip_flash'] = ['err', 'Esa IPv6 no está disponible para tu cuenta.']; return false;
            }
            $new6 = $choice;
        }

        // BD
        $zdbh->prepare("UPDATE x_vhosts SET vh_custom_ip6_vc=:ip WHERE vh_id_pk=:id")
             ->execute([':ip' => ($new6 === '' ? null : $new6), ':id' => $vhostid]);

        // SO: alias inet6 de la nueva; quitar el de la anterior si nadie lo usa
        if ($new6 !== '') { try { privilege::run('ip_alias_add', array($new6)); } catch (Exception $e) {} }
        if ($current !== '' && $current !== $new6) {
            $u = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_custom_ip6_vc=:ip AND vh_deleted_ts IS NULL");
            $u->execute([':ip' => $current]);
            $pr = $zdbh->prepare("SELECT COUNT(*) FROM x_ips WHERE ip_address_vc=:ip AND ip_is_primary_in=1");
            $pr->execute([':ip' => $current]);
            if ((int)$u->fetchColumn() === 0 && (int)$pr->fetchColumn() === 0) {
                try { privilege::run('ip_alias_del', array($current)); } catch (Exception $e) {}
            }
        }

        // DNS: registros AAAA (@ y www) + Apache rebuild
        self::setDomainAAAA($vhostid, $new6);
        // Herencia: los subdominios de este dominio siguen las IP del padre (A/AAAA en la zona del padre)
        self::propagateIPToSubdomains($vhostid, $domain);
        ctrl_options::SetSystemOption('apache_changed', 'true');

        // Correo: transporte por dominio (ata v4+v6) + SPF ip6
        self::syncMailTransport($domain, $vhostid);
        if ($new6 !== '')                           { self::syncDomainSpf($vhostid, $new6, true); }
        if ($current !== '' && $current !== $new6)  { self::syncDomainSpf($vhostid, $current, false); }
        try { privilege::run('mail_ip_sync'); } catch (Exception $e) {}

        $_SESSION['domains_ip_flash'] = ['ok', 'IPv6 del dominio ' . $domain . ' actualizada a '
            . ($new6 === '' ? 'ninguna (solo IPv4)' : $new6) . ' (sus subdominios la heredan). Los cambios se aplican en el próximo ciclo del daemon.'];
        return true;
    }

    /** Crea/actualiza (o elimina si $ip6 vacío) los registros AAAA @ y www del dominio + rebuild de zona. */
    private static function setDomainAAAA($vhostid, $ip6) {
        global $zdbh;
        $acc = (int)$zdbh->query("SELECT dn_acc_fk FROM x_dns WHERE dn_vhost_fk=" . (int)$vhostid . " LIMIT 1")->fetchColumn();
        $name = (string)$zdbh->query("SELECT vh_name_vc FROM x_vhosts WHERE vh_id_pk=" . (int)$vhostid)->fetchColumn();
        if ($ip6 === '') {
            $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=UNIX_TIMESTAMP()
                WHERE dn_vhost_fk=:v AND dn_type_vc='AAAA' AND dn_host_vc IN ('@','www') AND dn_deleted_ts IS NULL")
                ->execute([':v' => $vhostid]);
        } else {
            foreach (['@', 'www'] as $host) {
                $ex = $zdbh->prepare("SELECT dn_id_pk FROM x_dns WHERE dn_vhost_fk=:v AND dn_type_vc='AAAA' AND dn_host_vc=:h AND dn_deleted_ts IS NULL LIMIT 1");
                $ex->execute([':v' => $vhostid, ':h' => $host]);
                $id = $ex->fetchColumn();
                if ($id) {
                    $zdbh->prepare("UPDATE x_dns SET dn_target_vc=:ip WHERE dn_id_pk=:id")->execute([':ip' => $ip6, ':id' => $id]);
                } else {
                    $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,dn_name_vc,dn_vhost_fk,dn_type_vc,dn_host_vc,dn_ttl_in,dn_target_vc,dn_priority_in,dn_created_ts)
                                    VALUES (:a,:n,:v,'AAAA',:h,3600,:ip,0,UNIX_TIMESTAMP())")
                         ->execute([':a' => $acc, ':n' => $name, ':v' => $vhostid, ':h' => $host, ':ip' => $ip6]);
                }
            }
        }
        // marcar rebuild de la zona
        $row = $zdbh->query("SELECT so_value_tx FROM x_settings WHERE so_name_vc='dns_hasupdates'")->fetch();
        $ids = array_filter(explode(',', (string)($row['so_value_tx'] ?? '')), 'strlen');
        if (!in_array((string)$vhostid, $ids, true)) { $ids[] = (string)$vhostid; }
        $zdbh->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='dns_hasupdates'")->execute([':v' => implode(',', $ids)]);
    }

    /** Sincroniza el transporte de ENVÍO del dominio (por dominio: ata su v4 y/o v6). Si el dominio
     *  no tiene ninguna IP dedicada, se elimina el mapeo (usa el transporte por defecto). */
    private static function syncMailTransport($domain, $vhostid) {
        global $zdbh;
        try {
            $q = $zdbh->prepare("SELECT vh_custom_ip_vc, vh_custom_ip6_vc FROM x_vhosts WHERE vh_id_pk=:id");
            $q->execute([':id' => $vhostid]);
            $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
            $has = !fs_director::CheckForEmptyValue($r['vh_custom_ip_vc'] ?? '') || !fs_director::CheckForEmptyValue($r['vh_custom_ip6_vc'] ?? '');
            if (!$has) {
                $zdbh->prepare("DELETE FROM bulwark_postfix.sender_transport WHERE domain=:d")->execute([':d' => $domain]);
            } else {
                $zdbh->prepare("REPLACE INTO bulwark_postfix.sender_transport (domain, transport) VALUES (:d,:t)")
                     ->execute([':d' => $domain, ':t' => 'smtpout-' . (int)$vhostid]);
            }
        } catch (Exception $e) { /* la tabla se crea por migración; no bloquear la asignación */ }
    }

    /** Añade/quita ip4:<ip> o ip6:<ip> en el SPF (TXT @) del dominio para autorizar la IP de envío. */
    private static function syncDomainSpf($vhostid, $ip, $add) {
        global $zdbh;
        $isV6 = (strpos($ip, ':') !== false);
        if (!filter_var($ip, FILTER_VALIDATE_IP, $isV6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4)) return;
        $q = $zdbh->prepare("SELECT dn_id_pk, dn_target_vc FROM x_dns
            WHERE dn_vhost_fk=:v AND dn_type_vc='TXT' AND dn_host_vc='@' AND dn_target_vc LIKE 'v=spf1%'
              AND dn_deleted_ts IS NULL LIMIT 1");
        $q->execute([':v' => $vhostid]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;                                   // sin SPF -> nada que tocar
        $spf   = (string)$row['dn_target_vc'];
        $token = ($isV6 ? 'ip6:' : 'ip4:') . $ip;
        $has   = (bool)preg_match('/(^|\s)' . preg_quote($token, '/') . '(\s|$)/', $spf);
        if ($add && !$has) {
            // insertar antes del mecanismo 'all' final (~all / -all / ?all / all)
            $spf2 = preg_replace('/\s*([~\-\?\+]?all)\s*$/', ' ' . $token . ' $1', $spf, 1);
            $spf  = ($spf2 === $spf) ? ($spf . ' ' . $token) : $spf2;
        } elseif (!$add && $has) {
            $spf = preg_replace('/\s*' . preg_quote($token, '/') . '(?=\s|$)/', '', $spf, 1);
        } else {
            return;                                          // sin cambios
        }
        $spf = trim(preg_replace('/\s+/', ' ', $spf));
        $zdbh->prepare("UPDATE x_dns SET dn_target_vc=:t WHERE dn_id_pk=:id")->execute([':t' => $spf, ':id' => $row['dn_id_pk']]);
    }

    static function doSaveDomainIP() {
        global $controller;
        runtime_csfr::Protect();
        $uid = ctrl_users::GetUserDetail()['userid'];
        $f = $controller->GetAllControllerRequests('FORM');
        $vhostid = (int)($f['inVhostId'] ?? 0);
        // IPv4 primero; si falla, su flash de error ya está puesto.
        if (!self::ExecuteAssignDomainIP($vhostid, $uid, (string)($f['inDomainIP'] ?? ''))) return;
        // IPv6 (si el formulario lo trae).
        if (isset($f['inDomainIP6'])) { self::ExecuteAssignDomainIP6($vhostid, $uid, (string)$f['inDomainIP6']); }
    }

    /** Selector de IP para la vista de asignación de IP del dominio (show=IpSettings). */
    static function getDomainIpSelectorHTML() {
        $s = self::loadIpSettings();
        if (!$s) return '';
        global $zdbh;
        $vhostid = (int)$s['vhost_id'];
        $currentuser = ctrl_users::GetUserDetail();
        $uid = $currentuser['userid'];

        // IPs actuales del vhost (v4 y v6)
        $q = $zdbh->prepare("SELECT vh_custom_ip_vc, vh_custom_ip6_vc FROM x_vhosts WHERE vh_id_pk=:id");
        $q->execute([':id' => $vhostid]);
        $vr = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $current  = trim((string)($vr['vh_custom_ip_vc'] ?? ''));
        $current6 = trim((string)($vr['vh_custom_ip6_vc'] ?? ''));

        // Opciones IPv4 (con cuota)
        $quota   = self::userIpQuota($uid);
        $userIPs = self::userDedicatedIPs($uid);
        $canAddNew = ($quota === -1) || (count($userIPs) < $quota);
        $options = $userIPs;
        if ($canAddNew) { foreach (self::assignableIPsForUser($uid, '4') as $ip) { if (!in_array($ip, $options, true)) $options[] = $ip; } }
        if ($current !== '' && !in_array($current, $options, true)) { $options[] = $current; }
        sort($options);

        // Opciones IPv6 (abundante -> sin cuota)
        $options6 = self::assignableIPsForUser($uid, '6');
        if ($current6 !== '' && !in_array($current6, $options6, true)) { $options6[] = $current6; }
        sort($options6);

        $csrf = self::getCSFR_Tag();
        $h = '';
        if (!empty($_SESSION['domains_ip_flash'])) {
            [$t, $msg] = $_SESSION['domains_ip_flash']; unset($_SESSION['domains_ip_flash']);
            $h .= ui_sysmessage::shout(htmlspecialchars($msg, ENT_QUOTES), $t === 'ok' ? 'zannounceok' : 'zannounceerror');
        }

        $mkSelect = function($name, $cur, $opts, $sharedLabel) {
            $o = '<select name="' . $name . '" class="form-select form-select-sm" style="max-width:320px;display:inline-block;">'
               . '<option value="__shared__"' . ($cur === '' ? ' selected' : '') . '>' . $sharedLabel . '</option>';
            foreach ($opts as $ip) {
                $sel = ($ip === $cur) ? ' selected' : '';
                $o .= '<option value="' . htmlspecialchars($ip, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($ip, ENT_QUOTES) . ' (dedicada)</option>';
            }
            return $o . '</select>';
        };

        $qtxt = ($quota === -1) ? 'ilimitadas' : (string)$quota;
        $h .= '<form action="./?module=domains&action=SaveDomainIP&show=IpSettings&id=' . $vhostid . '" method="post">' . $csrf
            . '<input type="hidden" name="inVhostId" value="' . $vhostid . '">'
            . '<table class="zform table table-striped">'
            . '<tr><th style="width:160px;">IPv4 del sitio:</th><td>'
            . $mkSelect('inDomainIP', $current, $options, 'Compartida (IP del sistema)') . '</td></tr>'
            . '<tr><th>IPv6 del sitio:</th><td>'
            . $mkSelect('inDomainIP6', $current6, $options6, 'Ninguna (solo IPv4)') . '</td></tr>'
            . '<tr><th></th><td><button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-hdd-network me-1"></i>Guardar IPs</button></td></tr>'
            . '</table>'
            . '<small class="text-muted">IPv4 dedicadas de tu paquete: <strong>' . $qtxt . '</strong> · en uso: ' . count($userIPs)
            . '. La IPv6 es abundante y no consume cuota. "Compartida/Ninguna" no gastan cuota. '
            . 'Se ajustan los registros <strong>A</strong> (IPv4) y <strong>AAAA</strong> (IPv6) del dominio.</small></form>';
        return $h;
    }

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

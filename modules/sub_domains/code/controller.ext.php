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
    static function ListSubDomains($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_deleted_ts IS NULL AND vh_type_in=2 ORDER BY vh_name_vc ASC";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $sql->execute();
            while ($rowdomains = $sql->fetch()) {
                array_push($res, array('subname' => $rowdomains['vh_name_vc'],
                    'subdirectory' => $rowdomains['vh_directory_vc'],
                    'subactive' => $rowdomains['vh_active_in'],
                    'subenabled' => $rowdomains['vh_enabled_in'],
                    'subid' => $rowdomains['vh_id_pk']));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListDomains($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_deleted_ts IS NULL AND vh_type_in=1 ORDER BY vh_name_vc ASC";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $sql->execute();
            while ($rowdomains = $sql->fetch()) {
                array_push($res, array('name' => $rowdomains['vh_name_vc'],
                    'directory' => $rowdomains['vh_directory_vc'],
                    'active' => $rowdomains['vh_active_in'],
                    'id' => $rowdomains['vh_id_pk']));
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

    static function ExecuteDeleteSubDomain($id, $uid)
    {
        global $zdbh;
		// NEW - Delete Snuff files for domain
		$sql2 = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_id_pk=:id AND vh_acc_fk=:uid");
		$sql2->bindParam(':id', $id);
		$sql2->bindParam(':uid', $uid);
    	$sql2->execute();
    	while ($rowvhost = $sql2->fetch()) {

		$vhostuser = ctrl_users::GetUserDetail($rowvhost['vh_acc_fk']);
		$vhostusername = $vhostuser['username'];
		}

		// Delete Domain — AND vh_acc_fk=:uid impide borrar subdominios ajenos
        runtime_hook::Execute('OnBeforeDeleteSubDomain');
        $sql = $zdbh->prepare("UPDATE x_vhosts
							   SET vh_deleted_ts=:time
							   WHERE vh_id_pk=:id AND vh_acc_fk=:uid");
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':id', $id);
        $sql->bindParam(':uid', $uid);
        $sql->execute();
        self::SetWriteApacheConfigTrue();
        $retval = TRUE;
        runtime_hook::Execute('OnAfterDeleteSubDomain');
        return $retval;
    }

    static function ExecuteAddSubDomain($uid, $domain, $destination, $autohome)
    {
        global $zdbh;
        $retval = FALSE;
        runtime_hook::Execute('OnBeforeAddSubDomain');
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
														 2,
														 :time)"); //CLEANER FUNCTION ON $domain and $homedirectory_to_use (Think I got it?)
            $sql->bindParam(':userid', $currentuser['userid']);
            $sql->bindParam(':domain', $domain);
            $sql->bindParam(':destination', $destination);
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->execute();
            self::InheritParentIP((int)$zdbh->lastInsertId(), $domain);
            self::SetWriteApacheConfigTrue();
            $retval = TRUE;
            runtime_hook::Execute('OnAfterAddSubDomain');
            return $retval;
        }
    }

    /** Crea el esqueleto de directorios del vhost por doas como root (h_USERNAME:www). Igual que en
     *  el módulo domains: web/ es 2750 y el panel (www) no puede crear ahí. Idempotente. */
    private static function provisionVhostDirs($username, $vhdir) {
        if (!class_exists('privilege')) { require_once '/usr/local/bulwark/dryden/sys/privilege.class.php'; }
        $req = '/var/bulwark/run/vhost_diradd_req';
        if (@file_put_contents($req, $username . '|' . $vhdir) === false) {
            error_log("sub_domains: no se pudo escribir $req");
            return false;
        }
        @chmod($req, 0660);
        try { privilege::run('vhost_dir_add'); return true; }
        catch (\Throwable $e) { error_log("sub_domains vhost_dir_add '$username|$vhdir': " . $e->getMessage()); return false; }
    }

    /** Al crear un subdominio, hereda las IP dedicadas de su dominio padre (doble pila en el vhost)
     *  y crea su registro A (siempre, para que resuelva) y AAAA (si el padre tiene IPv6) como ETIQUETA
     *  dentro de la ZONA DEL PADRE (dn_vhost_fk=padre, dn_host_vc=<label>). El alias de red ya lo tiene
     *  el padre (misma IP compartida), así que aquí no se toca la red. */
    private static function InheritParentIP($subVhostId, $subFullName)
    {
        global $zdbh;
        if ($subVhostId <= 0 || strpos($subFullName, '.') === false) return;
        $label      = substr($subFullName, 0, strpos($subFullName, '.'));       // casa.dominio.tld -> casa
        $parentName = substr($subFullName, strpos($subFullName, '.') + 1);      // -> dominio.tld
        $p = $zdbh->prepare("SELECT vh_id_pk, vh_custom_ip_vc, vh_custom_ip6_vc FROM x_vhosts
                             WHERE vh_name_vc=:n AND vh_type_in=1 AND vh_deleted_ts IS NULL LIMIT 1");
        $p->execute([':n' => $parentName]);
        $parent = $p->fetch(PDO::FETCH_ASSOC);
        if (!$parent) return;
        $pv4 = trim((string)$parent['vh_custom_ip_vc']);
        $pv6 = trim((string)$parent['vh_custom_ip6_vc']);
        // Heredar la IP dedicada del padre en el vhost del subdominio (si la tiene)
        if ($pv4 !== '' || $pv6 !== '') {
            $zdbh->prepare("UPDATE x_vhosts SET vh_custom_ip_vc=:v4, vh_custom_ip6_vc=:v6 WHERE vh_id_pk=:id")
                 ->execute([':v4' => ($pv4 === '' ? null : $pv4), ':v6' => ($pv6 === '' ? null : $pv6), ':id' => $subVhostId]);
        }
        // Registros de la etiqueta en la zona del padre (solo si el padre ya tiene zona DNS)
        $acc = (int)$zdbh->query("SELECT dn_acc_fk FROM x_dns WHERE dn_vhost_fk=" . (int)$parent['vh_id_pk'] . " LIMIT 1")->fetchColumn();
        if (!$acc) return;
        $pid = (int)$parent['vh_id_pk'];
        $a4  = ($pv4 !== '') ? $pv4 : (string)ctrl_options::GetOption('server_ip');
        self::UpsertSubLabel($pid, $parentName, $acc, $label, 'A', $a4);
        if ($pv6 !== '') self::UpsertSubLabel($pid, $parentName, $acc, $label, 'AAAA', $pv6);
        // Marcar rebuild de la zona del padre
        $row = $zdbh->query("SELECT so_value_tx FROM x_settings WHERE so_name_vc='dns_hasupdates'")->fetch();
        $ids = array_filter(explode(',', (string)($row['so_value_tx'] ?? '')), 'strlen');
        if (!in_array((string)$pid, $ids, true)) { $ids[] = (string)$pid; }
        $zdbh->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='dns_hasupdates'")->execute([':v' => implode(',', $ids)]);
    }

    /** Upsert de un registro A/AAAA de etiqueta ($host) dentro de la zona del vhost padre. */
    private static function UpsertSubLabel($parentVhostId, $parentName, $acc, $host, $type, $target)
    {
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

    /**
     * End 'worker' methods.
     */

    /**
     * Webinterface sudo methods.
     */
    static function getSubDomainList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $res = array();
        $subdomains = self::ListSubDomains($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($subdomains)) {
            foreach ($subdomains as $row) {
                $status = self::getSubDomainStatusHTML($row['subactive'], $row['subenabled'], $row['subid']);
                $res[] = array('subname' => $row['subname'],
                    'subdirectory' => $row['subdirectory'],
                    'subactive' => $row['subactive'],
                    'substatus' => $status,
                    'subid' => $row['subid']);
            }
            return $res;
        } else {
            return false;
        }
    }

    static function getDomainList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $domains = self::ListDomains($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($domains)) {
            return $domains;
        } else {
            return false;
        }
    }

    static function getCreateSubDomain()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ($currentuser['subdomainquota'] < 0) or //-1 = unlimited
                ($currentuser['subdomainquota'] > ctrl_users::GetQuotaUsages('subdomains', $currentuser['userid']));
    }

    static function getSubDomainDirsList()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $domaindirectories = self::ListDomainDirs($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($domaindirectories)) {
            return $domaindirectories;
        } else {
            return false;
        }
    }

    static function doCreateSubDomain()
    {
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');

        // Verificar que el dominio padre pertenece al usuario autenticado
        $parentDomain = strtolower(trim($formvars['inDomain']));
        $ownerCheck = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_name_vc=:domain AND vh_acc_fk=:uid AND vh_deleted_ts IS NULL AND vh_type_in IN (1, 3)");
        $ownerCheck->bindParam(':domain', $parentDomain);
        $ownerCheck->bindParam(':uid', $currentuser['userid']);
        $ownerCheck->execute();
        if ($ownerCheck->fetchColumn() == 0) {
            self::$error = TRUE;
            return false;
        }

        if (self::ExecuteAddSubDomain($currentuser['userid'], $formvars['inSub'] . "." . $formvars['inDomain'], $formvars['inDestination'], $formvars['inAutoHome'])) {
            self::$ok = TRUE;
            return true;
        } else {
            return false;
        }
        return;
    }

    static function doDeleteSubDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inDelete'])) {
            if (self::ExecuteDeleteSubDomain($formvars['inDelete'], $currentuser['userid'])) {
                self::$ok = TRUE;
                return true;
            }
        }
        return false;
    }

    static function doConfirmDeleteSubDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListSubDomains($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['subid'] . ''])) {
                header('location: ./?module=' . $controller->GetCurrentModule() . '&show=Delete&id=' . $row['subid'] . '&domain=' . $row['subname']);
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

        // Verify if Current user can Delete Sub_Domains.
        // This shall avoid exposing Sub_Domains based on ID lookups.
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
        // Se refleja en value="..." de la plantilla; escapar para evitar XSS reflejado.
        $id = $controller->GetControllerRequest('URL', 'id');
        return ($id) ? htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') : '';
    }

    static function getCurrentDomain()
    {
        global $controller;
        $domain = $controller->GetControllerRequest('URL', 'domain');
        return ($domain) ? htmlspecialchars((string)$domain, ENT_QUOTES, 'UTF-8') : '';
    }

    static function getSubDomainUsagepChart()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $maximum = $currentuser['subdomainquota'];
        if ($maximum < 0) { //-1 = unlimited
            return '<img src="' . ui_tpl_assetfolderpath::Template() . 'img/misc/unlimited.png" alt="' . ui_language::translate('Unlimited') . '"/>';
        } else {
            $used = ctrl_users::GetQuotaUsages('subdomains', $currentuser['userid']);
            $free = max($maximum - $used, 0);
            return '<img src="etc/lib/charts/svg_pie.php?score=' . $free . '::' . $used
                    . '&labels=Free:_' . $free . '::Used:_' . $used . '&imagesize=320::200"'
                    . ' alt="' . ui_language::translate('Pie chart') . '"/>';
        }
    }

    static function getSubDomainStatusHTML($active, $enabled, $id)
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

        // Toggle (suspender/activar) + PHP. Cada subdominio es un vhost con su propio pool FPM.
        $actionsTd = '<td style="white-space:nowrap">'
            . '<button class="button-loader btn btn-sm ' . $toggleClass . '" type="submit"'
            . ' name="inToggle_' . (int)$id . '" value="' . (int)$id . '"'
            . ' formaction="./?module=' . htmlspecialchars($mod, ENT_QUOTES) . '&action=ToggleSubDomain">'
            . $toggleLabel . '</button> '
            . '<a href="./?module=' . htmlspecialchars($mod, ENT_QUOTES) . '&show=PhpSettings&id=' . (int)$id . '"'
            . ' class="btn btn-info btn-sm">PHP</a>'
            . '</td>';

        return $statusTd . $actionsTd;
    }

    static function ExecuteToggleSubDomain($vhostid, $uid)
    {
        global $zdbh;
        // Anti-IDOR: debe ser un subdominio (vh_type_in=2) del usuario autenticado.
        $sql = $zdbh->prepare("SELECT vh_enabled_in FROM x_vhosts
                                WHERE vh_id_pk=:id AND vh_acc_fk=:uid AND vh_type_in=2 AND vh_deleted_ts IS NULL");
        $sql->execute([':id' => $vhostid, ':uid' => $uid]);
        $row = $sql->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $newState = ((int)$row['vh_enabled_in'] === 1) ? 0 : 1;
        $upd = $zdbh->prepare("UPDATE x_vhosts SET vh_enabled_in=:state WHERE vh_id_pk=:id");
        $upd->execute([':state' => $newState, ':id' => $vhostid]);
        self::SetWriteApacheConfigTrue();
        return true;
    }

    static function doToggleSubDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        $subs = self::ListSubDomains($currentuser['userid']);
        if ($subs) {
            foreach ($subs as $row) {
                if (isset($formvars['inToggle_' . $row['subid']])) {
                    self::ExecuteToggleSubDomain($row['subid'], $currentuser['userid']);
                    self::$ok = true;
                    return true;
                }
            }
        }
        return false;
    }

    // -----------------------------------------------------------------------
    // Ajustes PHP por subdominio (x_domain_php + pools FPM). Portado de domains:
    // los subdominios son vhosts (vh_type_in=2) y fpm_pool_manager ya aplica x_domain_php.
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
        // Debe ser un SUBDOMINIO (vh_type_in=2) del usuario autenticado (anti-IDOR).
        $chk = $zdbh->prepare("SELECT vh_name_vc FROM x_vhosts
                                WHERE vh_id_pk=:id AND vh_acc_fk=:uid AND vh_type_in=2 AND vh_deleted_ts IS NULL");
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

    private static function phpVersionLabel($v)
    {
        if ($v === '' || $v === null) return 'Versión del sistema (por defecto)';
        return 'PHP ' . substr($v, 0, 1) . '.' . substr($v, 1);
    }

    static function getPhpVersionOptions()
    {
        if (!class_exists('fpm_pool_manager')) { require_once '/usr/local/bulwark/dryden/sys/fpm_pool_manager.class.php'; }
        $s = self::loadPhpSettings(); $cur = $s ? (string)$s['php_version'] : ''; $out = '';
        foreach (array_keys(fpm_pool_manager::InstalledVersions()) as $v) {
            $sel = ($v === $cur) ? ' selected' : '';
            $out .= '<option value="' . htmlspecialchars($v, ENT_QUOTES) . '"' . $sel . '>'
                 . htmlspecialchars(self::phpVersionLabel($v), ENT_QUOTES) . '</option>';
        }
        return $out;
    }

    static function getHasMultiplePhpVersions()
    {
        if (!class_exists('fpm_pool_manager')) { require_once '/usr/local/bulwark/dryden/sys/fpm_pool_manager.class.php'; }
        return count(fpm_pool_manager::InstalledVersions()) > 1;
    }

    static function getisPhpSettings()      { return self::loadPhpSettings() !== false; }

    /** Vista principal (lista + crear) solo cuando NO se está en ajustes PHP ni borrando. */
    static function getisSubDomainMain()    { return !self::getisPhpSettings() && !self::getisDeleteDomain(); }

    static function getPhpDomainName()       { $s = self::loadPhpSettings(); return $s ? htmlspecialchars($s['domain_name'], ENT_QUOTES) : ''; }
    static function getPhpVhostId()          { $s = self::loadPhpSettings(); return $s ? (int)$s['vhost_id'] : 0; }
    static function getPhpUploadMax()        { $s = self::loadPhpSettings(); return $s ? htmlspecialchars($s['upload_max'], ENT_QUOTES) : '50M'; }
    static function getPhpPostMax()          { $s = self::loadPhpSettings(); return $s ? htmlspecialchars($s['post_max'], ENT_QUOTES) : '50M'; }
    static function getPhpMemoryLimit()      { $s = self::loadPhpSettings(); return $s ? htmlspecialchars($s['memory_limit'], ENT_QUOTES) : '128M'; }
    static function getPhpMaxExec()          { $s = self::loadPhpSettings(); return $s ? (int)$s['max_exec'] : 30; }
    static function getPhpMaxInput()         { $s = self::loadPhpSettings(); return $s ? (int)$s['max_input'] : 60; }
    static function getPhpDisplayErrorsChecked() { $s = self::loadPhpSettings(); return ($s && $s['display_errors']) ? 'checked' : ''; }
    static function getPhpTimezone()        { $s = self::loadPhpSettings(); return $s ? htmlspecialchars((string)$s['timezone'], ENT_QUOTES) : ''; }
    static function getPhpMaxInputVars()    { $s = self::loadPhpSettings(); return $s ? (int)$s['max_input_vars'] : 1000; }
    static function getPhpOpcacheChecked()  { $s = self::loadPhpSettings(); return ($s && $s['opcache']) ? 'checked' : ''; }

    static function doSavePhpSettings()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars    = $controller->GetAllControllerRequests('FORM');
        $vhostid     = (int)($formvars['inVhostId'] ?? 0);
        if (self::ExecuteSavePhpSettings($vhostid, $currentuser['userid'], $formvars)) {
            self::$ok = true;
            // Diferir el reload de FPM a después de responder (evita 503 por execvp del worker).
            register_shutdown_function(function() {
                if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
                if (!class_exists('privilege')) { require_once '/usr/local/bulwark/dryden/sys/privilege.class.php'; }
                try { privilege::run('fpm_regenerate'); }
                catch (\Throwable $e) { error_log('sub_domains: fpm_regenerate (shutdown) failed: ' . $e->getMessage()); }
            });
            return true;
        }
        return false;
    }

    static function ExecuteSavePhpSettings($vhostid, $uid, $formvars)
    {
        global $zdbh;
        // Anti-IDOR: el vhost debe ser un subdominio del usuario.
        $chk = $zdbh->prepare("SELECT vh_id_pk FROM x_vhosts
                                WHERE vh_id_pk=:id AND vh_acc_fk=:uid AND vh_type_in=2 AND vh_deleted_ts IS NULL");
        $chk->execute([':id' => $vhostid, ':uid' => $uid]);
        if (!$chk->fetch()) return false;

        // Límites del paquete del propietario (cap de los valores).
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

        if (!class_exists('fpm_pool_manager')) { require_once '/usr/local/bulwark/dryden/sys/fpm_pool_manager.class.php'; }
        $php_version = (string)($formvars['inPhpVersion'] ?? '');
        if (!array_key_exists($php_version, fpm_pool_manager::InstalledVersions())) { $php_version = ''; }

        $timezone = (string)($formvars['inTimezone'] ?? '');
        if ($timezone !== '' && !in_array($timezone, timezone_identifiers_list(), true)) { $timezone = ''; }
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
                dp_php_version_vc=:ver, dp_timezone_vc=:tz, dp_max_input_vars_in=:miv, dp_opcache_in=:opc");
        $upd->execute([
            ':vid' => $vhostid, ':umax' => $upload_max, ':pmax' => $post_max, ':mem' => $memory,
            ':exec' => $max_exec, ':input' => $max_input, ':err' => $display_err, ':ver' => $php_version,
            ':tz' => $timezone, ':miv' => $max_input_vars, ':opc' => $opcache,
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
        return (self::parsePhpSize($domain_val) <= self::parsePhpSize($pkg_val)) ? $domain_val : $pkg_val;
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

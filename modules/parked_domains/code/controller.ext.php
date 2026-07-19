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
    static function ListParkedDomains($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_deleted_ts IS NULL AND vh_type_in=3 ORDER BY vh_name_vc ASC";
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
                    'created' => $rowdomains['vh_created_ts'],
                    'id' => $rowdomains['vh_id_pk']));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListCurrentDomain($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:uid AND vh_deleted_ts IS NULL";
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
                    'created' => $rowdomains['vh_created_ts'],
                    'id' => $rowdomains['vh_id_pk']));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ExecuteDeleteParkedDomain($id, $uid)
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

		// Delete Domain — AND vh_acc_fk=:uid impide borrar dominios aparcados ajenos
        runtime_hook::Execute('OnBeforeDeleteParkedDomain');
        $sql = $zdbh->prepare("UPDATE x_vhosts
							   SET vh_deleted_ts=:time
							   WHERE vh_id_pk=:id AND vh_acc_fk=:uid");
        $sql->bindParam(':id', $id);
        $sql->bindParam(':uid', $uid);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        self::SetWriteApacheConfigTrue();
        $retval = TRUE;
        runtime_hook::Execute('OnAfterDeleteParkedDomain');
        return $retval;
    }

    static function ExecuteAddParkedDomain($uid, $domain)
    {
        global $zdbh;
        $retval = FALSE;
        runtime_hook::Execute('OnBeforeAddParkedDomain');
        $currentuser = ctrl_users::GetUserDetail($uid);
        $domain = strtolower(str_replace(' ', '', $domain));
        if (!fs_director::CheckForEmptyValue(self::CheckCreateForErrors($domain))) {
            // If all has gone well we need to now create the domain in the database...
            $sql = $zdbh->prepare("INSERT INTO x_vhosts (vh_acc_fk,
														 vh_name_vc,
														 vh_directory_vc,
														 vh_type_in,
														 vh_created_ts) VALUES (
														 :userid,
														 :domain,
														 '',
														 3,
														 :time)");
            $sql->bindParam(':userid', $currentuser['userid']);
            $sql->bindParam(':domain', $domain);
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->execute();
            self::SetWriteApacheConfigTrue();
            $retval = TRUE;
            runtime_hook::Execute('OnAfterAddParkedDomain');
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
                    $sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_name_vc LIKE :Checked AND vh_type_in !=2 AND vh_deleted_ts IS NULL");
                    $Checked = '%' . $check . '%';
                    $sql->bindParam(':Checked', $Checked);
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

    /**
     * End 'worker' methods.
     */

    /**
     * Webinterface sudo methods.
     */
    static function getParkedDomainList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $res = array();
        $parkeddomains = self::ListParkedDomains($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($parkeddomains)) {
            foreach ($parkeddomains as $row) {
                $status = self::getParkedDomainStatusHTML($row['active'], $row['id']);
                $created = date(ctrl_Options::GetSystemOption('bulwark_df'), $row['created']);
                $res[] = array('name' => $row['name'],
                    'directory' => $row['directory'],
                    'active' => $row['active'],
                    'status' => $status,
                    'created' => $created,
                    'id' => $row['id']);
            }
            return $res;
        } else {
            return false;
        }
    }

    static function getCreateParkedDomain()
    {
        $currentuser = ctrl_users::GetUserDetail();
        return ($currentuser['parkeddomainquota'] < 0) or //-1 = unlimited
                ($currentuser['parkeddomainquota'] > ctrl_users::GetQuotaUsages('parkeddomains', $currentuser['userid']));
    }

    static function doCreateParkedDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteAddParkedDomain($currentuser['userid'], $formvars['inDomain'])) {
            self::$ok = TRUE;
            return true;
        } else {
            return false;
        }
        return;
    }

    static function doDeleteParkedDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inDelete'])) {
            if (self::ExecuteDeleteParkedDomain($formvars['inDelete'], $currentuser['userid'])) {
                self::$ok = TRUE;
                return true;
            }
        }
        return false;
    }

    static function doConfirmDeleteParkedDomain()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListParkedDomains($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['id'] . ''])) {
                header('location: ./?module=' . $controller->GetCurrentModule() . '&show=Delete&id=' . $row['id'] . '&domain=' . $row['name']);
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

        // Verify if Current user can Delete Parked Domains.
        // This shall avoid exposing Parked Domains based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

    	$sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_id_pk=:editedUsrID AND vh_deleted_ts IS NULL";
    	$numrows = $zdbh->prepare($sql);
    	$numrows->bindParam(':userid', $currentuser['userid']);
		$numrows->bindParam(':editedUsrID', $urlvars['id']);
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

    static function getParkedDomainUsagepChart()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $maximum = $currentuser['parkeddomainquota'];
        if ($maximum < 0) { //-1 = unlimited
            return '<img src="' . ui_tpl_assetfolderpath::Template() . 'img/misc/unlimited.png" alt="' . ui_language::translate('Unlimited') . '"/>';
        } else {
            $used = ctrl_users::GetQuotaUsages('parkeddomains', $currentuser['userid']);
            $free = max($maximum - $used, 0);
            return '<img src="etc/lib/charts/svg_pie.php?score=' . $free . '::' . $used
                    . '&labels=Free:_' . $free . '::Used:_' . $used . '&imagesize=320::200"'
                    . ' alt="' . ui_language::translate('Pie chart') . '"/>';
        }
    }

    static function getParkedDomainStatusHTML($int, $id)
    {
        global $controller;
        if ($int == 1) {
            return '<td><font color="green">' . ui_language::translate('Live') . '</font></td>'
                    . '<td></td>';
        } else {
            return '<td><font color="orange">' . ui_language::translate("Pending") . '</font></td>'
                    . '<td><a href="#" class="help_small" id="help_small_' . $id . '_a"'
                    . 'title="' . ui_language::translate('Your domain will become active at the next scheduled update.  This can take up to one hour.') . '">'
                    . '<img src="/modules/' . $controller->GetControllerRequest('URL', 'module') . '/assets/help_small.png" border="0" /></a></td>';
        }
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
            return ui_sysmessage::shout(ui_language::translate("The domain already appears to exsist on this server."), "zannounceerror");
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

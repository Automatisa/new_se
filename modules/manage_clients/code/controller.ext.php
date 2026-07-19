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
    static $alreadyexists;
    static $badname;
	static $badpass;
    static $bademail;
    static $badpasswordlength;
    static $userblank;
    static $emailblank;
    static $passwordblank;
    static $packageblank;
    static $groupblank;
    static $ok;
    static $edit;
    static $clientid;
    static $clientpkgid;
    static $resetform;
    static $not_unique_email;
    static $poolexceeded;

    /**
     * The 'worker' methods.
     */
    static function ListClients($uid = 0)
    {
        global $zdbh;
        if ($uid == 0) {
            $sql = "SELECT * FROM x_accounts WHERE ac_enabled_in=1 AND ac_deleted_ts IS NULL ORDER BY ac_user_vc";
            $numrows = $zdbh->prepare($sql);
            $numrows->execute();
        } else {
            $sql = "SELECT * FROM x_accounts WHERE ac_reseller_fk=:uid AND ac_enabled_in=1 AND ac_deleted_ts IS NULL ORDER BY ac_user_vc";
            $numrows = $zdbh->prepare($sql);
            $numrows->bindParam(':uid', $uid);
            $numrows->execute();
        }

        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            if ($uid == 0) {
                //do not bind as there is no need
            } else {
                //else we bind the pram to the sql statment
                $sql->bindParam(':uid', $uid);
            }
            $res = array();
            $sql->execute();
            while ($rowclients = $sql->fetch()) {
                if ($rowclients['ac_user_vc'] != "zadmin") {
                    //$numrowclients = $zdbh->query("SELECT COUNT(*) FROM x_accounts WHERE ac_reseller_fk=" . $rowclients['ac_id_pk'] . " AND ac_deleted_ts IS NULL")->fetch();
                    $numrows = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_reseller_fk=:ac_id_pk AND ac_deleted_ts IS NULL");
                    $numrows->bindParam(':ac_id_pk', $rowclients['ac_id_pk']);
                    $numrows->execute();
                    $numrowclients = $numrows->fetch();

                    $currentuser = ctrl_users::GetUserDetail($rowclients['ac_id_pk']);
                    $currentuser['created'] = date("m/d/Y", $rowclients['ac_created_ts']);
                    $currentuser['diskspacereadable'] = fs_director::ShowHumanFileSize(ctrl_users::GetQuotaUsages('diskspace', $currentuser['userid']));
                    $currentuser['diskspacequotareadable'] = fs_director::ShowHumanFileSize($currentuser['diskquota']);
                    $currentuser['bandwidthreadable'] = fs_director::ShowHumanFileSize(ctrl_users::GetQuotaUsages('bandwidth', $currentuser['userid']));
                    $currentuser['bandwidthquotareadable'] = fs_director::ShowHumanFileSize($currentuser['bandwidthquota']);
                    $currentuser['numclients'] = $numrowclients[0];
                    array_push($res, $currentuser);
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListAllClients($moveid, $uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_accounts WHERE ac_reseller_fk=:uid AND ac_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $skipclients = array();
            $sql->execute();
            while ($rowclients = $sql->fetch()) {
                //$getgroup = $zdbh->query("SELECT * FROM x_groups WHERE ug_id_pk=" . $rowclients['ac_group_fk'] . "")->fetch();
                $numrows = $zdbh->prepare("SELECT * FROM x_groups WHERE ug_id_pk=:ac_group_fk");
                $numrows->bindParam(':ac_group_fk', $rowclients['ac_group_fk']);
                $numrows->execute();
                $getgroup = $numrows->fetch();
                if ($rowclients['ac_id_pk'] != $moveid && (int)$rowclients['ac_group_fk'] === ctrl_groups::GROUP_ADMIN ||
                        $rowclients['ac_id_pk'] != $moveid && (int)$rowclients['ac_group_fk'] === ctrl_groups::GROUP_RESELLER) {
                    array_push($res, array('moveclientid' => $rowclients['ac_id_pk'],
                        'moveclientname' => $rowclients['ac_user_vc']));
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListDisabledClients($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_accounts WHERE ac_reseller_fk=:uid AND ac_enabled_in=0 AND ac_deleted_ts IS NULL";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $sql->execute();
            while ($rowclients = $sql->fetch()) {
                if ($rowclients['ac_user_vc'] != "zadmin") {
                    $currentuser = ctrl_users::GetUserDetail($rowclients['ac_id_pk']);
                    $currentuser['diskspacereadable'] = fs_director::ShowHumanFileSize(ctrl_users::GetQuotaUsages('diskspace', $currentuser['userid']));
                    $currentuser['diskspacequotareadable'] = fs_director::ShowHumanFileSize($currentuser['diskquota']);
                    $currentuser['bandwidthreadable'] = fs_director::ShowHumanFileSize(ctrl_users::GetQuotaUsages('bandwidth', $currentuser['userid']));
                    $currentuser['bandwidthquotareadable'] = fs_director::ShowHumanFileSize($currentuser['bandwidthquota']);
                    array_push($res, $currentuser);
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListCurrentClient($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_profiles WHERE ud_user_fk=:uid";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $sql->execute();
            $currentuser = ctrl_users::GetUserDetail($uid);
            while ($rowclients = $sql->fetch()) {
                array_push($res, array('fullname' => runtime_xss::xssClean(strip_tags($rowclients['ud_fullname_vc'])),
                    'username' => runtime_xss::xssClean(strip_tags($currentuser['username'])),
                    'userid' => runtime_xss::xssClean(strip_tags($currentuser['userid'])),
                    'fullname' => runtime_xss::xssClean(strip_tags($rowclients['ud_fullname_vc'])),
                    'postcode' => runtime_xss::xssClean(strip_tags($rowclients['ud_postcode_vc'])),
                    'address' => runtime_xss::xssClean(strip_tags($rowclients['ud_address_tx'])),
                    'phone' => runtime_xss::xssClean(strip_tags($rowclients['ud_phone_vc'])),
                    'email' => runtime_xss::xssClean(strip_tags($currentuser['email']))));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListGroups($uid)
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail($uid);
        // Los grupos se POSEEN por userid (ug_reseller_fk = id del dueño), igual que exige la
        // validación al guardar (doUpdateClient: ug_reseller_fk=selfid). Antes se filtraba por
        // 'resellerid' (= ac_reseller_fk), que para el admin (zadmin) es 0 -> no coincidía con
        // los grupos por defecto (ug_reseller_fk=1) y el desplegable salía VACÍO. Para resellers
        // también estaba roto: mostraba grupos que el guardado luego rechazaba.
        $ownerid = $currentuser['userid'];
        $sql = "SELECT * FROM x_groups WHERE ug_reseller_fk=:ownerid";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':ownerid', $ownerid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':ownerid', $ownerid);
            $res = array();
            $sql->execute();
            while ($rowgroups = $sql->fetch()) {
                if ((int)$currentuser['usergroupid'] === ctrl_groups::GROUP_ADMIN) {
                    $selected = "";
                    if ($rowgroups['ug_id_pk'] == $currentuser['usergroupid']) {
                        $selected = " selected";
                    }
                    array_push($res, array('groupid' => $rowgroups['ug_id_pk'],
                        'groupname' => runtime_xss::xssClean(ui_language::translate($rowgroups['ug_name_vc'])),
                        'groupselected' => $selected));
                } else {
                    if ((int)$rowgroups['ug_id_pk'] === ctrl_groups::GROUP_USER) {
                        array_push($res, array('groupid' => $rowgroups['ug_id_pk'],
                            'groupname' => runtime_xss::xssClean(ui_language::translate($rowgroups['ug_name_vc'])),
                            'groupselected' => $selected));
                    }
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListCurrentGroups($uid, $rid, $id)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_groups WHERE ug_reseller_fk=:rid";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':rid', $rid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $currentuser = ctrl_users::GetUserDetail($uid);
            $reseller = ctrl_users::GetUserDetail($id);
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':rid', $rid);
            $res = array();
            $sql->execute();
            while ($rowgroups = $sql->fetch()) {
                if ((int)$reseller['usergroupid'] === ctrl_groups::GROUP_ADMIN) {
                    $selected = "";
                    if ($rowgroups['ug_id_pk'] == $currentuser['usergroupid']) {
                        $selected = " selected";
                    }
                    array_push($res, array('groupid' => $rowgroups['ug_id_pk'],
                        'groupname' => ui_language::translate($rowgroups['ug_name_vc']),
                        'groupselected' => $selected));
                } else {
                    if ((int)$rowgroups['ug_id_pk'] === ctrl_groups::GROUP_USER) {
                        $selected = "";
                        if ($rowgroups['ug_id_pk'] == $currentuser['usergroupid']) {
                            $selected = " selected";
                        }
                        array_push($res, array('groupid' => $rowgroups['ug_id_pk'],
                            'groupname' => ui_language::translate($rowgroups['ug_name_vc']),
                            'groupselected' => $selected));
                    }
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListPackages($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_packages WHERE pk_reseller_fk=:uid AND pk_deleted_ts IS NULL";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $sql->execute();
            while ($rowgroups = $sql->fetch()) {
                array_push($res, array('packageid' => $rowgroups['pk_id_pk'],
                    'packagename' => ui_language::translate($rowgroups['pk_name_vc'])));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListCurrentPackages($uid, $rid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_packages WHERE pk_reseller_fk=:rid AND pk_deleted_ts IS NULL";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':rid', $rid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $currentuser = ctrl_users::GetUserDetail($uid);
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':rid', $rid);
            $res = array();
            $sql->execute();
            while ($rowgroups = $sql->fetch()) {
                $selected = "";
                if ($rowgroups['pk_id_pk'] == $currentuser['packageid']) {
                    $selected = " selected";
                }
                array_push($res, array('packageid' => $rowgroups['pk_id_pk'],
                    'packagename' => ui_language::translate($rowgroups['pk_name_vc']),
                    'packageselected' => $selected));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function SetClientAccount($userid, $column, $value)
    {
        global $zdbh;
        runtime_hook::Execute('OnBeforeSetClientAccount');
        $sql = $zdbh->prepare("UPDATE x_accounts
								SET :column=:value
								WHERE ac_id_pk=:userid");
        $sql->bindParam(':column', $column);
        $sql->bindParam(':value', $value);
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        runtime_hook::Execute('OnAfterSetClientAccount');
        return true;
    }

    static function SetClientProfile($userid, $column, $value)
    {
        global $zdbh;
        runtime_hook::Execute('OnBeforeSetClientProfile');
        $sql = $zdbh->prepare("UPDATE x_profiles SET :column=:value WHERE ud_user_fk=:userid");
        $sql->bindParam(':column', $column);
        $sql->bindParam(':value', $value);
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        runtime_hook::Execute('OnAfterSetClientProfile');
        return true;
    }

    static function ExecuteDeleteClient($userid, $moveid)
    {
        global $zdbh;
        runtime_hook::Execute('OnBeforeDeleteClient');
        $sql = $zdbh->prepare("
			UPDATE x_accounts
			SET ac_deleted_ts=:time
			WHERE ac_id_pk=:userid");
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        $sql = $zdbh->prepare("
			UPDATE x_accounts
			SET ac_reseller_fk = :moveid
			WHERE ac_reseller_fk = :userid");
        $sql->bindParam(':moveid', $moveid);
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        $sql = $zdbh->prepare("
			UPDATE x_packages
			SET pk_reseller_fk = :moveid
			WHERE pk_reseller_fk = :userid");
        $sql->bindParam(':moveid', $moveid);
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        $sql = $zdbh->prepare("
			UPDATE x_groups
			SET ug_reseller_fk = :moveid
			WHERE ug_reseller_fk = :userid");
        $sql->bindParam(':moveid', $moveid);
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        runtime_hook::Execute('OnAfterDeleteClient');
        self::$ok = true;
        return true;
    }

    static function ExecuteUpdateClient($clientid, array $client)
    {
        global $zdbh;
        extract($client, EXTR_SKIP);
        runtime_hook::Execute('OnBeforeUpdateClient');
        // AUTZ FIX: (1) el cliente debe pertenecer al reseller/admin actual — no se pueden
        // editar clientes de OTRO reseller; (2) el grupo asignado debe ser suyo (ug_reseller_fk):
        // un reseller NO puede asignar el grupo Administradores -> escalada de privilegios.
        $selfid = (int)ctrl_users::GetUserDetail()['userid'];
        $scopeChk = $zdbh->prepare("SELECT ac_id_pk FROM x_accounts WHERE ac_id_pk=:cid AND ac_reseller_fk=:uid AND ac_deleted_ts IS NULL");
        $scopeChk->execute([':cid' => (int)$clientid, ':uid' => $selfid]);
        if (!$scopeChk->fetch()) { self::$error = true; return false; }
        if (isset($group)) {
            $grpChk = $zdbh->prepare("SELECT COUNT(*) FROM x_groups WHERE ug_id_pk=:gid AND ug_reseller_fk=:uid");
            $grpChk->execute([':gid' => (int)$group, ':uid' => $selfid]);
            if ((int)$grpChk->fetchColumn() === 0) { self::$error = true; return false; }
        }
        // Si el paquete cambia, verificar pool del reseller antes de proceder.
        $old_row_sql = $zdbh->prepare("SELECT ac_package_fk, ac_reseller_fk FROM x_accounts WHERE ac_id_pk=:id AND ac_deleted_ts IS NULL");
        $old_row_sql->execute([':id' => $clientid]);
        $old_row = $old_row_sql->fetch(PDO::FETCH_ASSOC);
        if ($old_row && (int)$old_row['ac_package_fk'] !== (int)$package) {
            $reseller_id = (int)$old_row['ac_reseller_fk'];
            if ($reseller_id > 0) {
                $old_q_sql = $zdbh->prepare("SELECT * FROM x_quotas WHERE qt_package_fk=:pid");
                $old_q_sql->execute([':pid' => $old_row['ac_package_fk']]);
                $old_pkg_q = $old_q_sql->fetch(PDO::FETCH_ASSOC) ?: [];
                $new_q_sql = $zdbh->prepare("SELECT * FROM x_quotas WHERE qt_package_fk=:pid");
                $new_q_sql->execute([':pid' => $package]);
                $new_pkg_q = $new_q_sql->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($new_pkg_q && !ctrl_users::CheckResellerPoolForMove($reseller_id, $old_pkg_q, $new_pkg_q)) {
                    self::$poolexceeded = true;
                    return false;
                }
            }
        }
        if ($newpass != "") {
            // Check for password length...
            if (strlen($newpass) < ctrl_options::GetSystemOption('password_minlength')) {
                self::$badpassword = true;
                return false;
            }
			// Check for invalid password
        	if (!self::IsValidPassword($newpass)) {
            	self::$badpass = true;
            	return false;
        	}
            $crypto = new runtime_hash;
            $crypto->SetPassword($newpass);
            $randomsalt = $crypto->RandomSalt();
            $crypto->SetSalt($randomsalt);
            $secure_password = $crypto->CryptParts($crypto->Crypt())->Hash;

            $sql = $zdbh->prepare("UPDATE x_accounts SET ac_pass_vc= :newpass, ac_passsalt_vc= :passsalt WHERE ac_id_pk= :clientid");
            $sql->bindParam(':clientid', $clientid);
            $sql->bindParam(':newpass', $secure_password);
            $sql->bindParam(':passsalt', $randomsalt);
            $sql->execute();
        }
        // Valores válidos: 1=activo, 2=suspendido, 0=deshabilitado; cualquier otro → activo
        $enabled = in_array((string)$enabled, ['0', '1', '2'], true) ? (int)$enabled : 1;

        // FIX-29: nunca deshabilitar/suspender cuentas admin (grupo 1) ni la cuenta propia
        if ($enabled !== 1) {
            $chk = $zdbh->prepare("SELECT ac_group_fk FROM x_accounts WHERE ac_id_pk=:id");
            $chk->bindParam(':id', $clientid);
            $chk->execute();
            $chkrow = $chk->fetch(PDO::FETCH_ASSOC);
            $selfid = (int)ctrl_users::GetUserDetail()['userid'];
            if (!$chkrow || (int)$chkrow['ac_group_fk'] === 1 || (int)$clientid === $selfid) {
                $enabled = 1; // forzar activo: protección admin/self
            }
        }

        // ac_enabled_in: 1=activo, 0=bloqueado (suspendido o deshabilitado)
        // ac_suspended_in: 1=suspendido, 0=deshabilitado o activo
        $ac_enabled   = ($enabled === 1) ? 1 : 0;
        $ac_suspended = ($enabled === 2) ? 1 : 0;

        $sql = $zdbh->prepare("UPDATE x_accounts SET ac_email_vc= :email, ac_package_fk= :package, ac_enabled_in= :isenabled, ac_suspended_in= :issuspended, ac_group_fk= :group WHERE ac_id_pk = :clientid");
        $sql->bindParam(':email', $email);
        $sql->bindParam(':package', $package);
        $sql->bindParam(':isenabled', $ac_enabled);
        $sql->bindParam(':issuspended', $ac_suspended);
        $sql->bindParam(':group', $group);
        $sql->bindParam(':clientid', $clientid);
        $sql->execute();

        $sql = $zdbh->prepare("UPDATE x_profiles SET ud_fullname_vc= :fullname, ud_group_fk= :group, ud_package_fk= :package, ud_address_tx= :address,ud_postcode_vc= :postcode, ud_phone_vc= :phone WHERE ud_user_fk=:accountid");
        $sql->bindParam(':fullname', $fullname);
        $sql->bindParam(':group', $group);
        $sql->bindParam(':package', $package);
        $sql->bindParam(':address', $address);
        $sql->bindParam(':postcode', $post);
        $sql->bindParam(':phone', $phone);
        $sql->bindParam(':accountid', $clientid);
        $sql->execute();

        if ($enabled === 0) {
            self::DisableClient($clientid);
        } elseif ($enabled === 2) {
            self::SuspendClient($clientid);
        } else {
            self::EnableClient($clientid);
        }
        runtime_hook::Execute('OnAfterUpdateClient');
        self::$ok = true;
        return true;
    }

    static function EnableClient($userid)
    {
        runtime_hook::Execute('OnBeforeEnableClient');
        global $zdbh;
        $sql = $zdbh->prepare("UPDATE x_accounts SET ac_enabled_in=1, ac_suspended_in=0 WHERE ac_id_pk=:userid");
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        runtime_hook::Execute('OnAfterEnableClient');
        return true;
    }

    static function DisableClient($userid)
    {
        runtime_hook::Execute('OnBeforeDisableClient');
        global $zdbh;
        $sql = $zdbh->prepare("UPDATE x_accounts SET ac_enabled_in=0, ac_suspended_in=0 WHERE ac_id_pk=:userid");
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        runtime_hook::Execute('OnAfterDisableClient');
        return true;
    }

    static function SuspendClient($userid)
    {
        runtime_hook::Execute('OnBeforeSuspendClient');
        global $zdbh;
        $sql = $zdbh->prepare("UPDATE x_accounts SET ac_enabled_in=0, ac_suspended_in=1 WHERE ac_id_pk=:userid");
        $sql->bindParam(':userid', $userid);
        $sql->execute();
        runtime_hook::Execute('OnAfterSuspendClient');
        return true;
    }

    static function CheckEnabledHTML($userid)
    {
        global $zdbh;
        $sql = $zdbh->prepare("SELECT ac_enabled_in, ac_suspended_in FROM x_accounts WHERE ac_id_pk=:id AND ac_deleted_ts IS NULL");
        $sql->bindParam(':id', $userid);
        $sql->execute();
        $row = $sql->fetch(PDO::FETCH_ASSOC);

        $echecked = $schecked = $dchecked = '';
        if (!$row || (int)$row['ac_enabled_in'] === 1) {
            $echecked = 'CHECKED';
        } elseif ((int)$row['ac_suspended_in'] === 1) {
            $schecked = 'CHECKED';
        } else {
            $dchecked = 'CHECKED';
        }
        return [['echecked' => $echecked, 'schecked' => $schecked, 'dchecked' => $dchecked]];
    }

    static function CheckHasPackage($userid)
    {
        global $zdbh;
        $sql = "SELECT COUNT(*) FROM x_packages WHERE pk_reseller_fk=:userid AND pk_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $userid);

        if ($numrows->execute()) {
            if ($numrows->fetchColumn() == 0) {
                return false;
            }
        }
        return true;
    }

    static function ExecuteCreateClient($uid, $username, array $client)
    {
        global $zdbh;
        extract($client, EXTR_SKIP);
        // Check for spaces and remove if found...
        $username = strtolower(str_replace(' ', '', $username));
        $reseller = ctrl_users::GetUserDetail($uid);
        // Check for errors before we continue...
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrors($username, $packageid, $groupid, $email, $password))) {
            return false;
        }
        // AUTZ FIX: el grupo debe pertenecer al reseller/admin actual (ug_reseller_fk). Un
        // reseller NO puede crear una cuenta en el grupo Administradores -> escalada de privilegios.
        $grpChk = $zdbh->prepare("SELECT COUNT(*) FROM x_groups WHERE ug_id_pk=:gid AND ug_reseller_fk=:uid");
        $grpChk->execute([':gid' => (int)$groupid, ':uid' => (int)$uid]);
        if ((int)$grpChk->fetchColumn() === 0) { self::$error = true; return false; }
        // Pool check: el reseller no puede comprometer más recursos de los que tiene asignados.
        $pkg_quota_sql = $zdbh->prepare("SELECT q.* FROM x_quotas q JOIN x_packages p ON p.pk_id_pk = q.qt_package_fk WHERE q.qt_package_fk = :pid AND p.pk_deleted_ts IS NULL");
        $pkg_quota_sql->execute([':pid' => $packageid]);
        $pkg_quotas = $pkg_quota_sql->fetch(PDO::FETCH_ASSOC);
        if ($pkg_quotas && !ctrl_users::CheckResellerPoolForPkg($uid, $pkg_quotas, 0, 1)) {
            self::$poolexceeded = true;
            return false;
        }
        runtime_hook::Execute('OnBeforeCreateClient');

        $crypto = new runtime_hash;
        $crypto->SetPassword($password);
        $randomsalt = $crypto->RandomSalt();
        $crypto->SetSalt($randomsalt);
        $secure_password = $crypto->CryptParts($crypto->Crypt())->Hash;

        // No errors found, so we can add the user to the database...
        $sql = $zdbh->prepare("INSERT INTO x_accounts (ac_user_vc, ac_pass_vc, ac_passsalt_vc, ac_email_vc, ac_package_fk, ac_group_fk, ac_usertheme_vc, ac_usercss_vc, ac_reseller_fk, ac_created_ts) VALUES (
            :username, :password, :passsalt, :email, :packageid, :groupid, :resellertheme, :resellercss, :uid, :time)");
        $sql->bindParam(':uid', $uid);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':username', $username);
        $sql->bindParam(':password', $secure_password);
        $sql->bindParam(':passsalt', $randomsalt);
        $sql->bindParam(':email', $email);
        $sql->bindParam(':packageid', $packageid);
        $sql->bindParam(':groupid', $groupid);
        $sql->bindParam(':resellertheme', $reseller['usertheme']);
        $sql->bindParam(':resellercss', $reseller['usercss']);
        $sql->execute();
        // Now lets pull back the client ID so that we can add their personal address details etc...
        //$client = $zdbh->query("SELECT * FROM x_accounts WHERE ac_reseller_fk=" . $uid . " ORDER BY ac_id_pk DESC")->Fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_accounts WHERE ac_reseller_fk=:uid ORDER BY ac_id_pk DESC");
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        $client = $numrows->fetch();

        $sql = $zdbh->prepare("INSERT INTO x_profiles (ud_user_fk, ud_fullname_vc, ud_group_fk, ud_package_fk, ud_address_tx, ud_postcode_vc, ud_phone_vc, ud_created_ts) VALUES (:userid, :fullname, :packageid, :groupid, :address, :postcode, :phone, :time)");
        $sql->bindParam(':userid', $client['ac_id_pk']);
        $sql->bindParam(':fullname', $fullname);
        $sql->bindParam(':packageid', $packageid);
        $sql->bindParam(':groupid', $groupid);
        $sql->bindParam(':address', $address);
        $sql->bindParam(':postcode', $post);
        $sql->bindParam(':phone', $phone);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        // Now we add an entry into the bandwidth table, for the user for the upcoming month.
        $sql = $zdbh->prepare("INSERT INTO x_bandwidth (bd_acc_fk, bd_month_in, bd_transamount_bi, bd_diskamount_bi) VALUES (:ac_id_pk, :date, 0, 0)");
        $date = date("Ym", time());
        $sql->bindParam(':date', $date);
        $sql->bindParam(':ac_id_pk', $client['ac_id_pk']);
        $sql->execute();
        // Lets create the client directories
        $user_home    = ctrl_options::GetSystemOption('hosted_dir') . $username;
        $user_backups = $user_home . '/backups';
        $user_web     = $user_home . '/' . ctrl_options::DOMAINS_SUBDIR; // contenedor de dominios
        fs_director::CreateDirectory($user_home);
        fs_director::SetFileSystemPermissions($user_home, 0755);
        // public_html ya NO se crea aquí: se crea por dominio en modules/domains
        // con la estructura: hosted_dir/username/web/domain_dir/public_html/
        fs_director::CreateDirectory($user_web);
        // 0775: el grupo www necesita escritura para que el panel pueda crear los
        // directorios de cada dominio dentro de web/ (si no, solo el daemon como
        // root crea logs/tmp y faltan public_html/_errorpages/_cgi-bin).
        fs_director::SetFileSystemPermissions($user_web, 0775);
        fs_director::CreateDirectory($user_backups);
        fs_director::SetFileSystemPermissions($user_backups, 0755);
        // Send the user account details via. email (if requested)...
        if ($sendemail <> 0) {
            if (isset($_SERVER['HTTPS'])) {
                $protocol = 'https://';
            } else {
                $protocol = 'http://';
            }
            $port = ctrl_options::GetSystemOption('bulwark_port');
            $domain = ctrl_options::GetSystemOption('bulwark_domain');
            # If using non-standard port
            if ($port !== "80" && $port !== "443" && !empty($port)) {
                # Append port to domain
                $domain .= ":" . $port;
            }
            $emailsubject = str_replace("{{username}}", $username, $emailsubject);
            $emailsubject = str_replace("{{password}}", $password, $emailsubject);
            $emailsubject = str_replace("{{fullname}}", $fullname, $emailsubject);
            $emailbody = str_replace("{{username}}", $username, $emailbody);
            $emailbody = str_replace("{{password}}", $password, $emailbody);
            $emailbody = str_replace("{{fullname}}", $fullname, $emailbody);
            $emailbody = str_replace('{{controlpanelurl}}', $protocol . $domain, $emailbody);

            $phpmailer = new sys_email();
            $phpmailer->Subject = $emailsubject;
            $phpmailer->Body = $emailbody;
            $phpmailer->addAddress($email);
            $phpmailer->SendEmail();
        }
        runtime_hook::Execute('OnAfterCreateClient');
        self::$resetform = true;
        self::$ok = true;
        return true;
    }

    static function CheckCreateForErrors($username, $packageid, $groupid, $email, $password)
    {
        global $zdbh;
        $username = strtolower(str_replace(' ', '', $username));
		// Check to make sure the username is not blank or exists before we go any further...
        if (!fs_director::CheckForEmptyValue($username)) {
            $sql = "SELECT COUNT(*) FROM x_accounts WHERE UPPER(ac_user_vc)=:user AND ac_deleted_ts IS NULL";
            $numrows = $zdbh->prepare($sql);
            $user = strtoupper($username);
            $numrows->bindParam(':user', $user);
            if ($numrows->execute()) {
                if ($numrows->fetchColumn() <> 0) {
                    self::$alreadyexists = true;
                    return false;
                }
            }
            // Check if the OS system user h_USERNAME already exists in /etc/passwd.
            // Prevents creating a hosting account whose sysuser slot is already occupied.
            $sysuser_check = 'h_' . strtolower($username);
            $ph = @fopen('/etc/passwd', 'r');
            if ($ph) {
                while (($pl = fgets($ph)) !== false) {
                    if (strpos($pl, $sysuser_check . ':') === 0) {
                        fclose($ph);
                        self::$alreadyexists = true;
                        return false;
                    }
                }
                fclose($ph);
            }
		// Check to make sure the password is not blank before we go any further...
        if ($password == '') {
            self::$passwordblank = TRUE;
            return false;
        }	
		// Check for password length...
		if (strlen($password) < ctrl_options::GetSystemOption('password_minlength')) {
			self::$badpasswordlength = true;
			return false;
		}
		// Check for invalid password
        if (!self::IsValidPassword($password)) {
            self::$badpass = true;
            return false;
        }
		if (!self::IsValidUserName($username)) {
			self::$badname = true;
			return false;
		}

        } else {
            self::$userblank = true;
            return false;
        }
        // Check to make sure the packagename is not blank and exists before we go any further...
        if (!fs_director::CheckForEmptyValue($packageid)) {
            $sql = "SELECT COUNT(*) FROM x_packages WHERE pk_id_pk=:packageid AND pk_deleted_ts IS NULL";
            $numrows = $zdbh->prepare($sql);
            $numrows->bindParam(':packageid', $packageid);
            if ($numrows->execute()) {
                if ($numrows->fetchColumn() == 0) {
                    self::$packageblank = true;
                    return false;
                }
            }
        } else {
            self::$packageblank = true;
            return false;
        }
        // Check to make sure the groupname is not blank and exists before we go any further...
        if (!fs_director::CheckForEmptyValue($groupid)) {
            $sql = "SELECT COUNT(*) FROM x_groups WHERE ug_id_pk=:groupid";
            $numrows = $zdbh->prepare($sql);
            $numrows->bindParam(':groupid', $groupid);

            if ($numrows->execute()) {
                if ($numrows->fetchColumn() == 0) {
                    self::$groupblank = true;
                    return;
                }
            }
        } else {
            self::$groupblank = true;
            return false;
        }
        // Check for invalid characters in the email and that it exists...
        if (!fs_director::CheckForEmptyValue($email)) {
            if (!self::IsValidEmail($email)) {
                self::$bademail = true;
                return false;
            }
        } else {
            self::$emailblank = true;
            return false;
        }

        // Check that the email address is unique to the user's table
        if (!fs_director::CheckForEmptyValue($email)) {
            if (ctrl_users::CheckUserEmailIsUnique($email)) {
                self::$not_unique_email = false;
                return true;
            } else {
                self::$not_unique_email = true;
                return false;
            }
        } else {
            self::$not_unique_email = true;
            return false;
        }
		
		/*
        // Check for password length...
        if (!fs_director::CheckForEmptyValue($password)) {
            if (strlen($password) < ctrl_options::GetSystemOption('password_minlength')) {
                self::$badpassword = true;
                return false;
            }
        } else {
            self::$passwordblank = true;
            return false;
        }
		*/
        return true;
    }

	static function IsValidPassword($password)
    {
        //return preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{6,}/', $password) || preg_match('/-$/', $password) == 1;
		return preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z])/', $password) || preg_match('/-$/', $password) == 1;
    }
	
	static function IsValidEmail($email)
	{
		if (!filter_var($email, FILTER_SANITIZE_EMAIL))
				return false;
		if (!filter_var($email, FILTER_VALIDATE_EMAIL))
				return false;
		return true;
	}

    static function IsValidUserName($username)
    {
        if (!preg_match('/^[a-z\d][a-z\d-]{0,62}$/i', $username) || preg_match('/-$/', $username)) {
            return false;
        }
        return true;
    }

    static function DefaultEmailBody()
    {
        global $zdbh;
	$sql = "SELECT so_value_tx FROM x_settings WHERE so_name_vc = :configName";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindValue(':configName', 'welcome_message');
        $numrows->execute();

        $result = $numrows->fetch();
        
        if ($result) {
            return ($result['so_value_tx']);
        } else {
            return false;
        }
    }

    /**
     * Checks if the user already exists in the x_accounts table.
     * @global type $zdbh The ZPanelX database handle.
     * @param type $username The username to check against.
     * @return boolean
     */
    static function CheckUserExists($username)
    {
        global $zdbh;
        $sql = "SELECT COUNT(*) FROM x_accounts WHERE LOWER(ac_user_vc)=:username";
        $uniqueuser = $zdbh->prepare($sql);
        $uniqueuser->bindValue(':username', strtolower($username));
        if ($uniqueuser->execute()) {
            if ($uniqueuser->fetchColumn() > 0) {
                return true;
            } else {
                return false;
            }
        } else {
            return true;
        }
    }

    /**
     * Check if the Shadowing module is enabled/exists.
     * @global type $zdbh The ZPanelX database handle.
     * @return boolean
     */
    static function getShadowEnabled()
    {
        global $zdbh;
        $sql = "SELECT COUNT(*) FROM x_modules WHERE mo_folder_vc='shadowing' AND mo_enabled_en='true'";
        $shadowEnabled = $zdbh->prepare($sql);
        if ($shadowEnabled->execute()) {
            if ($shadowEnabled->fetchColumn() > 0) {
                return true;
            } else {
                return false;
            }
        } else {
            return true;
        }
    }

    /**
     * End 'worker' methods.
     */

    /**
     * Webinterface sudo methods.
     */
    static function doCreateClient()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inSWE'])) {
            $sendemail = $formvars['inSWE'];
        } else {
            $sendemail = 0;
        }
        $client = [
            'packageid'    => $formvars['inNewPackage'],
            'groupid'      => $formvars['inNewGroup'],
            'fullname'     => $formvars['inNewFullName'],
            'email'        => $formvars['inNewEmailAddress'],
            'address'      => $formvars['inNewAddress'],
            'post'         => $formvars['inNewPostCode'],
            'phone'        => $formvars['inNewPhone'],
            'password'     => $formvars['inNewPassword'],
            'sendemail'    => $sendemail,
            'emailsubject' => $formvars['inEmailSubject'],
            'emailbody'    => $formvars['inEmailBody'],
        ];
        if (self::ExecuteCreateClient($currentuser['userid'], $formvars['inNewUserName'], $client)) {
            unset($_POST['inNewUserName']);
            return true;
        } else {
            return false;
        }
    }

    static function doEditClient()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListClients($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['userid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['userid'] . "");
                exit;
            }
            if (isset($formvars['inEdit_' . $row['userid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $row['userid'] . "");
                exit;
            }
        }
        return;
    }

    static function doEditDisabledClient()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListDisabledClients($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['userid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['userid'] . "");
                exit;
            }
            if (isset($formvars['inEdit_' . $row['userid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $row['userid'] . "");
                exit;
            }
        }
        return;
    }

    static function doDeleteClient()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteDeleteClient($formvars['inDelete'], $formvars['inMoveClient']))
            return true;
        return false;
    }

    static function doUpdateClient()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        $client = [
            'package'  => $formvars['inPackage'],
            'enabled'  => $formvars['inEnabled'],
            'group'    => $formvars['inGroup'],
            'fullname' => $formvars['inFullName'],
            'email'    => $formvars['inEmailAddress'],
            'address'  => $formvars['inAddress'],
            'post'     => $formvars['inPostCode'],
            'phone'    => $formvars['inPhone'],
            'newpass'  => $formvars['inNewPassword'],
        ];
        if (self::ExecuteUpdateClient($formvars['inClientID'], $client))
            return true;
        return false;
    }

    static function getClientList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $clientlist = self::ListClients($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($clientlist)) {
            return $clientlist;
        } else {
            return false;
        }
    }

    static function getAllClientList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $urlvars = $controller->GetAllControllerRequests('URL');
        $clientlist = self::ListAllClients($urlvars['other'], $currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($clientlist)) {
            return $clientlist;
        } else {
            return false;
        }
    }

    static function getDisabledClientList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $disabledclientlist = self::ListDisabledClients($currentuser['userid']);
        if (!fs_director::CheckForEmptyValue($disabledclientlist)) {
            return $disabledclientlist;
        } else {
            return false;
        }
    }

    static function getCurrentClient()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        $client = self::ListCurrentClient($urlvars['other']);
        if (!fs_director::CheckForEmptyValue($client)) {
            return $client;
        } else {
            return false;
        }
    }

    static function getGroupList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListGroups($currentuser['userid']);
    }

    static function getCurrentGroupList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListCurrentGroups($controller->GetControllerRequest('URL', 'other'), $currentuser['resellerid'], $currentuser['userid']);
    }

    static function getPackageList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListPackages($currentuser['userid']);
    }

    static function getCurrentPackageList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListCurrentPackages($controller->GetControllerRequest('URL', 'other'), $currentuser['userid']);
    }

    static function getCheckEnabledHTML()
    {
        global $controller;
        return self::CheckEnabledHTML($controller->GetControllerRequest('URL', 'other'));
    }

    static function getHasPackage()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::CheckHasPackage($currentuser['userid']);
    }

    static function getIsReseller()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::CheckHasPackage($currentuser['userid']);
    }

    static function getisCreateClient()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if (!isset($urlvars['show']))
            return true;
        return false;
    }

    static function getisDeleteClient($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Delete Selected user.
        // This shall avoid exposing User based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

        $sql = " SELECT * FROM x_accounts WHERE ac_reseller_fk=:userid AND ac_id_pk=:editedUsrID AND ac_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->bindParam(':editedUsrID', $urlvars['other']);
        $numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }

        // Show User Info
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Delete");
    }

    static function getisEditClient($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars     = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Edit Selected user.
        // This shall avoid exposing User based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

        $sql = " SELECT * FROM x_accounts WHERE ac_reseller_fk=:userid AND ac_id_pk=:editedUsrID AND ac_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->bindParam(':editedUsrID', $urlvars['other']);
        $numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }

        // Show User Info
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Edit");
    }

    static function getEditCurrentName()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['username'];
        } else {
            return "";
        }
    }

    static function getEditCurrentEmail()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['email'];
        } else {
            return "";
        }
    }

    static function getEditCurrentFullName()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['fullname'];
        } else {
            return "";
        }
    }

    static function getEditCurrentPost()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['postcode'];
        } else {
            return "";
        }
    }

    static function getEditCurrentID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['userid'];
        } else {
            return "";
        }
    }

    static function getEditCurrentAddress()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['address'];
        } else {
            return "";
        }
    }

    static function getEditCurrentPhone()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentClient($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['phone'];
        } else {
            return "";
        }
    }

    static function getDefaultEmailBody()
    {
        global $controller;
        return self::DefaultEmailBody();
    }

    static function getFormName()
    {
        global $controller;
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inNewUserName']) && fs_director::CheckForEmptyValue(self::$resetform)) {
            return $formvars['inNewUserName'];
        }
        return;
    }

    static function getFormFullName()
    {
        global $controller;
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inNewFullName']) && fs_director::CheckForEmptyValue(self::$resetform)) {
            return $formvars['inNewFullName'];
        }
        return;
    }

    static function getFormEmail()
    {
        global $controller;
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inNewEmailAddress']) && fs_director::CheckForEmptyValue(self::$resetform)) {
            return $formvars['inNewEmailAddress'];
        }
        return;
    }

    static function getFormAddress()
    {
        global $controller;
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inNewAddress']) && fs_director::CheckForEmptyValue(self::$resetform)) {
            return $formvars['inNewAddress'];
        }
        return;
    }

    static function getFormPost()
    {
        global $controller;
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inNewPostCode']) && fs_director::CheckForEmptyValue(self::$resetform)) {
            return $formvars['inNewPostCode'];
        }
        return;
    }

    static function getFormPhone()
    {
        global $controller;
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inNewPhone']) && fs_director::CheckForEmptyValue(self::$resetform)) {
            return $formvars['inNewPhone'];
        }
        return;
    }
/*
    static function getRandomPassword()
    {
		$minpasswordlength = "16";
        $trylength = 9;
        if ($trylength < $minpasswordlength) {
            $uselength = $minpasswordlength;
        } else {
            $uselength = $trylength;
        }
        $password = fs_director::GenerateRandomPassword($uselength, 4);
        return $password;
    }
*/
    static function getMinPassLength()
    {
        $minpasswordlength = ctrl_options::GetSystemOption('password_minlength');
        $trylength = 9;
        if ($trylength < $minpasswordlength) {
            $uselength = $minpasswordlength;
        } else {
            $uselength = $trylength;
        }
        return $uselength;
    }

    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$userblank)) {
            return ui_sysmessage::shout(ui_language::translate("You need to specify a username to create a new client."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$emailblank)) {
            return ui_sysmessage::shout(ui_language::translate("You need to specify an email address to create a new client."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$passwordblank)) {
            return ui_sysmessage::shout(ui_language::translate("Your password cannot be blank."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$packageblank)) {
            return ui_sysmessage::shout(ui_language::translate("You must select a package for your new client."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$groupblank)) {
            return ui_sysmessage::shout(ui_language::translate("You must select a user group for your new client."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return ui_sysmessage::shout(ui_language::translate("Your client name is not valid. Please enter a valid client name."), "zannounceerror");
        }
		if (!fs_director::CheckForEmptyValue(self::$badpass)) {
            return ui_sysmessage::shout(ui_language::translate("Your password is not valid. Valid characters are A-Z, a-z, 0-9."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$bademail)) {
            return ui_sysmessage::shout(ui_language::translate("Your email address is not valid. Please enter a valid email address."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badpasswordlength)) {
            return ui_sysmessage::shout(ui_language::translate("Your password did not meet the minimun length requirements. Characters needed for password length") . ": " . ctrl_options::GetSystemOption('password_minlength'), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("A client with that name already appears to exsist on this server."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your client(s) have been saved successfully!"), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$not_unique_email)) {
            return ui_sysmessage::shout(ui_language::translate("Another user account is already using this email address."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$poolexceeded)) {
            return ui_sysmessage::shout(ui_language::translate("Cannot assign this package: the reseller's resource pool would be exceeded. Request a package upgrade from the administrator."), "zannounceerror");
        }
        return;
    }

    /**
     * Webinterface sudo methods.
     */
}

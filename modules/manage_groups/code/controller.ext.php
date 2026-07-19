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

    /**
     * The 'worker' methods.
     */
    static function GroupInfo($gid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_groups WHERE ug_id_pk=:gid";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':gid', $gid);
        $numrows->execute();
        //$numrows = $zdbh->query($sql);
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':gid', $gid);
            $res = array();
            $sql->execute();
            while ($rowgroups = $sql->fetch()) {
                array_push($res, array('groupid' => $rowgroups['ug_id_pk'], 'groupname' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_name_vc'])), 'groupdesc' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_notes_tx']))));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListGroups($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_groups WHERE ug_reseller_fk=:uid";
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
                // Grupos "propios" del reseller = todos menos los 3 de rol por defecto (ids 1/2/3).
                // Se clasifica por id fijo, no por nombre (que es solo texto).
                if (!in_array((int)$rowgroups['ug_id_pk'], array(ctrl_groups::GROUP_ADMIN, ctrl_groups::GROUP_RESELLER, ctrl_groups::GROUP_USER), true)) {
                    $stmt = $zdbh->prepare("SELECT COUNT(*) AS total FROM x_accounts WHERE ac_group_fk=:gid");
                    $stmt->execute([':gid' => $rowgroups['ug_id_pk']]);
                    $totalnoaccs = $stmt->fetch();
                    array_push($res, array('groupid' => $rowgroups['ug_id_pk'], 'groupname' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_name_vc'])), 'groupdesc' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_notes_tx'])), 'usersingroup' => runtime_xss::xssClean($totalnoaccs['total'])));
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListDefaultGroups($uid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_groups WHERE ug_reseller_fk=:uid";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->execute();
        //$numrows = $zdbh->query($sql);
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $res = array();
            $sql->execute();
            while ($rowgroups = $sql->fetch()) {
                // Grupos de rol por defecto = ids fijos 1/2/3 (Administrators/Resellers/Users).
                if (in_array((int)$rowgroups['ug_id_pk'], array(ctrl_groups::GROUP_ADMIN, ctrl_groups::GROUP_RESELLER, ctrl_groups::GROUP_USER), true)) {
                    $stmt = $zdbh->prepare("SELECT COUNT(*) AS total FROM x_accounts WHERE ac_group_fk=:gid");
                    $stmt->execute([':gid' => $rowgroups['ug_id_pk']]);
                    $totalnoaccs = $stmt->fetch();
                    array_push($res, array('groupid' => $rowgroups['ug_id_pk'], 'groupname' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_name_vc'])), 'groupdesc' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_notes_tx'])), 'usersingroup' => runtime_xss::xssClean($totalnoaccs['total'])));
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    static function GroupMoveTo($uid, $gid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_groups WHERE ug_reseller_fk=:uid AND ug_id_pk <> :gid";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':uid', $uid);
        $numrows->bindParam(':gid', $gid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':uid', $uid);
            $sql->bindParam(':gid', $gid);
            $res = array();
            $sql->execute();
            while ($rowgroups = $sql->fetch()) {
                array_push($res, array('groupid' => $rowgroups['ug_id_pk'], 'groupname' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_name_vc'])), 'groupdesc' => ui_language::translate(runtime_xss::xssClean($rowgroups['ug_notes_tx']))));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ExectuteCreateGroup($name, $desc, $uid)
    {
        global $zdbh;
        if (!fs_director::CheckForEmptyValue($name)) {
            $sql = $zdbh->prepare("INSERT INTO x_groups (ug_name_vc, ug_notes_tx, ug_reseller_fk) VALUES (:name, :desc, :uid)");
            $sql->bindParam(':name', $name);
            $sql->bindParam(':desc', $desc);
            $sql->bindParam(':uid', $uid);
            $sql->execute();
        }
        return true;
    }

    static function ExectuteUpdateGroup($gid, $name, $desc)
    {
        global $zdbh;
        $sql = $zdbh->prepare("UPDATE x_groups SET ug_name_vc = :name, ug_notes_tx = :desc WHERE ug_id_pk = :groupid");
        $sql->bindParam(':name', $name);
        $sql->bindParam(':desc', $desc);
        $sql->bindParam(':groupid', $gid);
        $sql->execute();
        return true;
    }

    static function ExecuteDeleteGroup($gid, $mgid = "")
    {
        global $zdbh;
        if ($mgid != "") {
            $sql = $zdbh->prepare("
            UPDATE x_accounts
            SET ac_group_fk = :mgid
            WHERE ac_group_fk = :gid");
            $sql->bindParam(':mgid', $mgid);
            $sql->bindParam(':gid', $gid);
            $sql->execute();
            $sql = $zdbh->prepare("
            DELETE FROM x_groups
            WHERE ug_id_pk = :gid");
            $sql->bindParam(':gid', $gid);
            $sql->execute();
            return true;
        } else {
            $sql = $zdbh->prepare("
            DELETE FROM x_groups
            WHERE ug_id_pk = :gid");
            $sql->bindParam(':gid', $gid);
            $sql->execute();
            return true;
        }
    }

    /**
     * End 'worker' methods.
     */

    /**
     * Webinterface sudo methods.
     */
    static function getGroupList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListGroups($currentuser['userid']);
    }

    static function getDefaultGroupList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListDefaultGroups($currentuser['userid']);
    }

    static function getGroupMoveToList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $urlvars = $controller->GetAllControllerRequests('URL');
        return self::GroupMoveTo($currentuser['userid'], $urlvars['other']);
    }

    /**
     * AUTZ: ¿puede el usuario actual editar/borrar este grupo?
     * - Los grupos INTEGRADOS (Administrators=1, Resellers=2, Users=3) no se tocan por nadie
     *   desde el panel: renombrar/borrar el grupo admin rompería los chequeos por nombre.
     * - El resto debe pertenecer al usuario actual (ug_reseller_fk = su id). Sin esto, un
     *   reseller podía manipular por ID (IDOR) grupos ajenos o del admin.
     */
    private static function canManageGroup($gid)
    {
        global $zdbh;
        $gid = (int)$gid;
        if ($gid <= 0 || in_array($gid, array(1, 2, 3), true)) {
            return false;
        }
        $self = (int)ctrl_users::GetUserDetail()['userid'];
        $chk = $zdbh->prepare("SELECT COUNT(*) FROM x_groups WHERE ug_id_pk=:gid AND ug_reseller_fk=:uid");
        $chk->execute(array(':gid' => $gid, ':uid' => $self));
        return ((int) $chk->fetchColumn() > 0);
    }

    /** Valida el nombre de un grupo nuevo: no reservado y con caracteres seguros. */
    private static function validGroupName($name)
    {
        $name = trim((string) $name);
        $reserved = array('administrators', 'resellers', 'users');
        if ($name === '' || in_array(strtolower($name), $reserved, true)) {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9 _\-]{1,64}$/', $name);
    }

    static function doCreateGroup()
    {
        // Creación de grupos personalizados DESACTIVADA (ver getisCreateGroup). Guard contra POST
        // directo: no se crea ningún grupo aunque llegue el formulario.
        runtime_csfr::Protect();
        return false;
    }

    static function doEditGroup()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListGroups($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['groupid'] . ''])) {
                header("location: ./?module=" . runtime_xss::xssClean($controller->GetCurrentModule()) . "&show=Delete&other=" . runtime_xss::xssClean($row['groupid']) . "");
                exit;
            }
            if (isset($formvars['inEdit_' . $row['groupid'] . ''])) {
                header("location: ./?module=" . runtime_xss::xssClean($controller->GetCurrentModule()) . "&show=Edit&other=" . runtime_xss::xssClean($row['groupid']) . "");
                exit;
            }
        }
        return;
    }

    static function doDeleteGroup()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // AUTZ: el grupo a borrar debe ser propio y no integrado (IDOR fix).
        if (!self::canManageGroup($formvars['inGroupID'])) {
            return false;
        }
        if (isset($formvars['inMoveGroup']) && $formvars['inMoveGroup'] !== "") {
            $inMoveGroup = $formvars['inMoveGroup'];
            // El grupo destino de reasignación también debe ser propio (no mover cuentas
            // a un grupo del admin/otro reseller).
            if (!self::canManageGroup($inMoveGroup)) {
                return false;
            }
        } else {
            $inMoveGroup = "";
        }
        if (self::ExecuteDeleteGroup($formvars['inGroupID'], $inMoveGroup))
            return true;
        return false;
    }

    static function doUpdateGroup()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // AUTZ: el grupo a editar debe ser propio y no integrado (IDOR fix).
        if (!self::canManageGroup($formvars['inGroupID'])) {
            return false;
        }
        // Impedir renombrar un grupo a un nombre reservado.
        if (!self::validGroupName($formvars['inGroupName'])) {
            return false;
        }
        if (self::ExectuteUpdateGroup($formvars['inGroupID'], $formvars['inGroupName'], $formvars['inDesc']))
            return true;
        return false;
    }

    static function getisCreateGroup()
    {
        // Creación de grupos personalizados DESACTIVADA: en el modelo de rol (admin/reseller/user
        // por id) + paquetes=cuotas, los grupos a medida no aportan utilidad y confunden. El
        // formulario "Create new user group" no se muestra. (Reversible: devolver true si no hay show.)
        return false;
    }

    static function getisDeleteGroup($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Delete Group Account.
        // This shall avoid exposing Group based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

        $sql = " SELECT * FROM x_groups WHERE ug_reseller_fk=:userid";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }

        // Show User Info
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Delete");
    }

    static function getisEditGroup($uid = null)
    {
        global $controller;
        global $zdbh;

        $urlvars     = $controller->GetAllControllerRequests('URL');

        // Verify if Current user can Edit Group Account.
        // This shall avoid exposing Group based on ID lookups.
        $currentuser = ctrl_users::GetUserDetail($uid);

        $sql = " SELECT * FROM x_groups WHERE ug_reseller_fk=:userid";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();

        if( $numrows->rowCount() == 0 ) {
            return;
        }

        // Show User Info
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Edit");
    }

    static function getCurrentID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::GroupInfo($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['groupid'];
        } else {
            return "";
        }
    }

    static function getEditCurrentName()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::GroupInfo($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['groupname'];
        } else {
            return "";
        }
    }

    static function getEditCurrentDesc()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::GroupInfo($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['groupdesc'];
        } else {
            return "";
        }
    }

    /**
     * Webinterface sudo methods.
     */
}

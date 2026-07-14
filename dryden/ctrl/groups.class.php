<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Group permissions class, handles user group permissions.
 * @package zpanelx
 * @subpackage dryden -> controller
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
class ctrl_groups {

    // IDs FIJOS de los grupos de rol por defecto (seed sentora_core.sql). El nivel de privilegio se
    // decide SIEMPRE por estos ids, nunca por el nombre del grupo (ug_name_vc), que es solo un texto
    // para mostrar y podría renombrarse/localizarse/falsificarse.
    const GROUP_ADMIN    = 1;
    const GROUP_RESELLER = 2;
    const GROUP_USER     = 3;

    /** ¿El grupo (por id) es el de administradores? */
    static function IsAdminGroupId($groupid) { return (int)$groupid === self::GROUP_ADMIN; }

    /**
     * Checks permissions to a module for a given user group.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param int $groupid The usergroup ID.
     * @param int $moduleid The module ID.
     * @return bool
     */
    static function CheckGroupModulePermissions($groupid, $moduleid) {
        global $zdbh;
        $sqlString = "SELECT pe_id_pk FROM 
                    x_permissions WHERE 
                    pe_group_fk = :groupid AND 
                    pe_module_fk = :moduleid";
        $bindArray = array(
            ':groupid' => $groupid,
            ':moduleid' => $moduleid,
        );
        $zdbh->bindQuery($sqlString, $bindArray);
        $result = $zdbh->returnRow();
        if ($result) {
            return true;
        }
        return false;
    }

    /**
     * ¿El paquete tiene una lista de módulos (feature list) definida?
     * Si la tabla no existe (instalaciones previas a la migración) o no hay filas, devuelve false
     * para que el llamador haga fallback al ACL de grupo. Se llama en CADA carga: nunca debe fatalar.
     */
    static function PackageHasModuleList($packageid) {
        global $zdbh;
        try {
            $q = $zdbh->prepare("SELECT COUNT(*) FROM x_package_modules WHERE pm_package_fk = :pid");
            $q->execute(array(':pid' => (int)$packageid));
            return ((int)$q->fetchColumn()) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /** ¿El paquete incluye ese módulo en su feature list? */
    static function CheckPackageModule($packageid, $moduleid) {
        global $zdbh;
        try {
            $q = $zdbh->prepare("SELECT 1 FROM x_package_modules WHERE pm_package_fk = :pid AND pm_module_fk = :mid LIMIT 1");
            $q->execute(array(':pid' => (int)$packageid, ':mid' => (int)$moduleid));
            return (bool)$q->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Acceso EFECTIVO de un usuario a un módulo (modelo hosting):
     *  - admin (1) / reseller (2): por ACL de grupo (x_permissions), como siempre.
     *  - user (3): por la LISTA DE MÓDULOS DEL PAQUETE (x_package_modules). Si el paquete no tiene
     *    lista definida, FALLBACK al ACL del grupo -> sin cambio de comportamiento en instalaciones
     *    existentes. Así se puede activar el modelo paquete-a-paquete sin romper nada.
     * @param array $user  Array de ctrl_users::GetUserDetail() (usa usergroupid y packageid).
     * @param int   $moduleid
     */
    static function CheckUserModuleAccess($user, $moduleid) {
        $gid = (int)($user['usergroupid'] ?? self::GROUP_USER);
        if ($gid !== self::GROUP_USER) {
            return self::CheckGroupModulePermissions($gid, $moduleid);
        }
        $pid = (int)($user['packageid'] ?? 0);
        if ($pid > 0 && self::PackageHasModuleList($pid)) {
            return self::CheckPackageModule($pid, $moduleid);
        }
        return self::CheckGroupModulePermissions($gid, $moduleid);
    }

    /**
     * Adds permission to enable a module for a given user group.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param int $groupid The usergroup ID.
     * @param int $moduleid The module ID.
     * @return bool
     */
    static function AddGroupModulePermissions($groupid, $moduleid) {
        global $zdbh;
        $sqlString = "SELECT COUNT(*) FROM 
                     x_permissions WHERE 
                     pe_group_fk = :groupid AND 
                     pe_module_fk = :moduleid";
        $bindArray = array(
            ':groupid' => $groupid,
            ':moduleid' => $moduleid,
        );
        $sqlPrepare = $zdbh->prepare($sqlString);
        $zdbh->bindParams($sqlPrepare, $bindArray);
        unset($sqlString);
        $rowCount = $sqlPrepare->rowCount();
        unset($sqlPrepare);

        if ($rowCount < 1) {
            $sqlString = "INSERT INTO x_permissions 
                         ( pe_group_fk , pe_module_fk ) VALUES 
                         ( :groupid , :moduleid )";
            $bindArray = array(
                ':groupid' => $groupid,
                ':moduleid' => $moduleid,
            );
            $sqlPrepare = $zdbh->prepare($sqlString);
            $zdbh->bindParams($sqlPrepare, $bindArray);
            $result = $sqlPrepare->execute();
            if ($result > 0) {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Deletes permission to disable a module for a given user group.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param int $groupid The usergroup ID. (If '0' will delete the permissions for ALL groups)
     * @param int $moduleid The module ID.
     * @return bool
     */
    static function DeleteGroupModulePermissions($groupid, $moduleid) {
        global $zdbh;
        $sqlString = "DELETE FROM x_permissions WHERE pe_module_fk = :moduleid ";
        if ($groupid > 0) {
            $sqlString .= "AND pe_group_fk = :groupid";
            $sqlQuery = $zdbh->prepare($sqlString);
            $sqlQuery->bindParam(':groupid', $groupid);
        } else {
            $sqlQuery = $zdbh->prepare($sqlString);
        }
        $sqlQuery->bindParam(':moduleid', $moduleid);

        if ($sqlQuery->execute() > 0) {
            return true;
        } else {
            return false;
        }
    }

}

?>

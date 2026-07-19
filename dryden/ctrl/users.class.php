<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * General user infoamtion class.
 * @package zpanelx
 * @subpackage dryden -> controller
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
class ctrl_users {

    /**
     * Returns an array of infomation for the account details, package, groups and quota limits for a given UID.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param int $uid The Bulwark user account ID.
     * @return array
     */
    static function GetUserDetail($uid = "") {
        global $zdbh;
        $userdetail = new runtime_dataobject();
        if ($uid == "") {
            $uid = ctrl_auth::CurrentUserID();
        }
        $rows = $zdbh->prepare("
            SELECT * FROM x_accounts
            LEFT JOIN x_profiles ON (x_accounts.ac_id_pk=x_profiles.ud_user_fk)
            LEFT JOIN x_groups   ON (x_accounts.ac_group_fk=x_groups.ug_id_pk)
            LEFT JOIN x_packages ON (x_accounts.ac_package_fk=x_packages.pk_id_pk)
            LEFT JOIN x_quotas   ON (x_accounts.ac_package_fk=x_quotas.qt_package_fk)
            WHERE x_accounts.ac_id_pk= :uid
          ");
        $rows->bindParam(':uid', $uid);
        $rows->execute();
        $dbvals = $rows->fetch();
        $userdetail->addItemValue('username', $dbvals['ac_user_vc']);
        $userdetail->addItemValue('userid', $dbvals['ac_id_pk']);
        $userdetail->addItemValue('password', $dbvals['ac_pass_vc']);
        $userdetail->addItemValue('email', $dbvals['ac_email_vc']);
        $userdetail->addItemValue('resellerid', $dbvals['ac_reseller_fk']);
        $userdetail->addItemValue('packageid', $dbvals['ac_package_fk']);
        $userdetail->addItemValue('enabled', $dbvals['ac_enabled_in']);
        $userdetail->addItemValue('usertheme', $dbvals['ac_usertheme_vc']);
        $userdetail->addItemValue('usercss', $dbvals['ac_usercss_vc']);
        $userdetail->addItemValue('lastlogon', $dbvals['ac_lastlogon_ts']);
        $userdetail->addItemValue('fullname', $dbvals['ud_fullname_vc']);
        $userdetail->addItemValue('packagename', $dbvals['pk_name_vc']);
        $userdetail->addItemValue('usergroup', $dbvals['ug_name_vc']);
        $userdetail->addItemValue('usergroupid', $dbvals['ac_group_fk']);
        $userdetail->addItemValue('address', $dbvals['ud_address_tx']);
        $userdetail->addItemValue('postcode', $dbvals['ud_postcode_vc']);
        $userdetail->addItemValue('phone', $dbvals['ud_phone_vc']);
        $userdetail->addItemValue('language', $dbvals['ud_language_vc']);
        $userdetail->addItemValue('diskquota', $dbvals['qt_diskspace_bi']);
        $userdetail->addItemValue('bandwidthquota', $dbvals['qt_bandwidth_bi']);
        $userdetail->addItemValue('domainquota', $dbvals['qt_domains_in']);
        $userdetail->addItemValue('subdomainquota', $dbvals['qt_subdomains_in']);
        $userdetail->addItemValue('parkeddomainquota', $dbvals['qt_parkeddomains_in']);
        $userdetail->addItemValue('ftpaccountsquota', $dbvals['qt_ftpaccounts_in']);
        $userdetail->addItemValue('mysqlquota', $dbvals['qt_mysql_in']);
        $userdetail->addItemValue('cronjobquota', $dbvals['qt_cronjobs_in']);
        $userdetail->addItemValue('phpmemoryquota', $dbvals['qt_php_memory_vc']);
        $userdetail->addItemValue('phpuploadquota', $dbvals['qt_php_upload_vc']);
        $userdetail->addItemValue('phppostquota', $dbvals['qt_php_post_vc']);
        $userdetail->addItemValue('phpexecquota', $dbvals['qt_php_exec_in']);
        $userdetail->addItemValue('phpmaxinputquota', $dbvals['qt_php_maxinput_in']);
        $userdetail->addItemValue('mailboxquota', $dbvals['qt_mailboxes_in']);
        $userdetail->addItemValue('forwardersquota', $dbvals['qt_fowarders_in']);
        $userdetail->addItemValue('distlistsquota', $dbvals['qt_distlists_in']);
        $userdetail->addItemValue('catorder', $dbvals['ac_catorder_vc']);
        return $userdetail->getDataObject();
    }

    /**
     * Returns the current usage of a particular resource.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $resource What time of quota should we be checking? (domains, subdomains, parkeddomains, mailboxes, distlists etc.)
     * @param int $acc_key The user ID of which to check the quota status for.
     * @return array Database table array of the quota infomation.
     */
    static function GetQuotaUsages($resource, $acc_key = 0) {
        global $zdbh;
        if ($resource == 'domains') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_vhosts WHERE vh_acc_fk= :acc_key AND vh_type_in=1 AND vh_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'subdomains') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_vhosts WHERE vh_acc_fk= :acc_key AND vh_type_in=2 AND vh_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'parkeddomains') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_vhosts WHERE vh_acc_fk= :acc_key AND vh_type_in=3 AND vh_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'mailboxes') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_mailboxes WHERE mb_acc_fk= :acc_key AND mb_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'forwarders') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_forwarders WHERE fw_acc_fk= :acc_key AND fw_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'distlists') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_distlists WHERE dl_acc_fk= :acc_key AND dl_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'ftpaccounts') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_ftpaccounts WHERE ft_acc_fk= :acc_key AND ft_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'mysql') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_mysql_databases WHERE my_acc_fk= :acc_key AND my_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'cronjobs') {
            $sql = $zdbh->prepare("SELECT COUNT(*) AS amount FROM x_cronjobs WHERE ct_acc_fk= :acc_key AND ct_deleted_ts IS NULL");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['amount'];
        }
        if ($resource == 'diskspace') {
            $sql = $zdbh->prepare("SELECT bd_diskamount_bi FROM x_bandwidth WHERE bd_acc_fk= :acc_key AND bd_month_in=" . date("Ym", time()) . "");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['bd_diskamount_bi'];
        }
        if ($resource == 'bandwidth') {
            $sql = $zdbh->prepare("SELECT bd_transamount_bi FROM x_bandwidth WHERE bd_acc_fk= :acc_key AND bd_month_in=" . date("Ym", time()) . "");
            $sql->bindParam(':acc_key', $acc_key);
            $sql->execute();
            $retval = $sql->fetch();
            $retval = $retval['bd_transamount_bi'];
        }
        return $retval;
    }

    /**
     * @todo Does this still need to be here as this is now managed under a module and not seen as 'core' but template still relies on this at present! - Bobby Allen
     */
    static function GetUserDomains($userid, $type = "1") {
        global $zdbh;
        $domains = 0;
        $numrows = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_acc_fk= :userid AND vh_deleted_ts IS NULL AND vh_type_in= :type");
        $numrows->bindParam(':userid', $userid);
        $numrows->bindParam(':type', $type);
        $status = $sql->execute();
        if ($status) {
            if ($numrows->fetchColumn() <> 0) {
                $domains = count($numrows->fetchColumn());
                return $domains;
            }
        }
        return $domains;
    }

    /**
     * Checks that the specified user is active and therefore allowed to login to the panel.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param int $uid The Bulwark user account ID.
     * @return boolean
     */
    static function CheckUserEnabled($uid) {
        global $zdbh;
        $domains = 0;
        $sql = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_id_pk= :uid AND ac_enabled_in=1 AND ac_deleted_ts IS NULL");
        $sql->bindParam(':uid', $uid);
        $status = $sql->execute();

        if ($status) {
            if ($sql->fetchColumn() <> 0) {
                return true;
            }
        }
        return false;
    }

    // Returns true when the account is suspended (ac_enabled_in=0, ac_suspended_in=1).
    // Suspended clients: panel blocked, FTP/DNS/MySQL/Web active, email blocked.
    static function CheckUserSuspended($uid) {
        global $zdbh;
        $sql = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_id_pk= :uid AND ac_enabled_in=0 AND ac_suspended_in=1 AND ac_deleted_ts IS NULL");
        $sql->bindParam(':uid', $uid);
        $sql->execute();
        return $sql->fetchColumn() > 0;
    }

    /**
     * Returns the effective state of an account considering reseller cascade.
     * Most restrictive state wins: disabled > suspended > active.
     * @return string 'active' | 'suspended' | 'disabled'
     */
    static function GetEffectiveAccountState($uid) {
        global $zdbh;
        $sql = $zdbh->prepare("
            SELECT a.ac_enabled_in, a.ac_suspended_in,
                   r.ac_enabled_in  AS res_enabled,
                   r.ac_suspended_in AS res_suspended
            FROM x_accounts a
            LEFT JOIN x_accounts r ON a.ac_reseller_fk = r.ac_id_pk AND r.ac_deleted_ts IS NULL
            WHERE a.ac_id_pk = :uid AND a.ac_deleted_ts IS NULL
        ");
        $sql->bindParam(':uid', $uid);
        $sql->execute();
        $row = $sql->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 'disabled';

        if ((int)$row['ac_enabled_in'] === 1)      $acState = 'active';
        elseif ((int)$row['ac_suspended_in'] === 1) $acState = 'suspended';
        else                                         $acState = 'disabled';

        if ($row['res_enabled'] !== null) {
            if ((int)$row['res_enabled'] === 0 && (int)$row['res_suspended'] === 0) $resState = 'disabled';
            elseif ((int)$row['res_enabled'] === 0)                                  $resState = 'suspended';
            else                                                                      $resState = 'active';
        } else {
            $resState = 'active';
        }

        $priority = ['disabled' => 0, 'suspended' => 1, 'active' => 2];
        return ($priority[$acState] <= $priority[$resState]) ? $acState : $resState;
    }

    /**
     * Checks that a specified email address is unique in the user accounts table.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver The ZPX database handle.
     * @param type $email The email address to check.
     * @return boolean
     */
    // -----------------------------------------------------------------------
    // Pool de recursos del reseller — validación en cascada
    // -----------------------------------------------------------------------

    /**
     * Devuelve las cuotas del paquete propio del reseller (su "pool" total).
     * Si el reseller es admin (pool=-1 en todos los campos) las validaciones pasan siempre.
     */
    static function GetResellerPool($uid) {
        global $zdbh;
        $sql = $zdbh->prepare("
            SELECT q.*
            FROM x_accounts a
            JOIN x_packages pk ON pk.pk_id_pk = a.ac_package_fk AND pk.pk_deleted_ts IS NULL
            JOIN x_quotas   q  ON q.qt_package_fk = pk.pk_id_pk
            WHERE a.ac_id_pk = :uid AND a.ac_deleted_ts IS NULL
        ");
        $sql->execute([':uid' => $uid]);
        return $sql->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Calcula cuántos recursos ha comprometido ya el reseller a sus clientes.
     * Devuelve NULL en un campo si algún paquete activo tiene -1 (ilimitado) con clientes.
     * @param int $exclude_pkg_id  Excluir este paquete del cálculo (al editar ese paquete).
     */
    static function GetResellerCommitted($uid, $exclude_pkg_id = 0) {
        global $zdbh;
        $sql = $zdbh->prepare("
            SELECT
                SUM(CASE WHEN q.qt_domains_in=-1       AND COALESCE(c.n,0)>0 THEN NULL WHEN q.qt_domains_in=-1       THEN 0 ELSE q.qt_domains_in       * COALESCE(c.n,0) END) AS domains,
                SUM(CASE WHEN q.qt_subdomains_in=-1    AND COALESCE(c.n,0)>0 THEN NULL WHEN q.qt_subdomains_in=-1    THEN 0 ELSE q.qt_subdomains_in    * COALESCE(c.n,0) END) AS subdomains,
                SUM(CASE WHEN q.qt_parkeddomains_in=-1 AND COALESCE(c.n,0)>0 THEN NULL WHEN q.qt_parkeddomains_in=-1 THEN 0 ELSE q.qt_parkeddomains_in * COALESCE(c.n,0) END) AS parkeddomains,
                SUM(CASE WHEN q.qt_mailboxes_in=-1     AND COALESCE(c.n,0)>0 THEN NULL WHEN q.qt_mailboxes_in=-1     THEN 0 ELSE q.qt_mailboxes_in     * COALESCE(c.n,0) END) AS mailboxes,
                SUM(CASE WHEN q.qt_fowarders_in=-1     AND COALESCE(c.n,0)>0 THEN NULL WHEN q.qt_fowarders_in=-1     THEN 0 ELSE q.qt_fowarders_in     * COALESCE(c.n,0) END) AS fowarders,
                SUM(CASE WHEN q.qt_distlists_in=-1     AND COALESCE(c.n,0)>0 THEN NULL WHEN q.qt_distlists_in=-1     THEN 0 ELSE q.qt_distlists_in     * COALESCE(c.n,0) END) AS distlists,
                SUM(CASE WHEN q.qt_ftpaccounts_in=-1   AND COALESCE(c.n,0)>0 THEN NULL WHEN q.qt_ftpaccounts_in=-1   THEN 0 ELSE q.qt_ftpaccounts_in   * COALESCE(c.n,0) END) AS ftpaccounts,
                SUM(CASE WHEN q.qt_cronjobs_in=-1      AND COALESCE(c.n,0)>0 THEN NULL WHEN q.qt_cronjobs_in=-1      THEN 0 ELSE q.qt_cronjobs_in      * COALESCE(c.n,0) END) AS cronjobs,
                SUM(CASE WHEN q.qt_mysql_in=-1         AND COALESCE(c.n,0)>0 THEN NULL WHEN q.qt_mysql_in=-1         THEN 0 ELSE q.qt_mysql_in         * COALESCE(c.n,0) END) AS mysql,
                SUM(q.qt_diskspace_bi * COALESCE(c.n,0)) AS diskspace,
                SUM(q.qt_bandwidth_bi * COALESCE(c.n,0)) AS bandwidth
            FROM x_packages p
            JOIN x_quotas q ON q.qt_package_fk = p.pk_id_pk
            LEFT JOIN (
                SELECT ac_package_fk, COUNT(*) AS n
                FROM x_accounts WHERE ac_deleted_ts IS NULL
                GROUP BY ac_package_fk
            ) c ON c.ac_package_fk = p.pk_id_pk
            WHERE p.pk_reseller_fk = :uid AND p.pk_deleted_ts IS NULL AND p.pk_id_pk != :excl
        ");
        $sql->execute([':uid' => $uid, ':excl' => $exclude_pkg_id]);
        return $sql->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Comprueba si crear/actualizar un paquete con $new_q valores cabe en el pool del reseller.
     *
     * @param int   $reseller_uid    ID del reseller propietario del paquete
     * @param array $new_q           Valores del paquete (claves = columnas de x_quotas)
     * @param int   $exclude_pkg_id  Paquete excluido del committed (el que se está editando)
     * @param int   $client_count    Nº de clientes actuales en el paquete (1 para creación)
     * @return bool true = dentro del pool, false = excede
     */
    static function CheckResellerPoolForPkg($reseller_uid, $new_q, $exclude_pkg_id = 0, $client_count = 1) {
        $pool = self::GetResellerPool($reseller_uid);
        if (!$pool) return true;
        $committed = self::GetResellerCommitted($reseller_uid, $exclude_pkg_id);

        static $countFields = [
            ['pool'=>'qt_domains_in',       'comm'=>'domains',       'pkg'=>'qt_domains_in'],
            ['pool'=>'qt_subdomains_in',     'comm'=>'subdomains',    'pkg'=>'qt_subdomains_in'],
            ['pool'=>'qt_parkeddomains_in',  'comm'=>'parkeddomains', 'pkg'=>'qt_parkeddomains_in'],
            ['pool'=>'qt_mailboxes_in',      'comm'=>'mailboxes',     'pkg'=>'qt_mailboxes_in'],
            ['pool'=>'qt_fowarders_in',      'comm'=>'fowarders',     'pkg'=>'qt_fowarders_in'],
            ['pool'=>'qt_distlists_in',      'comm'=>'distlists',     'pkg'=>'qt_distlists_in'],
            ['pool'=>'qt_ftpaccounts_in',    'comm'=>'ftpaccounts',   'pkg'=>'qt_ftpaccounts_in'],
            ['pool'=>'qt_cronjobs_in',       'comm'=>'cronjobs',      'pkg'=>'qt_cronjobs_in'],
            ['pool'=>'qt_mysql_in',          'comm'=>'mysql',         'pkg'=>'qt_mysql_in'],
        ];
        foreach ($countFields as $f) {
            $pv = (int)$pool[$f['pool']];
            if ($pv === -1) continue;
            $qv = (int)($new_q[$f['pkg']] ?? 0);
            if ($qv === -1) return false;
            $cv = $committed[$f['comm']];
            if ($cv === null) return false;
            if ((int)$cv + $qv * $client_count > $pv) return false;
        }
        foreach ([
            ['pool'=>'qt_diskspace_bi','comm'=>'diskspace','pkg'=>'qt_diskspace_bi'],
            ['pool'=>'qt_bandwidth_bi','comm'=>'bandwidth', 'pkg'=>'qt_bandwidth_bi'],
        ] as $f) {
            $pv = (float)$pool[$f['pool']];
            if ($pv == 0) continue;
            $qv = (float)($new_q[$f['pkg']] ?? 0);
            if ($qv == 0) return false;
            $cv = (float)($committed[$f['comm']] ?? 0);
            if ($cv + $qv * $client_count > $pv) return false;
        }
        // PHP: son máximos por dominio, no sumas — ningún paquete puede exceder el límite del reseller
        if (!empty($pool['qt_php_memory_vc'])   && !empty($new_q['qt_php_memory_vc'])   && self::parseSizeBytes($new_q['qt_php_memory_vc'])  > self::parseSizeBytes($pool['qt_php_memory_vc']))  return false;
        if (!empty($pool['qt_php_upload_vc'])   && !empty($new_q['qt_php_upload_vc'])   && self::parseSizeBytes($new_q['qt_php_upload_vc'])  > self::parseSizeBytes($pool['qt_php_upload_vc']))  return false;
        if (!empty($pool['qt_php_post_vc'])     && !empty($new_q['qt_php_post_vc'])     && self::parseSizeBytes($new_q['qt_php_post_vc'])    > self::parseSizeBytes($pool['qt_php_post_vc']))    return false;
        if (!empty($pool['qt_php_exec_in'])     && isset($new_q['qt_php_exec_in'])      && (int)$new_q['qt_php_exec_in']     > (int)$pool['qt_php_exec_in'])     return false;
        if (!empty($pool['qt_php_maxinput_in']) && isset($new_q['qt_php_maxinput_in'])  && (int)$new_q['qt_php_maxinput_in'] > (int)$pool['qt_php_maxinput_in']) return false;

        return true;
    }

    /**
     * Comprueba si mover un cliente de $old_pkg_q a $new_pkg_q respeta el pool del reseller.
     * Calcula el total committed actual, resta 1×old, suma 1×new y compara con el pool.
     */
    static function CheckResellerPoolForMove($reseller_uid, $old_pkg_q, $new_pkg_q) {
        $pool = self::GetResellerPool($reseller_uid);
        if (!$pool) return true;
        $committed = self::GetResellerCommitted($reseller_uid, 0);

        static $countFields = [
            ['pool'=>'qt_domains_in',      'comm'=>'domains',       'old'=>'qt_domains_in',       'new'=>'qt_domains_in'],
            ['pool'=>'qt_subdomains_in',   'comm'=>'subdomains',    'old'=>'qt_subdomains_in',    'new'=>'qt_subdomains_in'],
            ['pool'=>'qt_parkeddomains_in','comm'=>'parkeddomains', 'old'=>'qt_parkeddomains_in', 'new'=>'qt_parkeddomains_in'],
            ['pool'=>'qt_mailboxes_in',    'comm'=>'mailboxes',     'old'=>'qt_mailboxes_in',     'new'=>'qt_mailboxes_in'],
            ['pool'=>'qt_fowarders_in',    'comm'=>'fowarders',     'old'=>'qt_fowarders_in',     'new'=>'qt_fowarders_in'],
            ['pool'=>'qt_distlists_in',    'comm'=>'distlists',     'old'=>'qt_distlists_in',     'new'=>'qt_distlists_in'],
            ['pool'=>'qt_ftpaccounts_in',  'comm'=>'ftpaccounts',   'old'=>'qt_ftpaccounts_in',   'new'=>'qt_ftpaccounts_in'],
            ['pool'=>'qt_cronjobs_in',     'comm'=>'cronjobs',      'old'=>'qt_cronjobs_in',      'new'=>'qt_cronjobs_in'],
            ['pool'=>'qt_mysql_in',        'comm'=>'mysql',         'old'=>'qt_mysql_in',         'new'=>'qt_mysql_in'],
        ];
        foreach ($countFields as $f) {
            $pv  = (int)$pool[$f['pool']];
            if ($pv === -1) continue;
            $new = (int)($new_pkg_q[$f['new']] ?? 0);
            if ($new === -1) return false;
            $old = (int)($old_pkg_q[$f['old']] ?? 0);
            $cv  = $committed[$f['comm']];
            if ($cv === null) return false;
            $adjusted = (int)$cv - ($old === -1 ? 0 : $old) + $new;
            if ($adjusted > $pv) return false;
        }
        foreach ([
            ['pool'=>'qt_diskspace_bi','comm'=>'diskspace','old'=>'qt_diskspace_bi','new'=>'qt_diskspace_bi'],
            ['pool'=>'qt_bandwidth_bi','comm'=>'bandwidth', 'old'=>'qt_bandwidth_bi','new'=>'qt_bandwidth_bi'],
        ] as $f) {
            $pv  = (float)$pool[$f['pool']];
            if ($pv == 0) continue;
            $new = (float)($new_pkg_q[$f['new']] ?? 0);
            if ($new == 0) return false;
            $old = (float)($old_pkg_q[$f['old']] ?? 0);
            $cv  = (float)($committed[$f['comm']] ?? 0);
            if ($cv - $old + $new > $pv) return false;
        }
        // PHP max: verificar el nuevo paquete no excede el límite del reseller
        if (!empty($pool['qt_php_memory_vc'])   && !empty($new_pkg_q['qt_php_memory_vc'])   && self::parseSizeBytes($new_pkg_q['qt_php_memory_vc'])  > self::parseSizeBytes($pool['qt_php_memory_vc']))  return false;
        if (!empty($pool['qt_php_upload_vc'])   && !empty($new_pkg_q['qt_php_upload_vc'])   && self::parseSizeBytes($new_pkg_q['qt_php_upload_vc'])  > self::parseSizeBytes($pool['qt_php_upload_vc']))  return false;
        if (!empty($pool['qt_php_post_vc'])     && !empty($new_pkg_q['qt_php_post_vc'])     && self::parseSizeBytes($new_pkg_q['qt_php_post_vc'])    > self::parseSizeBytes($pool['qt_php_post_vc']))    return false;
        if (!empty($pool['qt_php_exec_in'])     && isset($new_pkg_q['qt_php_exec_in'])      && (int)$new_pkg_q['qt_php_exec_in']     > (int)$pool['qt_php_exec_in'])     return false;
        if (!empty($pool['qt_php_maxinput_in']) && isset($new_pkg_q['qt_php_maxinput_in'])  && (int)$new_pkg_q['qt_php_maxinput_in'] > (int)$pool['qt_php_maxinput_in']) return false;
        return true;
    }

    private static function parseSizeBytes(string $s): int {
        $s = trim($s); $unit = strtolower(substr($s, -1)); $val = (int)$s;
        switch ($unit) { case 'g': return $val*1073741824; case 'm': return $val*1048576; case 'k': return $val*1024; default: return $val; }
    }

    // -----------------------------------------------------------------------

    static function CheckUserEmailIsUnique($email) {
        global $zdbh;
            $sql = "SELECT COUNT(*) FROM x_accounts WHERE LOWER(ac_email_vc)=:email AND ac_deleted_ts IS NULL";
            $uniqueuser = $zdbh->prepare($sql);
            $uniqueuser->bindParam(':email', $email);
            if ($uniqueuser->execute()) {
                if ($uniqueuser->fetchColumn() > 0) {
                    return false;
                } else {
                    return true;
                }
            } else {
                return false;
            }

        }
    }
?>

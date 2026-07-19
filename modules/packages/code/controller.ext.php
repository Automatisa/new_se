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

    static $error;
    static $alreadyexists;
    static $badname;
    static $blank;
    static $ok;
    static $edit;
    static $samepackage;
    static $poolexceeded;

    /**
     * The 'worker' methods.
     */
	# get package quotas - tgates
	static function GetQuotas($pkg_quotas)
	{
		foreach($pkg_quotas as $pkg_quota) {
			# remove unused keys
			unset($pkg_quota['packageid']);
			unset($pkg_quota['PHPChecked']);
			unset($pkg_quota['packagename']);
			# make the array managable
			$pkg_quota = json_encode($pkg_quota);
			# make values translatable and readable
			$pkg_quota = str_replace('enablePHP', ui_language::translate('PHP Enabled'), $pkg_quota);
			$pkg_quota = str_replace('domains', ui_language::translate('Domains'), $pkg_quota);
			$pkg_quota = str_replace('subDomains', ui_language::translate('Sub Domains'), $pkg_quota);
			$pkg_quota = str_replace('parkedDomains', ui_language::translate('Park Domains'), $pkg_quota);
			$pkg_quota = str_replace('fowarders', ui_language::translate('Mail Forwarders'), $pkg_quota);
			$pkg_quota = str_replace('distlists', ui_language::translate('Distribution Lists'), $pkg_quota);
			$pkg_quota = str_replace('ftpaccounts', ui_language::translate('FTP Accounts'), $pkg_quota);
			$pkg_quota = str_replace('cronjobs', ui_language::translate('Cron Jobs'), $pkg_quota);
			$pkg_quota = str_replace('mysql', ui_language::translate('Databases'), $pkg_quota);
			$pkg_quota = str_replace('diskquota', ui_language::translate('Disc Quota'), $pkg_quota);
			$pkg_quota = str_replace('bandquota', ui_language::translate('Bandwidth Quota'), $pkg_quota);
			$pkg_quota = str_replace('mailboxes', ui_language::translate('Mailboxes'), $pkg_quota);
			$pkg_quota = str_replace('phpmemory', ui_language::translate('PHP Memory'), $pkg_quota);
			$pkg_quota = str_replace('phpupload', ui_language::translate('PHP Upload'), $pkg_quota);
			$pkg_quota = str_replace('phppost', ui_language::translate('PHP Post'), $pkg_quota);
			$pkg_quota = str_replace('phpexec', ui_language::translate('PHP Exec Time'), $pkg_quota);
			$pkg_quota = str_replace('phpmaxinput', ui_language::translate('PHP Max Input'), $pkg_quota);
			# clean up the string
			$pkg_quota = str_replace('{', '', $pkg_quota);
			$pkg_quota = str_replace('}', '', $pkg_quota);
			$pkg_quota = str_replace('"', '', $pkg_quota);
			$pkg_quota = str_replace(':', ' &nbsp;= &nbsp;', $pkg_quota);
			$pkg_quota = str_replace('-1', ui_language::translate('Unlimited'), $pkg_quota);
			$pkg_quota = str_replace(',', '<br>', $pkg_quota);
		}
		return $pkg_quota;
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
            while ($rowpackages = $sql->fetch()) {
                //$numrows = $zdbh->query("SELECT COUNT(*) FROM x_accounts WHERE ac_package_fk=" . $rowpackages['pk_id_pk'] . " AND ac_deleted_ts IS NULL")->fetchColumn();
                $numrows = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_package_fk=:pk_id_pk AND ac_deleted_ts IS NULL");
                $numrows->bindParam(':pk_id_pk', $rowpackages['pk_id_pk']);
                $numrows->execute();
                $Column = $numrows->fetchColumn();
                array_push($res, array(
					'packageid' => $rowpackages['pk_id_pk'],
                    'created' => date(ctrl_options::GetSystemOption('bulwark_df'), $rowpackages['pk_created_ts']),
                    'clients' => $Column[0],
                    'packagename' => ui_language::translate($rowpackages['pk_name_vc']),
					# get package quotas - tgates
					$pkg_quotas = self::ListCurrentPackage($rowpackages['pk_id_pk']),
					'pkg_quota' => self::GetQuotas($pkg_quotas)
					));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ListCurrentPackage($id)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_packages
				LEFT JOIN x_quotas  ON (x_packages.pk_id_pk=x_quotas.qt_package_fk)
				WHERE pk_id_pk=:id AND pk_deleted_ts IS NULL";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':id', $id);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':id', $id);
            $res = array();
            $sql->execute();
            while ($rowpackages = $sql->fetch()) {
                $PHPChecked = "";
                if ($rowpackages['pk_enablephp_in'] <> 0) {
                    $PHPChecked = "CHECKED";
                }
                array_push($res, array('packageid' => $rowpackages['pk_id_pk'],
                    'enablePHP' => $rowpackages['pk_enablephp_in'],
                    'PHPChecked' => $PHPChecked,
                    'domains' => $rowpackages['qt_domains_in'],
                    'subdomains' => $rowpackages['qt_subdomains_in'],
                    'parkeddomains' => $rowpackages['qt_parkeddomains_in'],
                    'fowarders' => $rowpackages['qt_fowarders_in'],
                    'distlists' => $rowpackages['qt_distlists_in'],
                    'ftpaccounts' => $rowpackages['qt_ftpaccounts_in'],
                    'cronjobs' => $rowpackages['qt_cronjobs_in'],
                    'mysql' => $rowpackages['qt_mysql_in'],
                    'diskquota' => ($rowpackages['qt_diskspace_bi'] / 1024000),
                    'bandquota' => ($rowpackages['qt_bandwidth_bi'] / 1024000),
                    'mailboxes' => $rowpackages['qt_mailboxes_in'],
                    'phpmemory' => $rowpackages['qt_php_memory_vc'],
                    'phpupload' => $rowpackages['qt_php_upload_vc'],
                    'phppost' => $rowpackages['qt_php_post_vc'],
                    'phpexec' => $rowpackages['qt_php_exec_in'],
                    'phpmaxinput' => $rowpackages['qt_php_maxinput_in'],
                    'maxproc' => $rowpackages['qt_maxproc_in'],
                    'maxmem' => $rowpackages['qt_maxmem_vc'],
                    'pcpu' => $rowpackages['qt_pcpu_in'],
                    'maxbackups' => $rowpackages['qt_backups_in'],
                    'maxbackupsremote' => $rowpackages['qt_backups_remote_in'],
                    'dbquota' => $rowpackages['qt_dbquota_in'],
                    'dedicatedips' => $rowpackages['qt_dedicatedips_in'] ?? 0,
                    'packagename' => stripslashes($rowpackages['pk_name_vc'])));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function ExecuteDeletePackage($pk_id_pk, $mpk_id_pk)
    {
        global $zdbh;

        $sql = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_package_fk=:packageid AND ac_deleted_ts IS NULL");
        $sql->bindParam(':packageid', $pk_id_pk);
        $sql->execute();
        $numrows = $sql->fetchAll();
        if ($numrows[0] <> 0) {
            if ($pk_id_pk == $mpk_id_pk) {
                self::$samepackage = true;
                return false;
            }
        }
        runtime_hook::Execute('OnBeforeDeletePackage');
        $sql = $zdbh->prepare("
            UPDATE x_accounts
            SET ac_package_fk = :mpk_id_pk
            WHERE ac_package_fk =:pk_id_pk");
        $sql->bindParam(':mpk_id_pk', $mpk_id_pk);
        $sql->bindParam(':pk_id_pk', $pk_id_pk);
        $sql->execute();
        $sql = $zdbh->prepare("
            UPDATE x_profiles
            SET ud_package_fk = :mpk_id_pk
            WHERE ud_package_fk = :pk_id_pk");
        $sql->bindParam(':mpk_id_pk', $mpk_id_pk);
        $sql->bindParam(':pk_id_pk', $pk_id_pk);
        $sql->execute();
        $sql = $zdbh->prepare("
			UPDATE x_packages
			SET pk_deleted_ts = :time
			WHERE pk_id_pk = :pk_id_pk");
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':pk_id_pk', $pk_id_pk);
        $sql->execute();
        runtime_hook::Execute('OnAfterDeletePackage');
        self::$ok = true;
        return true;
    }

    static function ExecuteCreatePackage($uid, array $pkg)
    {
        global $zdbh;
        extract($pkg, EXTR_SKIP);
        if (fs_director::CheckForEmptyValue(self::CheckNumeric($pkg))) {
            return false;
        }
        if (!self::CheckPhpSize($PhpMemory) || !self::CheckPhpSize($PhpUpload) || !self::CheckPhpSize($PhpPost)) {
            self::$error = true;
            return false;
        }
        // Verificar que los valores del paquete caben en el pool del reseller (mín. 1 cliente).
        $new_quotas = [
            'qt_domains_in' => (int)$Domains, 'qt_subdomains_in' => (int)$SubDomains,
            'qt_parkeddomains_in' => (int)$ParkedDomains, 'qt_mailboxes_in' => (int)$Mailboxes,
            'qt_fowarders_in' => (int)$Fowarders, 'qt_distlists_in' => (int)$DistLists,
            'qt_ftpaccounts_in' => (int)$FTPAccounts, 'qt_cronjobs_in' => (int)$CronJobs,
            'qt_mysql_in' => (int)$MySQL,
            'qt_diskspace_bi' => (float)$DiskQuota * 1024000,
            'qt_bandwidth_bi' => (float)$BandQuota * 1024000,
            'qt_php_memory_vc' => $PhpMemory, 'qt_php_upload_vc' => $PhpUpload,
            'qt_php_post_vc' => $PhpPost, 'qt_php_exec_in' => (int)$PhpExec,
            'qt_php_maxinput_in' => (int)$PhpMaxInput,
        ];
        if (!ctrl_users::CheckResellerPoolForPkg($uid, $new_quotas, 0, 1)) {
            self::$poolexceeded = true;
            return false;
        }
        $packagename = str_replace(' ', '', $packagename);
        // Check for errors before we continue...
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrors($packagename, $uid))) {
            return false;
        }
        runtime_hook::Execute('OnBeforeCreatePackage');
        # If the user submitted a 'new' request then we will simply add the package to the database...
        $sql = $zdbh->prepare("INSERT INTO x_packages (pk_reseller_fk,
										pk_name_vc,
										pk_enablephp_in,
										pk_created_ts) VALUES (
										:uid,
										:packagename,
										:php,
										:time);");
        $php = fs_director::GetCheckboxValue($EnablePHP);
        $sql->bindParam(':php', $php);
        $sql->bindParam(':uid', $uid);
        $time = time();
        $sql->bindParam(':time', $time);
        $pack = addslashes($packagename);
        $sql->bindParam(':packagename', $pack);
        $sql->execute();
        # Now lets pull back the package ID so we can use it in the other tables we are about to manipulate.
        //$package = $zdbh->query("SELECT * FROM x_packages WHERE pk_reseller_fk=" . $uid . " AND pk_name_vc='" . $packagename . "' AND pk_deleted_ts IS NULL")->Fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_packages WHERE pk_reseller_fk=:uid AND pk_name_vc=:packagename AND pk_deleted_ts IS NULL");
        $numrows->bindParam(':uid', $uid);
        $numrows->bindParam(':packagename', $packagename);
        $numrows->execute();
        $package = $numrows->fetch();


        $sql = $zdbh->prepare("INSERT INTO x_quotas (qt_package_fk,
										qt_domains_in,
										qt_subdomains_in,
										qt_parkeddomains_in,
										qt_mailboxes_in,
										qt_fowarders_in,
										qt_distlists_in,
										qt_ftpaccounts_in,
										qt_cronjobs_in,
										qt_mysql_in,
										qt_php_memory_vc,
										qt_php_upload_vc,
										qt_php_post_vc,
										qt_php_exec_in,
										qt_php_maxinput_in,
										qt_maxproc_in,
										qt_maxmem_vc,
										qt_pcpu_in,
										qt_backups_in,
										qt_backups_remote_in,
										qt_dbquota_in,
										qt_dedicatedips_in,
										qt_diskspace_bi,
										qt_bandwidth_bi) VALUES (
										:pk_id_pk,
										:Domains,
										:SubDomains,
										:ParkedDomains,
										:Mailboxes,
										:Fowarders,
										:DistLists,
										:FTPAccounts,
										:CronJobs,
										:MySQL,
										:PhpMemory,
										:PhpUpload,
										:PhpPost,
										:PhpExec,
										:PhpMaxInput,
										:MaxProc,
										:MaxMem,
										:Pcpu,
										:MaxBackups,
										:MaxBackupsRemote,
										:DbQuota,
										:DedicatedIps,
										:DiskQuotaFinal,
										:BandQuotaFinal)");
        $DiskQuotaFinal = $DiskQuota * 1024000;
        $BandQuotaFinal = $BandQuota * 1024000;
        $sql->bindParam(':DiskQuotaFinal', $DiskQuotaFinal);
        $sql->bindParam(':BandQuotaFinal', $BandQuotaFinal);
        $sql->bindParam(':MySQL', $MySQL);
        $sql->bindParam(':CronJobs', $CronJobs);
        $sql->bindParam(':DistLists', $DistLists);
        $sql->bindParam(':Fowarders', $Fowarders);
        $sql->bindParam(':Mailboxes', $Mailboxes);
        $sql->bindParam(':SubDomains', $SubDomains);
        $sql->bindParam(':FTPAccounts', $FTPAccounts);
        $sql->bindParam(':ParkedDomains', $ParkedDomains);
        $sql->bindParam(':Domains', $Domains);
        $sql->bindParam(':PhpMemory', $PhpMemory);
        $sql->bindParam(':PhpUpload', $PhpUpload);
        $sql->bindParam(':PhpPost', $PhpPost);
        $sql->bindParam(':PhpExec', $PhpExec);
        $sql->bindParam(':PhpMaxInput', $PhpMaxInput);
        $MaxProc = max(0, (int)$MaxProc);
        $Pcpu    = min(100, max(0, (int)$Pcpu));
        $MaxMem  = preg_match('/^\d+[KMGkmg]?$/', trim((string)$MaxMem)) ? trim((string)$MaxMem) : '1G';
        // Techo por reseller: no puede asignar más recursos de los que tiene su propio paquete.
        self::CapLimitsToReseller($uid, $MaxProc, $MaxMem, $Pcpu);
        $sql->bindParam(':MaxProc', $MaxProc);
        $sql->bindParam(':MaxMem', $MaxMem);
        $sql->bindParam(':Pcpu', $Pcpu);
        $MaxBackups = max(0, (int)$MaxBackups);
        $sql->bindParam(':MaxBackups', $MaxBackups);
        $MaxBackupsRemote = max(0, (int)$MaxBackupsRemote);
        $sql->bindParam(':MaxBackupsRemote', $MaxBackupsRemote);
        $DbQuota = max(0, (int)$DbQuota);
        $sql->bindParam(':DbQuota', $DbQuota);
        $DedicatedIps = (isset($DedicatedIps) && (int)$DedicatedIps < 0) ? -1 : max(0, (int)($DedicatedIps ?? 0));
        $sql->bindParam(':DedicatedIps', $DedicatedIps);
        $sql->bindParam(':pk_id_pk', $package['pk_id_pk']);
        $sql->execute();
        runtime_hook::Execute('OnAfterCreatePackage');
        self::$ok = true;
        return true;
    }

    static function ExecuteUpdatePackage($uid, $pid, array $pkg)
    {
        global $zdbh;
        extract($pkg, EXTR_SKIP);
        if (fs_director::CheckForEmptyValue(self::CheckNumeric($pkg))) {
            return false;
        }
        if (!self::CheckPhpSize($PhpMemory) || !self::CheckPhpSize($PhpUpload) || !self::CheckPhpSize($PhpPost)) {
            self::$error = true;
            return false;
        }
        // Nº de clientes actuales usando este paquete (mínimo 1 para reservar al menos 1 slot)
        $cnt_sql = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_package_fk=:pid AND ac_deleted_ts IS NULL");
        $cnt_sql->execute([':pid' => $pid]);
        $client_count = max(1, (int)$cnt_sql->fetchColumn());
        $new_quotas = [
            'qt_domains_in' => (int)$Domains, 'qt_subdomains_in' => (int)$SubDomains,
            'qt_parkeddomains_in' => (int)$ParkedDomains, 'qt_mailboxes_in' => (int)$Mailboxes,
            'qt_fowarders_in' => (int)$Fowarders, 'qt_distlists_in' => (int)$DistLists,
            'qt_ftpaccounts_in' => (int)$FTPAccounts, 'qt_cronjobs_in' => (int)$CronJobs,
            'qt_mysql_in' => (int)$MySQL,
            'qt_diskspace_bi' => (float)$DiskQuota * 1024000,
            'qt_bandwidth_bi' => (float)$BandQuota * 1024000,
            'qt_php_memory_vc' => $PhpMemory, 'qt_php_upload_vc' => $PhpUpload,
            'qt_php_post_vc' => $PhpPost, 'qt_php_exec_in' => (int)$PhpExec,
            'qt_php_maxinput_in' => (int)$PhpMaxInput,
        ];
        if (!ctrl_users::CheckResellerPoolForPkg($uid, $new_quotas, $pid, $client_count)) {
            self::$poolexceeded = true;
            return false;
        }
        $packagename = str_replace(' ', '', $packagename);
        // Check for errors before we continue...
        if (fs_director::CheckForEmptyValue(self::CheckCreateForErrors($packagename, $uid, $pid))) {
            return false;
        }
        runtime_hook::Execute('OnBeforeUpdatePackage');
        $sql = $zdbh->prepare("UPDATE x_packages SET pk_name_vc=:packagename,
								pk_enablephp_in = :php
								WHERE pk_id_pk  = :pid");

        $php = fs_director::GetCheckboxValue($EnablePHP);
        $sql->bindParam(':php', $php);
        $sql->bindParam(':pid', $pid);
        $sql->bindParam(':packagename', $packagename);
        $sql->execute();
        $sql = $zdbh->prepare("UPDATE x_quotas SET qt_domains_in = :Domains,
								qt_parkeddomains_in = :ParkedDomains,
								qt_ftpaccounts_in   = :FTPAccounts,
								qt_cronjobs_in      = :CronJobs,
								qt_subdomains_in    = :SubDomains,
								qt_mailboxes_in     = :Mailboxes,
								qt_fowarders_in     = :Fowarders,
								qt_distlists_in     = :DistLists,
								qt_diskspace_bi     = :DiskQuotaFinal,
								qt_bandwidth_bi     = :BandQuotaFinal,
								qt_mysql_in         = :MySQL,
								qt_php_memory_vc    = :PhpMemory,
								qt_php_upload_vc    = :PhpUpload,
								qt_php_post_vc      = :PhpPost,
								qt_php_exec_in      = :PhpExec,
								qt_php_maxinput_in  = :PhpMaxInput,
								qt_maxproc_in       = :MaxProc,
								qt_maxmem_vc        = :MaxMem,
								qt_pcpu_in          = :Pcpu,
								qt_backups_in       = :MaxBackups,
								qt_backups_remote_in = :MaxBackupsRemote,
								qt_dbquota_in       = :DbQuota,
									qt_dedicatedips_in  = :DedicatedIps
                                                                WHERE qt_package_fk = :pid");
        $DiskQuotaFinal = $DiskQuota * 1024000;
        $BandQuotaFinal = $BandQuota * 1024000;
        $sql->bindParam(':DiskQuotaFinal', $DiskQuotaFinal);
        $sql->bindParam(':BandQuotaFinal', $BandQuotaFinal);
        $sql->bindParam(':MySQL', $MySQL);
        $sql->bindParam(':CronJobs', $CronJobs);
        $sql->bindParam(':DistLists', $DistLists);
        $sql->bindParam(':Fowarders', $Fowarders);
        $sql->bindParam(':Mailboxes', $Mailboxes);
        $sql->bindParam(':SubDomains', $SubDomains);
        $sql->bindParam(':FTPAccounts', $FTPAccounts);
        $sql->bindParam(':ParkedDomains', $ParkedDomains);
        $sql->bindParam(':Domains', $Domains);
        $sql->bindParam(':PhpMemory', $PhpMemory);
        $sql->bindParam(':PhpUpload', $PhpUpload);
        $sql->bindParam(':PhpPost', $PhpPost);
        $sql->bindParam(':PhpExec', $PhpExec);
        $sql->bindParam(':PhpMaxInput', $PhpMaxInput);
        $MaxProc = max(0, (int)$MaxProc);
        $Pcpu    = min(100, max(0, (int)$Pcpu));
        $MaxMem  = preg_match('/^\d+[KMGkmg]?$/', trim((string)$MaxMem)) ? trim((string)$MaxMem) : '1G';
        // Techo por reseller: no puede asignar más recursos de los que tiene su propio paquete.
        self::CapLimitsToReseller($uid, $MaxProc, $MaxMem, $Pcpu);
        $sql->bindParam(':MaxProc', $MaxProc);
        $sql->bindParam(':MaxMem', $MaxMem);
        $sql->bindParam(':Pcpu', $Pcpu);
        $MaxBackups = max(0, (int)$MaxBackups);
        $sql->bindParam(':MaxBackups', $MaxBackups);
        $MaxBackupsRemote = max(0, (int)$MaxBackupsRemote);
        $sql->bindParam(':MaxBackupsRemote', $MaxBackupsRemote);
        $DbQuota = max(0, (int)$DbQuota);
        $sql->bindParam(':DbQuota', $DbQuota);
        $DedicatedIps = (isset($DedicatedIps) && (int)$DedicatedIps < 0) ? -1 : max(0, (int)($DedicatedIps ?? 0));
        $sql->bindParam(':DedicatedIps', $DedicatedIps);
        $sql->bindParam(':pid', $pid);
        $sql->execute();
        runtime_hook::Execute('OnAfterUpdatePackage');
        self::$ok = true;
        return true;
    }

    static function CheckCreateForErrors($packagename, $uid, $pid = 0)
    {
        global $zdbh;
        $packagename = str_replace(' ', '', $packagename);
        # Check to make sure the packagename is not blank or exists for reseller before we go any further...
        if (!fs_director::CheckForEmptyValue($packagename)) {
            $sql = "SELECT COUNT(*) FROM x_packages WHERE UPPER(pk_name_vc)=:packageNameSlashes AND pk_reseller_fk=:uid AND pk_id_pk !=:pid AND pk_deleted_ts IS NULL";
            $packageNameSlashes = addslashes(strtoupper($packagename));

            $numrows = $zdbh->prepare($sql);
            $numrows->bindParam(':packageNameSlashes', $packageNameSlashes);
            $numrows->bindParam(':uid', $uid);
            $numrows->bindParam(':pid', $pid);

            if ($numrows->execute()) {
                if ($numrows->fetchColumn() <> 0) {
                    self::$alreadyexists = true;
                    return false;
                }
            }
        } else {
            self::$blank = true;
            return false;
        }
        // Check packagename format.
        if (!self::IsValidPackageName($packagename)) {
            self::$badname = true;
            return false;
        }
        return true;
    }

    static function IsValidPackageName($packagename)
    {
        if (!preg_match('/^[a-z\d][a-z\d-]{0,62}$/i', $packagename) || preg_match('/-$/', $packagename)) {
            return false;
        }
        return true;
    }

    static function CheckNumeric(array $pkg)
    {
        $allNumeric = is_numeric($pkg['EnablePHP']) && is_numeric($pkg['Domains']) && is_numeric($pkg['SubDomains'])
            && is_numeric($pkg['ParkedDomains']) && is_numeric($pkg['Mailboxes']) && is_numeric($pkg['Fowarders'])
            && is_numeric($pkg['DistLists']) && is_numeric($pkg['FTPAccounts']) && is_numeric($pkg['CronJobs'])
            && is_numeric($pkg['MySQL']) && is_numeric($pkg['DiskQuota']) && is_numeric($pkg['BandQuota'])
            && is_numeric($pkg['PhpExec']) && is_numeric($pkg['PhpMaxInput']);
        if (!$allNumeric) {
            self::$error = true;
            return false;
        }
        $countsOk = (int)$pkg['Domains'] >= -1 && (int)$pkg['SubDomains'] >= -1 && (int)$pkg['ParkedDomains'] >= -1
            && (int)$pkg['Mailboxes'] >= -1 && (int)$pkg['Fowarders'] >= -1 && (int)$pkg['DistLists'] >= -1
            && (int)$pkg['FTPAccounts'] >= -1 && (int)$pkg['CronJobs'] >= -1 && (int)$pkg['MySQL'] >= -1;
        $quotasOk = (int)$pkg['DiskQuota'] >= 0 && (int)$pkg['BandQuota'] >= 0;
        $phpOk    = (int)$pkg['PhpExec'] >= 1 && (int)$pkg['PhpMaxInput'] >= 1;
        if (!$countsOk || !$quotasOk || !$phpOk) {
            self::$error = true;
            return false;
        }
        return true;
    }

    static function CheckPhpSize($val)
    {
        // Acepta: número + unidad opcional K/M/G, ej. '128M', '2G', '512K', '134217728'
        return (bool)preg_match('/^\d+[KMGkmg]?$/', trim((string)$val));
    }

    static function AddDefaultPackageTime($uid)
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
            $sql->execute();
            while ($rowpackages = $sql->fetch()) {
                if ($rowpackages['pk_created_ts'] == "") {
                    $add = $zdbh->prepare("UPDATE x_packages SET pk_created_ts=:time
									WHERE pk_id_pk  =:pk_id_pk");
                    $time = time();
                    $add->bindParam(':time', $time);
                    $add->bindParam(':pk_id_pk', $rowpackages['pk_id_pk']);
                    $add->execute();
                }
            }
        }
    }

    /**
     * End 'worker' methods.
     */

    /**
     * Webinterface sudo methods.
     */
    /**
     * AUTZ: ¿el paquete pertenece al usuario actual (pk_reseller_fk = su id)?
     * Sin esto, un reseller podía editar/borrar por ID (IDOR) paquetes de otro reseller o del
     * admin, y reasignar sus cuentas a un paquete arbitrario.
     */
    private static function canManagePackage($pid)
    {
        global $zdbh;
        $pid = (int) $pid;
        if ($pid <= 0) {
            return false;
        }
        $self = (int) ctrl_users::GetUserDetail()['userid'];
        $chk = $zdbh->prepare("SELECT COUNT(*) FROM x_packages WHERE pk_id_pk=:pid AND pk_reseller_fk=:uid AND pk_deleted_ts IS NULL");
        $chk->execute(array(':pid' => $pid, ':uid' => $self));
        return ((int) $chk->fetchColumn() > 0);
    }

    static function doCreatePackage()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inEnablePHP'])) {
            $EnablePHP = fs_director::GetCheckboxValue($formvars['inEnablePHP']);
        } else {
            $EnablePHP = 0;
        }
        $pkg = [
            'packagename'   => $formvars['inPackageName'],
            'EnablePHP'     => $EnablePHP,
            'Domains'       => $formvars['inNoDomains'],
            'SubDomains'    => $formvars['inNoSubDomains'],
            'ParkedDomains' => $formvars['inNoParkedDomains'],
            'Mailboxes'     => $formvars['inNoMailboxes'],
            'Fowarders'     => $formvars['inNoFowarders'],
            'DistLists'     => $formvars['inNoDistLists'],
            'FTPAccounts'   => $formvars['inNoFTPAccounts'],
            'CronJobs'      => $formvars['inNoCronJobs'],
            'MySQL'         => $formvars['inNoMySQL'],
            'DiskQuota'     => $formvars['inDiskQuota'],
            'BandQuota'     => $formvars['inBandQuota'],
            'PhpMemory'     => $formvars['inPhpMemory'],
            'PhpUpload'     => $formvars['inPhpUpload'],
            'PhpPost'       => $formvars['inPhpPost'],
            'PhpExec'       => $formvars['inPhpExec'],
            'PhpMaxInput'   => $formvars['inPhpMaxInput'],
            'MaxProc'       => isset($formvars['inMaxProc']) ? $formvars['inMaxProc'] : '100',
            'MaxMem'        => isset($formvars['inMaxMem'])  ? $formvars['inMaxMem']  : '1G',
            'Pcpu'          => isset($formvars['inPcpu'])    ? $formvars['inPcpu']    : '0',
            'MaxBackups'    => isset($formvars['inMaxBackups']) ? $formvars['inMaxBackups'] : '0',
            'MaxBackupsRemote' => isset($formvars['inMaxBackupsRemote']) ? $formvars['inMaxBackupsRemote'] : '0',
            'DbQuota'       => isset($formvars['inDbQuota']) ? $formvars['inDbQuota'] : '0',
            'DedicatedIps'  => isset($formvars['inNoDedicatedIps']) ? $formvars['inNoDedicatedIps'] : '0',
        ];
        if (self::ExecuteCreatePackage($currentuser['userid'], $pkg))
            return true;
        return false;
    }

    static function doUpdatePackage()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (isset($formvars['inEnablePHP'])) {
            $EnablePHP = fs_director::GetCheckboxValue($formvars['inEnablePHP']);
        } else {
            $EnablePHP = 0;
        }
        $pkg = [
            'packagename'   => $formvars['inPackageName'],
            'EnablePHP'     => $EnablePHP,
            'Domains'       => $formvars['inNoDomains'],
            'SubDomains'    => $formvars['inNoSubDomains'],
            'ParkedDomains' => $formvars['inNoParkedDomains'],
            'Mailboxes'     => $formvars['inNoMailboxes'],
            'Fowarders'     => $formvars['inNoFowarders'],
            'DistLists'     => $formvars['inNoDistLists'],
            'FTPAccounts'   => $formvars['inNoFTPAccounts'],
            'CronJobs'      => $formvars['inNoCronJobs'],
            'MySQL'         => $formvars['inNoMySQL'],
            'DiskQuota'     => $formvars['inDiskQuota'],
            'BandQuota'     => $formvars['inBandQuota'],
            'PhpMemory'     => $formvars['inPhpMemory'],
            'PhpUpload'     => $formvars['inPhpUpload'],
            'PhpPost'       => $formvars['inPhpPost'],
            'PhpExec'       => $formvars['inPhpExec'],
            'PhpMaxInput'   => $formvars['inPhpMaxInput'],
            'MaxProc'       => isset($formvars['inMaxProc']) ? $formvars['inMaxProc'] : '100',
            'MaxMem'        => isset($formvars['inMaxMem'])  ? $formvars['inMaxMem']  : '1G',
            'Pcpu'          => isset($formvars['inPcpu'])    ? $formvars['inPcpu']    : '0',
            'MaxBackups'    => isset($formvars['inMaxBackups']) ? $formvars['inMaxBackups'] : '0',
            'MaxBackupsRemote' => isset($formvars['inMaxBackupsRemote']) ? $formvars['inMaxBackupsRemote'] : '0',
            'DbQuota'       => isset($formvars['inDbQuota']) ? $formvars['inDbQuota'] : '0',
            'DedicatedIps'  => isset($formvars['inNoDedicatedIps']) ? $formvars['inNoDedicatedIps'] : '0',
        ];
        // AUTZ: solo se puede editar un paquete propio (IDOR fix).
        if (!self::canManagePackage($formvars['inPackageID'])) {
            return false;
        }
        if (self::ExecuteUpdatePackage($currentuser['userid'], $formvars['inPackageID'], $pkg))
            return true;
        return false;
    }

    static function doEditPackage()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        foreach (self::ListPackages($currentuser['userid']) as $row) {
            if (isset($formvars['inDelete_' . $row['packageid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $row['packageid'] . "");
                exit;
            }
            if (isset($formvars['inEdit_' . $row['packageid'] . ''])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Edit&other=" . $row['packageid'] . "");
                exit;
            }
        }
        return;
    }

    static function doDeletePackage()
    {
        global $controller;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // AUTZ: el paquete a borrar y el de destino deben ser propios (IDOR fix).
        if (!self::canManagePackage($formvars['inPackageID'])) {
            return false;
        }
        if (isset($formvars['inMovePackage']) && $formvars['inMovePackage'] !== ""
            && !self::canManagePackage($formvars['inMovePackage'])) {
            return false;
        }
        if (self::ExecuteDeletePackage($formvars['inPackageID'], $formvars['inMovePackage']))
            return true;
        return false;
    }

    static function getPackageList()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $packages = self::ListPackages($currentuser['userid']);
        if ($packages)
            return $packages;
        return false;
    }

    static function getPackageListDropdown()
    {
        $currentuser = ctrl_users::GetUserDetail();
        $packages = self::ListPackages($currentuser['userid']);
        $available = array();
        foreach ($packages as $package) {
            if ($package['packageid'] != $_GET['other']) $available[] = $package;
        }
        if (count($available) > 0)
            return $available;
        return false;
    }

    static function getisCreatePackage()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if (!isset($urlvars['show']))
            return true;
        return false;
    }

    static function getisDeletePackage()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "Delete"))
            return true;
        return false;
    }

    static function getisEditPackage()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "Edit")) {
            return true;
        } else {
            return false;
        }
    }

    static function getEditCurrentPackageName()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['packagename'];
        } else {
            return "";
        }
    }

    static function getEditCurrentPackageID()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['packageid'];
        } else {
            return "";
        }
    }

    static function getEditCurrentDomains()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['domains'];
        } else {
            return "";
        }
    }

    static function getEditCurrentSubDomains()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['subdomains'];
        } else {
            return "";
        }
    }

    static function getEditCurrentParkedDomains()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['parkeddomains'];
        } else {
            return "";
        }
    }

    static function getEditCurrentMailboxes()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['mailboxes'];
        } else {
            return "";
        }
    }

    static function getEditCurrentForwarders()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['fowarders'];
        } else {
            return "";
        }
    }

    static function getEditCurrentDistLists()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['distlists'];
        } else {
            return "";
        }
    }

    static function getEditCurrentFTP()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['ftpaccounts'];
        } else {
            return "";
        }
    }

    static function getEditCurrentMySQL()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['mysql'];
        } else {
            return "";
        }
    }

    static function getEditCurrentCronJobs()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['cronjobs'];
        } else {
            return "";
        }
    }

    static function getEditCurrentDisk()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['diskquota'];
        } else {
            return "";
        }
    }

    static function getEditCurrentBandWidth()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['bandquota'];
        } else {
            return "";
        }
    }

    static function getAddDefaultPackageTime()
    {
        $currentuser = ctrl_users::GetUserDetail();
        self::AddDefaultPackageTime($currentuser['userid']);
    }

    static function getEditCurrentPhpMemory()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['phpmemory'];
        } else {
            return '128M';
        }
    }

    static function getEditCurrentMaxProc()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['maxproc'];
        }
        return '100';
    }

    static function getEditCurrentMaxMem()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['maxmem'];
        }
        return '1G';
    }

    static function getEditCurrentMaxBackups()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['maxbackups'];
        }
        return '0';
    }

    static function getEditCurrentMaxBackupsRemote()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['maxbackupsremote'];
        }
        return '0';
    }

    static function getEditCurrentDbQuota()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['dbquota'];
        }
        return '0';
    }

    static function getEditCurrentDedicatedIps()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['dedicatedips'];
        }
        return '0';
    }

    static function getEditCurrentPcpu()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['pcpu'];
        }
        return '0';
    }

    /**
     * Techo de recursos por reseller: acota maxproc/maxmem/pcpu del paquete a los límites
     * del PROPIO paquete del reseller (un reseller no puede repartir más de lo que tiene).
     * Si el dueño no tiene paquete con límites (p.ej. admin), no se aplica techo. 0=ilimitado.
     */
    private static function CapLimitsToReseller($uid, &$maxproc, &$maxmem, &$pcpu)
    {
        global $zdbh;
        $st = $zdbh->prepare(
            "SELECT q.qt_maxproc_in, q.qt_maxmem_vc, q.qt_pcpu_in
               FROM x_accounts u
               JOIN x_profiles p ON p.ud_user_fk = u.ac_id_pk
               JOIN x_quotas   q ON q.qt_package_fk = p.ud_package_fk
              WHERE u.ac_id_pk = :uid AND u.ac_deleted_ts IS NULL LIMIT 1"
        );
        $st->execute([':uid' => (int)$uid]);
        $r = $st->fetch();
        if (!$r) {
            return;  // dueño sin paquete (admin) -> sin techo
        }
        $rp = (int)$r['qt_maxproc_in'];
        if ($rp > 0) { $maxproc = ($maxproc <= 0) ? $rp : min((int)$maxproc, $rp); }
        $rc = (int)$r['qt_pcpu_in'];
        if ($rc > 0) { $pcpu = ($pcpu <= 0) ? $rc : min((int)$pcpu, $rc); }
        $rmem = self::memBytes($r['qt_maxmem_vc']);
        if ($rmem > 0) {
            $mmem = self::memBytes($maxmem);
            if ($mmem <= 0 || $mmem > $rmem) { $maxmem = $r['qt_maxmem_vc']; }
        }
    }

    private static function memBytes($s)
    {
        if (!preg_match('/^(\d+)\s*([KMGkmg]?)/', trim((string)$s), $m)) {
            return 0;
        }
        $n = (int)$m[1];
        switch (strtoupper($m[2])) {
            case 'G': return $n * 1073741824;
            case 'M': return $n * 1048576;
            case 'K': return $n * 1024;
            default:  return $n;
        }
    }

    static function getEditCurrentPhpUpload()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['phpupload'];
        } else {
            return '50M';
        }
    }

    static function getEditCurrentPhpPost()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['phppost'];
        } else {
            return '50M';
        }
    }

    static function getEditCurrentPhpExec()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['phpexec'];
        } else {
            return '30';
        }
    }

    static function getEditCurrentPhpMaxInput()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['phpmaxinput'];
        } else {
            return '60';
        }
    }

    static function getPHPChecked()
    {
        global $controller;
        if ($controller->GetControllerRequest('URL', 'other')) {
            $current = self::ListCurrentPackage($controller->GetControllerRequest('URL', 'other'));
            return $current[0]['PHPChecked'];
        } else {
            return "";
        }
    }

    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$blank)) {
            return ui_sysmessage::shout(ui_language::translate("You need to specify a package name to create your package."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$badname)) {
            return ui_sysmessage::shout(ui_language::translate("Your package name is not valid. Please enter a valid package name."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("A package with that name already appears to exsist."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$error)) {
            return ui_sysmessage::shout(ui_language::translate("There was an error updating your packages"), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$samepackage)) {
            return ui_sysmessage::shout(ui_language::translate("You cant move clients to the same package you are deleting!"), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$poolexceeded)) {
            return ui_sysmessage::shout(ui_language::translate("The package values exceed the resource pool assigned to your reseller account. Please reduce the quotas or request a package upgrade from the administrator."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your packages have been saved successfully!"), "zannounceok");
        }
        return;
    }

    /**
     * Webinterface sudo methods.
     */
}

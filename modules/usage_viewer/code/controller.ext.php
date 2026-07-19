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
 * @change P.Peyremorte added unlimited, factorization in functions
 */
class module_controller extends ctrl_module
{

    static $diskquota;
    static $diskspace;
    static $bandwidth;
    static $bandwidthquota;
    static $domains;
    static $domainsquota;
    static $subdomains;
    static $subdomainsquota;
    static $parkeddomains;
    static $parkeddomainsquota;
    static $mysql;
    static $mysqlquota;
    static $ftpaccounts;
    static $ftpaccountsquota;
    static $mailboxes;
    static $mailboxquota;
    static $forwarders;
    static $forwardersquota;
    static $distlists;
    static $distlistsquota;
    static $cronjobs;
    static $cronjobsquota;
    static $backups;
    static $backupsquota;
    static $dbsize;
    static $dbsizequota;

    static private function check_pChart($display)
    {
        return $display;
    }

    static function getUsage()
    {
        return self::check_pChart(self::DisplayUsagepChart());
    }

    static function getDomainsUsage()
    {
        return self::check_pChart(self::DisplayDomainsUsagepChart());
    }

    static function getSubDomainsUsage()
    {
        return self::check_pChart(self::DisplaySubDomainsUsagepChart());
    }

    static function getParkedDomainsUsage()
    {
        return self::check_pChart(self::DisplayParkedDomainsUsagepChart());
    }

    static function getMysqlUsage()
    {
        return self::check_pChart(self::DisplayMysqlUsagepChart());
    }

    static function getFTPUsage()
    {
        return self::check_pChart(self::DisplayFTPUsagepChart());
    }

    static function getMailboxUsage()
    {
        return self::check_pChart(self::DisplayMailboxUsagepChart());
    }

    static function getForwardersUsage()
    {
        return self::check_pChart(self::DisplayForwardersUsagepChart());
    }

    static function getDistListUsage()
    {
        return self::check_pChart(self::DisplayDistListUsagepChart());
    }

    static function getCronjobsUsage()
    {
        return self::check_pChart(self::DisplayCronjobsUsagepChart());
    }

    static function getBackupsUsage()
    {
        return self::check_pChart(self::DisplayBackupsUsagepChart());
    }

    static function getDbSizeUsage()
    {
        return self::check_pChart(self::DisplayDbSizeUsagepChart());
    }

    #Begin Display Methods

    static function empty_as_0($value)
    {
        return (empty($value)) ? 0 : $value;
    }

    static function build_row_usage($name, $used, $quota, $human = false)
    {
        $res = '<tr><th nowrap="nowrap">' . ui_language::translate($name) . ':</th><td nowrap="nowrap">' . (($human) ? fs_director::ShowHumanFileSize($used) : $used);
        if ($quota < 0) {
            $res .= '</td><td style="text-align:center">&#8734; ' . ui_language::translate('Unlimited') . ' &#8734;</td>';
        } else {
            $res .= ' / ' . (($human) ? fs_director::ShowHumanFileSize($quota) : $quota) . '</td><td><img src="etc/lib/charts/svg_progress.php?percent=' . (($quota == 0 or $used == $quota) ? 100 : round($used / $quota * 100, 0)) . '"/>';
        }
        return $res . '</td></tr>';
    }

    static function DisplayUsagepChart()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();

        self::$diskquota = $currentuser['diskquota'];
        self::$diskspace = ctrl_users::GetQuotaUsages('diskspace', $currentuser['userid']);

        self::$bandwidthquota = module_controller::empty_as_0($currentuser['bandwidthquota']);
        self::$bandwidth = ctrl_users::GetQuotaUsages('bandwidth', $currentuser['userid']);

        self::$domainsquota = module_controller::empty_as_0($currentuser['domainquota']);
        self::$domains = ctrl_users::GetQuotaUsages('domains', $currentuser['userid']);

        self::$subdomainsquota = module_controller::empty_as_0($currentuser['subdomainquota']);
        self::$subdomains = ctrl_users::GetQuotaUsages('subdomains', $currentuser['userid']);

        self::$parkeddomainsquota = module_controller::empty_as_0($currentuser['parkeddomainquota']);
        self::$parkeddomains = ctrl_users::GetQuotaUsages('parkeddomains', $currentuser['userid']);

        self::$mysqlquota = module_controller::empty_as_0($currentuser['mysqlquota']);
        self::$mysql = ctrl_users::GetQuotaUsages('mysql', $currentuser['userid']);

        self::$ftpaccountsquota = module_controller::empty_as_0($currentuser['ftpaccountsquota']);
        self::$ftpaccounts = ctrl_users::GetQuotaUsages('ftpaccounts', $currentuser['userid']);

        self::$mailboxquota = module_controller::empty_as_0($currentuser['mailboxquota']);
        self::$mailboxes = ctrl_users::GetQuotaUsages('mailboxes', $currentuser['userid']);

        self::$forwardersquota = module_controller::empty_as_0($currentuser['forwardersquota']);
        self::$forwarders = ctrl_users::GetQuotaUsages('forwarders', $currentuser['userid']);

        self::$distlistsquota = $currentuser['distlistsquota'];
        self::$distlists = module_controller::empty_as_0(ctrl_users::GetQuotaUsages('distlists', $currentuser['userid']));

        // Cron jobs (hueco previo en la vista).
        self::$cronjobsquota = module_controller::empty_as_0($currentuser['cronjobquota']);
        self::$cronjobs = ctrl_users::GetQuotaUsages('cronjobs', $currentuser['userid']);

        // Nuevos límites del paquete (0 = ilimitado en BD -> -1 para el gráfico "∞").
        $qx = $zdbh->prepare("SELECT COALESCE(q.qt_backups_in,0) AS b, COALESCE(q.qt_dbquota_in,0) AS d
                                FROM x_accounts a JOIN x_quotas q ON q.qt_package_fk = a.ac_package_fk
                               WHERE a.ac_id_pk = :u LIMIT 1");
        $qx->execute(array(':u' => $currentuser['userid']));
        $qrow = $qx->fetch(PDO::FETCH_ASSOC) ?: array('b' => 0, 'd' => 0);

        // Copias de seguridad locales (nº de .zip en home/backups vs límite del paquete).
        if (!class_exists('sys_backup_retention')) {
            require_once '/usr/local/bulwark/dryden/sys/backup_retention.class.php';
        }
        self::$backups = count(sys_backup_retention::listLocal($currentuser['username']));
        self::$backupsquota = ((int)$qrow['b'] === 0) ? -1 : (int)$qrow['b'];

        // Tamaño de bases de datos (MB usados vs límite del paquete en MB).
        if (!class_exists('mysql_quota_manager')) {
            require_once '/usr/local/bulwark/dryden/sys/mysql_quota_manager.class.php';
        }
        self::$dbsize = (int)round(mysql_quota_manager::accountDbSize($currentuser['userid']) / 1048576);
        self::$dbsizequota = ((int)$qrow['d'] === 0) ? -1 : (int)$qrow['d'];

        $maximum = self::$diskquota;
        $used = self::$diskspace;
        if ($maximum == 0) {
            $free = disk_free_space(ctrl_options::GetOption('hosted_dir'));
            $freeLabel = fs_director::ShowHumanFileSize($free);
            $freeNote  = ui_language::translate('Free space corresponds to the server disk');
        } else {
            $free = max($maximum - $used, 0);
            $freeLabel = fs_director::ShowHumanFileSize($free);
            $freeNote  = '';
        }
        $usedLabel = fs_director::ShowHumanFileSize($used);


        $line = '<div class="row g-4 align-items-start">' .
                '<div class="col-lg-5">' .
                '<h2>' . ui_language::translate('Disk Usage Total') . '</h2>' .
                '<div class="text-center">' .
                '<img class="img-fluid" style="max-width:340px;width:100%;height:auto;" src="etc/lib/charts/svg_pie.php?score=' . $free . '::' . $used .
                '&amp;imagesize=340::200' .
                '&amp;labels=Free:_' . $freeLabel . '::Used:_' . $usedLabel . '"/>' .
                ($freeNote !== '' ? '<p class="help-block" style="margin-top:6px;">' . $freeNote . '</p>' : '') .
                '</div>' .
                '</div>' .
                '<div class="col-lg-7">' .
                '<h2>' . ui_language::translate('Package Usage Total') . '</h2>' .
                '<div class="table-responsive">' .
                '<table class="table table-striped" border="0" cellspacing="0" cellpadding="0">' .
                module_controller::build_row_usage('Disk space', self::$diskspace, (self::$diskquota == 0) ? -1 : self::$diskquota, true) .
                module_controller::build_row_usage('Bandwidth', self::$bandwidth, (self::$bandwidthquota == 0) ? -1 : self::$bandwidthquota, true) .
                module_controller::build_row_usage('Domains', self::$domains, self::$domainsquota) .
                module_controller::build_row_usage('Sub-domains', self::$subdomains, self::$subdomainsquota) .
                module_controller::build_row_usage('Parked domains', self::$parkeddomains, self::$parkeddomainsquota) .
                module_controller::build_row_usage('FTP accounts', self::$ftpaccounts, self::$ftpaccountsquota) .
                module_controller::build_row_usage('MySQL&reg databases', self::$mysql, self::$mysqlquota) .
                module_controller::build_row_usage('Mailboxes', self::$mailboxes, self::$mailboxquota) .
                module_controller::build_row_usage('Mail forwarders', self::$forwarders, self::$forwardersquota) .
                module_controller::build_row_usage('Distribution lists', self::$distlists, self::$distlistsquota) .
                '</table>' .
                '</div>' .
                '</div>' .
                '</div>';
        return $line;
    }

    static private function DisplayChart($name, $used, $maximum)
    {
		global $controller;
        if ($maximum < 0) { //-1 = unlimited
            $res = '<img src="modules/' . $controller->GetControllerRequest('URL', 'module') . '/assets/unlimited.png" alt="' . ui_language::translate('Unlimited') . '"/>';
        } else {
            $free = max($maximum - $used, 0);
            $res = '<img src="etc/lib/charts/svg_pie.php?score=' . $free . '::' . $used
                    . '&amp;imagesize=240::190'
                    . '&amp;labels=Free:_' . $free . '::Used:_' . $used . '"'
                    . ' alt="' . ui_language::translate('Pie chart') . '"/>';
        }
        return '<h2>' . ui_language::translate($name) . '</h2>' . $res;
    }

    static function DisplayDomainsUsagepChart()
    {
        return self::DisplayChart('Domain Usage', self::$domains, self::$domainsquota);
    }

    static function DisplaySubDomainsUsagepChart()
    {
        return self::DisplayChart('Sub-Domain Usage', self::$subdomains, self::$subdomainsquota);
    }

    static function DisplayParkedDomainsUsagepChart()
    {
        return self::DisplayChart('Parked-Domain Usage', self::$parkeddomains, self::$parkeddomainsquota);
    }

    static function DisplayMysqlUsagepChart()
    {
        return self::DisplayChart('MySQL&reg Database Usage', self::$mysql, self::$mysqlquota);
    }

    static function DisplayCronjobsUsagepChart()
    {
        return self::DisplayChart('Cron Jobs Usage', self::$cronjobs, self::$cronjobsquota);
    }

    static function DisplayBackupsUsagepChart()
    {
        return self::DisplayChart('Local Backups', self::$backups, self::$backupsquota);
    }

    static function DisplayDbSizeUsagepChart()
    {
        return self::DisplayChart('Database Size (MB)', self::$dbsize, self::$dbsizequota);
    }

    static function DisplayMailboxUsagepChart()
    {
        return self::DisplayChart('Mailbox Usage', self::$mailboxes, self::$mailboxquota);
    }

    static function DisplayFTPUsagepChart()
    {
        return self::DisplayChart('FTP Usage', self::$ftpaccounts, self::$ftpaccountsquota);
    }

    static function DisplayForwardersUsagepChart()
    {
        return self::DisplayChart('Forwarders Usage', self::$forwarders, self::$forwardersquota);
    }

    static function DisplayDistListUsagepChart()
    {
        return self::DisplayChart('Distribution List Usage', self::$distlists, self::$distlistsquota);
    }

    static function getBandwidthHistory()
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail();
        $userid      = (int)$currentuser['userid'];
        $groupid     = (int)$currentuser['usergroupid'];

        if ($groupid === 1) {
            // Admin: aggregate all accounts on the server
            $sql = $zdbh->prepare(
                "SELECT bd_month_in,
                        SUM(bd_transamount_bi) AS bd_transamount_bi,
                        SUM(bd_diskamount_bi)  AS bd_diskamount_bi
                 FROM x_bandwidth
                 GROUP BY bd_month_in
                 ORDER BY bd_month_in ASC"
            );
        } elseif ($groupid === 2) {
            // Reseller: aggregate own account + all sub-user accounts
            $subsql = $zdbh->prepare(
                "SELECT ac_id_pk FROM x_accounts
                 WHERE (ac_id_pk = :uid OR ac_reseller_fk = :uid2)
                   AND ac_deleted_ts IS NULL"
            );
            $subsql->bindValue(':uid',  $userid, PDO::PARAM_INT);
            $subsql->bindValue(':uid2', $userid, PDO::PARAM_INT);
            $subsql->execute();
            $ids = array_column($subsql->fetchAll(PDO::FETCH_ASSOC), 'ac_id_pk');
            if (empty($ids)) {
                $ids = [$userid];
            }
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $sql = $zdbh->prepare(
                "SELECT bd_month_in,
                        SUM(bd_transamount_bi) AS bd_transamount_bi,
                        SUM(bd_diskamount_bi)  AS bd_diskamount_bi
                 FROM x_bandwidth
                 WHERE bd_acc_fk IN ($ph)
                 GROUP BY bd_month_in
                 ORDER BY bd_month_in ASC"
            );
            foreach ($ids as $i => $id) {
                $sql->bindValue($i + 1, $id, PDO::PARAM_INT);
            }
        } else {
            // Regular user: own data only
            $sql = $zdbh->prepare(
                "SELECT bd_month_in, bd_transamount_bi, bd_diskamount_bi
                 FROM x_bandwidth
                 WHERE bd_acc_fk = :uid
                 ORDER BY bd_month_in ASC"
            );
            $sql->bindValue(':uid', $userid, PDO::PARAM_INT);
        }

        $sql->execute();
        $rows = $sql->fetchAll(PDO::FETCH_ASSOC);

        $currentYear = (int)date('Y');

        // Build per-year dataset: year => [12 months] with zeros for missing months
        $yearData = [];
        foreach ($rows as $row) {
            $ym   = (string)$row['bd_month_in'];
            $year = (int)substr($ym, 0, 4);
            $m    = (int)substr($ym, 4, 2) - 1; // 0-indexed
            if (!isset($yearData[$year])) {
                $yearData[$year] = array_fill(0, 12, ['bw' => 0, 'disk' => 0]);
            }
            $yearData[$year][$m] = [
                'bw'   => round((float)$row['bd_transamount_bi'] / 1048576, 2),
                'disk' => round((float)$row['bd_diskamount_bi']  / 1048576, 2),
            ];
        }

        // Always include current year even if no data yet
        if (!isset($yearData[$currentYear])) {
            $yearData[$currentYear] = array_fill(0, 12, ['bw' => 0, 'disk' => 0]);
        }
        ksort($yearData);

        $years       = array_keys($yearData);
        $defaultYear = $years[count($years) - 1];
        $dataJson    = json_encode($yearData, JSON_UNESCAPED_UNICODE);
        $monthJson   = json_encode(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']);

        $options = '';
        foreach (array_reverse($years) as $y) {
            $sel      = ($y === $defaultYear) ? ' selected' : '';
            $options .= '<option value="' . $y . '"' . $sel . '>' . $y . '</option>';
        }

        $html  = '<div style="margin-top:24px;border-top:1px solid #ddd;padding-top:16px;">';
        $html .= '<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">';
        $html .= '<h2 style="margin:0;">' . ui_language::translate('Usage History') . '</h2>';
        $html .= '<select id="zpx_year_sel" style="font-size:13px;padding:3px 8px;border:1px solid #ccc;border-radius:3px;">' . $options . '</select>';
        $html .= '</div>';
        $html .= '<div style="display:flex;gap:24px;flex-wrap:wrap;">';

        $html .= '<div style="flex:1;min-width:280px;">';
        $html .= '<h3 style="font-size:13px;color:#555;margin-bottom:6px;">Bandwidth (MB)</h3>';
        $html .= '<div style="position:relative;height:150px;"><canvas id="zpx_bw_chart"></canvas></div>';
        $html .= '</div>';

        $html .= '<div style="flex:1;min-width:280px;">';
        $html .= '<h3 style="font-size:13px;color:#555;margin-bottom:6px;">Disk Usage (MB)</h3>';
        $html .= '<div style="position:relative;height:150px;"><canvas id="zpx_disk_chart"></canvas></div>';
        $html .= '</div>';

        $html .= '</div></div>';

        $html .= '<script src="etc/lib/charts/chart.min.js"></script>';
        $html .= '<script>(function(){';
        $html .= 'var zpxM=' . $monthJson . ';';
        $html .= 'var zpxD=' . $dataJson . ';';
        $html .= 'var zpxO={responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{maxTicksLimit:5}}}};';
        $html .= 'var zpxBw=new Chart(document.getElementById("zpx_bw_chart"),{type:"bar",data:{labels:zpxM,datasets:[{label:"MB",data:[],backgroundColor:"#1a4e84",borderRadius:3}]},options:zpxO});';
        $html .= 'var zpxDk=new Chart(document.getElementById("zpx_disk_chart"),{type:"bar",data:{labels:zpxM,datasets:[{label:"MB",data:[],backgroundColor:"#27ae60",borderRadius:3}]},options:zpxO});';
        $html .= 'function zpxUpd(y){';
        $html .= 'var d=zpxD[y]||Array(12).fill({bw:0,disk:0});';
        $html .= 'zpxBw.data.datasets[0].data=d.map(function(m){return m.bw;});';
        $html .= 'zpxDk.data.datasets[0].data=d.map(function(m){return m.disk;});';
        $html .= 'zpxBw.update();zpxDk.update();';
        $html .= '}';
        $html .= 'document.getElementById("zpx_year_sel").addEventListener("change",function(){zpxUpd(this.value);});';
        $html .= 'zpxUpd("' . $defaultYear . '");';
        $html .= '})();</script>';

        return $html;
    }

    static function getTopConsumers()
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail();
        if ((int)$currentuser['usergroupid'] !== 1) {
            return ''; // only admin sees this
        }

        $month = date('Ym');
        $sql   = $zdbh->prepare(
            "SELECT a.ac_id_pk, a.ac_user_vc, g.ug_name_vc AS grp,
                    b.bd_diskamount_bi, b.bd_transamount_bi,
                    COALESCE(q.qt_diskspace_bi, 0)  AS disk_quota,
                    COALESCE(q.qt_bandwidth_bi, 0)  AS bw_quota
             FROM x_bandwidth b
             JOIN x_accounts a  ON b.bd_acc_fk      = a.ac_id_pk
             LEFT JOIN x_packages p ON a.ac_package_fk = p.pk_id_pk
             LEFT JOIN x_quotas  q  ON p.pk_id_pk      = q.qt_package_fk
             LEFT JOIN x_groups  g  ON a.ac_group_fk   = g.ug_id_pk
             WHERE b.bd_month_in = :month
               AND a.ac_deleted_ts IS NULL
             ORDER BY b.bd_diskamount_bi DESC"
        );
        $sql->bindParam(':month', $month);
        $sql->execute();
        $rows = $sql->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return '';
        }

        // helper: inline progress bar
        $bar = function(float $used, float $quota): string {
            if ($quota <= 0) {
                $pct   = 0;
                $color = '#95a5a6';
                $label = '&#8734;';
            } else {
                $pct   = min(100, round($used / $quota * 100));
                $color = $pct >= 100 ? '#e74c3c' : ($pct >= 80 ? '#f39c12' : '#27ae60');
                $label = $pct . '%';
            }
            $fill = $quota > 0 ? $pct : 0;
            return '<div style="display:flex;align-items:center;gap:6px;min-width:120px;">'
                 . '<div style="flex:1;background:#ddd;border-radius:2px;height:8px;">'
                 . '<div style="width:' . $fill . '%;background:' . $color . ';height:8px;border-radius:2px;"></div>'
                 . '</div>'
                 . '<span style="font-size:11px;color:' . $color . ';min-width:32px;">' . $label . '</span>'
                 . '</div>';
        };

        // row background based on worst quota %
        $rowBg = function(array $r) use ($bar): string {
            $dq  = (float)$r['disk_quota'];
            $bq  = (float)$r['bw_quota'];
            $dp  = $dq > 0 ? $r['bd_diskamount_bi'] / $dq * 100 : 0;
            $bp  = $bq > 0 ? $r['bd_transamount_bi'] / $bq * 100 : 0;
            $max = max($dp, $bp);
            if ($max >= 100) return '#fff0f0';
            if ($max >= 80)  return '#fff8ec';
            return '';
        };

        $html  = '<div style="margin-top:24px;border-top:1px solid #ddd;padding-top:16px;">';
        $html .= '<div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">';
        $html .= '<h2 style="margin:0;">Resource Consumers — ' . date('F Y') . '</h2>';
        $html .= '<input id="zpx_search" type="text" placeholder="Filter user..." '
               . 'style="font-size:12px;padding:3px 7px;border:1px solid #ccc;border-radius:3px;" '
               . 'oninput="zpxFilter()">';
        $html .= '</div>';

        $html .= '<table id="zpx_tc" class="table table-striped table-sm" style="font-size:12px;">';
        $html .= '<thead><tr>'
               . '<th style="cursor:pointer" onclick="zpxSort(0)">#</th>'
               . '<th style="cursor:pointer" onclick="zpxSort(1)">User &#9660;</th>'
               . '<th style="cursor:pointer" onclick="zpxSort(2)">Group</th>'
               . '<th style="cursor:pointer" onclick="zpxSort(3)">Disk used</th>'
               . '<th>Disk quota</th>'
               . '<th style="cursor:pointer" onclick="zpxSort(5)">BW used</th>'
               . '<th>BW quota</th>'
               . '</tr></thead><tbody>';

        foreach ($rows as $i => $r) {
            $diskMB = round($r['bd_diskamount_bi']  / 1048576, 2);
            $bwMB   = round($r['bd_transamount_bi'] / 1048576, 2);
            $dqMB   = $r['disk_quota'] > 0 ? round($r['disk_quota']  / 1048576, 2) : 0;
            $bqMB   = $r['bw_quota']   > 0 ? round($r['bw_quota']    / 1048576, 2) : 0;
            $dqLabel = $r['disk_quota'] > 0 ? $dqMB . ' MB' : '&#8734;';
            $bqLabel = $r['bw_quota']   > 0 ? $bqMB . ' MB' : '&#8734;';
            $bg = $rowBg($r);
            $bgStyle = $bg ? ' style="background:' . $bg . '"' : '';

            $html .= '<tr' . $bgStyle . '>'
                   . '<td>' . ($i + 1) . '</td>'
                   . '<td><strong>' . htmlspecialchars($r['ac_user_vc']) . '</strong></td>'
                   . '<td style="color:#888;">' . htmlspecialchars($r['grp']) . '</td>'
                   . '<td data-val="' . $r['bd_diskamount_bi'] . '">' . $diskMB . ' MB</td>'
                   . '<td>' . $bar($r['bd_diskamount_bi'], $r['disk_quota']) . ' <span style="font-size:11px;color:#888;">' . $dqLabel . '</span></td>'
                   . '<td data-val="' . $r['bd_transamount_bi'] . '">' . $bwMB . ' MB</td>'
                   . '<td>' . $bar($r['bd_transamount_bi'], $r['bw_quota']) . ' <span style="font-size:11px;color:#888;">' . $bqLabel . '</span></td>'
                   . '</tr>';
        }

        $html .= '</tbody></table></div>';

        $html .= '<script>(function(){';
        $html .= 'var zpxDir={};';
        $html .= 'window.zpxSort=function(col){';
        $html .= 'var tb=document.querySelector("#zpx_tc tbody");';
        $html .= 'var rows=Array.from(tb.querySelectorAll("tr"));';
        $html .= 'zpxDir[col]=!zpxDir[col];';
        $html .= 'rows.sort(function(a,b){';
        $html .= 'var av=a.cells[col].dataset.val||a.cells[col].innerText.trim();';
        $html .= 'var bv=b.cells[col].dataset.val||b.cells[col].innerText.trim();';
        $html .= 'var n=parseFloat(av)-parseFloat(bv);';
        $html .= 'var s=av.localeCompare(bv);';
        $html .= 'return (isNaN(n)?s:n)*(zpxDir[col]?1:-1);';
        $html .= '});';
        $html .= 'rows.forEach(function(r,i){r.cells[0].innerText=i+1;tb.appendChild(r);});';
        $html .= '};';
        $html .= 'window.zpxFilter=function(){';
        $html .= 'var q=document.getElementById("zpx_search").value.toLowerCase();';
        $html .= 'document.querySelectorAll("#zpx_tc tbody tr").forEach(function(r){';
        $html .= 'r.style.display=r.cells[1].innerText.toLowerCase().includes(q)?"":"none";';
        $html .= '});};';
        $html .= '})();</script>';

        return $html;
    }

    static function DisplaypBar($total, $quota)
    {
        $currentuser = ctrl_users::GetUserDetail();
        $typequota = $currentuser[$quota];
        $type = ctrl_users::GetQuotaUsages($total, $currentuser['userid']);
        if ($typequota == 0)
            return ''; //Quota are disabled
        if (fs_director::CheckForEmptyValue($type))
            return '<img src="etc/lib/charts/svg_progress.php?percent=0"/>';
        if ($type == $typequota)
            return '<img src="etc/lib/charts/svg_progress.php?percent=100"/>';
        return '<img src="etc/lib/charts/svg_progress.php?percent=' . round($type / $typequota * 100, 0) . '"/>';
    }

}

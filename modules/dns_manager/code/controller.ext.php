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

    static $editdomain;
    static $showform;
    static $ResultOk;
    static $ResultErr;

    private static function SetError($ErrorText)
    {
        if (empty(self::$ResultErr))
            self::$ResultErr = $ErrorText;
    }

    /* Load DNS CSS and JS files */

    static function getInit()
    {
        global $controller;
        $line = '<link rel="stylesheet" type="text/css" href="modules/' . $controller->GetControllerRequest('URL', 'module') . '/assets/dns.css">';
        $line .= '<script type="text/javascript" src="modules/' . $controller->GetControllerRequest('URL', 'module') . '/assets/dns.js"></script>';
        return $line;
    }

    /*
     * Determine which DNS page to show
     * Domain List or DNS Records
     */

    static function getRecordAction()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();

        // Soporte PRG: domainID puede llegar por GET (redirect tras SaveDNS exitoso)
        $domainID_get = $controller->GetControllerRequest('URL', 'domainID');
        if (!fs_director::CheckForEmptyValue($domainID_get) && fs_director::CheckForEmptyValue(self::$editdomain)) {
            self::$editdomain = (int)$domainID_get;
        }
        // Flash message tras redirect exitoso
        if ($controller->GetControllerRequest('URL', 'saved') === '1') {
            self::$ResultOk = 'Changes to your DNS have been saved successfully!';
        }

        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'domainID'))
            || !fs_director::CheckForEmptyValue($domainID_get)) {
            $display = self::DisplayRecords();
        } elseif (fs_director::CheckForEmptyValue(self::$editdomain)) {
            $display = self::DisplayDomains();
        } else {
            //Create default records if no records are found for the domain.
            if (fs_director::CheckForEmptyValue(self::$editdomain)) {
                $domainID = $controller->GetControllerRequest('FORM', 'domainID');
            } else {
                $domainID = self::$editdomain;
            }

            $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_acc_fk=:userid AND dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL";
            $numrows = $zdbh->prepare($sql);
            $numrows->bindParam(':userid', $currentuser['userid']);
            $numrows->bindParam(':domainID', $domainID);

            if ($numrows->execute()) {
                if ($numrows->fetchColumn() == 0) {
                    $display = self::DisplayDefaultRecords();
                } else {
                    $display = self::DisplayRecords();
                }
            }
        }
        return $display;
    }

    /*
     * Allow user to Create Initial Domain DNS records for the First time
     */

    static function DisplayDefaultRecords()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $line = "";
        $line .= "<div class=\"zgrid_wrapper\">";


        $line .= "<div id=\"dnsTitle\" class=\"account accountTitle\">";
        $line .= "<div class=\"content\"><h2>" . ui_language::translate("Create Default DNS Records") . "</h2>";
        $line .= "" . ui_language::translate("No records were found for this domain.  Click the button below to set up your domain records for the first time") . "";
        $line .= "<div>";
        $line .= "<div class=\"actions\"><a class=\"back btn btn-sm btn-secondary\" href=\"./?module=" . $controller->GetControllerRequest('URL', 'module') . "\">Domain List</a></div>";
        $line .= "</div><br class=\"clear\">";
        $line .= '</div>';
        $line .= '</div>';


        $line .= "<form action=\"./?module=dns_manager&action=CreateDefaultRecords\" method=\"post\">";
        $line .= "<table class=\"zform\">";
        $line .= "<tr>";
        $line .= "<td>";
        $line .= '<button type="submit" class="btn btn-primary"><i class="bi bi-pencil"></i> ' . ui_language::translate("Create Records") . '</button>';
        $line .= "</td>";
        $line .= "</tr>";
        $line .= "</table>";
        $line .= "<input type=\"hidden\" name=\"inDomain\" value =\"" . (int)$controller->GetControllerRequest('FORM', 'inDomain') . "\" />";
        $line .= "<input type=\"hidden\" name=\"inUserID\" value =\"" . $currentuser['userid'] . "\" />";
        $line .= self::getCSFR_Tag();
        $line .= '</form>';
        $line .= '</div>';
        return $line;
    }

    static function parseTarget($type, $target)
    {
        $target = trim((string)$target);
        switch ($type) {
            case 'CAA':
                if (preg_match('/^(\d+)\s+(\S+)\s+"([^"]*)"$/', $target, $m))
                    return ['flags' => $m[1], 'tag' => $m[2], 'value' => $m[3]];
                return ['flags' => '0', 'tag' => 'issue', 'value' => ''];
            case 'NAPTR':
                if (preg_match('/^(\d+)\s+(\d+)\s+"([^"]*)"\s+"([^"]*)"\s+"([^"]*)"\s+(\S+)$/', $target, $m))
                    return ['order' => $m[1], 'pref' => $m[2], 'flags' => $m[3], 'service' => $m[4], 'regexp' => $m[5], 'replacement' => $m[6]];
                return ['order' => '100', 'pref' => '10', 'flags' => '', 'service' => '', 'regexp' => '', 'replacement' => '.'];
            case 'SSHFP':
                if (preg_match('/^([1-4])\s+([12])\s+([0-9a-fA-F]+)$/', $target, $m))
                    return ['algo' => $m[1], 'fptype' => $m[2], 'fp' => $m[3]];
                return ['algo' => '4', 'fptype' => '2', 'fp' => ''];
            case 'TLSA':
                if (preg_match('/^([0-3])\s+([01])\s+([012])\s+([0-9a-fA-F]+)$/', $target, $m))
                    return ['usage' => $m[1], 'selector' => $m[2], 'matching' => $m[3], 'certdata' => $m[4]];
                return ['usage' => '3', 'selector' => '1', 'matching' => '1', 'certdata' => ''];
            case 'URI':
                if (preg_match('/^(\d+)\s+(\d+)\s+"([^"]*)"$/', $target, $m))
                    return ['priority' => $m[1], 'weight' => $m[2], 'uri' => $m[3]];
                return ['priority' => '10', 'weight' => '1', 'uri' => ''];
        }
        return [];
    }

    static function DnsRecordField($type, $ttl, $description, $userID, $domainID)
    {
        global $zdbh;
        global $controller;
        /* Begin DNS records */
        if (self::IsTypeAllowed($type)) {
            if ($type === 'A') {
                $activeCss = 'active show';
                if (ctrl_options::GetSystemOption('custom_ip') == 'false') {
                    $custom_ip = "READONLY";
                } else {
                    $custom_ip = NULL;
                }
            } else {
                $activeCss = '';
                $custom_ip = NULL;
            }

            $line = '<!-- ' . $type . ' RECORDS -->';
            $line .= '<div class="tab-pane ' . $activeCss . '" id="type' . $type . '">';
            $line .= '<div class="description">' . $description . '</div>';
            $line .= '<div class="header dns-row">';
            $line .= '<div class="hostName"><label class="enableToolTip">' . ui_language::translate('Host Name') . '</label></div>';
            $line .= '<div class="TTL"><label class="enableToolTip">TTL</label></div>';
            $line .= '<div class="in">&nbsp;</div>';
            $line .= '<div class="type">&nbsp;</div>';
            if ($type === 'MX') {
                $line .= '<div class="priority"><label class="enableToolTip">' . ui_language::translate('Priority') . '</label></div>';
                $line .= '<div class="target"><label class="enableToolTip">' . ui_language::translate('Target') . '</label></div>';
            } elseif ($type === 'SRV') {
                $line .= '<div class="priority"><label class="enableToolTip">' . ui_language::translate('Priority') . '</label></div>';
                $line .= '<div class="weight"><label class="enableToolTip">' . ui_language::translate('Weight') . '</label></div>';
                $line .= '<div class="port"><label class="enableToolTip">' . ui_language::translate('Port') . '</label></div>';
                $line .= '<div class="target"><label class="enableToolTip">' . ui_language::translate('Target') . '</label></div>';
            } elseif ($type === 'CAA') {
                $line .= '<div class="caa-flags"><label>Flags</label></div>';
                $line .= '<div class="caa-tag"><label>Tag</label></div>';
                $line .= '<div class="caa-value"><label>Value</label></div>';
            } elseif ($type === 'NAPTR') {
                $line .= '<div class="naptr-order"><label>Order</label></div>';
                $line .= '<div class="naptr-pref"><label>Pref</label></div>';
                $line .= '<div class="naptr-flags"><label>Flags</label></div>';
                $line .= '<div class="naptr-service"><label>Service</label></div>';
                $line .= '<div class="naptr-regexp"><label>Regexp</label></div>';
                $line .= '<div class="naptr-replacement"><label>Replacement</label></div>';
            } elseif ($type === 'SSHFP') {
                $line .= '<div class="sshfp-algo"><label>Algorithm</label></div>';
                $line .= '<div class="sshfp-fptype"><label>FP-Type</label></div>';
                $line .= '<div class="sshfp-fp"><label>Fingerprint (hex)</label></div>';
            } elseif ($type === 'TLSA') {
                $line .= '<div class="tlsa-usage"><label>Usage</label></div>';
                $line .= '<div class="tlsa-selector"><label>Selector</label></div>';
                $line .= '<div class="tlsa-matching"><label>Matching</label></div>';
                $line .= '<div class="tlsa-cert"><label>Cert Data (hex)</label></div>';
            } elseif ($type === 'URI') {
                $line .= '<div class="uri-priority"><label>Priority</label></div>';
                $line .= '<div class="uri-weight"><label>Weight</label></div>';
                $line .= '<div class="uri-uri"><label>URI</label></div>';
            } else {
                $line .= '<div class="target"><label class="enableToolTip">' . ui_language::translate('Target') . '</label></div>';
            }
            $line .= '<div class="actions"><label>' . ui_language::translate('Actions') . '</label></div>';
            $line .= '<br>';
            $line .= '</div>';

            // default._domainkey is managed exclusively via the DKIM section — exclude it from
            // the editable record list so users cannot accidentally delete or corrupt it here.
            $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_acc_fk=:userid AND dn_type_vc=:type AND dn_vhost_fk=:domainID AND dn_host_vc != 'default._domainkey' AND dn_deleted_ts IS NULL ORDER BY dn_host_vc ASC");
            $sql->bindParam(':type', $type);
            $sql->bindParam(':userid', $userID);
            $sql->bindParam(':domainID', $domainID);
            $sql->execute();

            while ($rowdns = $sql->fetch()) {
                $line .= '<div class="dnsRecord dns-row">';
                $line .= '<div class="hostName"><span>' . htmlspecialchars($rowdns['dn_host_vc'], ENT_QUOTES, 'UTF-8') . '</span></div>';
                $line .= '<div class="TTL">';
                $line .= '<input name="ttl[' . $rowdns['dn_id_pk'] . ']" value="' . $rowdns['dn_ttl_in'] . '" class="form-control form-control-sm" type="text">';
                $line .= '<input name="original_ttl[' . $rowdns['dn_id_pk'] . ']" value="' . $rowdns['dn_ttl_in'] . '" type="hidden"></div>';
                $line .= '<div class="in">IN</div>';
                $line .= '<div class="type">' . $type . '</div>';

                $id  = $rowdns['dn_id_pk'];
                $esc = htmlspecialchars($rowdns['dn_target_vc'], ENT_QUOTES, 'UTF-8');
                if ($type === 'MX') {
                    $line .= '<div class="priority"><input name="priority[' . $id . ']" value="' . $rowdns['dn_priority_in'] . '" type="text" class="form-control form-control-sm"><input name="original_priority[' . $id . ']" value="' . $rowdns['dn_priority_in'] . '" type="hidden"></div>';
                    $line .= '<div class="target"><input name="target[' . $id . ']" value="' . $esc . '" class="form-control form-control-sm" type="text"><input name="original_target[' . $id . ']" value="' . $esc . '" type="hidden"></div>';
                } elseif ($type === 'SRV') {
                    $line .= '<div class="priority"><input name="priority[' . $id . ']" value="' . $rowdns['dn_priority_in'] . '" class="form-control form-control-sm" type="text"><input name="original_priority[' . $id . ']" value="' . $rowdns['dn_priority_in'] . '" type="hidden"></div>';
                    $line .= '<div class="weight"><input name="weight[' . $id . ']" value="' . $rowdns['dn_weight_in'] . '" class="form-control form-control-sm" type="text"><input name="original_weight[' . $id . ']" value="' . $rowdns['dn_weight_in'] . '" type="hidden"></div>';
                    $line .= '<div class="port"><input name="port[' . $id . ']" value="' . $rowdns['dn_port_in'] . '" type="text" class="form-control form-control-sm"><input name="original_port[' . $id . ']" value="' . $rowdns['dn_port_in'] . '" type="hidden"></div>';
                    $line .= '<div class="target"><input name="target[' . $id . ']" value="' . $esc . '" class="form-control form-control-sm" type="text"><input name="original_target[' . $id . ']" value="' . $esc . '" type="hidden"></div>';
                } elseif ($type === 'CAA') {
                    $p = self::parseTarget('CAA', $rowdns['dn_target_vc']);
                    $line .= '<div class="caa-flags"><input name="caa_flags[' . $id . ']" value="' . htmlspecialchars($p['flags'], ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" type="text"><input name="original_caa_flags[' . $id . ']" value="' . htmlspecialchars($p['flags'], ENT_QUOTES, 'UTF-8') . '" type="hidden"></div>';
                    $line .= '<div class="caa-tag"><select name="caa_tag[' . $id . ']">';
                    foreach (['issue' => 'issue', 'issuewild' => 'issuewild', 'iodef' => 'iodef'] as $v => $l) {
                        $sel = ($p['tag'] === $v) ? ' selected' : '';
                        $line .= '<option value="' . $v . '"' . $sel . '>' . $l . '</option>';
                    }
                    $line .= '</select></div>';
                    $line .= '<div class="caa-value"><input name="caa_value[' . $id . ']" value="' . htmlspecialchars($p['value'], ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" type="text"><input name="original_caa_value[' . $id . ']" value="' . htmlspecialchars($p['value'], ENT_QUOTES, 'UTF-8') . '" type="hidden"></div>';
                    $line .= '<input name="target[' . $id . ']" value="' . $esc . '" type="hidden">';
                    $line .= '<input name="original_target[' . $id . ']" value="' . $esc . '" type="hidden">';
                } elseif ($type === 'NAPTR') {
                    $p = self::parseTarget('NAPTR', $rowdns['dn_target_vc']);
                    $line .= '<div class="naptr-order"><input name="naptr_order[' . $id . ']" value="' . htmlspecialchars($p['order'], ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" type="text"><input name="original_naptr_order[' . $id . ']" value="' . htmlspecialchars($p['order'], ENT_QUOTES, 'UTF-8') . '" type="hidden"></div>';
                    $line .= '<div class="naptr-pref"><input name="naptr_pref[' . $id . ']" value="' . htmlspecialchars($p['pref'], ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" type="text"><input name="original_naptr_pref[' . $id . ']" value="' . htmlspecialchars($p['pref'], ENT_QUOTES, 'UTF-8') . '" type="hidden"></div>';
                    $line .= '<div class="naptr-flags"><input name="naptr_flags[' . $id . ']" value="' . htmlspecialchars($p['flags'], ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" type="text"><input name="original_naptr_flags[' . $id . ']" value="' . htmlspecialchars($p['flags'], ENT_QUOTES, 'UTF-8') . '" type="hidden"></div>';
                    $line .= '<div class="naptr-service"><input name="naptr_service[' . $id . ']" value="' . htmlspecialchars($p['service'], ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" type="text"><input name="original_naptr_service[' . $id . ']" value="' . htmlspecialchars($p['service'], ENT_QUOTES, 'UTF-8') . '" type="hidden"></div>';
                    $line .= '<div class="naptr-regexp"><input name="naptr_regexp[' . $id . ']" value="' . htmlspecialchars($p['regexp'], ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" type="text"><input name="original_naptr_regexp[' . $id . ']" value="' . htmlspecialchars($p['regexp'], ENT_QUOTES, 'UTF-8') . '" type="hidden"></div>';
                    $line .= '<div class="naptr-replacement"><input name="naptr_replacement[' . $id . ']" value="' . htmlspecialchars($p['replacement'], ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" type="text"><input name="original_naptr_replacement[' . $id . ']" value="' . htmlspecialchars($p['replacement'], ENT_QUOTES, 'UTF-8') . '" type="hidden"></div>';
                    $line .= '<input name="target[' . $id . ']" value="' . $esc . '" type="hidden">';
                    $line .= '<input name="original_target[' . $id . ']" value="' . $esc . '" type="hidden">';
                } elseif ($type === 'SSHFP') {
                    $p = self::parseTarget('SSHFP', $rowdns['dn_target_vc']);
                    $line .= '<div class="sshfp-algo"><select name="sshfp_algo[' . $id . ']">';
                    foreach (['1' => '1-RSA', '2' => '2-DSA', '3' => '3-ECDSA', '4' => '4-Ed25519'] as $v => $l) {
                        $sel = ($p['algo'] === $v) ? ' selected' : '';
                        $line .= '<option value="' . $v . '"' . $sel . '>' . $l . '</option>';
                    }
                    $line .= '</select></div>';
                    $line .= '<div class="sshfp-fptype"><select name="sshfp_fptype[' . $id . ']">';
                    foreach (['1' => '1-SHA1', '2' => '2-SHA256'] as $v => $l) {
                        $sel = ($p['fptype'] === $v) ? ' selected' : '';
                        $line .= '<option value="' . $v . '"' . $sel . '>' . $l . '</option>';
                    }
                    $line .= '</select></div>';
                    $line .= '<div class="sshfp-fp"><input name="sshfp_fp[' . $id . ']" value="' . htmlspecialchars($p['fp'], ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" type="text"><input name="original_sshfp_fp[' . $id . ']" value="' . htmlspecialchars($p['fp'], ENT_QUOTES, 'UTF-8') . '" type="hidden"></div>';
                    $line .= '<input name="target[' . $id . ']" value="' . $esc . '" type="hidden">';
                    $line .= '<input name="original_target[' . $id . ']" value="' . $esc . '" type="hidden">';
                } elseif ($type === 'TLSA') {
                    $p = self::parseTarget('TLSA', $rowdns['dn_target_vc']);
                    $line .= '<div class="tlsa-usage"><select name="tlsa_usage[' . $id . ']">';
                    foreach (['0' => '0-PKIX-TA', '1' => '1-PKIX-EE', '2' => '2-DANE-TA', '3' => '3-DANE-EE'] as $v => $l) {
                        $sel = ($p['usage'] === $v) ? ' selected' : '';
                        $line .= '<option value="' . $v . '"' . $sel . '>' . $l . '</option>';
                    }
                    $line .= '</select></div>';
                    $line .= '<div class="tlsa-selector"><select name="tlsa_selector[' . $id . ']">';
                    foreach (['0' => '0-Full cert', '1' => '1-SubjPubKey'] as $v => $l) {
                        $sel = ($p['selector'] === $v) ? ' selected' : '';
                        $line .= '<option value="' . $v . '"' . $sel . '>' . $l . '</option>';
                    }
                    $line .= '</select></div>';
                    $line .= '<div class="tlsa-matching"><select name="tlsa_matching[' . $id . ']">';
                    foreach (['0' => '0-Full', '1' => '1-SHA256', '2' => '2-SHA512'] as $v => $l) {
                        $sel = ($p['matching'] === $v) ? ' selected' : '';
                        $line .= '<option value="' . $v . '"' . $sel . '>' . $l . '</option>';
                    }
                    $line .= '</select></div>';
                    $line .= '<div class="tlsa-cert"><input name="tlsa_cert[' . $id . ']" value="' . htmlspecialchars($p['certdata'], ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" type="text"><input name="original_tlsa_cert[' . $id . ']" value="' . htmlspecialchars($p['certdata'], ENT_QUOTES, 'UTF-8') . '" type="hidden"></div>';
                    $line .= '<input name="target[' . $id . ']" value="' . $esc . '" type="hidden">';
                    $line .= '<input name="original_target[' . $id . ']" value="' . $esc . '" type="hidden">';
                } elseif ($type === 'URI') {
                    $p = self::parseTarget('URI', $rowdns['dn_target_vc']);
                    $line .= '<div class="uri-priority"><input name="uri_priority[' . $id . ']" value="' . htmlspecialchars($p['priority'], ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" type="text"><input name="original_uri_priority[' . $id . ']" value="' . htmlspecialchars($p['priority'], ENT_QUOTES, 'UTF-8') . '" type="hidden"></div>';
                    $line .= '<div class="uri-weight"><input name="uri_weight[' . $id . ']" value="' . htmlspecialchars($p['weight'], ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" type="text"><input name="original_uri_weight[' . $id . ']" value="' . htmlspecialchars($p['weight'], ENT_QUOTES, 'UTF-8') . '" type="hidden"></div>';
                    $line .= '<div class="uri-uri"><input name="uri_uri[' . $id . ']" value="' . htmlspecialchars($p['uri'], ENT_QUOTES, 'UTF-8') . '" class="form-control form-control-sm" type="text"><input name="original_uri_uri[' . $id . ']" value="' . htmlspecialchars($p['uri'], ENT_QUOTES, 'UTF-8') . '" type="hidden"></div>';
                    $line .= '<input name="target[' . $id . ']" value="' . $esc . '" type="hidden">';
                    $line .= '<input name="original_target[' . $id . ']" value="' . $esc . '" type="hidden">';
                } else {
                    $line .= '<div class="target"><input name="target[' . $id . ']" value="' . $esc . '" class="form-control form-control-sm" type="text" ' . $custom_ip . '><input name="original_target[' . $id . ']" value="' . $esc . '" type="hidden"></div>';
                }
                $line .= '<button type="button" class="delete btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete</button>';
                $line .= '<button type="button" class="undo btn btn-success btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Undo</button>';
                $line .= '<input name="type[' . $rowdns['dn_id_pk'] . ']" value="' . $type . '" type="hidden">';
                $line .= '<input class="delete" name="delete[' . $rowdns['dn_id_pk'] . ']" value="false" type="hidden">';
                $line .= '<br>';
                $line .= '</div>';
            }
            $line .= '<div class="add dns-row" style="padding:8px 0;border-top:1px solid #EEE;">'
                   . '<button type="submit" class="add-row btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> ' . ui_language::translate("Add New Record") . '</button>'
                   . ' <button type="submit" class="save disabled btn btn-secondary btn-sm float-end"><i class="bi bi-floppy me-1"></i>' . ui_language::translate("Save") . '</button>'
                   . '</div>';

            // New Record Template
            $line .= '<div class="newRecord dns-row" style="display: none">';
            $line .= '<div class="hostName"><input name="proto_hostName" class="form-control form-control-sm" type="text" placeholder="' . ui_language::translate('Host Name') . '"></div>';

            $line .= '<div class="TTL"><input name="proto_ttl" value="' . $ttl . '" class="form-control form-control-sm" type="text" placeholder="' . ui_language::translate('TTL') . '"></div>';
            $line .= '<div class="in">IN</div>';
            $line .= '<div class="type">' . $type . '</div>';
            if ($type === 'MX') {
                $line .= '<div class="priority"><input name="proto_priority" class="form-control form-control-sm" type="text" placeholder="' . ui_language::translate('Priority') . '"></div>';
                $line .= '<div class="target"><input name="proto_target" class="form-control form-control-sm" type="text" placeholder="' . ui_language::translate('Target') . '"></div>';
            } elseif ($type === 'SRV') {
                $line .= '<div class="priority"><input name="proto_priority" class="form-control form-control-sm" type="text" placeholder="' . ui_language::translate('Priority') . '"></div>';
                $line .= '<div class="weight"><input name="proto_weight" class="form-control form-control-sm" type="text" placeholder="' . ui_language::translate('Weight') . '"></div>';
                $line .= '<div class="port"><input name="proto_port" class="form-control form-control-sm" type="text" placeholder="' . ui_language::translate('Port') . '"></div>';
                $line .= '<div class="target"><input name="proto_target" class="form-control form-control-sm" type="text" placeholder="' . ui_language::translate('Target') . '"></div>';
            } elseif ($type === 'CAA') {
                $line .= '<div class="caa-flags"><input name="proto_caa_flags" value="0" class="form-control form-control-sm" type="text" placeholder="0-255"></div>';
                $line .= '<div class="caa-tag"><select name="proto_caa_tag"><option value="issue">issue</option><option value="issuewild">issuewild</option><option value="iodef">iodef</option></select></div>';
                $line .= '<div class="caa-value"><input name="proto_caa_value" class="form-control form-control-sm" type="text" placeholder="letsencrypt.org"></div>';
                $line .= '<input name="proto_target" type="hidden" value="">';
            } elseif ($type === 'NAPTR') {
                $line .= '<div class="naptr-order"><input name="proto_naptr_order" value="100" class="form-control form-control-sm" type="text" placeholder="100"></div>';
                $line .= '<div class="naptr-pref"><input name="proto_naptr_pref" value="10" class="form-control form-control-sm" type="text" placeholder="10"></div>';
                $line .= '<div class="naptr-flags"><input name="proto_naptr_flags" class="form-control form-control-sm" type="text" placeholder="u"></div>';
                $line .= '<div class="naptr-service"><input name="proto_naptr_service" class="form-control form-control-sm" type="text" placeholder="E2U+sip"></div>';
                $line .= '<div class="naptr-regexp"><input name="proto_naptr_regexp" class="form-control form-control-sm" type="text" placeholder="!^.*$!sip:info@ex.com!"></div>';
                $line .= '<div class="naptr-replacement"><input name="proto_naptr_replacement" value="." class="form-control form-control-sm" type="text" placeholder="."></div>';
                $line .= '<input name="proto_target" type="hidden" value="">';
            } elseif ($type === 'SSHFP') {
                $line .= '<div class="sshfp-algo"><select name="proto_sshfp_algo"><option value="1">1-RSA</option><option value="2">2-DSA</option><option value="3">3-ECDSA</option><option value="4" selected>4-Ed25519</option></select></div>';
                $line .= '<div class="sshfp-fptype"><select name="proto_sshfp_fptype"><option value="1">1-SHA1</option><option value="2" selected>2-SHA256</option></select></div>';
                $line .= '<div class="sshfp-fp"><input name="proto_sshfp_fp" class="form-control form-control-sm" type="text" placeholder="hex fingerprint"></div>';
                $line .= '<input name="proto_target" type="hidden" value="">';
            } elseif ($type === 'TLSA') {
                $line .= '<div class="tlsa-usage"><select name="proto_tlsa_usage"><option value="0">0-PKIX-TA</option><option value="1">1-PKIX-EE</option><option value="2">2-DANE-TA</option><option value="3" selected>3-DANE-EE</option></select></div>';
                $line .= '<div class="tlsa-selector"><select name="proto_tlsa_selector"><option value="0">0-Full cert</option><option value="1" selected>1-SubjPubKey</option></select></div>';
                $line .= '<div class="tlsa-matching"><select name="proto_tlsa_matching"><option value="0">0-Full</option><option value="1" selected>1-SHA256</option><option value="2">2-SHA512</option></select></div>';
                $line .= '<div class="tlsa-cert"><input name="proto_tlsa_cert" class="form-control form-control-sm" type="text" placeholder="hex cert data"></div>';
                $line .= '<input name="proto_target" type="hidden" value="">';
            } elseif ($type === 'URI') {
                $line .= '<div class="uri-priority"><input name="proto_uri_priority" value="10" class="form-control form-control-sm" type="text" placeholder="10"></div>';
                $line .= '<div class="uri-weight"><input name="proto_uri_weight" value="1" class="form-control form-control-sm" type="text" placeholder="1"></div>';
                $line .= '<div class="uri-uri"><input name="proto_uri_uri" class="form-control form-control-sm" type="text" placeholder="https://example.com/"></div>';
                $line .= '<input name="proto_target" type="hidden" value="">';
            } else {
                $line .= '<div class="target"><input name="proto_target" class="form-control form-control-sm" type="text" placeholder="' . ui_language::translate('Target') . '"></div>';
            }
            $line .= '<input class="delete" name="proto_delete" value="false" type="hidden"><button type="button" class="delete btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete</button><input name="proto_type" value="' . $type . '" type="hidden">';
            $line .= '</div>';
            $line .= '</div> <!-- END ' . $type . ' RECORDS -->';
            return $line;
        }
    }

    /*
     * Build and show DNS Record HTML output
     * TODO: Break into smaller Functions
     */

    static function DisplayRecords()
    {
        //Post Debug
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        if (!fs_director::CheckForEmptyValue(self::$editdomain)) {
            $domainID = self::$editdomain;
        } elseif (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'domainID'))) {
            $domainID = $controller->GetControllerRequest('FORM', 'domainID');
        } else {
            $domainID = (int)$controller->GetControllerRequest('URL', 'domainID');
        }
        $numrows2 = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_id_pk=:domainID AND vh_type_in !=2 AND vh_deleted_ts IS NULL");
        $numrows2->bindParam(':domainID', $domainID);
        $numrows2->execute();
        $domain = $numrows2->fetch();

        // Check DNS Zone File for Errors
        try {
            $zone_message = self::CheckZoneRecord($domainID);
        } catch (Throwable $e) {
            error_log('[dns_manager] CheckZoneRecord error: ' . $e->getMessage());
            $zone_message = 'Zone check failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
        $zonecheck_file = ctrl_options::GetSystemOption('temp_dir') . $domain['vh_name_vc'] . ".txt";
        $zone_message = str_replace($zonecheck_file, '', $zone_message);
        if (strstr(strtoupper($zone_message), "OK")) {
            if (substr_count($zone_message, ":") >= 2) {
                $zone_error_message = '<font color="orange">' . ui_language::translate('Your DNS zone has been loaded, but with errors. Some features may not work until corrected.') . '</font>';
            } else {
                $zone_error_message = '<font color="green">' . ui_language::translate('Your DNS zone has been loaded without errors.') . '</font>';
            }
            $zone_status = '<img src="modules/' . $controller->GetControllerRequest('URL', 'module') . '/assets/up.png">';
        } else {
            $zone_error_message = '<font color="red">' . ui_language::translate('Errors detected have prevented your DNS zone from being loaded. Please correct the error(s) listed below. Until these errors are fixed, your DNS will not work.') . '</font>';
            $zone_status = '<img src="modules/' . $controller->GetControllerRequest('URL', 'module') . '/assets/down.png">';
        }

        // Top Edit buttons
        $line = '<!-- DNS FORM -->';
        $line .= '<div id="dnsTitle" class="account accountTitle dnsTitle">';
        $line .= '<div class="content"><h4>' . ui_language::translate('DNS records for') . ':  <a href="http://' . $domain['vh_name_vc'] . '" target="_blank">' . $domain['vh_name_vc'] . '</a></h4>';
        $line .= '<div>';
        $line .= '<div class="actions d-flex flex-wrap gap-2 align-items-center"><a class="undo disabled btn btn-sm btn-outline-secondary" rel="popover" data-title="Undo" data-content="Undo all un-saved changes." data-bs-placement="top"><i class="bi bi-arrow-counterclockwise me-1"></i>' . ui_language::translate('Undo Changes') . '</a><a class="save disabled btn btn-sm btn-primary"><i class="bi bi-floppy me-1"></i>' . ui_language::translate('Save Changes') . '</a><a class="back btn btn-sm btn-secondary" href="./?module=' . $controller->GetControllerRequest('URL', 'module') . '"><i class="bi bi-arrow-left me-1"></i>' . ui_language::translate('Domain List') . '</a></div>';
        $line .= '</div><br class="clear">';
        $line .= '</div>';
        $line .= '</div>';

        $line .= '<form id="dnsRecordsForm" action="./?module=dns_manager&action=SaveDNS" method="post">';
        $line .= '<input id="domainName" name="domainName" value="' . $domain['vh_name_vc'] . '" type="hidden">';
        $line .= '<input id="domainID" name="domainID" value="' . $domain['vh_id_pk'] . '" type="hidden">';

        $line .= '<!-- TABS -->';
        $line .= '<div id="dnsRecords">';

        $line .= '<ul class="nav nav-tabs" role="tablist">';
        if (self::IsTypeAllowed('A')) {
            $line .= '<li class="nav-item" role="presentation"><a class="nav-link active" href="#typeA" data-bs-toggle="tab" role="tab">A</a></li>';
        }
        if (self::IsTypeAllowed('AAAA')) {
            $line .= '<li class="nav-item" role="presentation"><a class="nav-link" href="#typeAAAA" data-bs-toggle="tab" role="tab">AAAA</a></li>';
        }
        if (self::IsTypeAllowed('CNAME')) {
            $line .= '<li class="nav-item" role="presentation"><a class="nav-link" href="#typeCNAME" data-bs-toggle="tab" role="tab">CNAME</a></li>';
        }
        if (self::IsTypeAllowed('MX')) {
            $line .= '<li class="nav-item" role="presentation"><a class="nav-link" href="#typeMX" data-bs-toggle="tab" role="tab">MX</a></li>';
        }
        if (self::IsTypeAllowed('TXT')) {
            $line .= '<li class="nav-item" role="presentation"><a class="nav-link" href="#typeTXT" data-bs-toggle="tab" role="tab">TXT</a></li>';
        }
        if (self::IsTypeAllowed('SRV')) {
            $line .= '<li class="nav-item" role="presentation"><a class="nav-link" href="#typeSRV" data-bs-toggle="tab" role="tab">SRV</a></li>';
        }
        if (self::IsTypeAllowed('NS')) {
            $line .= '<li class="nav-item" role="presentation"><a class="nav-link" href="#typeNS" data-bs-toggle="tab" role="tab">NS</a></li>';
        }
        if (self::IsTypeAllowed('CAA')) {
            $line .= '<li class="nav-item" role="presentation"><a class="nav-link" href="#typeCAA" data-bs-toggle="tab" role="tab">CAA</a></li>';
        }
        if (self::IsTypeAllowed('NAPTR')) {
            $line .= '<li class="nav-item" role="presentation"><a class="nav-link" href="#typeNAPTR" data-bs-toggle="tab" role="tab">NAPTR</a></li>';
        }
        if (self::IsTypeAllowed('SSHFP')) {
            $line .= '<li class="nav-item" role="presentation"><a class="nav-link" href="#typeSSHFP" data-bs-toggle="tab" role="tab">SSHFP</a></li>';
        }
        if (self::IsTypeAllowed('TLSA')) {
            $line .= '<li class="nav-item" role="presentation"><a class="nav-link" href="#typeTLSA" data-bs-toggle="tab" role="tab">TLSA</a></li>';
        }
        if (self::IsTypeAllowed('URI')) {
            $line .= '<li class="nav-item" role="presentation"><a class="nav-link" href="#typeURI" data-bs-toggle="tab" role="tab">URI</a></li>';
        }
        $line .= '</ul>';
        $line .= '<!-- END TABS -->';

        $line .= '<div class="tab-content">';

        $aDescription = ui_language::translate("The A record contains an IPv4 address. Its target is an IPv4 address, e.g. '192.168.1.1'.");
        $aaaaDescription = ui_language::translate("The AAAA record contains an IPv6 address. Its target is an IPv6 address, e.g. '2607:fe90:2::1'.");
        $cnameDescription = ui_language::translate("The CNAME record specifies the canonical name of a record. Its target is a fully qualified domain name, e.g.
'webserver-01.example.com'.");
        $mxDescription = ui_language::translate("The MX record specifies a mail exchanger host for a domain. Each mail exchanger has a priority or preference that is a numeric value between 0 and 65535.  Its target is a fully qualified domain name, e.g. 'mail.example.com'.");
        $txtDescription = ui_language::translate("The TXT field can be used to attach textual data to a domain.");
        $srvDescription = ui_language::translate("SRV records can be used to encode the location and port of services on a domain name.  Its target is a fully qualified domain name, e.g. 'host.example.com'.");
        $spfDescription = ui_language::translate("SPF records is used to store Sender Policy Framework details.  Its target is a text string, e.g.<br>'v=spf1 a:192.168.1.1 include:example.com mx ptr -all' (Click <a href=\"http://www.microsoft.com/mscorp/safety/content/technologies/senderid/wizard/\" target=\"_blank\">HERE</a> for the Microsoft SPF Wizard.)");
        $nsDescription = ui_language::translate("Nameserver record. Specifies nameservers for a domain. Its target is a fully qualified domain name, e.g.  'ns1.example.com'.  The records should match what the domain name has registered with the internet root servers.");
        $caaDescription = ui_language::translate("CAA (Certification Authority Authorization) records specify which Certificate Authorities may issue SSL/TLS certificates for this domain. Format: &lt;flags&gt; &lt;tag&gt; &lt;value&gt; — e.g. <b>0 issue \"letsencrypt.org\"</b>. Tags: <b>issue</b> (DV/OV certs), <b>issuewild</b> (wildcard certs), <b>iodef</b> (violation report URI).");
        $naptrDescription = ui_language::translate("NAPTR (Naming Authority Pointer) records are used for SIP/VoIP and ENUM. Format: &lt;order&gt; &lt;pref&gt; \"&lt;flags&gt;\" \"&lt;service&gt;\" \"&lt;regexp&gt;\" &lt;replacement&gt; — e.g. <b>100 10 \"u\" \"E2U+sip\" \"!^.*\$!sip:info@example.com!\" .</b>");
        $sshfpDescription = ui_language::translate("SSHFP records publish SSH host key fingerprints in DNS. Format: &lt;algorithm&gt; &lt;fp-type&gt; &lt;fingerprint-hex&gt; — e.g. <b>4 2 abc123...</b> (algorithm: 1=RSA 2=DSA 3=ECDSA 4=Ed25519 | fp-type: 1=SHA-1 2=SHA-256).");
        $tlsaDescription = ui_language::translate("TLSA (DANE) records bind a TLS certificate to a port/protocol. Hostname must be _port._proto (e.g. <b>_443._tcp</b>). Format: &lt;usage&gt; &lt;selector&gt; &lt;matching&gt; &lt;cert-data-hex&gt; — e.g. <b>3 1 1 abc123...</b> (usage: 0-3 | selector: 0-1 | matching: 0-2).");
        $uriDescription = ui_language::translate("URI records map a hostname to a URI. Format: &lt;priority&gt; &lt;weight&gt; \"&lt;uri&gt;\" — e.g. <b>10 1 \"https://example.com/\"</b>.");

        $tts = 86400;
        $line .= self::DnsRecordField('A', $tts, $aDescription, $currentuser['userid'], $domainID);
        $line .= self::DnsRecordField('AAAA', $tts, $aaaaDescription, $currentuser['userid'], $domainID);
        $line .= self::DnsRecordField('CNAME', $tts, $cnameDescription, $currentuser['userid'], $domainID);
        $line .= self::DnsRecordField('MX', $tts, $mxDescription, $currentuser['userid'], $domainID);
        $line .= self::DnsRecordField('TXT', $tts, $txtDescription, $currentuser['userid'], $domainID);
        $line .= self::DnsRecordField('SRV', $tts, $srvDescription, $currentuser['userid'], $domainID);
        $line .= self::DnsRecordField('SPF', $tts, $spfDescription, $currentuser['userid'], $domainID);
        $line .= self::DnsRecordField('NS', $tts, $nsDescription, $currentuser['userid'], $domainID);
        $line .= self::DnsRecordField('CAA', $tts, $caaDescription, $currentuser['userid'], $domainID);
        $line .= self::DnsRecordField('NAPTR', $tts, $naptrDescription, $currentuser['userid'], $domainID);
        $line .= self::DnsRecordField('SSHFP', $tts, $sshfpDescription, $currentuser['userid'], $domainID);
        $line .= self::DnsRecordField('TLSA', $tts, $tlsaDescription, $currentuser['userid'], $domainID);
        $line .= self::DnsRecordField('URI', $tts, $uriDescription, $currentuser['userid'], $domainID);

        $line .= '<input name="newRecords" value="0" type="hidden">';
        $line .= '</div> <!-- END TABS CONTENT -->';
        /* END TABS SECTION */
        $line .= '</div> <!-- END TABS -->';

        // Bottom Edit buttons
        $line .= "<div id=\"dnsTitleBottom\" class=\"account accountTitle dnsTitle\">";
        $line .= "<div class=\"content\">";
        $line .= "<div>";
        $line .= "<div class=\"actions d-flex flex-wrap gap-2 align-items-center\"><a class=\"undo disabled btn btn-sm btn-outline-secondary\"><i class=\"bi bi-arrow-counterclockwise me-1\"></i>" . ui_language::translate("Undo Changes") . "</a><a class=\"save disabled btn btn-sm btn-primary\"><i class=\"bi bi-floppy me-1\"></i>" . ui_language::translate("Save Changes") . "</a><a class=\"back btn btn-sm btn-secondary\" href=\"./?module=" . $controller->GetControllerRequest('URL', 'module') . "\"><i class=\"bi bi-arrow-left me-1\"></i>" . ui_language::translate("Domain List") . "</a></div>";
        $line .= "</div><br class=\"clear\">";
        $line .= '</div>';
        $line .= self::getCSFR_Tag();
        $line .= "</form>";
        $line .= "<!-- END DNS FORM -->";
        $line .= "<div class=\"zgrid_wrapper\">";
        $line .= "<h2>DNS Status for domain: " . $domain['vh_name_vc'] . "</h2>";
        $line .= "<table class=\"none\" cellpadding=\"0\" cellspacing=\"0\"><tr valign=\"top\"><td>";
        $line .= $zone_status;
        $line .= "</td><td>" . $zone_error_message . "<br><br>" . ui_language::translate("Please note that changes to your zone records can take up to 24 hours before they become 'live'.") . "<br><br><b>" . ui_language::translate("Output of DNS zone checker:") . "</b><br>";
        $line .= $zone_message;
        $line .= "</td></tr></table>";
        $line .= '</div>';

        // DKIM key management
        $dkimRec = $zdbh->prepare(
            "SELECT dn_target_vc FROM x_dns
             WHERE dn_vhost_fk=:did AND dn_host_vc='default._domainkey'
               AND dn_type_vc='TXT' AND dn_deleted_ts IS NULL LIMIT 1"
        );
        $dkimRec->bindParam(':did', $domainID);
        $dkimRec->execute();
        $dkimRow = $dkimRec->fetch();

        $line .= '<div class="zgrid_wrapper">';
        $line .= '<h4>' . ui_language::translate('DKIM Key') . '</h4>';
        if (!$dkimRow) {
            $line .= '<p><span class="badge bg-warning">Sin clave DKIM</span></p>';
        } elseif ($dkimRow['dn_target_vc'] === 'PENDING') {
            $line .= '<p><span class="badge bg-info">Generando clave&hellip; (próximo ciclo del daemon, &lt;5 min)</span></p>';
        } else {
            $line .= '<p><span class="badge bg-success">Clave DKIM activa</span> &nbsp;';
            $line .= '<code>default._domainkey.' . $domain['vh_name_vc'] . '</code></p>';
        }
        $line .= '<form action="./?module=dns_manager&action=RegenerateDKIM" method="post" style="display:inline">';
        $line .= '<input type="hidden" name="domainID" value="' . $domain['vh_id_pk'] . '">';
        $line .= '<button type="submit" class="btn btn-warning btn-sm">';
        $line .= '<i class="bi bi-arrow-clockwise"></i> ';
        $line .= ui_language::translate($dkimRow ? 'Regenerar clave DKIM' : 'Crear clave DKIM');
        $line .= '</button>';
        $line .= self::getCSFR_Tag();
        $line .= '</form>';
        $line .= '</div>';

        // DNSSEC zone signing
        $dnssecRow = $zdbh->prepare(
            "SELECT dd_enabled_in, dd_ds_txt, dd_keytag_in FROM x_dns_dnssec WHERE dd_vhost_fk=:did LIMIT 1"
        );
        $dnssecRow->bindParam(':did', $domainID);
        $dnssecRow->execute();
        $dnssec = $dnssecRow->fetch();

        $dnssecEnabled = $dnssec && (int)$dnssec['dd_enabled_in'] === 1;
        $dnssecDs      = $dnssec ? $dnssec['dd_ds_txt']    : null;
        $dnssecPending = $dnssecEnabled && empty($dnssecDs);

        $line .= '<div class="zgrid_wrapper">';
        $line .= '<h4><i class="bi bi-lock"></i> DNSSEC</h4>';

        if (!$dnssecEnabled) {
            $line .= '<p><span class="badge bg-default">Desactivado</span></p>';
            $line .= '<p style="color:#777;font-size:13px">Firmado criptográfico de la zona — los visitantes pueden verificar que los registros DNS son auténticos.</p>';
        } elseif ($dnssecPending) {
            $line .= '<p><span class="badge bg-info">Activando&hellip;</span> BIND está generando las claves (&lt;5 min)</p>';
            $line .= '<p style="color:#777;font-size:13px">El registro DS aparecerá aquí en cuanto el daemon lo detecte. Mientras tanto, DNSSEC <b>no está activo en internet</b> — no registres nada aún en tu registrador.</p>';
        } else {
            $line .= '<p><span class="badge bg-success">Activo</span></p>';
            $line .= '<p style="margin-top:8px"><strong>Registro DS</strong> — copia este valor en el panel de tu registrador de dominio:</p>';
            $line .= '<div style="background:#f5f5f5;border:1px solid #ddd;border-radius:4px;padding:10px 12px;font-family:monospace;font-size:12px;word-break:break-all;margin-bottom:8px" id="dnssecDsValue">';
            $line .= htmlspecialchars($dnssecDs, ENT_QUOTES, 'UTF-8');
            $line .= '</div>';
            $line .= '<button type="button" class="btn btn-secondary btn-sm" onclick="dnssecCopyDS()">';
            $line .= '<i class="bi bi-clipboard"></i> Copiar DS</button>';
            $line .= '<script>function dnssecCopyDS(){var t=document.getElementById("dnssecDsValue").innerText;navigator.clipboard?navigator.clipboard.writeText(t):prompt("Copia este DS record:",t);}</script>';
            $line .= '<p style="margin-top:10px;color:#777;font-size:12px"><b>Algoritmo:</b> 13 (ECDSAP256SHA256) &mdash; <b>Tipo digest:</b> 2 (SHA-256)</p>';
        }

        // Toggle button
        if ($dnssecEnabled) {
            $line .= '<form action="./?module=dns_manager&action=ToggleDnssec" method="post" style="margin-top:10px">';
            $line .= '<input type="hidden" name="domainID" value="' . $domain['vh_id_pk'] . '">';
            $line .= '<input type="hidden" name="enable" value="0">';
            $line .= '<button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm(\'¿Desactivar DNSSEC para ' . addslashes($domain['vh_name_vc']) . '?\nDebes eliminar el DS record de tu registrador antes para evitar SERVFAIL.\')">';
            $line .= '<i class="bi bi-x-lg"></i> Desactivar DNSSEC</button>';
            $line .= self::getCSFR_Tag();
            $line .= '</form>';
        } else {
            $line .= '<form action="./?module=dns_manager&action=ToggleDnssec" method="post" style="margin-top:10px">';
            $line .= '<input type="hidden" name="domainID" value="' . $domain['vh_id_pk'] . '">';
            $line .= '<input type="hidden" name="enable" value="1">';
            $line .= '<button type="submit" class="btn btn-primary btn-sm">';
            $line .= '<i class="bi bi-lock"></i> Activar DNSSEC</button>';
            $line .= self::getCSFR_Tag();
            $line .= '</form>';
        }
        $line .= '</div>';

        // Danger zone
        $line .= '<div class="zgrid_wrapper" style="border-left:4px solid #d9534f;padding-left:12px;margin-top:16px">';
        $line .= '<h4 style="color:#d9534f"><i class="bi bi-exclamation-triangle"></i> ' . ui_language::translate('Danger Zone') . '</h4>';
        $line .= '<p>' . ui_language::translate('Delete ALL DNS records for this domain. The domain will be isolated from the internet.') . '</p>';
        $line .= '<form id="dnsDeleteAllForm" action="./?module=dns_manager&action=DeleteAllDNS" method="post">';
        $line .= '<input type="hidden" name="domainID" value="' . $domain['vh_id_pk'] . '">';
        $line .= '<button type="button" class="btn btn-danger" onclick="dnsConfirmDeleteAll(\'' . addslashes($domain['vh_name_vc']) . '\')">';
        $line .= '<i class="bi bi-trash"></i>  ' . ui_language::translate('Delete All DNS Records') . '</button>';
        $line .= self::getCSFR_Tag();
        $line .= '</form>';
        $line .= '<script>
function dnsConfirmDeleteAll(domain) {
    if (confirm("¿Borrar TODOS los registros DNS de " + domain + "?\nEl dominio quedará aislado de internet.")) {
        document.getElementById("dnsDeleteAllForm").submit();
    }
}
</script>';
        $line .= '</div>';

        // Zone Export
        try {
            $zonefile = ctrl_options::GetSystemOption('zone_dir') . $domain['vh_name_vc'] . '.txt';
            $zoneContent = file_exists($zonefile) ? file_get_contents($zonefile) : false;
        } catch (Throwable $e) {
            error_log('[dns_manager] Zone Export error: ' . $e->getMessage());
            $zoneContent = false;
        }
        $zoneFileMtime = ($zoneContent !== false) ? filemtime($zonefile) : false;
        $line .= '<div class="zgrid_wrapper">';
        $line .= '<h4>' . ui_language::translate('Zone Export') . '</h4>';
        if ($zoneContent !== false) {
            $line .= '<p class="text-muted" style="font-size:12px;margin-bottom:6px"><i class="bi bi-clock"></i> '
                . ui_language::translate('Zone file last updated') . ': <b>'
                . date('Y-m-d H:i:s', $zoneFileMtime)
                . '</b> &mdash; ' . ui_language::translate('Reflects the last daemon sync, not unsaved edits.') . '</p>';
            $line .= '<p>';
            $line .= '<button type="button" class="btn btn-secondary btn-sm" onclick="toggleZoneExport(this)">';
            $line .= '<i class="bi bi-eye"></i> ' . ui_language::translate('Show Zone File') . '</button>';
            $line .= '&nbsp;<button type="button" class="btn btn-secondary btn-sm" onclick="downloadZone(\'' . addslashes($domain['vh_name_vc']) . '\')">';
            $line .= '<i class="bi bi-download"></i> ' . ui_language::translate('Download') . '</button>';
            $line .= '</p>';
            $line .= '<div id="zoneExportContent" style="display:none;margin-top:8px">';
            $line .= '<textarea id="zoneExportText" style="width:100%;height:300px;font-family:monospace;font-size:11px" readonly>';
            $line .= htmlspecialchars($zoneContent, ENT_NOQUOTES, 'UTF-8');
            $line .= '</textarea>';
            $line .= '</div>';
            $line .= '<script>
function toggleZoneExport(btn) {
    var el = document.getElementById("zoneExportContent");
    var shown = el.style.display !== "none";
    el.style.display = shown ? "none" : "block";
    btn.innerHTML = shown ? \'<span class="bi bi-eye"></span> Show Zone File\'
                          : \'<span class="bi bi-eye-slash"></span> Hide Zone File\';
}
function downloadZone(domain) {
    var text = document.getElementById("zoneExportText").value;
    var blob = new Blob([text], {type: "text/plain"});
    var a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = domain + ".zone";
    a.click();
    URL.revokeObjectURL(a.href);
}
</script>';
        } else {
            $line .= '<p class="text-muted">' . ui_language::translate('Zone file not yet generated. Use the Sync button to generate it.') . '</p>';
        }
        $line .= '</div>';

        $line .= '</div>';


        $line .='
<div id="dns-modal" class="modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-circle"></i> Error</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="dns-modal-body">
                <p>Se ha producido un error al procesar la operación DNS.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal"><i class="bi bi-x me-1"></i>Cerrar</button>
            </div>
        </div>
    </div>
</div>';

        return $line;
    }

    /*
     * Show Domain Dropdown list/entrance page
     * If no domains exist it shows an Empty list
     * TODO: Tell them to add a domain if no domains exist
     */

    static function DisplayDomains()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();

        // Domains WITH active DNS records
        $line = '<div class="zgrid_wrapper">';
        $line .= "<h2>" . ui_language::translate("Manage Domains") . "</h2>";
        $line .= ui_language::translate("Choose fom the list of domains below");
        $line .= '<form id="dnsSelectForm" name="DisplayDNS" action="./?module=dns_manager&action=DisplayRecords" method="post">';
        $line .= "<br><br>";
        $line .= "<table class=\"zform\"><tr>";
        $line .= '<td><select name="inDomain" id="inDomain" onchange="dnsDomainChanged(this.value)">';
        $line .= '<option value="" selected="selected">-- ' . ui_language::translate("Select a domain") . ' --</option>';
        $sql = $zdbh->prepare(
            "SELECT vh_id_pk, vh_name_vc FROM x_vhosts
             WHERE vh_acc_fk=:userid AND vh_type_in!=2 AND vh_deleted_ts IS NULL
               AND vh_id_pk IN (SELECT DISTINCT dn_vhost_fk FROM x_dns WHERE dn_deleted_ts IS NULL)
             ORDER BY vh_name_vc ASC"
        );
        $sql->bindParam(':userid', $currentuser['userid']);
        $sql->execute();
        while ($rowdomains = $sql->fetch()) {
            $line .= '<option value="' . $rowdomains['vh_id_pk'] . '">' . $rowdomains['vh_name_vc'] . '</option>';
        }
        $line .= "</select></td>";
        $line .= "<td>";
        $line .= '<button type="button" id="btnEditDomain" class="btn btn-lg disabled" onclick="dnsSubmitAction(\'DisplayRecords\')"><i class="bi bi-pencil"></i>  ' . ui_language::translate("Edit") . '</button>';
        $line .= '&nbsp;';
        $line .= '<button type="button" id="btnRegenZone" class="btn btn-lg disabled" onclick="dnsSubmitAction(\'RegenerateZone\')"><i class="bi bi-arrow-clockwise"></i>  ' . ui_language::translate("Sync") . '</button>';
        $line .= "</td></tr></table>";
        $line .= self::getCSFR_Tag();
        $line .= '</form>';

        // Domains WITHOUT active DNS records (Create section)
        $sqlNoDNS = $zdbh->prepare(
            "SELECT vh_id_pk, vh_name_vc FROM x_vhosts
             WHERE vh_acc_fk=:userid AND vh_type_in!=2 AND vh_deleted_ts IS NULL
               AND vh_id_pk NOT IN (SELECT DISTINCT dn_vhost_fk FROM x_dns WHERE dn_deleted_ts IS NULL)
             ORDER BY vh_name_vc ASC"
        );
        $sqlNoDNS->bindParam(':userid', $currentuser['userid']);
        $sqlNoDNS->execute();
        $domainsNoDNS = $sqlNoDNS->fetchAll();

        if (!empty($domainsNoDNS)) {
            $line .= '<hr>';
            $line .= '<p>' . ui_language::translate("Domains without DNS records") . ':</p>';
            $line .= '<form id="dnsCreateForm" action="./?module=dns_manager&action=CreateDefaultRecords" method="post">';
            $line .= "<table class=\"zform\"><tr>";
            $line .= '<td><select name="inDomain" id="inDomainCreate" onchange="dnsCreateChanged(this.value)">';
            $line .= '<option value="" selected="selected">-- ' . ui_language::translate("Select a domain") . ' --</option>';
            foreach ($domainsNoDNS as $d) {
                $line .= '<option value="' . $d['vh_id_pk'] . '">' . $d['vh_name_vc'] . '</option>';
            }
            $line .= "</select></td>";
            $line .= '<td><button type="button" id="btnCreateDNS" class="btn btn-lg disabled" onclick="dnsCreateSubmit()"><i class="bi bi-plus-lg"></i>  ' . ui_language::translate("Create") . '</button></td>';
            $line .= "</tr></table>";
            $line .= self::getCSFR_Tag();
            $line .= '</form>';
        }

        $line .= '<script>
function dnsDomainChanged(val) {
    var d = !val;
    document.getElementById("btnEditDomain").classList.toggle("disabled", d);
    document.getElementById("btnRegenZone").classList.toggle("disabled", d);
}
function dnsSubmitAction(action) {
    var form = document.getElementById("dnsSelectForm");
    form.action = "./?module=dns_manager&action=" + action;
    form.submit();
}
function dnsCreateChanged(val) {
    document.getElementById("btnCreateDNS").classList.toggle("disabled", !val);
}
function dnsCreateSubmit() {
    var sel = document.getElementById("inDomainCreate");
    var name = sel.options[sel.selectedIndex].text;
    if (confirm("¿Crear registros DNS por defecto para " + name + "?")) {
        document.getElementById("dnsCreateForm").submit();
    }
}
</script>';
        $line .= '<p>&nbsp;</p>';
        $line .= '</div>';
        return $line;
    }

    static function doEditClient()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $sql = $zdbh->prepare("SELECT * FROM x_accounts WHERE ac_reseller_fk=:userid AND ac_deleted_ts IS NULL");
        $sql->bindParam(':userid', $currentuser['userid']);
        $sql->execute();
        while ($rowclients = $sql->fetch()) {
            if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inEdit_' . $rowclients['ac_id_pk'] . ''))) {
                self::$editdomain = TRUE;
                self::$clientid = $rowclients['ac_id_pk'];
                return;
            }
            if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inDelete_' . $rowclients['ac_id_pk'] . ''))) {
                self::DeleteClient($rowclients['ac_id_pk']);
                return;
            }
        }
    }

    static function doDisplayRecords()
    {
        global $controller;
        runtime_csfr::Protect();
        $domainID = (int)$controller->GetControllerRequest('FORM', 'inDomain');
        if ($domainID > 0 && !headers_sent()) {
            header('Location: ./?module=dns_manager&domainID=' . $domainID);
            exit();
        }
        self::$editdomain = $domainID ?: false;
        return;
    }

    static function doRegenerateZone()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $domainID = $controller->GetControllerRequest('FORM', 'inDomain');
        if (fs_director::CheckForEmptyValue($domainID)) {
            self::$ResultErr = 'No domain selected.';
            return;
        }
        $check = $zdbh->prepare("SELECT vh_name_vc FROM x_vhosts WHERE vh_id_pk=:did AND vh_acc_fk=:uid AND vh_deleted_ts IS NULL");
        $check->bindParam(':did', $domainID);
        $check->bindParam(':uid', $currentuser['userid']);
        $check->execute();
        $vhost = $check->fetch();
        if (!$vhost) {
            self::$ResultErr = 'Domain not found.';
            return;
        }
        self::TriggerDNSUpdate($domainID);
        self::$ResultOk = 'Zone regeneration queued for ' . $vhost['vh_name_vc'] . '. Active in less than 5 minutes.';
        return;
    }

    static function doDeleteAllDNS()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $domainID = $controller->GetControllerRequest('FORM', 'domainID');
        if (fs_director::CheckForEmptyValue($domainID)) {
            self::$ResultErr = 'No domain specified.';
            return;
        }
        $check = $zdbh->prepare("SELECT vh_name_vc FROM x_vhosts WHERE vh_id_pk=:did AND vh_acc_fk=:uid AND vh_deleted_ts IS NULL");
        $check->bindParam(':did', $domainID);
        $check->bindParam(':uid', $currentuser['userid']);
        $check->execute();
        $vhost = $check->fetch();
        if (!$vhost) {
            self::$ResultErr = 'Domain not found.';
            return;
        }
        $del = $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=:time WHERE dn_vhost_fk=:did AND dn_deleted_ts IS NULL");
        $del->bindParam(':did', $domainID);
        $time = time();
        $del->bindParam(':time', $time);
        $del->execute();
        self::TriggerDNSUpdate($domainID);
        self::$ResultOk = 'All DNS records for ' . $vhost['vh_name_vc'] . ' have been deleted.';
        return;
    }

    static function doRegenerateDKIM()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();

        $domainID = $controller->GetControllerRequest('FORM', 'domainID');
        if (fs_director::CheckForEmptyValue($domainID)) {
            self::$ResultErr = 'No domain specified.';
            return;
        }

        // Verify the domain belongs to the current user
        $check = $zdbh->prepare(
            "SELECT vh_id_pk, vh_name_vc FROM x_vhosts
             WHERE vh_id_pk=:did AND vh_acc_fk=:uid AND vh_deleted_ts IS NULL"
        );
        $check->bindParam(':did', $domainID);
        $check->bindParam(':uid', $currentuser['userid']);
        $check->execute();
        $vhost = $check->fetch();
        if (!$vhost) {
            self::$ResultErr = 'Domain not found or access denied.';
            return;
        }

        // Find the existing default._domainkey record
        $rec = $zdbh->prepare(
            "SELECT dn_id_pk FROM x_dns
             WHERE dn_vhost_fk=:did AND dn_host_vc='default._domainkey'
               AND dn_type_vc='TXT' AND dn_deleted_ts IS NULL"
        );
        $rec->bindParam(':did', $domainID);
        $rec->execute();
        $existing = $rec->fetch();

        if ($existing) {
            // Reset to PENDING — daemon overwrites the key file and updates the zone
            $upd = $zdbh->prepare("UPDATE x_dns SET dn_target_vc='PENDING' WHERE dn_id_pk=:id");
            $upd->bindParam(':id', $existing['dn_id_pk']);
            $upd->execute();
        } else {
            // No DKIM record yet — create placeholder
            self::createDNSRecord([
                'uid'        => $currentuser['userid'],
                'domainName' => $vhost['vh_name_vc'],
                'domainID'   => $domainID,
                'type'       => 'TXT',
                'hostName'   => 'default._domainkey',
                'ttl'        => 3600,
                'target'     => 'PENDING',
            ]);
        }

        self::TriggerDNSUpdate($domainID);
        self::$ResultOk = 'Nueva clave DKIM en proceso. Estará activa en menos de 5 minutos.';
        self::$editdomain = $domainID;
        return;
    }

    static function doToggleDnssec()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();

        $domainID = (int)$controller->GetControllerRequest('FORM', 'domainID');
        $enable   = (int)$controller->GetControllerRequest('FORM', 'enable');

        if (!$domainID) {
            self::$ResultErr = 'No domain specified.';
            return;
        }

        $check = $zdbh->prepare(
            "SELECT vh_name_vc FROM x_vhosts WHERE vh_id_pk=:did AND vh_acc_fk=:uid AND vh_deleted_ts IS NULL"
        );
        $check->bindParam(':did', $domainID);
        $check->bindParam(':uid', $currentuser['userid']);
        $check->execute();
        $vhost = $check->fetch();
        if (!$vhost) {
            self::$ResultErr = 'Domain not found or access denied.';
            return;
        }

        if ($enable) {
            $ts = time();
            $ins = $zdbh->prepare(
                "INSERT IGNORE INTO x_dns_dnssec (dd_vhost_fk, dd_enabled_in, dd_enabled_ts) VALUES (:vid, 1, :ts)"
            );
            $ins->bindParam(':vid', $domainID);
            $ins->bindParam(':ts',  $ts);
            $ins->execute();
            $upd = $zdbh->prepare(
                "UPDATE x_dns_dnssec SET dd_enabled_in=1, dd_enabled_ts=:ts WHERE dd_vhost_fk=:vid"
            );
            $upd->bindParam(':vid', $domainID);
            $upd->bindParam(':ts',  $ts);
            $upd->execute();
            self::$ResultOk = 'DNSSEC activado para ' . $vhost['vh_name_vc'] . '. Las claves se generarán en menos de 5 minutos.';
        } else {
            $upd = $zdbh->prepare(
                "UPDATE x_dns_dnssec SET dd_enabled_in=0, dd_ds_txt=NULL, dd_keytag_in=NULL WHERE dd_vhost_fk=:vid"
            );
            $upd->bindParam(':vid', $domainID);
            $upd->execute();
            self::$ResultOk = 'DNSSEC desactivado para ' . $vhost['vh_name_vc'] . '. Recuerda eliminar el DS record de tu registrador.';
        }

        self::TriggerDNSUpdate($domainID);
        self::$editdomain = $domainID;
        return;
    }

    static function doSaveDNS()
    {
        global $zdbh;
        global $controller;

        // Tell the browser and any intermediate proxy NOT to cache this response.
        // If a stale cached page is being served, the user sees the previous result
        // (e.g. an apparently blank page after a save error).
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        }

        runtime_csfr::Protect();
        try {
            $validation = self::CheckForErrors();
            if ($validation === true) {
                self::SaveDNS();
                // POST-Redirect-GET: evita que "Navegar atrás → reenviar" cause error CSRF.
                $domainID = (int)$controller->GetControllerRequest('FORM', 'domainID');
                if (!headers_sent() && $domainID > 0) {
                    header('Location: ./?module=dns_manager&domainID=' . $domainID . '&saved=1');
                    exit();
                }
                self::$ResultOk = 'Changes to your DNS have been saved successfully!';
            } elseif ($validation === false && empty(self::$ResultErr)) {
                // Validation returned false but no specific message was set — generic fallback
                self::SetError('The DNS records could not be saved. Please check the format of every field and try again.');
            }
            // else: validation failed and SetError() already populated self::$ResultErr with details
        } catch (Throwable $e) {
            error_log('[dns_manager] doSaveDNS error: ' . $e->getMessage());
            self::SetError('Could not save DNS records: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        return;
    }

    /**
     * Creates a new DNS record from an array of key value pairs.
     * @param array $rec Array of record properties (uid, domainName, domainID, type, hostName, ttl, target)
     * @return void
     */
    static function createDNSRecord(array $rec)
    {
        global $zdbh;
        $sql = $zdbh->prepare('INSERT INTO x_dns (dn_acc_fk,
                           dn_name_vc,
                           dn_vhost_fk,
                           dn_type_vc,
                           dn_host_vc,
                           dn_ttl_in,
                           dn_target_vc,
                           dn_priority_in,
                           dn_weight_in,
                           dn_port_in,
                           dn_created_ts) VALUES (
                           :userid,
                           :domainName,
                           :domainID,
                           :type_new,
                           :hostName_new,
                           :ttl_new,
                           :target_new,
                           :priority_new,
                           :weight_new,
                           :port_new,
                           :time)'
        );

        $priority_new = array_key_exists('priority', $rec) ? $rec['priority'] : 0;
        $weight_new = array_key_exists('weight', $rec) ? $rec['weight'] : 0;
        $port_new = array_key_exists('port', $rec) ? $rec['port'] : 0;
        $time = array_key_exists('time', $rec) ? $rec['time'] : time();

        $sql->bindParam(':userid', $rec['uid']);
        $sql->bindParam(':domainName', $rec['domainName']);
        $sql->bindParam(':domainID', $rec['domainID']);
        $sql->bindParam(':type_new', $rec['type']);
        $sql->bindParam(':hostName_new', $rec['hostName']);
        $sql->bindParam(':ttl_new', $rec['ttl']);
        $sql->bindParam(':target_new', $rec['target']);
        $sql->bindParam(':priority_new', $priority_new);
        $sql->bindParam(':weight_new', $weight_new);
        $sql->bindParam(':port_new', $port_new);
        $sql->bindParam(':time', $time);
        $sql->execute();

        self::TriggerDNSUpdate($rec['domainID']);
    }

    static function doCreateDefaultRecords()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();

        $domainID = $controller->GetControllerRequest('FORM', 'inDomain');
        // HIGH-4 FIX: never trust inUserID from form — always use the authenticated session user
        $currentuser = ctrl_users::GetUserDetail();
        $userID = $currentuser['userid'];
        // AND vh_acc_fk=:uid previene crear registros DNS en dominios ajenos
        $numrows = $zdbh->prepare('SELECT * FROM x_vhosts WHERE vh_id_pk=:domainID AND vh_acc_fk=:uid AND vh_type_in !=2 AND vh_deleted_ts IS NULL');
        $numrows->bindParam(':domainID', $domainID);
        $numrows->bindParam(':uid', $userID);
        $numrows->execute();
        $domainName = $numrows->fetch();
        if (!$domainName) {
            return;
        }
        $domainName = $domainName['vh_name_vc'];

        // Prevent duplicate creation if the domain already has active DNS records
        $existCheck = $zdbh->prepare('SELECT COUNT(*) FROM x_dns WHERE dn_vhost_fk=:did AND dn_deleted_ts IS NULL AND dn_host_vc != \'default._domainkey\'');
        $existCheck->bindParam(':did', $domainID);
        $existCheck->execute();
        if ($existCheck->fetchColumn() > 0) {
            self::$editdomain = $domainID;
            return;
        }
        if (!fs_director::CheckForEmptyValue(ctrl_options::GetSystemOption('server_ip'))) {
            $targetIP = ctrl_options::GetSystemOption('server_ip');
        } else {
            $targetIP = $_SERVER["SERVER_ADDR"]; //This needs checking on windows 7 we may need to use LOCAL_ADDR :- Sam Mottley
        }
        //Get list of DNS rows to create
        $RowCount = $zdbh->prepare('SELECT count(*) FROM x_dns_create WHERE dc_acc_fk=:userId');
        $RowCount->bindparam(':userId', $userID);
        $RowCount->execute();
        if ($RowCount->fetchColumn() > 0) {
            //The current user have specifics entries, use them only
            $CreateList = $zdbh->prepare('SELECT * FROM x_dns_create WHERE dc_acc_fk=:userId');
            $CreateList->bindparam(':userId', $userID);
            $CreateList->execute();
        } else {
            //no entry specific to this user is present, use default entries (user number = 0)
            $CreateList = $zdbh->query('SELECT * FROM x_dns_create WHERE dc_acc_fk=0');
        }
        while ($CreateItem = $CreateList->fetch()) {
            $Target = str_replace(':IP:', $targetIP, $CreateItem['dc_target_vc']);
            $Target = str_replace(':DOMAIN:', $domainName, $Target);
            // Nameservers compartidos del panel (fallback a ns1/ns2.<dominio> si no
            // se han configurado, para no romper zonas si faltan los ajustes).
            $ns1 = ctrl_options::GetSystemOption('dns_ns1');
            $ns2 = ctrl_options::GetSystemOption('dns_ns2');
            if (fs_director::CheckForEmptyValue($ns1)) { $ns1 = 'ns1.' . $domainName; }
            if (fs_director::CheckForEmptyValue($ns2)) { $ns2 = 'ns2.' . $domainName; }
            $Target = str_replace(':NS1:', $ns1, $Target);
            $Target = str_replace(':NS2:', $ns2, $Target);

            $Row = array(
                'uid' => $userID,
                'domainName' => $domainName,
                'domainID' => $domainID,
                'type' => $CreateItem['dc_type_vc'],
                'hostName' => $CreateItem['dc_host_vc'],
                'ttl' => $CreateItem['dc_ttl_in'],
                'target' => $Target);

            if (!empty($CreateItem['dc_priority_in']))
                $Row['priority'] = $CreateItem['dc_priority_in'];

            if (!empty($CreateItem['dc_weight_in']))
                $Row['weight'] = $CreateItem['dc_weight_in'];

            if (!empty($CreateItem['dc_port_in']))
                $Row['port'] = $CreateItem['dc_port_in'];

            self::createDNSRecord($Row);
        }

        // DKIM: insert a placeholder that the daemon will replace with the real
        // RSA 2048 public key on its next run (within 5 minutes).
        // The daemon's CheckDKIMKeysHook() detects 'PENDING' and generates the key.
        $dkimCheck = $zdbh->prepare(
            "SELECT COUNT(*) FROM x_dns
             WHERE dn_vhost_fk=:did AND dn_host_vc='default._domainkey' AND dn_deleted_ts IS NULL"
        );
        $dkimCheck->bindParam(':did', $domainID);
        $dkimCheck->execute();
        if ($dkimCheck->fetchColumn() == 0) {
            self::createDNSRecord([
                'uid'        => $userID,
                'domainName' => $domainName,
                'domainID'   => $domainID,
                'type'       => 'TXT',
                'hostName'   => 'default._domainkey',
                'ttl'        => 3600,
                'target'     => 'PENDING',
            ]);
        }

        self::$editdomain = $domainID;
        return;
    }

    static function SaveDNS()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $dnsrecords = array();
        //Grab form inputs in array and assign them to variables
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'domainName'))) {
            $domainName = $controller->GetControllerRequest('FORM', 'domainName');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'domainID'))) {
            $domainID = $controller->GetControllerRequest('FORM', 'domainID');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'ttl'))) {
            $ttl = $controller->GetControllerRequest('FORM', 'ttl');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_ttl'))) {
            $original_ttl = $controller->GetControllerRequest('FORM', 'original_ttl');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'target'))) {
            $target = $controller->GetControllerRequest('FORM', 'target');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_target'))) {
            $original_target = $controller->GetControllerRequest('FORM', 'original_target');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'type'))) {
            $type = $controller->GetControllerRequest('FORM', 'type');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'delete'))) {
            $delete = $controller->GetControllerRequest('FORM', 'delete');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'hostName'))) {
            $hostName = $controller->GetControllerRequest('FORM', 'hostName');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'priority'))) {
            $priority = $controller->GetControllerRequest('FORM', 'priority');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_priority'))) {
            $original_priority = $controller->GetControllerRequest('FORM', 'original_priority');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'weight'))) {
            $weight = $controller->GetControllerRequest('FORM', 'weight');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_weight'))) {
            $original_weight = $controller->GetControllerRequest('FORM', 'original_weight');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'port'))) {
            $port = $controller->GetControllerRequest('FORM', 'port');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_port'))) {
            $original_port = $controller->GetControllerRequest('FORM', 'original_port');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'newRecords'))) {
            $newRecords = $controller->GetControllerRequest('FORM', 'newRecords');
        }
        //Get all existing records for domain and add the id's to an array
        $sql = $zdbh->prepare("SELECT dn_id_pk FROM x_dns WHERE dn_acc_fk=:userid AND dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL");
        $sql->bindParam(':userid', $currentuser['userid']);
        $sql->bindParam(':domainID', $domainID);
        $sql->execute();
        while ($rowdns = $sql->fetch()) {
            $dnsrecords[] = $rowdns['dn_id_pk'];
        }
        //Existing Records
        //Sort through the dns record array by id and update as needed
        foreach ($dnsrecords as $id) {
            // Protect DKIM record — managed exclusively through the DKIM section
            $hostCheck = $zdbh->prepare("SELECT dn_host_vc FROM x_dns WHERE dn_id_pk=:id");
            $hostCheck->bindParam(':id', $id);
            $hostCheck->execute();
            $hostRow = $hostCheck->fetch();
            if ($hostRow && $hostRow['dn_host_vc'] === 'default._domainkey') {
                continue;
            }

            if ($delete[$id] == "true") {
                //The record has been marked for deletion, so lets delete it!
                $sql = $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=:time WHERE dn_id_pk =:id AND dn_deleted_ts IS NULL");
                $sql->bindParam(':id', $id);
                $time = time();
                $sql->bindParam(':time', $time);
                $sql->execute();
                self::TriggerDNSUpdate($domainID);
            } else {
                //The record needs updating instead.
                //TTL
                if (isset($ttl[$id]) && !fs_director::CheckForEmptyValue($ttl[$id]) && $ttl[$id] != $original_ttl[$id] && is_numeric($ttl[$id])) {
                    $sql = $zdbh->prepare("UPDATE x_dns SET dn_ttl_in=:cleanRecord WHERE dn_id_pk = :id AND dn_deleted_ts IS NULL");
                    $sql->bindParam(':id', $id);
                    $cleanRecord = self::CleanRecord($ttl[$id], $type[$id]);
                    $sql->bindParam(':cleanRecord', $cleanRecord);
                    $sql->execute();
                }
                //TARGET
                if (isset($target[$id]) && !fs_director::CheckForEmptyValue($target[$id]) && $target[$id] != $original_target[$id]) {
                    $sql = $zdbh->prepare("UPDATE x_dns SET dn_target_vc=:cleanRecord WHERE dn_id_pk = :id AND dn_deleted_ts IS NULL");
                    $sql->bindParam(':id', $id);
                    $cleanRecord = self::CleanRecord($target[$id], $type[$id]);
                    $sql->bindParam(':cleanRecord', $cleanRecord);
                    $sql->execute();
                }
                //PRIORITY
                if (isset($priority[$id]) && !fs_director::CheckForEmptyValue($priority[$id]) && $priority[$id] != $original_priority[$id]) {
                    $sql = $zdbh->prepare("UPDATE x_dns SET dn_priority_in=:cleanRecord WHERE dn_id_pk = :id AND dn_deleted_ts IS NULL");
                    $sql->bindParam(':id', $id);
                    $cleanRecord = self::CleanRecord($priority[$id], $type[$id]);
                    $sql->bindParam(':cleanRecord', $cleanRecord);
                    $sql->execute();
                }
                //WEIGHT
                if (isset($weight[$id]) && !fs_director::CheckForEmptyValue($weight[$id]) && $weight[$id] != $original_weight[$id]) {
                    $sql = $zdbh->prepare("UPDATE x_dns SET dn_weight_in=:cleanRecord WHERE dn_id_pk = :id AND dn_deleted_ts IS NULL");
                    $sql->bindParam(':id', $id);
                    $cleanRecord = self::CleanRecord($weight[$id], $type[$id]);
                    $sql->bindParam(':cleanRecord', $cleanRecord);
                    $sql->execute();
                }
                //PORT
                if (isset($port[$id]) && !fs_director::CheckForEmptyValue($port[$id]) && $port[$id] != $original_port[$id]) {
                    $sql = $zdbh->prepare("UPDATE x_dns SET dn_port_in=:cleanRecord WHERE dn_id_pk = :id AND dn_deleted_ts IS NULL");
                    $sql->bindParam(':id', $id);
                    $cleanRecord = self::CleanRecord($port[$id], $type[$id]);
                    $sql->bindParam(':cleanRecord', $cleanRecord);
                    $sql->execute();
                }
                //Flag the record for needing updating on next daemon run...
                self::TriggerDNSUpdate($domainID);
            }
        }
        //NEW Records
        //Find all new records in post array
        if (isset($newRecords) && !fs_director::CheckForEmptyValue($newRecords)) {
            $numnew = $newRecords;
            $id = 1;
            while ($numnew >= $id) {
                if (isset($type['new_' . $id]) && !fs_director::CheckForEmptyValue($target['new_' . $id])) {
                    if ($delete['new_' . $id] != "true" && !fs_director::CheckForEmptyValue($type['new_' . $id])) {
                        if (isset($hostName['new_' . $id])) {
                            $hostName_new = self::CleanRecord($hostName['new_' . $id], $type['new_' . $id]);
                        } else {
                            $hostName_new = "NULL";
                        }
                        if (isset($type['new_' . $id]) && !fs_director::CheckForEmptyValue($type['new_' . $id])) {
                            $type_new = $type['new_' . $id];
                        } else {
                            $type_new = "NULL";
                        }
                        if (isset($ttl['new_' . $id]) && !fs_director::CheckForEmptyValue($ttl['new_' . $id])) {
                            $ttl_new = self::CleanRecord($ttl['new_' . $id], $type['new_' . $id]);
                        } else {
                            $ttl_new = "0";
                        }
                        if (isset($target['new_' . $id]) && !fs_director::CheckForEmptyValue($target['new_' . $id])) {
                            //If Custom IP addresses are not allowed.
                            if ($type['new_' . $id] == 'A') {
                                if (ctrl_options::GetSystemOption('custom_ip') == 'false') {
                                    if (!fs_director::CheckForEmptyValue(ctrl_options::GetSystemOption('server_ip'))) {
                                        $target['new_' . $id] = ctrl_options::GetSystemOption('server_ip');
                                    } else {
                                        $target['new_' . $id] = $_SERVER["SERVER_ADDR"];
                                    }
                                }
                            }
                            $target_new = self::CleanRecord($target['new_' . $id], $type['new_' . $id]);
                        } else {
                            $target_new = "NULL";
                        }
                        if (isset($priority['new_' . $id]) && !fs_director::CheckForEmptyValue($priority['new_' . $id])) {
                            $priority_new = self::CleanRecord($priority['new_' . $id], $type['new_' . $id]);
                        } else {
                            $priority_new = "0";
                        }
                        if (isset($weight['new_' . $id]) && !fs_director::CheckForEmptyValue($weight['new_' . $id])) {
                            $weight_new = self::CleanRecord($weight['new_' . $id], $type['new_' . $id]);
                        } else {
                            $weight_new = "0";
                        }
                        if (isset($port['new_' . $id]) && !fs_director::CheckForEmptyValue($port['new_' . $id])) {
                            $port_new = self::CleanRecord($port['new_' . $id], $type['new_' . $id]);
                        } else {
                            $port_new = "0";
                        }
                        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                           dn_name_vc,
                           dn_vhost_fk,
                           dn_type_vc,
                           dn_host_vc,
                           dn_ttl_in,
                           dn_target_vc,
                           dn_priority_in,
                           dn_weight_in,
                           dn_port_in,
                           dn_created_ts) VALUES (
                           :userid,
                           :domainName,
                           :domainID,
                           :type_new,
                           :hostName_new,
                           :ttl_new,
                           :target_new,
                           :priority_new,
                           :weight_new,
                           :port_new,
                           :time)"
                        );
                        $sql->bindParam(':userid', $currentuser['userid']);
                        $sql->bindParam(':domainName', $domainName);
                        $sql->bindParam(':domainID', $domainID);
                        $sql->bindParam(':type_new', $type_new);
                        $sql->bindParam(':hostName_new', $hostName_new);
                        $sql->bindParam(':ttl_new', $ttl_new);
                        $sql->bindParam(':target_new', $target_new);
                        $sql->bindParam(':priority_new', $priority_new);
                        $sql->bindParam(':weight_new', $weight_new);
                        $sql->bindParam(':port_new', $port_new);
                        $time = time();
                        $sql->bindParam(':time', $time);
                        $sql->execute();
                        //Flag the record for needing updating on next daemon run...
                        self::TriggerDNSUpdate($domainID);
                    }
                }
                $id++;
            }
        }
        return;
    }

    //Use the same method as above and check for input errors doSaveDNS() uses before continuing.
    static function CheckForErrors()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $dnsrecords = array();

        //Grab form inputs in array and assign them to variables
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'domainName'))) {
            $domainName = $controller->GetControllerRequest('FORM', 'domainName');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'domainID'))) {
            $domainID = $controller->GetControllerRequest('FORM', 'domainID');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'ttl'))) {
            $ttl = $controller->GetControllerRequest('FORM', 'ttl');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_ttl'))) {
            $original_ttl = $controller->GetControllerRequest('FORM', 'original_ttl');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'target'))) {
            $target = $controller->GetControllerRequest('FORM', 'target');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_target'))) {
            $original_target = $controller->GetControllerRequest('FORM', 'original_target');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'type'))) {
            $type = $controller->GetControllerRequest('FORM', 'type');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'delete'))) {
            $delete = $controller->GetControllerRequest('FORM', 'delete');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'hostName'))) {
            $hostName = $controller->GetControllerRequest('FORM', 'hostName');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'priority'))) {
            $priority = $controller->GetControllerRequest('FORM', 'priority');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_priority'))) {
            $original_priority = $controller->GetControllerRequest('FORM', 'original_priority');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'weight'))) {
            $weight = $controller->GetControllerRequest('FORM', 'weight');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_weight'))) {
            $original_weight = $controller->GetControllerRequest('FORM', 'original_weight');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'port'))) {
            $port = $controller->GetControllerRequest('FORM', 'port');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'original_port'))) {
            $original_port = $controller->GetControllerRequest('FORM', 'original_port');
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'newRecords'))) {
            $newRecords = $controller->GetControllerRequest('FORM', 'newRecords');
        }
        //Get all existing records for domain and add the id's to an array
        $sql = $zdbh->prepare('SELECT dn_id_pk FROM x_dns WHERE dn_acc_fk=:userid AND dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL');
        $sql->bindParam(':userid', $currentuser['userid']);
        $sql->bindParam(':domainID', $domainID);
        $sql->execute();
        while ($rowdns = $sql->fetch()) {
            $dnsrecords[] = $rowdns['dn_id_pk'];
        }

        //Existing Records
        //Sort through the dns record array by id and update as needed
        foreach ($dnsrecords as $id) {
            if ($delete[$id] == "false") {
                //TTL
                if (isset($ttl[$id]) && !fs_director::CheckForEmptyValue($ttl[$id]) && $ttl[$id] != $original_ttl[$id]) {
                    if (!is_numeric($ttl[$id])) {
                        self::SetError('TTL must be a numeric value.');
                        return FALSE;
                    }
                }

                //TARGET
                if (isset($target[$id]) && !fs_director::CheckForEmptyValue($target[$id]) && $target[$id] != $original_target[$id]) {
                    if (!self::validateDnsTarget($type[$id], $target[$id], $id)) return FALSE;
                }

                //PRIORITY
                if (isset($priority[$id]) && !fs_director::CheckForEmptyValue($priority[$id]) && $priority[$id] != $original_priority[$id]) {
                    if (!self::validateNumericRange($priority[$id], 'Priority', 0, 65535)) return FALSE;
                }

                //WEIGHT
                if (isset($weight[$id]) && !fs_director::CheckForEmptyValue($weight[$id]) && $weight[$id] != $original_weight[$id]) {
                    if (!self::validateNumericRange($weight[$id], 'Weight', 0, 65535)) return FALSE;
                }

                //PORT
                if (isset($port[$id]) && !fs_director::CheckForEmptyValue($port[$id]) && $port[$id] != $original_port[$id]) {
                    if (!self::validateNumericRange($port[$id], 'PORT', 0, 65535)) return FALSE;
                }
            }
        }

        //NEW Records
        //Find all new records in post array
        if (isset($newRecords) && !fs_director::CheckForEmptyValue($newRecords)) {
            $numnew = $newRecords;
            for ($id = 1; $id <= $numnew; $id++) {
                $NewId = 'new_' . $id;
                if (isset($type[$NewId])) {
                    if ($delete[$NewId] == "false" && !fs_director::CheckForEmptyValue($type[$NewId])) {
                        //HOSTNAME
                        if (isset($hostName[$NewId]) && !fs_director::CheckForEmptyValue($hostName[$NewId]) && $hostName[$NewId] != "@") {
                            //Check that hostname does not already exist.
                            $numrows = $zdbh->prepare('SELECT dn_id_pk FROM x_dns WHERE dn_host_vc=:hostName2 AND dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL');
                            $hostName2 = $hostName[$NewId];
                            $numrows->bindParam(':hostName2', $hostName2);
                            $numrows->bindParam(':domainID', $domainID);
                            $numrows->execute();
                            if ($numrows->fetch()) {
                                self::SetError('Hostnames must be unique.');
                                return FALSE;
                            }

                            if ($type[$NewId] != "SRV") {
                                if (!($hostName[$NewId] == '*' or self::IsValidTargetName($hostName[$NewId]) )) {
                                    self::SetError('Hostname invalid.');
                                    return FALSE;
                                }
                            }
                        }
                        //TTL
                        if (isset($ttl[$NewId]) && !fs_director::CheckForEmptyValue($ttl[$NewId])) {
                            if (!is_numeric($ttl[$NewId])) {
                                self::SetError('TTL must be a numeric value.');
                                return FALSE;
                            }
                        }
                        //TARGET
                        if (isset($target[$NewId]) && !fs_director::CheckForEmptyValue($target[$NewId])) {
                            if (!self::validateDnsTarget($type[$NewId], $target[$NewId], $NewId)) return FALSE;
                        }
                        //PRIORITY
                        if (isset($priority[$NewId]) && !fs_director::CheckForEmptyValue($priority[$NewId])) {
                            if (!self::validateNumericRange($priority[$NewId], 'Priority', 0, 65535)) return FALSE;
                        }
                        //WEIGHT
                        if (isset($weight[$NewId]) && !fs_director::CheckForEmptyValue($weight[$NewId])) {
                            if (!self::validateNumericRange($weight[$NewId], 'Weight', 0, 65535)) return FALSE;
                        }
                        //PORT
                        if (isset($port[$NewId]) && !fs_director::CheckForEmptyValue($port[$NewId])) {
                            if (!self::validateNumericRange($port[$NewId], 'PORT', 0, 65535)) return FALSE;
                        }
                    }
                }
            }
        }
        // NS minimum protection: at least 2 NS records must remain after this save
        if (isset($type) && is_array($type)) {
            $nsTotalExisting = 0;
            $nsRemaining = 0;
            foreach ($dnsrecords as $id) {
                if (isset($type[$id]) && $type[$id] === 'NS') {
                    $nsTotalExisting++;
                    if (!isset($delete[$id]) || $delete[$id] !== 'true') {
                        $nsRemaining++;
                    }
                }
            }
            // Count new NS records being added in this save
            if (isset($newRecords) && !fs_director::CheckForEmptyValue($newRecords)) {
                for ($i = 1; $i <= (int)$newRecords; $i++) {
                    $NewId = 'new_' . $i;
                    if (isset($type[$NewId]) && $type[$NewId] === 'NS'
                        && isset($target[$NewId]) && !fs_director::CheckForEmptyValue($target[$NewId])
                        && (!isset($delete[$NewId]) || $delete[$NewId] !== 'true')) {
                        $nsRemaining++;
                    }
                }
            }
            if ($nsTotalExisting > 0 && $nsRemaining < 2) {
                self::SetError('A minimum of 2 NS records is required. Please keep at least 2 NS records for this domain.');
                return FALSE;
            }
        }
        return true;
    }

    private static function extractDnsFormInputs(): array {
        global $controller;
        $fields = ['domainName', 'domainID', 'ttl', 'original_ttl', 'target', 'original_target',
                    'type', 'delete', 'hostName', 'priority', 'original_priority',
                    'weight', 'original_weight', 'port', 'original_port', 'newRecords'];
        $result = [];
        foreach ($fields as $f) {
            $val = $controller->GetControllerRequest('FORM', $f);
            if (!fs_director::CheckForEmptyValue($val)) {
                $result[$f] = $val;
            }
        }
        return $result;
    }

    private static function validateDnsTarget(string $type, $target, $id): bool {
        if ($type === 'A') {
            if (!self::IsValidIPv4($target)) { self::SetError('IP Address is not a valid IPV4 address.'); return false; }
        } elseif ($type === 'AAAA') {
            if (!self::IsValidIPv6($target)) { self::SetError('IP Address is not a valid IPV6 address'); return false; }
        } elseif ($type === 'TXT' || $type === 'SPF' || $type === 'NS') {
            // no validation needed
        } elseif ($type === 'CAA') {
            if (!preg_match('/^\d+\s+\S+\s+"[^"]*"$/', $target)) {
                self::SetError('Invalid CAA record format. Required: &lt;flags&gt; &lt;tag&gt; "&lt;value&gt;" — e.g. 0 issue "letsencrypt.org"'); return false;
            }
        } elseif ($type === 'NAPTR') {
            if (!preg_match('/^\d+\s+\d+\s+"[^"]*"\s+"[^"]*"\s+"[^"]*"\s+\S+$/', $target)) {
                self::SetError('Formato NAPTR inválido. Ej: 100 10 "u" "E2U+sip" "!^.*$!sip:info@example.com!" .'); return false;
            }
        } elseif ($type === 'SSHFP') {
            if (!preg_match('/^[1-4]\s+[12]\s+[0-9a-fA-F]{20,128}$/', $target)) {
                self::SetError('Formato SSHFP inválido. Ej: 4 2 abc123... (algoritmo 1-4, tipo 1-2, hex)'); return false;
            }
        } elseif ($type === 'TLSA') {
            $val = trim((string)$target);
            if (!preg_match('/^[0-3]\s+[01]\s+[012]\s+[0-9a-fA-F]{2,512}$/', $val)) {
                self::SetError('Formato TLSA inválido. Ej: 3 1 1 abc123... (uso 0-3, selector 0-1, matching 0-2, hex)'); return false;
            }
        } elseif ($type === 'URI') {
            if (!preg_match('/^\d+\s+\d+\s+"[^"]*"$/', $target)) {
                self::SetError('Formato URI inválido. Ej: 10 1 "https://example.com/"'); return false;
            }
        } else {
            if (!self::IsValidIP($target) && !self::IsValidDomainName($target)) {
                self::SetError('An invalid domain name character was entered. Domain names are limited to alphanumeric characters and hyphens.'); return false;
            }
            if (!self::IsValidDomainName($target) && !self::IsValidIP($target)) {
                self::SetError('Target is not a valid IP address'); return false;
            }
        }
        return true;
    }

    private static function validateNumericRange($val, string $name, int $min, int $max): bool {
        if (!is_numeric($val)) {
            self::SetError($name . ' must be a numeric value.');
            return false;
        }
        if ($val < $min || $val > $max) {
            self::SetError('The ' . strtolower($name) . ' of a dns record must be a numeric value between ' . $min . ' and ' . $max);
            return false;
        }
        return true;
    }

    static function IsValidDomainName($a)
    {
        if ($a != "@") {
            $part = explode(".", $a);
            foreach ($part as $check) {
                if (!preg_match('/^[a-z\d][a-z\d\-]{0,62}$/i', $check) || preg_match('/-$/', $check)) {
                    return false;
                }
            }
        }
        return true;
    }

    static function IsValidTargetName($a)
    {
        if ($a != "@") {
            $part = explode(".", $a);
            foreach ($part as $check) {
                if (!preg_match('/^[a-z\d_][a-z\d\-_]{0,62}$/i', $check) || preg_match('/-$/', $check)) {
                    return false;
                }
            }
        }
        return true;
    }

    static function CleanRecord($data, $type)
    {
        $data = trim($data);
        if ($type == 'SPF' || $type == 'TXT') {
            $data = str_replace('"', '', $data);
            $data = str_replace('\'', '', $data);
            $data = addslashes($data);
        } elseif ($type == 'CAA' || $type == 'NAPTR' || $type == 'SSHFP' || $type == 'TLSA' || $type == 'URI') {
            // Free-form RDATA — preserve spaces and quotes as-is
        } else {
            $data = str_replace(' ', '', $data);
        }
        //Add '@' if hostname is blank on NS and MX records.
        if ($type == 'NS' || $type == 'MX') {
            if ($data == '') {
                $data = "@";
            }
        }

        // Preserve case for TXT, SPF, CAA and free-form RDATA types
        if (!($type == 'SPF' || $type == 'TXT' || $type == 'CAA' || $type == 'NAPTR' || $type == 'SSHFP' || $type == 'TLSA' || $type == 'URI')) {
            $data = strtolower($data);
        }

        return $data;
    }

    static function IsTypeAllowed($type)
    {
        global $zdbh;
        $record_types = ctrl_options::GetSystemOption('allowed_types');
        $record_types = explode(' ', $record_types);
        if (in_array($type, $record_types)) {
            return TRUE;
        } else {
            return FALSE;
        }
    }

    static function IsValidIP($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return TRUE;
        } else {
            return FALSE;
        }
    }

    static function IsValidIPv4($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return TRUE;
        } else {
            return FALSE;
        }
    }

    static function IsValidIPv6($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return TRUE;
        } else {
            return FALSE;
        }
    }

    static function getResult()
    {
        // Hardened: never let the notice rendering itself crash the page.
        // If ui_sysmessage or ui_language throw, fall back to a plain Bootstrap alert.
        try {
            if (!fs_director::CheckForEmptyValue(self::$ResultOk)) {
                return ui_sysmessage::shout(ui_language::translate(self::$ResultOk), 'zannouncesuccess', 'SUCCESS DNS SAVED');
            } elseif (!fs_director::CheckForEmptyValue(self::$ResultErr)) {
                return ui_sysmessage::shout(ui_language::translate(self::$ResultErr), 'zannounceerror', 'ERROR DNS NOT SAVED');
            }
        } catch (Throwable $e) {
            error_log('[dns_manager] getResult error: ' . $e->getMessage());
            $msg = self::$ResultErr ?: self::$ResultOk;
            if ($msg !== null && $msg !== '') {
                return '<div class="alert alert-danger"><p>' . htmlspecialchars((string)$msg, ENT_QUOTES, 'UTF-8') . '</p></div>';
            }
            return '<div class="alert alert-danger"><p>DNS Manager: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></div>';
        }
        return;
    }

    /**
     * Render-time guard: wrap any module-controller call so a single failure
     * (PDO, missing table, regex blowup) cannot blank the whole panel page.
     * Used as the ultimate safety net by the template.
     */
    static function safeRender($method, $default = '')
    {
        try {
            $out = self::$method();
            return $out === null ? $default : (string)$out;
        } catch (Throwable $e) {
            error_log('[dns_manager] safeRender(' . $method . ') error: ' . $e->getMessage());
            return '<div class="alert alert-danger"><strong>DNS Manager error:</strong> '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
        }
    }

    static function TriggerDNSUpdate($id)
    {
        global $zdbh;
        global $controller;
        $records_list = ctrl_options::GetSystemOption('dns_hasupdates');
        $record_array = explode(',', $records_list);
        if (!in_array($id, $record_array)) {
            if (empty($records_list)) {
                $records_list .= $id;
            } else {
                $records_list .= ',' . $id;
            }
            $sql = "UPDATE x_settings SET so_value_tx=:newlist WHERE so_name_vc='dns_hasupdates'";
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':newlist', $records_list);
            $sql->execute();
            return true;
        }
    }

    private static function splitTXT($value, $chunkLen = 250)
    {
        if (strlen($value) <= $chunkLen) {
            return '"' . $value . '"';
        }
        return '"' . implode('" "', str_split($value, $chunkLen)) . '"';
    }

    static function CheckZoneRecord($domainID)
    {
        global $zdbh;
        $hasrecords = false;
        $sql = 'SELECT COUNT(*) FROM x_dns WHERE dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL';
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':domainID', $domainID);

        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                $hasrecords = true;
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL ORDER BY dn_type_vc");
                $sql->bindParam(':domainID', $domainID);
                $sql->execute();
                $numrows = $zdbh->prepare("SELECT dn_name_vc FROM x_dns WHERE dn_vhost_fk=:domainID AND dn_deleted_ts IS NULL");
                $numrows->bindParam(':domainID', $domainID);
                $numrows->execute();
                $domain = $numrows->fetch();

                $zonecheck_file = (ctrl_options::GetSystemOption('temp_dir')) . $domain['dn_name_vc'] . ".txt";
                $checkline = "$" . "TTL 10800" . fs_filehandler::NewLine();
                $checkline .= "@ IN SOA " . $domain['dn_name_vc'] . ". ";
                $checkline .= "postmaster." . $domain['dn_name_vc'] . ". (" . fs_filehandler::NewLine();
                $checkline .= " " . date("Ymdt") . " ;serial" . fs_filehandler::NewLine();
                $checkline .= " " . ctrl_options::GetSystemOption('refresh_ttl') . " ;refresh after 6 hours" . fs_filehandler::NewLine();
                $checkline .= " " . ctrl_options::GetSystemOption('retry_ttl') . " ;retry after 1 hour" . fs_filehandler::NewLine();
                $checkline .= " " . ctrl_options::GetSystemOption('expire_ttl') . " ;expire after 1 week" . fs_filehandler::NewLine();
                $checkline .= " " . ctrl_options::GetSystemOption('minimum_ttl') . " ) ;minimum TTL of 1 day" . fs_filehandler::NewLine();
                while ($rowdns = $sql->fetch()) {
                    if ($rowdns['dn_type_vc'] == "A") {
                        $checkline .= $rowdns['dn_host_vc'] . " " . $rowdns['dn_ttl_in'] . " IN A " . $rowdns['dn_target_vc'] . fs_filehandler::NewLine();
                    }
                    if ($rowdns['dn_type_vc'] == "AAAA") {
                        $checkline .= $rowdns['dn_host_vc'] . " " . $rowdns['dn_ttl_in'] . " IN AAAA " . $rowdns['dn_target_vc'] . fs_filehandler::NewLine();
                    }
                    if ($rowdns['dn_type_vc'] == "CNAME") {
                        $checkline .= $rowdns['dn_host_vc'] . " " . $rowdns['dn_ttl_in'] . " IN CNAME " . $rowdns['dn_target_vc'] . fs_filehandler::NewLine();
                    }
                    if ($rowdns['dn_type_vc'] == "MX") {
                        $checkline .= $rowdns['dn_host_vc'] . " " . $rowdns['dn_ttl_in'] . " IN MX " . $rowdns['dn_priority_in'] . " " . $rowdns['dn_target_vc'] . "." . fs_filehandler::NewLine();
                    }
                    if ($rowdns['dn_type_vc'] == "TXT") {
                        $val = stripslashes($rowdns['dn_target_vc']);
                        if ($val === 'PENDING') continue;
                        $checkline .= $rowdns['dn_host_vc'] . " " . $rowdns['dn_ttl_in'] . " IN TXT " . self::splitTXT($val) . fs_filehandler::NewLine();
                    }
                    if ($rowdns['dn_type_vc'] == "SRV") {
                        $checkline .= $rowdns['dn_host_vc'] . " " . $rowdns['dn_ttl_in'] . " IN SRV " . $rowdns['dn_priority_in'] . " " . $rowdns['dn_weight_in'] . " " . $rowdns['dn_port_in'] . " " . $rowdns['dn_target_vc'] . "." . fs_filehandler::NewLine();
                    }
                    if ($rowdns['dn_type_vc'] == "SPF") {
                        // RFC 7208: SPF RR type retired — use TXT only
                        $checkline .= $rowdns['dn_host_vc'] . " " . $rowdns['dn_ttl_in'] . " IN TXT " . self::splitTXT(stripslashes($rowdns['dn_target_vc'])) . fs_filehandler::NewLine();
                    }
                    if ($rowdns['dn_type_vc'] == "NS") {
                        $checkline .= $rowdns['dn_host_vc'] . " " . $rowdns['dn_ttl_in'] . " IN NS " . $rowdns['dn_target_vc'] . "." . fs_filehandler::NewLine();
                    }
                    if ($rowdns['dn_type_vc'] == "CAA") {
                        $checkline .= $rowdns['dn_host_vc'] . " " . $rowdns['dn_ttl_in'] . " IN CAA " . $rowdns['dn_target_vc'] . fs_filehandler::NewLine();
                    }
                    if ($rowdns['dn_type_vc'] == "NAPTR") {
                        $checkline .= $rowdns['dn_host_vc'] . " " . $rowdns['dn_ttl_in'] . " IN NAPTR " . $rowdns['dn_target_vc'] . fs_filehandler::NewLine();
                    }
                    if ($rowdns['dn_type_vc'] == "SSHFP") {
                        $checkline .= $rowdns['dn_host_vc'] . " " . $rowdns['dn_ttl_in'] . " IN SSHFP " . $rowdns['dn_target_vc'] . fs_filehandler::NewLine();
                    }
                    if ($rowdns['dn_type_vc'] == "TLSA") {
                        $checkline .= $rowdns['dn_host_vc'] . " " . $rowdns['dn_ttl_in'] . " IN TLSA " . $rowdns['dn_target_vc'] . fs_filehandler::NewLine();
                    }
                    if ($rowdns['dn_type_vc'] == "URI") {
                        $checkline .= $rowdns['dn_host_vc'] . " " . $rowdns['dn_ttl_in'] . " IN URI " . $rowdns['dn_target_vc'] . fs_filehandler::NewLine();
                    }
                }
                fs_filehandler::UpdateFile($zonecheck_file, 0777, $checkline);
            }
        }
        if ($hasrecords == true) {
            //Check the temp zone record for errors
            if (file_exists($zonecheck_file)) {
                $command = ctrl_options::GetSystemOption('named_checkzone');
                $tmpOut = tempnam(sys_get_temp_dir(), 'bulwark_zoneout_');
                $fullCmd = escapeshellcmd($command)
                    . ' ' . escapeshellarg($domain['dn_name_vc'])
                    . ' ' . escapeshellarg($zonecheck_file)
                    . ' > ' . escapeshellarg($tmpOut) . ' 2>&1';
                system($fullCmd, $retval);
                $content_grabbed = (file_exists($tmpOut) ? file_get_contents($tmpOut) : '');
                @unlink($tmpOut);
                unlink($zonecheck_file);
                if ($retval == 0) {
                    //Syntax check passed.
                    return $content_grabbed;
                } else {
                    //Syntax ERROR.
                    return $content_grabbed;
                }
            }
        }
    }

}

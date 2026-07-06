<?php

class module_controller extends ctrl_module {

    static $ok;
    static $dnsok;
    static $lastSyncIP;

    // Módulo exclusivo de administradores — redirige si no es grupo 1
    private static function requireAdmin(): void
    {
        $u = ctrl_users::GetUserDetail();
        if ((int)($u['usergroupid'] ?? 3) !== 1) {
            header('Location: ./?module=dashboard');
            exit;
        }
    }

    // -----------------------------------------------------------------------
    // Data
    // -----------------------------------------------------------------------

    static function ListAutoIPSettings() {
        global $zdbh;
        $n = $zdbh->query("SELECT COUNT(*) FROM x_autoip");
        if ($n->fetchColumn() > 0) {
            $serverip = ctrl_options::GetOption('server_ip');
            $syncip   = self::$lastSyncIP !== null ? self::$lastSyncIP : $serverip;
            return [['ai_oldip_vc' => $serverip, 'ai_syncip_vc' => $syncip]];
        }
        return false;
    }

    static function getAutoIPSettings() {
        self::requireAdmin();
        $s = self::ListAutoIPSettings();
        return (!fs_director::CheckForEmptyValue($s)) ? $s : false;
    }

    // -----------------------------------------------------------------------
    // Interface IP list
    // -----------------------------------------------------------------------

    static function getDetectedIPs() {
        self::requireAdmin();
        $serverip = ctrl_options::GetOption('server_ip');
        $ips = [];

        $out = [];
        @exec('ifconfig -a 2>/dev/null', $out);
        $iface = '';
        foreach ($out as $line) {
            if (preg_match('/^([a-zA-Z][a-zA-Z0-9]*):?\s/', $line, $m)) {
                $iface = rtrim($m[1], ':');
            }
            if ($iface !== ''
                && preg_match('/^\s+inet\s+([\d.]+)/', $line, $m)
                && filter_var($m[1], FILTER_VALIDATE_IP)
                && $m[1] !== '127.0.0.1'
            ) {
                $ip  = $m[1];
                $pub = filter_var($ip, FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
                $ips[] = [
                    'ai_iface'    => $iface,
                    'ai_ip'       => $ip,
                    'ai_type'     => $pub ? 'Public' : 'Private',
                    'ai_typecss'  => $pub ? 'color:green;' : 'color:darkorange;',
                    'ai_isactive' => ($ip === $serverip) ? '✓' : '',
                ];
            }
        }

        // Linux fallback
        if (empty($ips)) {
            $out = [];
            @exec('ip addr show 2>/dev/null', $out);
            $iface = '';
            foreach ($out as $line) {
                if (preg_match('/^\d+:\s+([^:@\s]+)/', $line, $m)) { $iface = $m[1]; }
                if ($iface !== ''
                    && preg_match('/^\s+inet\s+([\d.]+)/', $line, $m)
                    && filter_var($m[1], FILTER_VALIDATE_IP)
                    && $m[1] !== '127.0.0.1'
                ) {
                    $ip  = $m[1];
                    $pub = filter_var($ip, FILTER_VALIDATE_IP,
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
                    $ips[] = [
                        'ai_iface'    => $iface,
                        'ai_ip'       => $ip,
                        'ai_type'     => $pub ? 'Public' : 'Private',
                        'ai_typecss'  => $pub ? 'color:green;' : 'color:darkorange;',
                        'ai_isactive' => ($ip === $serverip) ? '✓' : '',
                    ];
                }
            }
        }

        return $ips ?: false;
    }

    // -----------------------------------------------------------------------
    // DNS rebuild
    // -----------------------------------------------------------------------

    static function TriggerDNSRebuild() {
        global $zdbh;
        $row  = $zdbh->query(
            "SELECT so_value_tx FROM x_settings WHERE so_name_vc='dns_hasupdates'"
        )->fetch();
        $ids  = array_filter(explode(',', (string)($row['so_value_tx'] ?? '')), 'strlen');
        if (!in_array('0', $ids)) { $ids[] = '0'; }
        $zdbh->prepare(
            "UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='dns_hasupdates'"
        )->execute([':v' => implode(',', $ids)]);
    }

    // -----------------------------------------------------------------------
    // Actions
    // -----------------------------------------------------------------------

    /**
     * Save a new Server IP to x_settings without touching DNS records.
     */
    static function ExecuteSaveServerIP($newip) {
        global $zdbh;
        $zdbh->prepare(
            "UPDATE x_settings SET so_value_tx=:ip WHERE so_name_vc='server_ip'"
        )->execute([':ip' => $newip]);
        self::$ok = true;
    }

    /**
     * Replace $oldip with current server_ip in DNS A records, then rebuild zones.
     */
    static function ExecuteSyncDNS($oldip) {
        global $zdbh;
        $newip = ctrl_options::GetOption('server_ip');
        if ($oldip !== '' && $oldip !== $newip) {
            $zdbh->prepare(
                "UPDATE x_dns SET dn_target_vc=:newip
                 WHERE dn_target_vc=:oldip AND dn_type_vc='A' AND dn_deleted_ts IS NULL"
            )->execute([':newip' => $newip, ':oldip' => $oldip]);
        }
        self::TriggerDNSRebuild();
        self::$dnsok = true;
    }

    /**
     * Replace $oldip with current server_ip in vhost custom IPs.
     */
    static function ExecuteSyncVhosts($oldip) {
        global $zdbh;
        $newip = ctrl_options::GetOption('server_ip');
        if ($oldip !== '' && $oldip !== $newip) {
            $zdbh->prepare(
                "UPDATE x_vhosts SET vh_custom_ip_vc=:newip
                 WHERE vh_custom_ip_vc=:oldip AND vh_deleted_ts IS NULL"
            )->execute([':newip' => $newip, ':oldip' => $oldip]);
        }
        self::$ok = true;
    }

    static function doupdateautoip() {
        self::requireAdmin();
        runtime_csfr::Protect();
        global $controller;
        $f = $controller->GetAllControllerRequests('FORM');

        if (isset($f['inForceDNS']) || isset($f['inForceVhost'])) {
            $oldip = trim((string)($f['inSyncIP'] ?? ''));
            self::$lastSyncIP = $oldip;
            if (filter_var($oldip, FILTER_VALIDATE_IP)) {
                if (isset($f['inForceDNS'])) {
                    self::ExecuteSyncDNS($oldip);
                } else {
                    self::ExecuteSyncVhosts($oldip);
                }
            }
            return;
        }

        if (isset($f['inUpdate'])) {
            $newip = trim((string)($f['inManualIP'] ?? ''));
            if ($newip !== '' && filter_var($newip, FILTER_VALIDATE_IP)) {
                self::ExecuteSaveServerIP($newip);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Install
    // -----------------------------------------------------------------------

    static function getInstallDatabase() {
        global $zdbh;
        include(ctrl_options::GetOption('sentora_root') . '/cnf/db.php');
        $exists = $zdbh->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema='" . $dbname . "' AND table_name='x_autoip'"
        )->fetchColumn();

        if ($exists == 0) {
            $zdbh->exec("CREATE TABLE `x_autoip` (
                `ai_id_pk`         int(6)       NOT NULL DEFAULT '0',
                `ai_script_vc`     varchar(255)          DEFAULT NULL,
                `ai_email_vc`      varchar(255)          DEFAULT NULL,
                `ai_command_vc`    varchar(255)          DEFAULT NULL,
                `ai_newip_vc`      varchar(50)           DEFAULT NULL,
                `ai_oldip_vc`      varchar(50)           DEFAULT NULL,
                `ai_enabled_in`    int(1)                DEFAULT '0',
                `ai_lastupdate_ts` varchar(50)           DEFAULT NULL,
                PRIMARY KEY (`ai_id_pk`)
            )");
            $zdbh->exec("INSERT INTO `x_autoip` VALUES ('1',null,null,null,null,null,'0',null)");

            // Seed server_ip on fresh install if currently empty and a public IP is detectable.
            $row = $zdbh->query(
                "SELECT so_value_tx FROM x_settings WHERE so_name_vc='server_ip'"
            )->fetch();
            if ($row && $row['so_value_tx'] === '') {
                $detected = self::detectOutboundIP();
                if ($detected !== null) {
                    $zdbh->prepare(
                        "UPDATE x_settings SET so_value_tx=:ip WHERE so_name_vc='server_ip'"
                    )->execute([':ip' => $detected]);
                }
            }
        }
    }

    // UDP socket trick + shell fallback — used only at install time to seed server_ip.
    // Returns the primary outbound IP if it is publicly routable, otherwise null.
    static function detectOutboundIP() {
        $ip = null;
        if (function_exists('socket_create')) {
            $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($sock !== false) {
                @socket_connect($sock, '8.8.8.8', 53);
                $addr = '';
                @socket_getsockname($sock, $addr);
                @socket_close($sock);
                if (filter_var($addr, FILTER_VALIDATE_IP)) { $ip = $addr; }
            }
        }
        if ($ip === null) {
            $iface = null;
            $out   = [];
            @exec('route -n get default 2>/dev/null', $out);
            foreach ($out as $l) {
                if (preg_match('/^\s*interface:\s*(\S+)/', $l, $m)) { $iface = $m[1]; break; }
            }
            if ($iface === null) {
                $out = [];
                @exec('ip route show default 2>/dev/null', $out);
                foreach ($out as $l) {
                    if (preg_match('/dev\s+(\S+)/', $l, $m)) { $iface = $m[1]; break; }
                }
            }
            if ($iface !== null && preg_match('/^[a-zA-Z0-9_]+$/', $iface)) {
                $out = [];
                @exec('ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null', $out);
                foreach ($out as $l) {
                    if (preg_match('/inet\s+([\d.]+)/', $l, $m)
                            && filter_var($m[1], FILTER_VALIDATE_IP)) {
                        $ip = $m[1];
                        break;
                    }
                }
            }
        }
        if ($ip === null) return null;
        return filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false ? $ip : null;
    }

    // -----------------------------------------------------------------------
    // Misc
    // -----------------------------------------------------------------------

    static function getDescription() { return ui_module::GetModuleDescription(); }
    static function getModuleName()   { return ui_module::GetModuleName(); }

    static function getModuleIcon() {
        global $controller;
        return "/modules/" . $controller->GetControllerRequest('URL', 'module') . "/assets/icon.png";
    }

    static function getResult() {
        if (!fs_director::CheckForEmptyValue(self::$dnsok)) {
            return ui_sysmessage::shout(
                ui_language::translate("DNS records updated and zone rebuild scheduled."),
                "zannounceok"
            );
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(
                ui_language::translate("Server IP saved successfully."),
                "zannounceok"
            );
        }
        return;
    }

}
?>

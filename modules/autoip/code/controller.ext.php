<?php

require_once '/usr/local/sentora/dryden/sys/privilege.class.php';

class module_controller extends ctrl_module {

    static $ok;
    static $dnsok;
    static $lastSyncIP;
    static $ok_msg;  // mensaje OK del pool de IPs (nombre que conserva el PRG del framework)
    static $err_msg; // mensaje de error del pool de IPs (idem)

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
    // Pool de IPs (multi-IP, Fase 1) — inventario x_ips + alias de SO
    // -----------------------------------------------------------------------

    /** Nº de dominios (vhosts activos) que usan una IP como custom IP. */
    private static function ipDomainCount($ip) {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_custom_ip_vc=:ip AND vh_deleted_ts IS NULL");
        $q->execute([':ip' => $ip]);
        return (int)$q->fetchColumn();
    }

    static function getIpPool() {
        global $zdbh;
        $rows = $zdbh->query("SELECT * FROM x_ips ORDER BY ip_is_primary_in DESC, ip_address_vc ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['domains'] = self::ipDomainCount($r['ip_address_vc']); }
        return $rows;
    }

    static function getIpPoolHTML() {
        self::requireAdmin();
        $pool = self::getIpPool();
        $csrf = self::getCSFR_Tag();

        $h  = '';
        if (!fs_director::CheckForEmptyValue(self::$err_msg)) {
            $h .= ui_sysmessage::shout(self::$err_msg, 'zannounceerror');
        } elseif (!fs_director::CheckForEmptyValue(self::$ok_msg)) {
            $h .= ui_sysmessage::shout(self::$ok_msg, 'zannounceok');
        }

        $h .= '<p class="text-muted" style="font-size:12px;margin-bottom:8px;">Inventario de IPs del servidor. '
            . 'Al añadir una IP se configura como <strong>alias</strong> en la interfaz (persistente) y queda disponible '
            . 'para asignar a dominios. La IP primaria no se puede quitar.</p>';

        $h .= '<table class="table table-sm align-middle" style="max-width:760px;">'
            . '<thead><tr><th>IP</th><th>Tipo</th><th>Modo</th><th>Dominios</th><th>Estado</th>'
            . '<th style="text-align:right;">Acciones</th></tr></thead><tbody>';
        foreach ($pool as $p) {
            $ip   = htmlspecialchars((string)$p['ip_address_vc'], ENT_QUOTES);
            $prim = !empty($p['ip_is_primary_in']);
            $pub  = filter_var($p['ip_address_vc'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            $tipo = $prim ? '<span class="badge bg-primary">Primaria</span>'
                          : ($pub ? '<span class="badge bg-success">Pública</span>' : '<span class="badge bg-warning">Privada</span>');
            $modo = !empty($p['ip_shared_in']) ? 'Compartida' : 'Dedicada';
            $dom  = (int)$p['domains'];
            $ena  = !empty($p['ip_enabled_in']);
            $estado = $ena ? '<span class="badge bg-success">Activa</span>' : '<span class="badge bg-secondary">Inactiva</span>';

            $acc = '';
            if (!$prim) {
                // activar/desactivar
                $acc .= '<form method="post" action="./?module=autoip&action=ToggleIP" style="display:inline;">' . $csrf
                      . '<input type="hidden" name="inIpId" value="' . (int)$p['ip_id_pk'] . '">'
                      . '<button type="submit" class="btn btn-sm btn-outline-secondary">' . ($ena ? 'Desactivar' : 'Activar') . '</button></form> ';
                // eliminar (bloqueado si hay dominios usándola)
                if ($dom === 0) {
                    $acc .= '<form method="post" action="./?module=autoip&action=RemoveIP" style="display:inline;">' . $csrf
                          . '<input type="hidden" name="inIpId" value="' . (int)$p['ip_id_pk'] . '">'
                          . '<button type="submit" class="btn btn-sm btn-danger" onclick="return confirm(\'Quitar ' . $ip . ' del pool y de la interfaz?\')">Eliminar</button></form>';
                } else {
                    $acc .= '<span class="text-muted" style="font-size:12px;">en uso</span>';
                }
            } else {
                $acc = '<span class="text-muted">—</span>';
            }

            $h .= '<tr><td><strong>' . $ip . '</strong></td><td>' . $tipo . '</td><td>' . $modo . '</td>'
                . '<td>' . $dom . '</td><td>' . $estado . '</td>'
                . '<td style="text-align:right;">' . $acc . '</td></tr>';
        }
        $h .= '</tbody></table>';

        // formulario de alta
        $h .= '<form method="post" action="./?module=autoip&action=AddIP" style="margin-top:10px;">' . $csrf
            . '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
            . '<input type="text" name="inNewIP" placeholder="Nueva IP (IPv4)" maxlength="45" style="width:200px;" class="form-control form-control-sm" required>'
            . '<label style="font-size:13px;"><input type="checkbox" name="inShared" value="1" checked> Compartida</label>'
            . '<button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Añadir IP</button>'
            . '</div><small class="text-muted">Compartida = varios dominios; sin marcar = dedicada (un dominio). Se añade como alias a la interfaz.</small></form>';

        return $h;
    }

    static function doAddIP() {
        self::requireAdmin();
        runtime_csfr::Protect();
        global $zdbh, $controller;
        $f = $controller->GetAllControllerRequests('FORM');
        $ip     = trim((string)($f['inNewIP'] ?? ''));
        $shared = isset($f['inShared']) ? 1 : 0;

        if (!filter_var($ip, FILTER_VALIDATE_IP)) { self::$err_msg = 'IP inválida.'; return; }
        $ex = $zdbh->prepare("SELECT COUNT(*) FROM x_ips WHERE ip_address_vc=:ip");
        $ex->execute([':ip' => $ip]);
        if ($ex->fetchColumn() > 0) { self::$err_msg = 'Esa IP ya está en el pool.'; return; }

        $isPrimary = ($ip === (string)ctrl_options::GetOption('server_ip')) ? 1 : 0;
        $zdbh->prepare("INSERT INTO x_ips (ip_address_vc, ip_shared_in, ip_enabled_in, ip_is_primary_in, ip_created_ts)
                        VALUES (:ip,:sh,1,:pr,:ts)")
             ->execute([':ip' => $ip, ':sh' => $shared, ':pr' => $isPrimary, ':ts' => time()]);

        // alias en el SO: solo IPv4 y si no es la primaria (la primaria ya está en la interfaz)
        $msg = 'IP ' . htmlspecialchars($ip, ENT_QUOTES) . ' añadida al pool.';
        if (!$isPrimary && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            try {
                $r = privilege::run('ip_alias_add', array($ip));
                if (is_array($r) && (int)$r[0] !== 0) { $msg .= ' (aviso: el alias de red devolvió código ' . (int)$r[0] . ').'; }
                else { $msg .= ' Alias configurado en la interfaz.'; }
            } catch (Exception $e) { $msg .= ' (no se pudo configurar el alias: ' . $e->getMessage() . ').'; }
        }
        self::$ok_msg = $msg;
    }

    static function doRemoveIP() {
        self::requireAdmin();
        runtime_csfr::Protect();
        global $zdbh, $controller;
        $f  = $controller->GetAllControllerRequests('FORM');
        $id = (int)($f['inIpId'] ?? 0);
        if ($id <= 0) { self::$err_msg = 'IP no válida.'; return; }

        $row = $zdbh->prepare("SELECT * FROM x_ips WHERE ip_id_pk=:id");
        $row->execute([':id' => $id]);
        $ipr = $row->fetch(PDO::FETCH_ASSOC);
        if (!$ipr) { self::$err_msg = 'IP no encontrada.'; return; }
        if (!empty($ipr['ip_is_primary_in'])) { self::$err_msg = 'No se puede quitar la IP primaria.'; return; }
        if (self::ipDomainCount($ipr['ip_address_vc']) > 0) { self::$err_msg = 'Esa IP está en uso por dominios; reasígnalos primero.'; return; }

        // quitar alias del SO (IPv4) y borrar del pool
        if (filter_var($ipr['ip_address_vc'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            try { privilege::run('ip_alias_del', array($ipr['ip_address_vc'])); } catch (Exception $e) {}
        }
        $zdbh->prepare("DELETE FROM x_ips WHERE ip_id_pk=:id")->execute([':id' => $id]);
        self::$ok_msg = 'IP ' . htmlspecialchars((string)$ipr['ip_address_vc'], ENT_QUOTES) . ' eliminada del pool y de la interfaz.';
    }

    static function doToggleIP() {
        self::requireAdmin();
        runtime_csfr::Protect();
        global $zdbh, $controller;
        $f  = $controller->GetAllControllerRequests('FORM');
        $id = (int)($f['inIpId'] ?? 0);
        if ($id <= 0) { self::$err_msg = 'IP no válida.'; return; }
        $zdbh->prepare("UPDATE x_ips SET ip_enabled_in = 1 - ip_enabled_in WHERE ip_id_pk=:id AND ip_is_primary_in=0")
             ->execute([':id' => $id]);
        self::$ok_msg = 'Estado de la IP actualizado.';
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

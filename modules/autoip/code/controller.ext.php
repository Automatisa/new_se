<?php

require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';

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
    // Pool de IPs (multi-IP, Fase 1b) — INVENTARIO del sistema (x_ips)
    //
    // El pool son las IPs que el sistema tiene disponibles. Aquí es SOLO inventario:
    // el alias en la interfaz NO se configura al añadir, sino al ASIGNAR la IP a un
    // dominio (Fase 1c) — aliasar un /24 entero por adelantado no tiene sentido.
    // "Compartida/dedicada" es resultado del uso (nº de dominios), no algo que se marca.
    // Solo el admin gestiona el pool; el reparto a resellers/usuarios va en fases 1c/2.
    // -----------------------------------------------------------------------

    /** Nº de dominios (vhosts activos) que usan una IP. */
    private static function ipDomainCount($ip) {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_custom_ip_vc=:ip AND vh_deleted_ts IS NULL");
        $q->execute([':ip' => $ip]);
        return (int)$q->fetchColumn();
    }

    /** rDNS/PTR de una IP para correo: [ptr => hostname|null, fcrdns => bool].
     *  fcrdns = true si el PTR resuelve de vuelta a la misma IP (Forward-Confirmed rDNS),
     *  que es lo que exigen los grandes proveedores para aceptar el correo saliente. */
    private static function ipRdns($ip) {
        $ptr = @gethostbyaddr($ip);
        if ($ptr === false || $ptr === '' || $ptr === $ip) return ['ptr' => null, 'fcrdns' => false];
        $fwd = @gethostbynamel($ptr);
        return ['ptr' => $ptr, 'fcrdns' => (is_array($fwd) && in_array($ip, $fwd, true))];
    }

    /** Dominios (con su propietario) que usan una IP — para que el admin vea a quién está asignada. */
    private static function ipDomains($ip) {
        global $zdbh;
        $q = $zdbh->prepare("SELECT v.vh_name_vc AS domain, a.ac_user_vc AS owner
            FROM x_vhosts v LEFT JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk
            WHERE v.vh_custom_ip_vc=:ip AND v.vh_deleted_ts IS NULL ORDER BY v.vh_name_vc");
        $q->execute([':ip' => $ip]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    static function getIpPool() {
        global $zdbh;
        $rows = $zdbh->query("SELECT * FROM x_ips ORDER BY ip_is_primary_in DESC, INET6_ATON(ip_address_vc) ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['domain_list'] = self::ipDomains($r['ip_address_vc']);
            $r['domains']     = count($r['domain_list']);
        }
        return $rows;
    }

    /** Expande una entrada de alta: IP suelta o CIDR IPv4 a.b.c.d/nn (prefijo /24..32; máx 256). */
    private static function expandIPInput($in) {
        $in = trim($in);
        if (filter_var($in, FILTER_VALIDATE_IP)) return array($in);
        if (preg_match('#^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/(\d{1,2})$#', $in, $m)) {
            $base = $m[1]; $prefix = (int)$m[2];
            if (!filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $prefix < 24 || $prefix > 32) return null;
            $count   = 1 << (32 - $prefix);
            $network = ip2long($base) & (~($count - 1));
            $ips = array();
            for ($i = 0; $i < $count; $i++) {
                // en bloques de 4+ direcciones (<= /30) excluir red y broadcast
                if ($prefix <= 30 && ($i === 0 || $i === $count - 1)) continue;
                $ips[] = long2ip($network + $i);
            }
            return $ips;
        }
        return null;
    }

    static function getIpPoolHTML() {
        self::requireAdmin();
        $pool      = self::getIpPool();
        $csrf      = self::getCSFR_Tag();
        $resellers = self::getResellers();
        $rmap      = [];
        foreach ($resellers as $r) { $rmap[(int)$r['ac_id_pk']] = $r['ac_user_vc']; }

        $h  = '';
        if (!fs_director::CheckForEmptyValue(self::$err_msg)) {
            $h .= ui_sysmessage::shout(self::$err_msg, 'zannounceerror');
        } elseif (!fs_director::CheckForEmptyValue(self::$ok_msg)) {
            $h .= ui_sysmessage::shout(self::$ok_msg, 'zannounceok');
        }

        $h .= '<p class="text-muted" style="font-size:12px;margin-bottom:8px;">Inventario de IPs disponibles en el sistema. '
            . 'Añadir aquí es <strong>solo inventario</strong>: el alias de red se configura al <em>asignar</em> la IP a un dominio. '
            . 'La IP primaria (compartida del sistema) no se puede quitar.</p>';

        $h .= '<table class="table table-sm align-middle" style="max-width:820px;">'
            . '<thead><tr><th>IP</th><th>Tipo</th><th>Asignación</th><th>Estado</th>'
            . '<th style="text-align:right;">Acciones</th></tr></thead><tbody>';
        foreach ($pool as $p) {
            $ip   = htmlspecialchars((string)$p['ip_address_vc'], ENT_QUOTES);
            $prim = !empty($p['ip_is_primary_in']);
            $pub  = filter_var($p['ip_address_vc'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            $tipo = $prim ? '<span class="badge bg-primary">Primaria</span>'
                          : ($pub ? '<span class="badge bg-success">Pública</span>' : '<span class="badge bg-warning">Privada</span>');
            $dom  = (int)$p['domains'];
            $ena  = !empty($p['ip_enabled_in']);
            $estado = $ena ? '<span class="badge bg-success">Activa</span>' : '<span class="badge bg-secondary">Inactiva</span>';

            $rid = (int)($p['ip_reseller_fk'] ?? 0);
            // Asignación (visible para el admin): reseller al que se cedió + dominios/propietarios que la usan.
            if ($prim) {
                $asig = '<span class="text-muted">Compartida (sistema)</span>';
            } else {
                $parts = [];
                if ($rid > 0) {
                    $rn = isset($rmap[$rid]) ? htmlspecialchars($rmap[$rid], ENT_QUOTES) : ('#' . $rid);
                    $parts[] = '<span class="badge bg-info">Reseller: ' . $rn . '</span>';
                }
                if (!empty($p['domain_list'])) {
                    $dl = [];
                    foreach ($p['domain_list'] as $d) {
                        $dl[] = htmlspecialchars((string)$d['domain'], ENT_QUOTES)
                              . ' <span class="text-muted">(' . htmlspecialchars((string)($d['owner'] ?? '?'), ENT_QUOTES) . ')</span>';
                    }
                    $tag = ($dom === 1) ? 'dedicada' : 'compartida';
                    $parts[] = '<span style="font-size:12px;">' . implode(', ', $dl) . ' · <em>' . $tag . '</em></span>';
                    // rDNS para correo (solo IPs en uso -> conjunto pequeño; evita bloqueos con rangos grandes)
                    $r = self::ipRdns($p['ip_address_vc']);
                    if ($r['ptr'] === null) {
                        $parts[] = '<span class="badge bg-secondary" title="El correo saliente desde esta IP puede rechazarse sin PTR">rDNS: sin PTR</span>';
                    } elseif ($r['fcrdns']) {
                        $parts[] = '<span class="badge bg-success" title="Forward-Confirmed rDNS OK">rDNS: ' . htmlspecialchars($r['ptr'], ENT_QUOTES) . ' ✓</span>';
                    } else {
                        $parts[] = '<span class="badge bg-warning" title="El PTR no resuelve de vuelta a esta IP (FCrDNS falla)">rDNS: ' . htmlspecialchars($r['ptr'], ENT_QUOTES) . ' ⚠</span>';
                    }
                }
                $asig = $parts ? implode('<br>', $parts) : '<span class="text-muted">Libre</span>';
            }

            $acc = '<span class="text-muted">—</span>';
            if (!$prim) {
                $acc  = '<form method="post" action="./?module=autoip&action=ToggleIP" style="display:inline;">' . $csrf
                      . '<input type="hidden" name="inIpId" value="' . (int)$p['ip_id_pk'] . '">'
                      . '<button type="submit" class="btn btn-sm btn-outline-secondary">' . ($ena ? 'Desactivar' : 'Activar') . '</button></form> ';
                if ($rid > 0) {
                    // asignada a un reseller: permitir liberarla (si no está en uso)
                    $acc .= '<form method="post" action="./?module=autoip&action=ReleaseReseller" style="display:inline;">' . $csrf
                          . '<input type="hidden" name="inIpId" value="' . (int)$p['ip_id_pk'] . '">'
                          . '<button type="submit" class="btn btn-sm btn-outline-warning"' . ($dom > 0 ? ' disabled title="en uso por dominios"' : '') . '>Liberar</button></form>';
                } elseif ($dom === 0) {
                    // libre: asignar a un reseller + eliminar del pool
                    if (!empty($rmap)) {
                        $acc .= '<form method="post" action="./?module=autoip&action=AssignReseller" style="display:inline;">' . $csrf
                              . '<input type="hidden" name="inIpId" value="' . (int)$p['ip_id_pk'] . '">'
                              . '<select name="inReseller" class="form-select form-select-sm" style="width:auto;display:inline-block;"><option value="">Reseller…</option>';
                        foreach ($rmap as $id2 => $name2) {
                            $acc .= '<option value="' . $id2 . '">' . htmlspecialchars($name2, ENT_QUOTES) . '</option>';
                        }
                        $acc .= '</select> <button type="submit" class="btn btn-sm btn-info">Asignar</button></form> ';
                    }
                    $acc .= '<form method="post" action="./?module=autoip&action=RemoveIP" style="display:inline;">' . $csrf
                          . '<input type="hidden" name="inIpId" value="' . (int)$p['ip_id_pk'] . '">'
                          . '<button type="submit" class="btn btn-sm btn-danger" onclick="return confirm(\'Quitar ' . $ip . ' del pool?\')">Eliminar</button></form>';
                } else {
                    $acc .= '<span class="text-muted" style="font-size:12px;">en uso por dominios</span>';
                }
            }

            $h .= '<tr><td><strong>' . $ip . '</strong></td><td>' . $tipo . '</td><td>' . $asig . '</td>'
                . '<td>' . $estado . '</td><td style="text-align:right;">' . $acc . '</td></tr>';
        }
        $h .= '</tbody></table>';

        // formulario de alta (IP suelta o rango CIDR)
        $h .= '<form method="post" action="./?module=autoip&action=AddIP" style="margin-top:10px;">' . $csrf
            . '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
            . '<input type="text" name="inNewIP" placeholder="IPv4/IPv6 (192.168.1.50 · fd00::10) o rango IPv4 (192.168.1.48/29)" maxlength="45" style="width:380px;" class="form-control form-control-sm" required>'
            . '<button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Añadir al pool</button>'
            . '</div><small class="text-muted">Acepta una IP suelta (IPv4 o IPv6) o un rango CIDR IPv4 (/24 a /32; en bloques se excluyen red y broadcast). Solo inventario — sin tocar la red aún.</small></form>';

        return $h;
    }

    static function doAddIP() {
        self::requireAdmin();
        runtime_csfr::Protect();
        global $zdbh, $controller;
        $f     = $controller->GetAllControllerRequests('FORM');
        $input = trim((string)($f['inNewIP'] ?? ''));

        $ips = self::expandIPInput($input);
        if ($ips === null || empty($ips)) { self::$err_msg = 'IP o rango CIDR inválido (rango /24 a /32).'; return; }

        $server_ip = (string)ctrl_options::GetOption('server_ip');
        $added = 0; $skipped = 0;
        $ins = $zdbh->prepare("INSERT INTO x_ips (ip_address_vc, ip_enabled_in, ip_is_primary_in, ip_created_ts) VALUES (:ip,1,:pr,:ts)");
        $chk = $zdbh->prepare("SELECT COUNT(*) FROM x_ips WHERE ip_address_vc=:ip");
        foreach ($ips as $ip) {
            $chk->execute([':ip' => $ip]);
            if ($chk->fetchColumn() > 0) { $skipped++; continue; }
            $ins->execute([':ip' => $ip, ':pr' => ($ip === $server_ip ? 1 : 0), ':ts' => time()]);
            $added++;
        }
        self::$ok_msg = 'Pool actualizado: ' . $added . ' IP(s) añadidas'
                      . ($skipped ? ', ' . $skipped . ' ya existían' : '')
                      . '. El alias de red se configura al asignar la IP a un dominio.';
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
        if (!empty($ipr['ip_reseller_fk']))    { self::$err_msg = 'Esa IP está asignada a un reseller; retírasela primero.'; return; }
        if (self::ipDomainCount($ipr['ip_address_vc']) > 0) { self::$err_msg = 'Esa IP está en uso por dominios; reasígnalos primero.'; return; }

        $zdbh->prepare("DELETE FROM x_ips WHERE ip_id_pk=:id")->execute([':id' => $id]);
        self::$ok_msg = 'IP ' . htmlspecialchars((string)$ipr['ip_address_vc'], ENT_QUOTES) . ' eliminada del pool.';
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

    // ---- Fase 2: reparto de IPs a resellers ----------------------------------------------------

    /** Cuentas reseller (grupo 2). */
    private static function getResellers() {
        global $zdbh;
        return $zdbh->query("SELECT ac_id_pk, ac_user_vc FROM x_accounts
            WHERE ac_group_fk=2 AND ac_deleted_ts IS NULL ORDER BY ac_user_vc")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Nº de IPs ya asignadas a un reseller. */
    private static function resellerIpCount($rid) {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_ips WHERE ip_reseller_fk=:r");
        $q->execute([':r' => $rid]);
        return (int)$q->fetchColumn();
    }

    /** Cuota de IPs del paquete del reseller (-1 ilimitado, 0 ninguna). */
    private static function resellerIpQuota($rid) {
        global $zdbh;
        $q = $zdbh->prepare("SELECT COALESCE(qt.qt_dedicatedips_in,0) FROM x_accounts a
            LEFT JOIN x_packages pk ON pk.pk_id_pk=a.ac_package_fk AND pk.pk_deleted_ts IS NULL
            LEFT JOIN x_quotas  qt ON qt.qt_package_fk=pk.pk_id_pk
            WHERE a.ac_id_pk=:r AND a.ac_deleted_ts IS NULL");
        $q->execute([':r' => $rid]);
        $v = $q->fetchColumn();
        return $v === false ? 0 : (int)$v;
    }

    static function doAssignReseller() {
        self::requireAdmin();
        runtime_csfr::Protect();
        global $zdbh, $controller;
        $f   = $controller->GetAllControllerRequests('FORM');
        $id  = (int)($f['inIpId'] ?? 0);
        $rid = (int)($f['inReseller'] ?? 0);
        if ($id <= 0 || $rid <= 0) { self::$err_msg = 'Datos inválidos.'; return; }

        $rc = $zdbh->prepare("SELECT ac_user_vc FROM x_accounts WHERE ac_id_pk=:id AND ac_group_fk=2 AND ac_deleted_ts IS NULL");
        $rc->execute([':id' => $rid]);
        if (!$rc->fetch()) { self::$err_msg = 'Reseller no válido.'; return; }

        $ip = $zdbh->prepare("SELECT * FROM x_ips WHERE ip_id_pk=:id"); $ip->execute([':id' => $id]);
        $ipr = $ip->fetch(PDO::FETCH_ASSOC);
        if (!$ipr || !empty($ipr['ip_is_primary_in'])) { self::$err_msg = 'IP no válida.'; return; }
        if (!empty($ipr['ip_reseller_fk'])) { self::$err_msg = 'La IP ya está asignada a un reseller.'; return; }
        if (self::ipDomainCount($ipr['ip_address_vc']) > 0) { self::$err_msg = 'La IP está en uso por dominios.'; return; }

        $quota = self::resellerIpQuota($rid);
        if ($quota !== -1 && (self::resellerIpCount($rid) + 1) > $quota) {
            self::$err_msg = 'El reseller ha alcanzado su límite de IPs de su paquete (' . $quota . ').'; return;
        }
        $zdbh->prepare("UPDATE x_ips SET ip_reseller_fk=:r WHERE ip_id_pk=:id")->execute([':r' => $rid, ':id' => $id]);
        self::$ok_msg = 'IP ' . htmlspecialchars((string)$ipr['ip_address_vc'], ENT_QUOTES) . ' asignada al reseller.';
    }

    static function doReleaseReseller() {
        self::requireAdmin();
        runtime_csfr::Protect();
        global $zdbh, $controller;
        $f  = $controller->GetAllControllerRequests('FORM');
        $id = (int)($f['inIpId'] ?? 0);
        if ($id <= 0) { self::$err_msg = 'IP no válida.'; return; }
        $ip = $zdbh->prepare("SELECT * FROM x_ips WHERE ip_id_pk=:id"); $ip->execute([':id' => $id]);
        $ipr = $ip->fetch(PDO::FETCH_ASSOC);
        if (!$ipr) { self::$err_msg = 'IP no encontrada.'; return; }
        if (self::ipDomainCount($ipr['ip_address_vc']) > 0) { self::$err_msg = 'La IP está en uso por dominios de ese reseller; libérala primero.'; return; }
        $zdbh->prepare("UPDATE x_ips SET ip_reseller_fk=NULL WHERE ip_id_pk=:id")->execute([':id' => $id]);
        self::$ok_msg = 'IP ' . htmlspecialchars((string)$ipr['ip_address_vc'], ENT_QUOTES) . ' devuelta al pool del admin.';
    }

    // -----------------------------------------------------------------------
    // Install
    // -----------------------------------------------------------------------

    static function getInstallDatabase() {
        global $zdbh;
        include(ctrl_options::GetOption('bulwark_root') . '/cnf/db.php');
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

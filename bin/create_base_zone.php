#!/usr/bin/php
<?php
/**
 * create_base_zone.php — Crea la ZONA DNS BASE del dominio proveedor del panel
 * (modelo de nameservers compartidos, tipo HestiaCP) como dominio de 'zadmin'.
 *
 * Lee los ajustes dns_provider_domain / dns_ns1 / dns_ns2 / dns_ns1_ip /
 * dns_ns2_ip / server_ip / bulwark_domain de x_settings y crea:
 *   - el vhost del dominio proveedor (directorios + fila en x_vhosts)
 *   - la zona DNS: SOA(dns_ns1), NS ns1/ns2, A ns1/ns2 (a sus IPs), A @, A www,
 *     A del subdominio del panel, A mail, MX, SPF, DMARC y placeholder DKIM.
 *
 * Es IDEMPOTENTE (no duplica vhost ni registros). No usa module_controller para
 * evitar la colisión de clases entre módulos (igual que bin/setzadmin).
 *
 * Uso: php bin/create_base_zone.php   (tras la instalación; el instalador lo llama)
 */

$rootPath = str_replace('\\', '/', dirname(__FILE__));
$rootPath = str_replace('/bin', '/', $rootPath);
chdir($rootPath);

require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

if (!runtime_controller::IsCLI()) {
    exit(1);
}

global $zdbh;

$provider = ctrl_options::GetSystemOption('dns_provider_domain');
if (fs_director::CheckForEmptyValue($provider)) {
    fwrite(STDERR, "dns_provider_domain vacío: no se crea zona base.\n");
    exit(0);
}

$ns1   = ctrl_options::GetSystemOption('dns_ns1');
$ns2   = ctrl_options::GetSystemOption('dns_ns2');
$ip    = ctrl_options::GetSystemOption('server_ip');
$ns1ip = ctrl_options::GetSystemOption('dns_ns1_ip');
$ns2ip = ctrl_options::GetSystemOption('dns_ns2_ip');
if (fs_director::CheckForEmptyValue($ns1))   { $ns1   = 'ns1.' . $provider; }
if (fs_director::CheckForEmptyValue($ns2))   { $ns2   = 'ns2.' . $provider; }
if (fs_director::CheckForEmptyValue($ns1ip)) { $ns1ip = $ip; }
if (fs_director::CheckForEmptyValue($ns2ip)) { $ns2ip = $ip; }
$fqdn = ctrl_options::GetSystemOption('bulwark_domain'); // p.ej. panel.provider.com

// Cuenta zadmin (grupo 1)
$uid = $zdbh->query("SELECT ac_id_pk FROM x_accounts WHERE ac_user_vc='zadmin' AND ac_deleted_ts IS NULL LIMIT 1")->fetchColumn();
if (!$uid) { fwrite(STDERR, "No existe la cuenta zadmin.\n"); exit(1); }
$username = 'zadmin';

// ── 1. vhost del dominio proveedor ──────────────────────────────────────────
$vstmt = $zdbh->prepare("SELECT vh_id_pk FROM x_vhosts WHERE vh_name_vc=:d AND vh_deleted_ts IS NULL");
$vstmt->execute([':d' => $provider]);
$vid = $vstmt->fetchColumn();

if (!$vid) {
    $destination = str_replace('.', '_', $provider);
    $paths = ctrl_options::GetVhostPaths($username, $destination);
    foreach (['domain_root', 'public_html', 'tmp', 'logs', 'errorpages', 'cgibin'] as $p) {
        if (!empty($paths[$p])) { fs_director::CreateDirectory($paths[$p]); }
    }
    if (!empty($paths['domain_root'])) { fs_director::SetFileSystemPermissions($paths['domain_root'], 0755); }

    $ins = $zdbh->prepare("INSERT INTO x_vhosts (vh_acc_fk, vh_name_vc, vh_directory_vc, vh_type_in, vh_created_ts)
                           VALUES (:u, :d, :dir, 1, :t)");
    $ins->execute([':u' => $uid, ':d' => $provider, ':dir' => $destination, ':t' => time()]);
    $vid = $zdbh->lastInsertId();
    echo "vhost creado: {$provider} (id {$vid})\n";
} else {
    echo "vhost ya existe: {$provider} (id {$vid})\n";
}

// ── 2. registros DNS de la zona base ────────────────────────────────────────
$cnt = $zdbh->prepare("SELECT COUNT(*) FROM x_dns WHERE dn_vhost_fk=:v AND dn_deleted_ts IS NULL");
$cnt->execute([':v' => $vid]);

if ((int)$cnt->fetchColumn() === 0) {
    // subdominio del panel si el FQDN es hijo del dominio proveedor
    $panelHost = null;
    $suffix = '.' . $provider;
    if ($fqdn && strlen($fqdn) > strlen($suffix) && substr($fqdn, -strlen($suffix)) === $suffix) {
        $panelHost = substr($fqdn, 0, -strlen($suffix));
    }

    // [type, host, ttl, target, priority]
    $records = [
        ['NS',  '@',     172800, $ns1,   null],
        ['NS',  '@',     172800, $ns2,   null],
        ['A',   'ns1',   172800, $ns1ip, null],
        ['A',   'ns2',   172800, $ns2ip, null],
        ['A',   '@',     3600,   $ip,    null],
        ['A',   'www',   3600,   $ip,    null],
        ['A',   'mail',  3600,   $ip,    null],
        ['MX',  '@',     3600,   'mail.' . $provider, 10],
        ['TXT', '@',     3600,   'v=spf1 a mx ip4:' . $ip . ' ~all', null],
        ['TXT', '_dmarc',3600,   'v=DMARC1; p=none; rua=mailto:postmaster@' . $provider . '; fo=1', null],
        ['TXT', 'default._domainkey', 3600, 'PENDING', null],
    ];
    if ($panelHost !== null && $panelHost !== 'www') {
        $records[] = ['A', $panelHost, 3600, $ip, null];
    }

    $dstmt = $zdbh->prepare("INSERT INTO x_dns
        (dn_acc_fk, dn_name_vc, dn_vhost_fk, dn_type_vc, dn_host_vc, dn_ttl_in, dn_target_vc, dn_priority_in, dn_created_ts)
        VALUES (:u, :name, :v, :type, :host, :ttl, :target, :prio, :t)");
    foreach ($records as $r) {
        $dstmt->execute([
            ':u' => $uid, ':name' => $provider, ':v' => $vid,
            ':type' => $r[0], ':host' => $r[1], ':ttl' => $r[2],
            ':target' => $r[3], ':prio' => $r[4], ':t' => time(),
        ]);
    }
    echo "zona base creada: " . count($records) . " registros (SOA usa {$ns1})\n";
} else {
    echo "la zona ya tiene registros; no se modifica.\n";
}

// ── 3. avisar al daemon para que escriba zona + named.conf + vhost apache ────
$zdbh->exec("UPDATE x_settings SET so_value_tx='true' WHERE so_name_vc='apache_changed'");
$zdbh->exec("UPDATE x_settings SET so_value_tx='true' WHERE so_name_vc='dns_hasupdates'");
echo "OK (ejecuta el daemon para aplicar).\n";

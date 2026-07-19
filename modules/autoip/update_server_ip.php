#!/usr/local/bin/php
<?php
/**
 * update_server_ip.php — CLI tool: update Bulwark server_ip and cascade to DNS A records.
 * Usage: php update_server_ip.php <ip-address>
 * Called by: /usr/local/bin/update_ip_server
 */

if (php_sapi_name() !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit("CLI only.\n");
}

$newip = trim($argv[1] ?? '');

if ($newip === '' || !filter_var($newip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    fwrite(STDERR, "Error: invalid or missing IPv4 address.\n");
    fwrite(STDERR, "Usage: " . basename($argv[0]) . " <ip-address>\n");
    exit(1);
}

$bulwark_root = '/usr/local/bulwark/';
require $bulwark_root . 'cnf/db.php';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$row  = $pdo->query("SELECT so_value_tx FROM x_settings WHERE so_name_vc='server_ip'")->fetch();
$oldip = $row ? $row['so_value_tx'] : '';

echo "Old server_ip : " . ($oldip ?: '(none)') . "\n";
echo "New server_ip : " . $newip . "\n";

if ($oldip === $newip) {
    echo "No change needed.\n";
    exit(0);
}

// Update server_ip
$pdo->prepare("UPDATE x_settings SET so_value_tx=:ip WHERE so_name_vc='server_ip'")
    ->execute([':ip' => $newip]);

// Cascade to DNS A records
$dnsRows = 0;
if ($oldip !== '') {
    $stmt = $pdo->prepare(
        "UPDATE x_dns SET dn_target_vc=:newip
         WHERE dn_target_vc=:oldip AND dn_type_vc='A' AND dn_deleted_ts IS NULL"
    );
    $stmt->execute([':newip' => $newip, ':oldip' => $oldip]);
    $dnsRows = $stmt->rowCount();
}
echo "DNS A records updated: {$dnsRows}\n";

// Cascade to vhost custom IPs
$vhRows = 0;
if ($oldip !== '') {
    $stmt = $pdo->prepare(
        "UPDATE x_vhosts SET vh_custom_ip_vc=:newip
         WHERE vh_custom_ip_vc=:oldip AND vh_deleted_ts IS NULL"
    );
    $stmt->execute([':newip' => $newip, ':oldip' => $oldip]);
    $vhRows = $stmt->rowCount();
}
echo "Vhost custom IPs updated: {$vhRows}\n";

// Update x_autoip tracking fields
$pdo->prepare(
    "UPDATE x_autoip SET ai_newip_vc=:newip, ai_oldip_vc=:oldip, ai_lastupdate_ts=:ts WHERE ai_id_pk=1"
)->execute([':newip' => $newip, ':oldip' => $oldip, ':ts' => time()]);

// Schedule DNS zone rebuild
$r = $pdo->query("SELECT so_value_tx FROM x_settings WHERE so_name_vc='dns_hasupdates'")->fetch();
$ids = array_filter(explode(',', (string)($r['so_value_tx'] ?? '')), 'strlen');
if (!in_array('0', $ids)) { $ids[] = '0'; }
$pdo->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='dns_hasupdates'")
    ->execute([':v' => implode(',', $ids)]);

echo "DNS zone rebuild scheduled.\n";
echo "Done.\n";

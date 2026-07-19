#!/usr/local/bin/php
<?php
/**
 * ClamAV post-scan processor — ejecutado como root por clamav_scan_mailboxes.sh
 * Lee la lista de archivos infectados, los enruta a la cuarentena correcta
 * (por usuario o global), escribe metadatos JSON, envía email al usuario
 * afectado y actualiza el contador Redis.
 *
 * Uso: php clamav_post_scan.php <infected_list_file>
 */

define('BULWARK_ROOT',      '/usr/local/bulwark');
define('HOSTDATA_ROOT',     '/var/bulwark/hostdata');
define('ADMIN_QUARANTINE',  '/var/bulwark/clamav/quarantine');
define('SCAN_LOG',          '/var/bulwark/clamav/scan_results.log');
define('RESTORE_REQUESTS',  '/var/bulwark/run/restore_requests');
define('REDIS_HOST',        '127.0.0.1');
define('REDIS_PORT',        6379);

require_once BULWARK_ROOT . '/cnf/db.php';

// ---- DB / Redis helpers ------------------------------------------------

function dbConnect(): PDO {
    global $host, $dbname, $user, $pass;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    return $pdo;
}

function redisClient(): ?Redis {
    static $r = null;
    if ($r === null) {
        $r = new Redis();
        if (!@$r->connect(REDIS_HOST, REDIS_PORT, 2)) {
            $r = null;
        } else {
            $rp = @file_get_contents('/usr/local/bulwark/cnf/redis.pass');
            if ($rp !== false && trim($rp) !== '') { try { $r->auth(['panel', trim($rp)]); } catch (Exception $e) {} }
        }
    }
    return $r;
}

// ---- Owner resolution -------------------------------------------------

function resolveOwner(string $path): ?array {
    if (strpos($path, HOSTDATA_ROOT . '/') !== 0) return null;
    $rel   = substr($path, strlen(HOSTDATA_ROOT) + 1);
    $parts = explode('/', $rel, 3);
    if (count($parts) < 2) return null;
    [$username, $dirKey] = $parts;

    try {
        $stmt = dbConnect()->prepare(
            "SELECT v.vh_name_vc FROM x_vhosts v
             JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk
             WHERE a.ac_user_vc = ? AND v.vh_directory_vc = ?
               AND v.vh_deleted_ts IS NULL LIMIT 1"
        );
        $stmt->execute([$username, $dirKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $row = null;
    }

    return [
        'user'        => $username,
        'dir_key'     => $dirKey,
        'domain_name' => $row ? $row['vh_name_vc'] : str_replace('_', '.', $dirKey),
    ];
}

function getUserEmail(string $username): ?string {
    try {
        $stmt = dbConnect()->prepare(
            "SELECT ac_email_vc FROM x_accounts
             WHERE ac_user_vc = ? AND ac_deleted_ts IS NULL LIMIT 1"
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && filter_var($row['ac_email_vc'], FILTER_VALIDATE_EMAIL))
            ? $row['ac_email_vc'] : null;
    } catch (Exception $e) {
        return null;
    }
}

// ---- Quarantine -------------------------------------------------------

function quarantineFile(string $filePath, string $signature, ?array $owner): array {
    $ts       = date('Ymd_His');
    $basename = basename($filePath);
    $qname    = $basename . '.' . $ts;

    $qDir = ($owner !== null)
        ? HOSTDATA_ROOT . '/' . $owner['user'] . '/quarantine'
        : ADMIN_QUARANTINE;

    if (!is_dir($qDir)) {
        mkdir($qDir, 0750, true);
        chown($qDir, 'www');
        chgrp($qDir, 'www');
    }

    $dest = $qDir . '/' . $qname;
    if (!@rename($filePath, $dest)) {
        return ['ok' => false, 'error' => "No se pudo mover $filePath a $dest"];
    }

    chmod($dest, 0640);
    chown($dest, 'www');
    chgrp($dest, 'www');

    $meta = [
        'original_path' => $filePath,
        'signature'     => $signature,
        'user'          => $owner['user']        ?? null,
        'dir_key'       => $owner['dir_key']      ?? null,
        'domain_name'   => $owner['domain_name']  ?? null,
        'quarantined_at'=> date('c'),
        'qname'         => $qname,
        'status'        => 'quarantined',
    ];
    $metaFile = $dest . '.json';
    file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    chmod($metaFile, 0640);
    chown($metaFile, 'www');
    chgrp($metaFile, 'www');

    return ['ok' => true, 'dest' => $dest, 'qname' => $qname, 'meta' => $meta];
}

function countUserQuarantine(string $username): int {
    $qDir = HOSTDATA_ROOT . '/' . $username . '/quarantine';
    if (!is_dir($qDir)) return 0;
    $files = array_filter(
        (array)scandir($qDir),
        fn($f) => $f !== '.' && $f !== '..' && !str_ends_with($f, '.json') && is_file($qDir . '/' . $f)
    );
    return count($files);
}

// ---- Notifications ----------------------------------------------------

function sendUserEmail(string $email, string $username, array $quarantined): void {
    $count   = count($quarantined);
    $subject = "[Seguridad] " . ($count === 1 ? "Archivo infectado detectado" : "$count archivos infectados detectados") . " en tu alojamiento";

    $fileLines = '';
    foreach ($quarantined as $q) {
        $m = $q['meta'];
        $fileLines .= "  Archivo:  " . basename($m['original_path']) . "\n"
                    . "  Ruta:     " . $m['original_path']          . "\n"
                    . "  Dominio:  " . ($m['domain_name'] ?? '—')    . "\n"
                    . "  Firma:    " . $m['signature']               . "\n"
                    . "  Fecha:    " . $m['quarantined_at']          . "\n\n";
    }

    $body = "Hola $username,\n\n"
        . "El sistema antivirus ha detectado y puesto en cuarentena "
        . ($count === 1 ? "un archivo infectado" : "$count archivos infectados")
        . " en tu cuenta de alojamiento:\n\n"
        . $fileLines
        . "Los archivos han sido eliminados de su ubicación original y movidos a cuarentena.\n\n"
        . "Qué hacer:\n"
        . "  1. Accede al panel y abre el módulo 'Antivirus' para ver los archivos en cuarentena.\n"
        . "  2. Si reconoces el archivo como legítimo (falso positivo), solicita la restauración.\n"
        . "  3. Si no lo reconoces, es malware real — no lo restaures.\n\n"
        . "Si tienes dudas, contacta con el administrador del servidor.\n\n"
        . "-- Sistema de seguridad automático\n";

    $headers = "From: seguridad@" . php_uname('n') . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "X-Mailer: Bulwark-ClamAV\r\n";

    @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

function updateRedisNotification(string $username, int $count): void {
    $r = redisClient();
    if ($r === null) return;
    $key = "bulwark:quarantine:$username:count";
    if ($count > 0) {
        $r->set($key, $count);
    } else {
        $r->del($key);
    }
}

// ---- Main -------------------------------------------------------------

$infectedListFile = $argv[1] ?? null;
if (!$infectedListFile || !file_exists($infectedListFile)) exit(0);

$lines = file($infectedListFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
@unlink($infectedListFile);

$infected = [];
foreach ($lines as $line) {
    if (preg_match('/^(.+): (.+) FOUND$/', $line, $m)) {
        $infected[] = ['path' => $m[1], 'sig' => $m[2]];
    }
}

if (empty($infected)) exit(0);

$byUser  = [];
$logLines = [];

foreach ($infected as $item) {
    $owner  = resolveOwner($item['path']);
    $result = quarantineFile($item['path'], $item['sig'], $owner);

    $logLines[] = $item['path'] . ': ' . $item['sig'] . ' FOUND';
    if ($result['ok']) {
        $suffix = $owner ? ' [usuario: ' . $owner['user'] . ']' : ' [admin]';
        $logLines[] = $item['path'] . ": movido a '" . $result['dest'] . "'" . $suffix;
        if ($owner) {
            $byUser[$owner['user']][] = $result;
        }
    } else {
        $logLines[] = $item['path'] . ': ERROR: ' . $result['error'];
    }
}

// Emails + Redis
foreach ($byUser as $username => $quarantined) {
    $email = getUserEmail($username);
    if ($email) sendUserEmail($email, $username, $quarantined);
    updateRedisNotification($username, countUserQuarantine($username));
}

// Append to admin log
file_put_contents(SCAN_LOG, implode("\n", $logLines) . "\n", FILE_APPEND);
chmod(SCAN_LOG, 0644);

exit(0);

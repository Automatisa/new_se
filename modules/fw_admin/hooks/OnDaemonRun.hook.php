<?php
/**
 * fw_admin — OnDaemonRun.hook.php
 * Se ejecuta cada ~5 minutos con el daemon de Bulwark.
 *
 * Responsabilidades:
 *  1. Genera /var/bulwark/logs/fw_status.json (vía privilege::run)
 *  2. Sincroniza x_fw_auto_banned con los bans activos de SSHGuard (tabla pf <sshguard>)
 *  3. Re-aplica x_fw_blocked a pf por si hay discrepancias (ej: tras reboot)
 */

// Los hooks se incluyen dentro de runtime_hook::Execute() (método estático),
// por lo que las variables globales del daemon no se heredan automáticamente.
global $zdbh;

if (!class_exists('privilege')) {
    require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
}

echo fs_filehandler::NewLine() . "START fw_admin Firewall Sync Hook." . fs_filehandler::NewLine();

if (!ui_module::CheckModuleEnabled('Firewall Admin')) {
    echo "fw_admin module DISABLED — nothing to do." . fs_filehandler::NewLine();
    echo "END fw_admin Firewall Sync Hook." . fs_filehandler::NewLine();
    return;
}

echo "fw_admin module ENABLED." . fs_filehandler::NewLine();

// ---- 1. Actualizar JSON de estado ----
echo "Refreshing fw_status.json..." . fs_filehandler::NewLine();
try {
    [$code,, $err] = privilege::run('fw_status_dump');
    if ($code !== 0) {
        echo "  WARNING: fw_status_dump returned $code — $err" . fs_filehandler::NewLine();
    } else {
        echo "  fw_status.json updated." . fs_filehandler::NewLine();
    }
} catch (\Exception $e) {
    echo "  ERROR (fw_status_dump): " . $e->getMessage() . fs_filehandler::NewLine();
    error_log(date('Y-m-d H:i:s') . " fw_admin OnDaemonRun fw_status_dump: " . $e->getMessage());
}

// ---- 2. Leer JSON y sincronizar x_fw_auto_banned ----
$jsonPath = ctrl_options::GetSystemOption('fw_status_json_path')
                ?: '/var/bulwark/logs/fw_status.json';

$status = [];
if (file_exists($jsonPath)) {
    $raw = @file_get_contents($jsonPath);
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $status = $decoded;
        }
    }
}

$sshguardIPs = $status['sshguard_blocked'] ?? [];
echo "SSHGuard active bans: " . count($sshguardIPs) . fs_filehandler::NewLine();

// Mapa de nombre de servicio SSHGuard → puerto estándar
$servicePorts = [
    'SSH'        => 22, 'SSHD'      => 22,
    'ProFTPD'    => 21, 'FTP'       => 21, 'vsftpd' => 21, 'Pure-FTPd' => 21,
    'Exim'       => 25, 'Postfix'   => 25, 'Sendmail' => 25, 'SMTP' => 25,
    'Dovecot'    => 143, 'IMAP'     => 143, 'Courier' => 143,
    'POP3'       => 110,
    'Apache'     => 80, 'nginx'     => 80, 'HTTP' => 80,
    'Asterisk'   => 5060,
    'Cyrus'      => 143,
];

/**
 * Detecta el servicio atacado desde auth.log buscando líneas de SSHGuard.
 * El daemon corre como root, así que puede leer /var/log/auth.log.
 * Lee los últimos 100 KB para no cargar el fichero entero.
 */
$detectService = function(string $ip) use ($servicePorts): array {
    $baseIp  = preg_replace('/\/\d+$/', '', $ip); // quitar /32 o /128
    $authLog = '/var/log/auth.log';
    if (!is_readable($authLog)) {
        return ['', 0];
    }
    $fh = @fopen($authLog, 'r');
    if (!$fh) {
        return ['', 0];
    }
    // Leer últimos 100 KB
    fseek($fh, 0, SEEK_END);
    $size   = ftell($fh);
    $offset = max(0, $size - 102400);
    fseek($fh, $offset);
    $chunk = fread($fh, 102400);
    fclose($fh);

    $service = '';
    $search  = 'Attack from "' . $baseIp . '"';
    $pos     = strrpos($chunk, $search);
    if ($pos !== false) {
        $lineEnd = strpos($chunk, "\n", $pos);
        $line    = substr($chunk, $pos, $lineEnd !== false ? $lineEnd - $pos : 200);
        if (preg_match('/on service (\S+)/', $line, $m)) {
            $service = $m[1];
        }
    }
    $port = $servicePorts[$service] ?? 0;
    return [$service, $port];
};

if (!empty($sshguardIPs) || true) {
    // Marcar todos como inactivos primero
    $zdbh->exec("UPDATE x_fw_auto_banned SET fa_active_in=0");

    $now = time();
    foreach ($sshguardIPs as $ip) {
        // Validar IP antes de insertar
        if (!filter_var($ip, FILTER_VALIDATE_IP)
            && !preg_match('/^[0-9a-fA-F:.]+\/[0-9]{1,3}$/', $ip)) {
            continue;
        }

        [$service, $port] = $detectService($ip);

        $exists = $zdbh->prepare(
            "SELECT fa_id_pk FROM x_fw_auto_banned WHERE fa_ip_vc=:ip LIMIT 1"
        );
        $exists->bindValue(':ip', $ip);
        $exists->execute();

        if ($exists->fetchColumn()) {
            $upd = $zdbh->prepare(
                "UPDATE x_fw_auto_banned
                 SET fa_active_in=1, fa_since_ts=:ts, fa_service_vc=:svc, fa_port_in=:port
                 WHERE fa_ip_vc=:ip"
            );
            $upd->bindValue(':ts',   $now);
            $upd->bindValue(':svc',  $service);
            $upd->bindValue(':port', $port);
            $upd->bindValue(':ip',   $ip);
            $upd->execute();
        } else {
            $ins = $zdbh->prepare(
                "INSERT INTO x_fw_auto_banned
                    (fa_ip_vc, fa_jail_vc, fa_service_vc, fa_port_in, fa_since_ts, fa_active_in)
                 VALUES (:ip, 'sshguard', :svc, :port, :ts, 1)"
            );
            $ins->bindValue(':ip',   $ip);
            $ins->bindValue(':svc',  $service);
            $ins->bindValue(':port', $port);
            $ins->bindValue(':ts',   $now);
            $ins->execute();
        }
    }
    echo "  x_fw_auto_banned synced." . fs_filehandler::NewLine();
}

// ---- 3. Re-aplicar bloqueos manuales a pf (reconciliación tras reboot) ----
echo "Re-applying manual blocks to pf..." . fs_filehandler::NewLine();
try {
    [$code,, $err] = privilege::run('fw_block_apply');
    if ($code !== 0) {
        echo "  WARNING: fw_block_apply returned $code — $err" . fs_filehandler::NewLine();
    } else {
        echo "  pf bulwark_blocked table refreshed." . fs_filehandler::NewLine();
    }
} catch (\Exception $e) {
    echo "  ERROR (fw_block_apply): " . $e->getMessage() . fs_filehandler::NewLine();
    error_log(date('Y-m-d H:i:s') . " fw_admin OnDaemonRun fw_block_apply: " . $e->getMessage());
}

// ---- 4. Re-aplicar lista blanca ----
echo "Re-applying whitelist to pf..." . fs_filehandler::NewLine();
try {
    [$code,, $err] = privilege::run('fw_whitelist_apply');
    if ($code !== 0) {
        echo "  WARNING: fw_whitelist_apply returned $code — $err" . fs_filehandler::NewLine();
    } else {
        echo "  pf bulwark_whitelist table refreshed." . fs_filehandler::NewLine();
    }
} catch (\Exception $e) {
    echo "  ERROR (fw_whitelist_apply): " . $e->getMessage() . fs_filehandler::NewLine();
    error_log(date('Y-m-d H:i:s') . " fw_admin OnDaemonRun fw_whitelist_apply: " . $e->getMessage());
}

// ---- 5. Re-aplicar reglas personalizadas al anchor bulwark_rules ----
echo "Re-applying custom rules to pf anchor..." . fs_filehandler::NewLine();
try {
    [$code,, $err] = privilege::run('fw_rules_apply');
    if ($code !== 0) {
        echo "  WARNING: fw_rules_apply returned $code — $err" . fs_filehandler::NewLine();
    } else {
        echo "  pf anchor bulwark_rules refreshed." . fs_filehandler::NewLine();
    }
} catch (\Exception $e) {
    echo "  ERROR (fw_rules_apply): " . $e->getMessage() . fs_filehandler::NewLine();
    error_log(date('Y-m-d H:i:s') . " fw_admin OnDaemonRun fw_rules_apply: " . $e->getMessage());
}

echo "END fw_admin Firewall Sync Hook." . fs_filehandler::NewLine();

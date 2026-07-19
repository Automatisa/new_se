<?php
/**
 * fw_admin — OnDaemonDay.hook.php
 * Se ejecuta una vez al día con el daemon de Bulwark.
 *
 * Responsabilidades:
 *  1. Limpia registros inactivos de x_fw_auto_banned con más de 7 días de antigüedad
 *  2. Elimina soft-deleted de x_fw_blocked y x_fw_whitelist con más de 30 días
 *  3. Registra en sencrypt.log el resumen diario de actividad del cortafuegos
 */

// Los hooks se incluyen dentro de runtime_hook::Execute() (método estático).
global $zdbh;

echo fs_filehandler::NewLine() . "START fw_admin Daily Cleanup Hook." . fs_filehandler::NewLine();

if (!ui_module::CheckModuleEnabled('Firewall Admin')) {
    echo "fw_admin module DISABLED — nothing to do." . fs_filehandler::NewLine();
    echo "END fw_admin Daily Cleanup Hook." . fs_filehandler::NewLine();
    return;
}

$logFile = ctrl_options::GetSystemOption('bulwark_root') . 'modules/fw_admin/fw_admin.log';
$now     = time();

// ---- 1. Limpiar x_fw_auto_banned inactivos > 7 días ----
$cutoff7 = $now - (7 * 86400);
$del1 = $zdbh->prepare(
    "DELETE FROM x_fw_auto_banned WHERE fa_active_in=0 AND fa_since_ts < :cutoff"
);
$del1->bindValue(':cutoff', $cutoff7);
$del1->execute();
$n1 = $del1->rowCount();
echo "  Deleted $n1 stale auto-ban records (inactive > 7 days)." . fs_filehandler::NewLine();

// ---- 2. Limpiar soft-deletes de x_fw_blocked > 30 días ----
$cutoff30 = $now - (30 * 86400);
$del2 = $zdbh->prepare(
    "DELETE FROM x_fw_blocked WHERE fb_deleted_ts IS NOT NULL AND fb_deleted_ts < :cutoff"
);
$del2->bindValue(':cutoff', $cutoff30);
$del2->execute();
$n2 = $del2->rowCount();
echo "  Deleted $n2 soft-deleted block records (> 30 days)." . fs_filehandler::NewLine();

// ---- 3. Limpiar soft-deletes de x_fw_whitelist > 30 días ----
$del3 = $zdbh->prepare(
    "DELETE FROM x_fw_whitelist WHERE fw_deleted_ts IS NOT NULL AND fw_deleted_ts < :cutoff"
);
$del3->bindValue(':cutoff', $cutoff30);
$del3->execute();
$n3 = $del3->rowCount();
echo "  Deleted $n3 soft-deleted whitelist records (> 30 days)." . fs_filehandler::NewLine();

// ---- 4. Limpiar x_fw_login_attempts con más de 24 horas ----
try {
    $del4 = $zdbh->prepare(
        "DELETE FROM x_fw_login_attempts WHERE la_ts_in < :cutoff"
    );
    $del4->bindValue(':cutoff', $now - 86400);
    $del4->execute();
    $n4 = $del4->rowCount();
    echo "  Deleted $n4 login attempt records (> 24h)." . fs_filehandler::NewLine();
} catch (\Throwable $e) {
    $n4 = 0; // tabla puede no existir aún
}

// ---- 5. Resumen diario en log ----
$activeBlocked   = (int)$zdbh->query("SELECT COUNT(*) FROM x_fw_blocked WHERE fb_active_in=1 AND fb_deleted_ts IS NULL")->fetchColumn();
$activeWhitelist = (int)$zdbh->query("SELECT COUNT(*) FROM x_fw_whitelist WHERE fw_deleted_ts IS NULL")->fetchColumn();
$activeSgBans    = (int)$zdbh->query("SELECT COUNT(*) FROM x_fw_auto_banned WHERE fa_active_in=1")->fetchColumn();

$summary = date('Y-m-d H:i:s')
         . " | blocked=$activeBlocked whitelist=$activeWhitelist sshguard_bans=$activeSgBans"
         . " | pruned: auto=$n1 blocks=$n2 white=$n3 login_attempts=$n4\n";

@error_log($summary, 3, $logFile);
echo "  Daily summary: " . trim($summary) . fs_filehandler::NewLine();

echo "END fw_admin Daily Cleanup Hook." . fs_filehandler::NewLine();

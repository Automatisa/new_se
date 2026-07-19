<?php
/**
 * Limpieza automática diaria de x_logs según log_retention_days.
 * Se ejecuta una vez al día por el daemon de Bulwark.
 */

global $zdbh;

$days = (int) ctrl_options::GetSystemOption('log_retention_days');
if ($days < 1) $days = 30;

$cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
$stmt = $zdbh->prepare("DELETE FROM x_logs WHERE lg_when_ts < :cutoff");
$stmt->bindValue(':cutoff', $cutoff);
$stmt->execute();

$deleted = $stmt->rowCount();
if ($deleted > 0) {
    echo "Log cleanup: {$deleted} entradas eliminadas (retención: {$days} días, corte: {$cutoff})" . PHP_EOL;
}

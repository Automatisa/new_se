#!/usr/bin/php
<?php
/**
 * dns_cluster_sync.php — Sincroniza la lista de zonas de los peers del cluster DNS.
 * Envoltorio CLI de dns_cluster::SyncRemoteZones() (también lo llama el daemon).
 */
$rootPath = str_replace('\\', '/', dirname(__FILE__));
$rootPath = str_replace('/bin', '/', $rootPath);
chdir($rootPath);

require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once 'dryden/sys/dns_cluster.class.php';

if (!runtime_controller::IsCLI()) {
    exit(1);
}

$nodesChanged = dns_cluster::SyncClusterNodes();
echo $nodesChanged ? "Malla de nodos actualizada.\n" : "Sin cambios en la malla de nodos.\n";

$zonesChanged = dns_cluster::SyncRemoteZones();
echo $zonesChanged ? "Zonas remotas actualizadas.\n" : "Sin cambios en las zonas remotas.\n";

// Si algo cambió, marcar regeneración de named.conf (se aplica en el próximo ciclo del
// daemon, que corre como root y recarga BIND). SyncClusterNodes/SyncRemoteZones ya la marcan.
if ($nodesChanged || $zonesChanged) {
    echo "Cambios detectados: named.conf se regenerará en el próximo ciclo del daemon.\n";
}

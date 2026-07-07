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

$changed = dns_cluster::SyncRemoteZones();
echo $changed ? "Zonas remotas actualizadas.\n" : "Sin cambios en las zonas remotas.\n";

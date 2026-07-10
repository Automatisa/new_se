#!/usr/bin/php
<?php
/**
 * dns_cluster_node.php — Gestión de nodos del cluster DNS desde el nodo actual.
 *
 * Uso:
 *   php bin/dns_cluster_node.php list
 *   php bin/dns_cluster_node.php disable <hostname>   # baja (tombstone): se propaga por la malla
 *   php bin/dns_cluster_node.php enable  <hostname>   # reactiva un nodo dado de baja
 *
 * La baja marca el nodo como deshabilitado (tombstone), borra sus zonas remotas y
 * regenera named.conf (deja de esclavizarlo / notificarlo). El estado se propaga al
 * resto de nodos vía GET /v1/cluster/nodes (SyncClusterNodes), y una baja NO se revierte
 * por gossip (solo este 'enable' o un nuevo join reactivan el nodo). Ejecutar en el
 * primario (o en cualquier nodo) como el usuario del panel.
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
$action = isset($argv[1]) ? strtolower($argv[1]) : 'list';
$target = isset($argv[2]) ? strtolower(trim($argv[2])) : '';

if ($action === 'list') {
    $rows = $zdbh->query("SELECT nd_name_vc, nd_ip_vc, nd_is_self_in, nd_enabled_in FROM x_dns_nodes ORDER BY nd_name_vc")->fetchAll(PDO::FETCH_ASSOC);
    printf("%-32s %-16s %-6s %s\n", 'NODO', 'IP', 'SELF', 'ESTADO');
    foreach ($rows as $r) {
        printf("%-32s %-16s %-6s %s\n",
            $r['nd_name_vc'], $r['nd_ip_vc'],
            ((int)$r['nd_is_self_in'] === 1 ? 'sí' : ''),
            ((int)$r['nd_enabled_in'] === 1 ? 'activo' : 'BAJA (tombstone)'));
    }
    exit(0);
}

if (($action === 'disable' || $action === 'enable') && $target !== '') {
    $st = $zdbh->prepare("SELECT nd_id_pk, nd_is_self_in FROM x_dns_nodes WHERE nd_name_vc=:n");
    $st->execute([':n' => $target]);
    $node = $st->fetch();
    if (!$node) {
        fwrite(STDERR, "Nodo no encontrado: $target\n");
        exit(2);
    }
    if ((int)$node['nd_is_self_in'] === 1) {
        fwrite(STDERR, "No se puede dar de baja/alta el propio nodo (self).\n");
        exit(3);
    }
    $id = (int)$node['nd_id_pk'];

    if ($action === 'disable') {
        $zdbh->prepare("UPDATE x_dns_nodes SET nd_enabled_in=0 WHERE nd_id_pk=:id")->execute([':id' => $id]);
        $zdbh->prepare("DELETE FROM x_dns_remote_zones WHERE rz_node_fk=:id")->execute([':id' => $id]);
        echo "Nodo '$target' dado de BAJA (tombstone). Se propagará al resto de la malla.\n";
    } else {
        $zdbh->prepare("UPDATE x_dns_nodes SET nd_enabled_in=1 WHERE nd_id_pk=:id")->execute([':id' => $id]);
        echo "Nodo '$target' REACTIVADO.\n";
    }

    // Regenerar named.conf en este nodo (sin pisar ids de dominio pendientes).
    $cur = (string)ctrl_options::GetSystemOption('dns_hasupdates');
    if (trim($cur) === '') {
        $zdbh->exec("UPDATE x_settings SET so_value_tx='cluster' WHERE so_name_vc='dns_hasupdates'");
    }
    echo "Ejecuta el daemon (o espera al ciclo) para regenerar named.conf y propagar.\n";
    exit(0);
}

fwrite(STDERR, "Uso: php bin/dns_cluster_node.php list | disable <hostname> | enable <hostname>\n");
exit(1);

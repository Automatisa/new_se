<?php
/**
 * fpm_regen.php — Regenera pools PHP-FPM inmediatamente como root.
 *
 * Arranca el entorno mínimo de Bulwark (BD + opciones) y llama a
 * fpm_pool_manager::Regenerate(). Se invoca vía privilege::run('fpm_regenerate')
 * desde el panel cuando el usuario guarda la configuración PHP de un dominio,
 * sin tener que esperar al siguiente ciclo del daemon.
 *
 * Debe ejecutarse como root (lo garantiza doas).
 */

set_time_limit(30);

$rawPath  = str_replace('\\', '/', dirname(__FILE__));
$rootPath = str_replace('/bin', '/', $rawPath);
chdir($rootPath);

require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once 'dryden/sys/fpm_pool_manager.class.php';

$count = fpm_pool_manager::Regenerate();
echo "fpm_regen: $count pools regenerados." . PHP_EOL;
exit(0);

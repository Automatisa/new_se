<?php
/**
 * setup_api_manager.php
 * Registra el módulo api_manager en la base de datos del panel.
 *
 * Ejecutar UNA SOLA VEZ como root desde el directorio raíz del panel:
 *   php /usr/local/bulwark/bin/setup_api_manager.php
 */

if (php_sapi_name() !== 'cli') {
    die("Este script sólo puede ejecutarse desde la línea de comandos.\n");
}

$rawPath  = str_replace("\\", "/", dirname(__FILE__));
$rootPath = str_replace("/bin", "/", $rawPath);
chdir($rootPath);

require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

echo "\n=== setup_api_manager.php ===\n\n";

// ── 1. Tabla + x_settings ────────────────────────────────────────────────────
echo "1. Creando tabla x_api_tokens y entrada en x_settings...\n";
include 'modules/api_manager/deploy/install.run';
echo "   OK\n";

// ── 2. Registrar en x_modules ────────────────────────────────────────────────
echo "2. Registrando módulo en x_modules...\n";
$chk = $zdbh->prepare("SELECT mo_id_pk FROM x_modules WHERE mo_folder_vc = 'api_manager'");
$chk->execute();
$existing = $chk->fetch();

if ($existing) {
    $mod_id = (int)$existing['mo_id_pk'];
    echo "   Ya registrado (id=$mod_id), omitiendo.\n";
} else {
    ui_module::ModuleInfoToDB('api_manager');
    $chk2 = $zdbh->prepare("SELECT mo_id_pk FROM x_modules WHERE mo_folder_vc = 'api_manager'");
    $chk2->execute();
    $mod_id = (int)$chk2->fetch()['mo_id_pk'];
    echo "   Registrado con id=$mod_id\n";
}

// ── 3. Activar el módulo ─────────────────────────────────────────────────────
echo "3. Activando módulo...\n";
$upd = $zdbh->prepare(
    "UPDATE x_modules SET mo_enabled_en = 'true' WHERE mo_id_pk = :id"
);
$upd->bindParam(':id', $mod_id, PDO::PARAM_INT);
$upd->execute();
echo "   OK\n";

// ── 4. Conceder permiso al grupo zadmin ───────────────────────────────────────
echo "4. Concediendo acceso al grupo zadmin...\n";
$grp = $zdbh->prepare("SELECT ug_id_pk FROM x_groups WHERE ug_name_vc = 'Administrators'");
$grp->execute();
$zadmin = $grp->fetch();

if (!$zadmin) {
    echo "   AVISO: grupo 'zadmin' no encontrado. Concede permisos manualmente desde moduleadmin.\n";
} else {
    $gid = (int)$zadmin['ug_id_pk'];
    ctrl_groups::AddGroupModulePermissions($gid, $mod_id);
    echo "   Permiso concedido (group_id=$gid, module_id=$mod_id)\n";
}

echo "\n=== Completado. ===\n";
echo "Accede al panel > Server Admin > API Manager para gestionar tokens.\n\n";

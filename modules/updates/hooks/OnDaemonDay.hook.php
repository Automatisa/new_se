<?php
// Chequeo DIARIO de actualizaciones del sistema (paquetes + seguridad + parches base). El script
// corre en 2º plano y cachea el resultado en /var/bulwark/updates/status.json, que lee el módulo.
// El daemon corre como root; ejecuta el script directamente vía privilege::run (doas/whitelist).
if (is_file('/usr/local/bulwark/bin/sys_update_check.sh')) {
    if (!class_exists('privilege')) {
        require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
    }
    try {
        privilege::run('sys_update_check', array(), true);
    } catch (\Throwable $e) {
        error_log('updates OnDaemonDay: ' . $e->getMessage());
    }
}
// Aplica automáticamente las SUBVERSIONES (parches) de los paquetes gestionados/pinados; los
// saltos de MAYOR quedan retenidos para "Verificar y actualizar" desde el panel. Corre como root
// (el daemon ya es root) y refresca el catálogo por su cuenta; hace su propio fork en 2º plano.
if (is_file('/usr/local/bulwark/bin/pkg_pin.sh')) {
    @exec('/usr/local/bulwark/bin/pkg_pin.sh auto-sub >/dev/null 2>&1');
}
return true;

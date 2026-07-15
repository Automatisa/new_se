<?php
// Chequeo DIARIO de actualizaciones del sistema (paquetes + seguridad + parches base). El script
// corre en 2º plano y cachea el resultado en /var/sentora/updates/status.json, que lee el módulo.
// El daemon corre como root; ejecuta el script directamente vía privilege::run (doas/whitelist).
if (is_file('/usr/local/sentora/bin/sys_update_check.sh')) {
    if (!class_exists('privilege')) {
        require_once '/usr/local/sentora/dryden/sys/privilege.class.php';
    }
    try {
        privilege::run('sys_update_check', array(), true);
    } catch (\Throwable $e) {
        error_log('updates OnDaemonDay: ' . $e->getMessage());
    }
}
return true;

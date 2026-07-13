<?php

/**
 * OnDaemonRun (backup_admin) — Programador de copias automáticas por bloques.
 * Cada ejecución del daemon (cada 5 min): encola las cuentas cuya programación está vencida y
 * procesa un bloque de N copias (backup_batch_size), para no colapsar el servidor.
 * El interruptor maestro es el ajuste 'schedule_bu'; la programación por cuenta vive en
 * x_backup_schedule (la configura el usuario desde el módulo Backup).
 */
include('cnf/db.php');
try {
    $zdbh = new db_driver("mysql:host=" . $host . ";dbname=" . $dbname . "", $user, $pass);
    $GLOBALS['zdbh'] = $zdbh;
} catch (PDOException $e) {
    return;
}

if (ui_module::CheckModuleEnabled('Backup Config') && class_exists('sys_backup_scheduler')) {
    if (strtolower((string)ctrl_options::GetSystemOption('schedule_bu')) === 'true') {
        list($enq, $proc) = sys_backup_scheduler::tick();
        if ($enq > 0 || $proc > 0) {
            echo "Backup scheduler: $enq encoladas, $proc procesadas." . fs_filehandler::NewLine();
        }
    }
}

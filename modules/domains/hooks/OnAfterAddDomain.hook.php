<?php

// Cuando se añade un dominio: insertar fila por defecto en x_domain_php
// para que el pool FPM tenga configuración al primer ciclo del daemon.

if (!class_exists('fpm_pool_manager')) {
    require_once '/usr/local/bulwark/dryden/sys/fpm_pool_manager.class.php';
}

fpm_pool_manager::InsertDefaults();

<?php

// Cuando se elimina un dominio: limpiar su fila en x_domain_php.
// El pool FPM obsoleto lo elimina el daemon en el siguiente ciclo
// mediante el glob de bulwark_*.conf vs vhosts activos.

global $zdbh;

try {
    $zdbh->exec("
        DELETE FROM x_domain_php
        WHERE dp_vhost_fk NOT IN (
            SELECT vh_id_pk FROM x_vhosts
            WHERE vh_deleted_ts IS NULL AND vh_type_in IN (1, 2)
        )
    ");
} catch (PDOException $e) {
    // Tabla puede no existir aún
}

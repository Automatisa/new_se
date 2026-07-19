#!/bin/sh
# 0015_imapsync.sh — Migración de correo IMAP con imapsync (siempre EXTERNO -> panel):
#   - Tabla x_imapsync_jobs (cola de trabajos con estado/progreso).
#   - Ajustes CONFIGURABLES (límites de recursos y ejecuciones), editables luego en el módulo.
#   - Registra el módulo admin 'imapsync' (categoría Server Admin) con permiso SOLO al grupo admin (1).
# Idempotente.

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")
MY="mysql -u$U -p$P -h$H -N"

# --- tabla de trabajos ---
THAS=$($MY -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='x_imapsync_jobs'" 2>/dev/null)
if [ "$THAS" = "0" ]; then
    $MY -e "CREATE TABLE \`$DB\`.\`x_imapsync_jobs\` (
      \`ij_id_pk\` int(10) unsigned NOT NULL AUTO_INCREMENT,
      \`ij_acc_fk\` int(10) DEFAULT NULL,
      \`ij_dest_user_vc\` varchar(255) NOT NULL,
      \`ij_src_host_vc\` varchar(255) NOT NULL,
      \`ij_src_port_in\` int(6) DEFAULT 993,
      \`ij_src_ssl_vc\` varchar(8) DEFAULT 'ssl',
      \`ij_src_user_vc\` varchar(255) NOT NULL,
      \`ij_status_vc\` varchar(16) DEFAULT 'queued',
      \`ij_bytes_bi\` bigint(20) DEFAULT 0,
      \`ij_msgs_in\` int(11) DEFAULT 0,
      \`ij_total_msgs_in\` int(11) DEFAULT 0,
      \`ij_runs_in\` int(6) DEFAULT 0,
      \`ij_lastrun_ts\` int(20) DEFAULT NULL,
      \`ij_passfile_vc\` varchar(255) DEFAULT NULL,
      \`ij_log_vc\` varchar(255) DEFAULT NULL,
      \`ij_error_tx\` text DEFAULT NULL,
      \`ij_created_ts\` int(20) DEFAULT NULL,
      \`ij_updated_ts\` int(20) DEFAULT NULL,
      \`ij_deleted_ts\` int(20) DEFAULT NULL,
      PRIMARY KEY (\`ij_id_pk\`),
      KEY \`ij_status\` (\`ij_status_vc\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8" 2>/dev/null
    echo "x_imapsync_jobs creada"
else
    echo "x_imapsync_jobs ya existe"
fi

# --- ajustes configurables ---
add_setting() {
    NAME="$1"; VAL="$2"; CLEAN="$3"; DESC="$4"
    HAS=$($MY -e "SELECT COUNT(*) FROM \`$DB\`.x_settings WHERE so_name_vc='$NAME'" 2>/dev/null)
    if [ "$HAS" = "0" ]; then
        $MY -e "INSERT INTO \`$DB\`.x_settings (so_name_vc,so_cleanname_vc,so_value_tx,so_defvalues_tx,so_desc_tx,so_module_vc,so_usereditable_en)
                VALUES ('$NAME','$CLEAN','$VAL',NULL,'$DESC','imapsync','false')" 2>/dev/null
        echo "$NAME=$VAL añadido"
    fi
}
add_setting "imapsync_max_concurrent"  "2"   "Max procesos simultaneos"     "Maximo de migraciones imapsync corriendo a la vez (global)."
add_setting "imapsync_max_per_acct_day" "5"  "Max ejecuciones/cuenta/dia"   "Maximo de ejecuciones por cuenta y dia."
add_setting "imapsync_job_timeout"     "900" "Timeout por tanda (s)"        "Segundos maximos por ejecucion (tanda); como imapsync es incremental, se reanuda."
add_setting "imapsync_nice"            "19"  "Prioridad CPU (nice)"         "nice de la CPU (0-19; 19 = minima prioridad)."
add_setting "imapsync_max_bytes_sec"   "0"   "Limite bytes/seg"             "Limite de ancho de banda por proceso (0 = sin limite)."
add_setting "imapsync_max_msgs_sec"    "0"   "Limite mensajes/seg"          "Limite de ritmo por proceso (0 = sin limite)."

# --- registrar modulo admin ---
MHAS=$($MY -e "SELECT COUNT(*) FROM \`$DB\`.x_modules WHERE mo_folder_vc='imapsync'" 2>/dev/null)
if [ "$MHAS" = "0" ]; then
    $MY -e "INSERT INTO \`$DB\`.x_modules (mo_category_fk,mo_name_vc,mo_version_in,mo_folder_vc,mo_type_en,mo_desc_tx,mo_installed_ts,mo_enabled_en)
            VALUES (2,'IMAP Migration (imapsync)',100,'imapsync','user','Migracion de cuentas de correo IMAP externas hacia el panel (solo administradores).',UNIX_TIMESTAMP(),'true')" 2>/dev/null
    echo "modulo imapsync registrado"
fi
MOID=$($MY -e "SELECT mo_id_pk FROM \`$DB\`.x_modules WHERE mo_folder_vc='imapsync' LIMIT 1" 2>/dev/null)
if [ -n "$MOID" ]; then
    PHAS=$($MY -e "SELECT COUNT(*) FROM \`$DB\`.x_permissions WHERE pe_group_fk=1 AND pe_module_fk=$MOID" 2>/dev/null)
    if [ "$PHAS" = "0" ]; then $MY -e "INSERT INTO \`$DB\`.x_permissions (pe_group_fk,pe_module_fk) VALUES (1,$MOID)" 2>/dev/null; echo "permiso admin concedido a imapsync"; fi
fi
exit 0

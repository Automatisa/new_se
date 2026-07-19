#!/bin/sh
# 0011_x_le_status.sh — Tabla de estado de certificados Let's Encrypt (cacheado por el daemon para
# la vista de administración). Una fila por vhost con SSL; el hook OnDaemonDay la actualiza en cada
# pasada (emitido/caduca/estado/último error). La vista admin lee de aquí (rápido, sin recorrer los
# homes de otros usuarios). Idempotente.

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")
MY="mysql -u$U -p$P -h$H -N"

HAS=$($MY -e "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='x_le_status'" 2>/dev/null)

if [ "$HAS" = "0" ]; then
    $MY -e "CREATE TABLE \`$DB\`.\`x_le_status\` (
              \`ls_id_pk\` int(10) unsigned NOT NULL AUTO_INCREMENT,
              \`ls_vhost_fk\` int(10) NOT NULL,
              \`ls_domain_vc\` varchar(255) NOT NULL,
              \`ls_owner_vc\` varchar(64) DEFAULT NULL,
              \`ls_env_vc\` varchar(16) DEFAULT 'production',
              \`ls_state_vc\` varchar(24) DEFAULT 'unknown',
              \`ls_issued_ts\` int(20) DEFAULT NULL,
              \`ls_expires_ts\` int(20) DEFAULT NULL,
              \`ls_last_error_tx\` text DEFAULT NULL,
              \`ls_updated_ts\` int(20) DEFAULT NULL,
              PRIMARY KEY (\`ls_id_pk\`),
              UNIQUE KEY \`ls_vhost_uq\` (\`ls_vhost_fk\`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8" 2>/dev/null
    echo "x_le_status creada"
else
    echo "x_le_status ya existe"
fi
exit 0

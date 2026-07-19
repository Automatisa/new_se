#!/bin/sh
# 0014_vh_wildcard.sh — Añade x_vhosts.vh_le_wildcard_in: si =1, el daemon emite un certificado
# WILDCARD (*.dominio + dominio) por reto DNS-01 en vez de un cert HTTP-01 por nombre. Un solo
# cert cubre todos los subdominios -> esquiva el límite de LE de 50 certs/dominio registrado/7 días.
# Idempotente.

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")
MY="mysql -u$U -p$P -h$H -N"

HAS=$($MY -e "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='x_vhosts' AND COLUMN_NAME='vh_le_wildcard_in'" 2>/dev/null)
if [ "$HAS" = "0" ]; then
    $MY -e "ALTER TABLE \`$DB\`.\`x_vhosts\` ADD COLUMN \`vh_le_wildcard_in\` tinyint(1) DEFAULT 0 AFTER \`vh_le_reissue_ts\`" 2>/dev/null
    echo "vh_le_wildcard_in añadida a x_vhosts"
else
    echo "vh_le_wildcard_in ya existe"
fi
exit 0

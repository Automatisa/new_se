#!/bin/sh
# 0005_vh_custom_ip6.sh — Añade x_vhosts.vh_custom_ip6_vc (IPv6 dedicada del dominio, doble pila).
# Idempotente (SHOW COLUMNS; su MySQL no soporta ADD COLUMN IF NOT EXISTS).

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")
MY="mysql -u$U -p$P -h$H -N"

HAS=$($MY -e "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='x_vhosts' AND COLUMN_NAME='vh_custom_ip6_vc'" 2>/dev/null)

if [ "$HAS" = "0" ]; then
    $MY -e "ALTER TABLE \`$DB\`.\`x_vhosts\` ADD COLUMN \`vh_custom_ip6_vc\` varchar(45) DEFAULT NULL AFTER \`vh_custom_ip_vc\`" 2>/dev/null
    echo "vh_custom_ip6_vc añadida a x_vhosts"
else
    echo "vh_custom_ip6_vc ya existe"
fi
exit 0

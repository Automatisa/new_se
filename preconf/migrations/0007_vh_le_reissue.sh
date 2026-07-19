#!/bin/sh
# 0007_vh_le_reissue.sh — Añade x_vhosts.vh_le_reissue_ts: marca de "reemisión forzada de cert
# Let's Encrypt solicitada" (UNIX ts). El botón del panel la fija (con guardas de rate-limit) y el
# hook OnDaemonDay de sencrypt la honra (fuerza emisión saltándose la ventana de 30 días) y la
# limpia. Idempotente.

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")
MY="mysql -u$U -p$P -h$H -N"

HAS=$($MY -e "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='x_vhosts' AND COLUMN_NAME='vh_le_reissue_ts'" 2>/dev/null)

if [ "$HAS" = "0" ]; then
    $MY -e "ALTER TABLE \`$DB\`.\`x_vhosts\` ADD COLUMN \`vh_le_reissue_ts\` int(20) DEFAULT NULL AFTER \`vh_ssl_tx\`" 2>/dev/null
    echo "vh_le_reissue_ts añadida a x_vhosts"
else
    echo "vh_le_reissue_ts ya existe"
fi
exit 0

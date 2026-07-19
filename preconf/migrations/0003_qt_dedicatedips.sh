#!/bin/sh
# 0003_qt_dedicatedips.sh — Añade la cuota qt_dedicatedips_in a x_quotas (nº máx de IPs dedicadas
# por paquete; -1=ilimitado, 0=ninguna). Multi-IP Fase 1c. Idempotente (comprueba SHOW COLUMNS;
# su MySQL no soporta ADD COLUMN IF NOT EXISTS). El paquete de administración (id 1) queda a -1.

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")

MY="mysql -u$U -p$P -h$H -N"

HAS=$($MY -e "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='x_quotas' AND COLUMN_NAME='qt_dedicatedips_in'" 2>/dev/null)

if [ "$HAS" = "0" ]; then
    $MY -e "ALTER TABLE \`$DB\`.\`x_quotas\` ADD COLUMN \`qt_dedicatedips_in\` int(11) NOT NULL DEFAULT 0" 2>/dev/null
    # El paquete de administración (id 1) sin límite de IPs.
    $MY -e "UPDATE \`$DB\`.\`x_quotas\` SET qt_dedicatedips_in=-1 WHERE qt_package_fk=1" 2>/dev/null
    echo "qt_dedicatedips_in añadida a x_quotas (admin=-1)"
else
    echo "qt_dedicatedips_in ya existe (nada que hacer)"
fi
exit 0

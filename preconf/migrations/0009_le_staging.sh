#!/bin/sh
# 0009_le_staging.sh — Añade el ajuste le_staging (modo de PRUEBAS de Let's Encrypt).
# 'false' por defecto: emisión contra PRODUCCIÓN (comportamiento de siempre). Con 'true', sencrypt
# emite contra el entorno de STAGING (acme-staging-v02) con cuenta y carpeta separadas
# (letsencrypt-staging/) para validar el flujo SIN gastar los límites de producción ni arriesgar un
# bloqueo de IP; los certs de staging son de una raíz NO confiada y NO se cablean al panel/vhosts.
# Idempotente.

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")
MY="mysql -u$U -p$P -h$H -N"

HAS=$($MY -e "SELECT COUNT(*) FROM \`$DB\`.x_settings WHERE so_name_vc='le_staging'" 2>/dev/null)

if [ "$HAS" = "0" ]; then
    $MY -e "INSERT INTO \`$DB\`.x_settings
            (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
            VALUES ('le_staging','Lets Encrypt Staging','false','true|false',
                    'Emite contra el entorno de pruebas de Lets Encrypt (staging) para validar el flujo sin gastar limites de produccion. Los certificados de staging NO son confiados por los navegadores.',
                    'Sencrypt Config','false')" 2>/dev/null
    echo "le_staging añadido a x_settings"
else
    echo "le_staging ya existe"
fi
exit 0

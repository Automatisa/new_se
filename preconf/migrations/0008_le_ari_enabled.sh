#!/bin/sh
# 0008_le_ari_enabled.sh — Añade el ajuste le_ari_enabled (ACME Renewal Info de Let's Encrypt).
# 'false' por defecto: la lógica ARI (ventana de renovación sugerida + campo `replaces` para
# exención de rate-limit) queda implementada pero INACTIVA hasta validarla con un dominio público.
# Con 'false' el comportamiento es el de siempre (renovación a los 30 días). Idempotente.

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")
MY="mysql -u$U -p$P -h$H -N"

HAS=$($MY -e "SELECT COUNT(*) FROM \`$DB\`.x_settings WHERE so_name_vc='le_ari_enabled'" 2>/dev/null)

if [ "$HAS" = "0" ]; then
    $MY -e "INSERT INTO \`$DB\`.x_settings
            (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
            VALUES ('le_ari_enabled','Lets Encrypt ARI','false','true|false',
                    'Activa ACME Renewal Info (ARI): renovacion segun la ventana sugerida por Lets Encrypt y campo replaces (exencion de rate-limit). Requiere validacion con dominio publico.',
                    'Sencrypt Config','false')" 2>/dev/null
    echo "le_ari_enabled añadido a x_settings"
else
    echo "le_ari_enabled ya existe"
fi
exit 0

#!/bin/sh
# 0006_server_ip6.sh — Añade el ajuste server_ip6 (IPv6 primaria del servidor, doble pila).
# Si se define, el panel escucha además en [IPv6]:puerto (hook Apache). Vacío = panel solo IPv4.
# Idempotente (INSERT solo si no existe).

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")
MY="mysql -u$U -p$P -h$H -N"

HAS=$($MY -e "SELECT COUNT(*) FROM \`$DB\`.x_settings WHERE so_name_vc='server_ip6'" 2>/dev/null)

if [ "$HAS" = "0" ]; then
    $MY -e "INSERT INTO \`$DB\`.x_settings
            (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
            VALUES ('server_ip6','Server IPv6 Address','',NULL,
                    'IPv6 primaria del servidor (doble pila). Si se define, el panel escucha tambien en [IPv6]:puerto. Vacio = panel solo por IPv4.',
                    'Bulwark Config','false')" 2>/dev/null
    echo "server_ip6 añadido a x_settings"
else
    echo "server_ip6 ya existe"
fi
exit 0

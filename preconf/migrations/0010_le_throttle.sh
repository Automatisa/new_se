#!/bin/sh
# 0010_le_throttle.sh — Ajustes para escalar la emisión de certificados Let's Encrypt:
#   le_max_per_run  : máximo de certificados a EMITIR por pasada del daemon (evita ráfagas que
#                     superen el límite de LE de 300 órdenes/cuenta/3h). Default 100.
#   le_backoff_until: marca (UNIX ts) hasta la que se pausan las emisiones tras detectar un
#                     rate-limit de LE (se respeta la recarga de la cuota). Default 0 (sin pausa).
# Idempotente.

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")
MY="mysql -u$U -p$P -h$H -N"

add_setting() {
    NAME="$1"; VAL="$2"; CLEAN="$3"; DESC="$4"
    HAS=$($MY -e "SELECT COUNT(*) FROM \`$DB\`.x_settings WHERE so_name_vc='$NAME'" 2>/dev/null)
    if [ "$HAS" = "0" ]; then
        $MY -e "INSERT INTO \`$DB\`.x_settings
                (so_name_vc, so_cleanname_vc, so_value_tx, so_defvalues_tx, so_desc_tx, so_module_vc, so_usereditable_en)
                VALUES ('$NAME','$CLEAN','$VAL',NULL,'$DESC','Sencrypt Config','false')" 2>/dev/null
        echo "$NAME añadido a x_settings"
    else
        echo "$NAME ya existe"
    fi
}

add_setting "le_max_per_run" "100" "LE max certs por pasada" "Maximo de certificados a emitir por pasada del daemon (evita superar 300 ordenes/cuenta/3h de Lets Encrypt)."
add_setting "le_backoff_until" "0" "LE backoff hasta (ts)" "Marca temporal hasta la que se pausan las emisiones tras un rate-limit de Lets Encrypt."
exit 0

#!/bin/sh
# 0016_imapsync_user.sh — Convierte el módulo imapsync en módulo de USUARIO: lo mueve a la categoría
# de correo (6) y concede permiso a usuarios (grupo 3) y resellers (grupo 2), además del admin (1) que
# ya lo tenía. La migración de correo es de cada usuario (con scope por cuenta); solo los AJUSTES son
# de admin (se ocultan en la plantilla con <% if Admin %>). Idempotente.

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")
MY="mysql -u$U -p$P -h$H -N"

# categoría 6 = Correo (donde están mailboxes/webmail)
$MY -e "UPDATE \`$DB\`.x_modules SET mo_category_fk=6 WHERE mo_folder_vc='imapsync'" 2>/dev/null
echo "imapsync movido a la categoria de correo"

MOID=$($MY -e "SELECT mo_id_pk FROM \`$DB\`.x_modules WHERE mo_folder_vc='imapsync' LIMIT 1" 2>/dev/null)
if [ -n "$MOID" ]; then
    for G in 2 3; do
        PHAS=$($MY -e "SELECT COUNT(*) FROM \`$DB\`.x_permissions WHERE pe_group_fk=$G AND pe_module_fk=$MOID" 2>/dev/null)
        if [ "$PHAS" = "0" ]; then
            $MY -e "INSERT INTO \`$DB\`.x_permissions (pe_group_fk,pe_module_fk) VALUES ($G,$MOID)" 2>/dev/null
            echo "permiso concedido a grupo $G para imapsync"
        fi
    done
fi
exit 0

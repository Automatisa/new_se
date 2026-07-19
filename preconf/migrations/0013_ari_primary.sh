#!/bin/sh
# 0013_ari_primary.sh — ARI como lógica PRIMARIA de renovación (estilo Shopify):
#   - Añade a x_le_status la ventana ARI cacheada (ls_ari_start_ts, ls_ari_end_ts, ls_renew_at_ts)
#     para visibilidad en la vista de administración.
#   - ACTIVA le_ari_enabled='true': con ARI, la renovación sigue la ventana sugerida por Let's Encrypt
#     -> se adapta sola al acortamiento de los certificados (90 -> 64 -> 45 días) y responde a
#     revocaciones, y las renovaciones quedan EXENTAS de rate-limits (campo `replaces`). Si ARI falla,
#     el hook cae al fallback estático de 30 días (seguro).
# Idempotente.

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")
MY="mysql -u$U -p$P -h$H -N"

add_col() {
    COL="$1"; DEF="$2"
    HAS=$($MY -e "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='x_le_status' AND COLUMN_NAME='$COL'" 2>/dev/null)
    if [ "$HAS" = "0" ]; then
        $MY -e "ALTER TABLE \`$DB\`.\`x_le_status\` ADD COLUMN \`$COL\` $DEF" 2>/dev/null
        echo "$COL añadida a x_le_status"
    else
        echo "$COL ya existe"
    fi
}

# La tabla x_le_status la crea la migración 0011; si aún no existe, saltar (0011 correrá antes).
THAS=$($MY -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='x_le_status'" 2>/dev/null)
if [ "$THAS" = "1" ]; then
    add_col "ls_ari_start_ts" "int(20) DEFAULT NULL"
    add_col "ls_ari_end_ts"   "int(20) DEFAULT NULL"
    add_col "ls_renew_at_ts"  "int(20) DEFAULT NULL"
fi

# Activar ARI como lógica primaria de renovación.
$MY -e "UPDATE \`$DB\`.x_settings SET so_value_tx='true' WHERE so_name_vc='le_ari_enabled'" 2>/dev/null
echo "le_ari_enabled activado (ARI primario)"
exit 0

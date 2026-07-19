#!/bin/sh
# 0012_le_admin_module.sh — Registra el módulo de administración 'le_admin' (Estado Let's Encrypt).
# Categoría 2 (Server Admin, como autoip). Permiso SOLO al grupo admin (1); NO se concede a
# usuarios (3) ni resellers (2), así que es admin-only por acceso al módulo (no solo por un guard).
# Idempotente.

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")
MY="mysql -u$U -p$P -h$H -N"

HAS=$($MY -e "SELECT COUNT(*) FROM \`$DB\`.x_modules WHERE mo_folder_vc='le_admin'" 2>/dev/null)
if [ "$HAS" = "0" ]; then
    $MY -e "INSERT INTO \`$DB\`.x_modules
            (mo_category_fk, mo_name_vc, mo_version_in, mo_folder_vc, mo_type_en, mo_desc_tx, mo_installed_ts, mo_enabled_en)
            VALUES (2, 'Let''s Encrypt Status', 100, 'le_admin', 'user',
                    'Estado de emisiones y certificados Let''s Encrypt de todo el servidor (solo administradores).',
                    UNIX_TIMESTAMP(), 'true')" 2>/dev/null
    echo "modulo le_admin registrado en x_modules"
else
    echo "modulo le_admin ya existe en x_modules"
fi

MOID=$($MY -e "SELECT mo_id_pk FROM \`$DB\`.x_modules WHERE mo_folder_vc='le_admin' LIMIT 1" 2>/dev/null)
if [ -n "$MOID" ]; then
    PHAS=$($MY -e "SELECT COUNT(*) FROM \`$DB\`.x_permissions WHERE pe_group_fk=1 AND pe_module_fk=$MOID" 2>/dev/null)
    if [ "$PHAS" = "0" ]; then
        $MY -e "INSERT INTO \`$DB\`.x_permissions (pe_group_fk, pe_module_fk) VALUES (1, $MOID)" 2>/dev/null
        echo "permiso de admin (grupo 1) concedido al modulo le_admin (id $MOID)"
    else
        echo "permiso de admin ya existe para le_admin"
    fi
fi
exit 0

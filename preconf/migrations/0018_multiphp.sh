#!/bin/sh
# 0018_multiphp.sh — Multi-PHP (Vía B): versión de PHP por dominio.
# Añade la columna dp_php_version_vc a x_domain_php. Valor '' = versión del sistema (por defecto);
# 'NN' (p.ej. '81','84') = usar el FPM de /usr/local/phpNN/ (compilado con PREFIX propio).
# El panel enruta el pool del dominio al directorio de pools de esa versión; el socket NO cambia,
# así Apache no se toca. Idempotente.

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")
MY="mysql -u$U -p$P -h$H -N"

add_col() {
    COL="$1"; DEF="$2"
    HAS=$($MY -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='x_domain_php' AND COLUMN_NAME='$COL'" 2>/dev/null)
    if [ "$HAS" = "0" ]; then
        $MY -e "ALTER TABLE \`$DB\`.\`x_domain_php\` ADD COLUMN \`$COL\` $DEF" 2>/dev/null
        echo "$COL añadida"
    fi
}
add_col "dp_php_version_vc" "varchar(4) NOT NULL DEFAULT ''"
exit 0

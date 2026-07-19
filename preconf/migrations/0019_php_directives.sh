#!/bin/sh
# 0019_php_directives.sh — Más directivas PHP por dominio (fijas, sin editor libre):
# date.timezone, max_input_vars y opcache.enable. Se aplican como php_admin_value en el pool FPM
# de la versión elegida por el dominio. Idempotente.

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
# timezone vacío = no forzar (usa la del php.ini de la versión). opcache activado por defecto.
add_col "dp_timezone_vc"       "varchar(64) NOT NULL DEFAULT ''"
add_col "dp_max_input_vars_in" "int(11) NOT NULL DEFAULT 1000"
add_col "dp_opcache_in"        "tinyint(1) NOT NULL DEFAULT 1"
exit 0

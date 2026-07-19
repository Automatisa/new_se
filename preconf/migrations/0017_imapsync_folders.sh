#!/bin/sh
# 0017_imapsync_folders.sh — Elección de carpetas al migrar: incluir o no Spam/Junk y Papelera/Trash.
# imapsync preserva fecha (syncinternaldates) y estado leído/flags por defecto; aquí solo se decide qué
# carpetas se copian. Columnas nuevas en x_imapsync_jobs (por defecto NO incluir spam/papelera).
# Idempotente.

DBPHP=/usr/local/bulwark/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")
DB=$(php -r "include \"$DBPHP\"; echo \$dbname;")
MY="mysql -u$U -p$P -h$H -N"

add_col() {
    COL="$1"; DEF="$2"
    HAS=$($MY -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='x_imapsync_jobs' AND COLUMN_NAME='$COL'" 2>/dev/null)
    if [ "$HAS" = "0" ]; then
        $MY -e "ALTER TABLE \`$DB\`.\`x_imapsync_jobs\` ADD COLUMN \`$COL\` $DEF" 2>/dev/null
        echo "$COL añadida"
    fi
}
add_col "ij_inc_spam_in"  "tinyint(1) DEFAULT 0"
add_col "ij_inc_trash_in" "tinyint(1) DEFAULT 0"
exit 0

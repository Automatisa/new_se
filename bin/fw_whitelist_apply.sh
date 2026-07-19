#!/bin/sh
# fw_whitelist_apply.sh — Reconstruye la tabla pf 'bulwark_whitelist' desde la BD.
# Invocado ÚNICAMENTE por privilege::run('fw_whitelist_apply'). Sin argumentos.
#
# La tabla pf bulwark_whitelist tiene precedencia sobre bloqueos manuales y sobre
# la tabla sshguard (pf.conf: pass quick from <bulwark_whitelist> antes que block).

TABLE="bulwark_whitelist"
DESTFILE="/var/bulwark/run/pf_whitelist.txt"
TMPFILE="/tmp/bulwark_pf_whitelist.$$"
CNF="/usr/local/bulwark/cnf/db.php"

VALID_RE='^([0-9]{1,3}\.){3}[0-9]{1,3}(/([0-9]|[12][0-9]|3[02]))?$|^([0-9a-fA-F]{0,4}:){2,7}[0-9a-fA-F]{0,4}(/([0-9]{1,2}|1[01][0-9]|12[0-8]))?$'

if [ ! -f "$CNF" ]; then
    echo "ERROR: $CNF no encontrado." >&2
    exit 1
fi

DB_HOST=$(grep "host"   "$CNF" | sed "s/.*= '//;s/'.*//")
DB_NAME=$(grep "dbname" "$CNF" | sed "s/.*= '//;s/'.*//")
DB_USER=$(grep "user"   "$CNF" | sed "s/.*= '//;s/'.*//")
DB_PASS=$(grep "pass"   "$CNF" | sed "s/.*= '//;s/'.*//")

mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" "$DB_NAME" \
    --batch --skip-column-names \
    -e "SELECT fw_ip_vc FROM x_fw_whitelist WHERE fw_deleted_ts IS NULL;" \
    2>/dev/null \
  | grep -E "$VALID_RE" \
  > "$TMPFILE"

mkdir -p /var/bulwark/run
mv "$TMPFILE" "$DESTFILE"
chmod 644 "$DESTFILE"

if pfctl -si 2>/dev/null | grep -q "^Status: Enabled"; then
    pfctl -t "$TABLE" -T replace -f "$DESTFILE" 2>/dev/null || true
fi

exit 0

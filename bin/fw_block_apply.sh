#!/bin/sh
# fw_block_apply.sh — Reconstruye la tabla pf 'bulwark_blocked' desde la BD.
# Invocado ÚNICAMENTE por privilege::run('fw_block_apply'). Sin argumentos.
#
# Flujo de datos seguro:
#   1. Lee credenciales de BD de /usr/local/bulwark/cnf/db.php
#   2. Consulta x_fw_blocked (IPs activas, sin borrar)
#   3. Valida cada línea con regex (IPv4, IPv6, CIDR) — rechaza cualquier dato inválido
#   4. Escribe a fichero temporal, mueve atómicamente a destino
#   5. Reemplaza la tabla pf en caliente (sin recargar pf.conf completo)

TABLE="bulwark_blocked"
DESTFILE="/var/bulwark/run/pf_blocked.txt"
CNF="/usr/local/bulwark/cnf/db.php"
mkdir -p /var/bulwark/run
TMPFILE=$(mktemp /var/bulwark/run/.pf_blocked.XXXXXX) || exit 1

# Regex: IPv4 opcional CIDR /0-32, IPv6 (simplificada) opcional CIDR /0-128
VALID_RE='^([0-9]{1,3}\.){3}[0-9]{1,3}(/([0-9]|[12][0-9]|3[02]))?$|^([0-9a-fA-F]{0,4}:){2,7}[0-9a-fA-F]{0,4}(/([0-9]{1,2}|1[01][0-9]|12[0-8]))?$'

if [ ! -f "$CNF" ]; then
    echo "ERROR: $CNF no encontrado." >&2
    exit 1
fi

DB_HOST=$(grep "host"   "$CNF" | sed "s/.*= '//;s/'.*//")
DB_NAME=$(grep "dbname" "$CNF" | sed "s/.*= '//;s/'.*//")
DB_USER=$(grep "user"   "$CNF" | sed "s/.*= '//;s/'.*//")
DB_PASS=$(grep "pass"   "$CNF" | sed "s/.*= '//;s/'.*//")

# Credenciales por fichero temporal 0600 (--defaults-extra-file): NO en la línea de
# comandos, donde la contraseña sería visible por `ps` para cualquier usuario local.
DEFAULTS=$(mktemp /var/bulwark/run/.pf_db.XXXXXX) || { rm -f "$TMPFILE"; exit 1; }
chmod 600 "$DEFAULTS"
printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "$DB_USER" "$DB_PASS" > "$DEFAULTS"

# Obtener IPs activas y validar cada línea con regex
mysql --defaults-extra-file="$DEFAULTS" "$DB_NAME" \
    --batch --skip-column-names \
    -e "SELECT fb_ip_vc FROM x_fw_blocked WHERE fb_active_in=1 AND fb_deleted_ts IS NULL;" \
    2>/dev/null \
  | grep -E "$VALID_RE" \
  > "$TMPFILE"
rm -f "$DEFAULTS"

mv "$TMPFILE" "$DESTFILE"
chmod 644 "$DESTFILE"

# Aplicar atómicamente a la tabla pf (sin reload completo de pf.conf)
if pfctl -si 2>/dev/null | grep -q "^Status: Enabled"; then
    pfctl -t "$TABLE" -T replace -f "$DESTFILE" 2>/dev/null || true
fi

exit 0

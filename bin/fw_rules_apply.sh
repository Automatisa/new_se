#!/bin/sh
# fw_rules_apply.sh — Aplica reglas personalizadas de pf desde x_fw_rules.
#
# Lee la tabla x_fw_rules (fr_enabled_in=1), genera reglas pf y las carga
# en el anchor "bulwark_rules". Sin argumentos. Sin ejecución directa del usuario.
#
# Requisito en /etc/pf.conf:
#   block in all          ← política por defecto: bloquear entrada
#   pass out all keep state ← permitir salida y respuestas
#   anchor "bulwark_rules"  ← donde se cargan estas reglas (antes de block in all si necesario)

set -e

RULES_FILE="/var/bulwark/run/pf_custom_rules.txt"
TMPFILE="/tmp/bulwark_fw_rules.$$"
DATA_FILE="/tmp/bulwark_fw_rules_data.$$"
CNF="/usr/local/bulwark/cnf/db.php"

[ -f "$CNF" ] || exit 1

DB_HOST=$(grep "host"   "$CNF" | sed "s/.*= '//;s/'.*//")
DB_NAME=$(grep "dbname" "$CNF" | sed "s/.*= '//;s/'.*//")
DB_USER=$(grep "user"   "$CNF" | sed "s/.*= '//;s/'.*//")
DB_PASS=$(grep "pass"   "$CNF" | sed "s/.*= '//;s/'.*//")

mysql_q() {
    mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" "$DB_NAME" \
        --batch --skip-column-names -e "$1" 2>/dev/null
}

# Verificar que la tabla existe
mysql_q "SELECT 1 FROM x_fw_rules LIMIT 1;" > /dev/null 2>&1 || exit 0

# Detectar si existe la columna fr_port_max_in (v102+)
HAS_MAX=$(mysql_q "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='x_fw_rules'
    AND COLUMN_NAME='fr_port_max_in';" 2>/dev/null || echo 0)

if [ "$HAS_MAX" -gt 0 ] 2>/dev/null; then
    QUERY="SELECT fr_action_en, fr_direction_en, fr_proto_vc, fr_src_vc,
                  fr_port_in, fr_port_max_in
           FROM x_fw_rules
           WHERE fr_enabled_in=1
           ORDER BY fr_order_in ASC, fr_id_pk ASC;"
else
    QUERY="SELECT fr_action_en, fr_direction_en, fr_proto_vc, fr_src_vc,
                  fr_port_in, 0
           FROM x_fw_rules
           WHERE fr_enabled_in=1
           ORDER BY fr_order_in ASC, fr_id_pk ASC;"
fi

mysql_q "$QUERY" > "$DATA_FILE" 2>/dev/null

# Generar reglas pf
> "$TMPFILE"
while IFS=$(printf '\t') read action dir proto src port portmax; do
    # Sanitizar: solo valores permitidos
    action=$(printf '%s' "$action"  | grep -xE 'block|pass'          || printf 'block')
    dir=$(printf '%s' "$dir"        | grep -xE 'in|out|any'          || printf 'in')
    proto=$(printf '%s' "$proto"    | grep -xE 'tcp|udp|icmp|any'    || printf 'tcp')
    port=$(printf '%s' "$port"      | grep -xE '[0-9]{1,5}'          || printf '0')
    portmax=$(printf '%s' "$portmax"| grep -xE '[0-9]{1,5}'          || printf '0')

    # Validar src: IPv4, IPv6, CIDR o "any"
    VALID_IP='^([0-9]{1,3}\.){3}[0-9]{1,3}(/[0-9]{1,2})?$|^([0-9a-fA-F]{0,4}:){2,7}[0-9a-fA-F]{0,4}(/[0-9]{1,3})?$'
    if ! printf '%s' "$src" | grep -qxE "$VALID_IP"; then
        src="any"
    fi

    # Construir cabecera de regla
    if [ "$action" = "block" ]; then
        line="block drop"
    else
        line="pass"
    fi

    [ "$dir" != "any" ] && line="$line $dir"

    # Todas las reglas del anchor usan quick (primera coincidencia gana)
    line="$line quick"

    # Protocolo
    [ "$proto" != "any" ] && line="$line proto $proto"

    # Origen
    if [ "$src" = "any" ]; then
        line="$line from any"
    else
        line="$line from $src"
    fi

    # Puerto o rango de puertos (ICMP no lleva puerto)
    if [ "$proto" = "icmp" ]; then
        line="$line to any"
    elif [ "$port" -gt 0 ] 2>/dev/null; then
        if [ "$portmax" -gt "$port" ] 2>/dev/null; then
            # Rango: pf usa "port X:Y"
            line="$line to any port ${port}:${portmax}"
        else
            line="$line to any port $port"
        fi
    else
        line="$line to any"
    fi

    # keep state para reglas pass (mejora rendimiento y permite conexiones establecidas)
    [ "$action" = "pass" ] && line="$line keep state"

    printf '%s\n' "$line" >> "$TMPFILE"
done < "$DATA_FILE"

rm -f "$DATA_FILE"

# Aplicar al anchor si pf está activo
if pfctl -si 2>/dev/null | grep -q "^Status: Enabled"; then
    pfctl -a bulwark_rules -f "$TMPFILE" 2>/dev/null || true
fi

# Guardar copia legible para el visor del panel
cp "$TMPFILE" "$RULES_FILE" 2>/dev/null || true
rm -f "$TMPFILE"

chown root:www "$RULES_FILE" 2>/dev/null || true
chmod 640 "$RULES_FILE" 2>/dev/null || true

exit 0

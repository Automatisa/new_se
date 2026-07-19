#!/bin/sh
# fw_status_dump.sh — Vuelca el estado de pf y SSHGuard a JSON legible por www.
# Invocado ÚNICAMENTE por privilege::run('fw_status_dump'). Sin argumentos.
#
# Salida: /var/bulwark/logs/fw_status.json (propietario www:www, modo 640)
# El JSON es leído por el controller PHP y por OnDaemonRun.hook.php.

OUTPUT="/var/bulwark/logs/fw_status.json"
TMPFILE="/tmp/bulwark_fw_status.$$"

mkdir -p /var/bulwark/logs

# ---- Estado de pf ----
PF_ON=false
pfctl -si 2>/dev/null | grep -q "^Status: Enabled" && PF_ON=true

# ---- Estado de SSHGuard ----
SG_ON=false
service sshguard status 2>/dev/null | grep -q "is running" && SG_ON=true

# ---- Serializar lista de IPs a array JSON ----
# Salida de pfctl -T show: una IP/CIDR por línea con espacios iniciales
ips_to_json() {
    local table="$1"
    pfctl -t "$table" -T show 2>/dev/null \
      | tr -d ' \t' \
      | grep -E '^[0-9a-fA-F:.]' \
      | awk 'BEGIN{f=1}{if(!f)printf ","; printf "\"%s\"",$0; f=0}'
}

BLOCKED_JSON=$(ips_to_json bulwark_blocked)
SSHGUARD_JSON=$(ips_to_json sshguard)

# Contadores — wc -l no falla con exit 1 aunque cuente 0 líneas
BLOCKED_N=$(pfctl -t bulwark_blocked -T show 2>/dev/null \
    | grep -E '^[[:space:]]*[0-9a-fA-F]' | wc -l | tr -d ' \t')
SG_N=$(pfctl -t sshguard -T show 2>/dev/null \
    | grep -E '^[[:space:]]*[0-9a-fA-F]' | wc -l | tr -d ' \t')
BLOCKED_N=${BLOCKED_N:-0}
SG_N=${SG_N:-0}

TS=$(date +%s)

# ---- Reglas pf activas (visor en panel) ----
pf_rules_to_json() {
    pfctl -sr 2>/dev/null | head -80 \
      | awk 'BEGIN{f=1}{
          gsub(/\\/, "\\\\"); gsub(/"/, "\\\"");
          if(!f) printf ","; printf "\"%s\"",$0; f=0
        }'
}
PF_RULES_JSON=$(pf_rules_to_json)

# ---- Reglas del anchor bulwark_rules (personalizadas) ----
pf_anchor_to_json() {
    pfctl -a bulwark_rules -sr 2>/dev/null | head -50 \
      | awk 'BEGIN{f=1}{
          gsub(/\\/, "\\\\"); gsub(/"/, "\\\"");
          if(!f) printf ","; printf "\"%s\"",$0; f=0
        }'
}
PF_ANCHOR_JSON=$(pf_anchor_to_json)

cat > "$TMPFILE" <<ENDJSON
{
  "generated_ts": $TS,
  "pf_enabled": $PF_ON,
  "sshguard_enabled": $SG_ON,
  "manual_blocked_count": $BLOCKED_N,
  "sshguard_blocked_count": $SG_N,
  "manual_blocked": [$BLOCKED_JSON],
  "sshguard_blocked": [$SSHGUARD_JSON],
  "pf_rules": [$PF_RULES_JSON],
  "pf_anchor_rules": [$PF_ANCHOR_JSON]
}
ENDJSON

mv "$TMPFILE" "$OUTPUT"

# Intentar asignar propietario www (FreeBSD) o apache (Linux)
chown www:www "$OUTPUT" 2>/dev/null \
  || chown apache:apache "$OUTPUT" 2>/dev/null \
  || true
chmod 640 "$OUTPUT"

exit 0

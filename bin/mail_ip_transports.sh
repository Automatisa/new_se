#!/bin/sh
# mail_ip_transports.sh — Regenera los transportes de Postfix por IP dedicada (envío saliente con
# smtp_bind_address), multi-IP Fase 3b. Reescribe SOLO el bloque gestionado entre marcadores en
# master.cf y hace 'postfix check' ANTES de recargar: si la config queda inválida, RESTAURA el
# backup y NO recarga (nunca deja el correo roto). Idempotente, sin argumentos.
#
# Lo invoca el panel (módulo domains) al asignar/quitar una IP dedicada a un dominio, vía doas.

set -u
MASTER=/usr/local/etc/postfix/master.cf
BEGIN="# BEGIN sentora-mailip"
END="# END sentora-mailip"
DBPHP=/usr/local/sentora/cnf/db.php

[ -f "$MASTER" ] || { echo "mail_ip_transports: no existe $MASTER" >&2; exit 1; }

U=$(php -r "include \"$DBPHP\"; echo \$user;" 2>/dev/null)
P=$(php -r "include \"$DBPHP\"; echo \$pass;" 2>/dev/null)
H=$(php -r "include \"$DBPHP\"; echo \$host;" 2>/dev/null)

# IPs dedicadas en uso por dominios activos
IPS=$(mysql -u"$U" -p"$P" -h"$H" -N -e \
    "SELECT DISTINCT vh_custom_ip_vc FROM sentora_core.x_vhosts
     WHERE vh_custom_ip_vc IS NOT NULL AND vh_custom_ip_vc<>'' AND vh_deleted_ts IS NULL" 2>/dev/null)

# Construir el bloque de transportes
BLOCK="$BEGIN
# Generado por mail_ip_transports.sh — NO editar a mano."
N=0
for ip in $IPS; do
    echo "$ip" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || continue   # solo IPv4 bien formada
    name="smtpip-$(echo "$ip" | tr '.' '-')"
    BLOCK="$BLOCK
$name unix - - n - - smtp
  -o smtp_bind_address=$ip
  -o syslog_name=postfix-smtpip"
    N=$((N+1))
done
BLOCK="$BLOCK
$END"

# Backup + quitar el bloque anterior + añadir el nuevo al final
cp -p "$MASTER" "$MASTER.sentorabak"
awk -v b="$BEGIN" -v e="$END" '
  $0==b {skip=1}
  skip && $0==e {skip=0; next}
  !skip {print}
' "$MASTER.sentorabak" > "$MASTER.tmp"
printf '%s\n' "$BLOCK" >> "$MASTER.tmp"
mv "$MASTER.tmp" "$MASTER"

# Validar antes de recargar; si falla, restaurar
if postfix check >/dev/null 2>&1; then
    postfix reload >/dev/null 2>&1
    echo "mail_ip_transports: OK ($N transporte(s) de IP dedicada)"
    exit 0
else
    cp -p "$MASTER.sentorabak" "$MASTER"
    postfix check >/dev/null 2>&1
    echo "mail_ip_transports: 'postfix check' FALLÓ — master.cf restaurado, sin recargar" >&2
    exit 1
fi

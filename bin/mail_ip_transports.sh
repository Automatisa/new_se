#!/bin/sh
# mail_ip_transports.sh — Regenera los transportes de Postfix por DOMINIO con IP dedicada (envío
# saliente con smtp_bind_address / smtp_bind_address6), multi-IP doble pila. Un transporte por
# dominio (smtpout-<vhostid>) que ata su IPv4 y/o su IPv6. Reescribe SOLO el bloque gestionado
# entre marcadores en master.cf y hace 'postfix check' ANTES de recargar: si la config queda
# inválida, RESTAURA el backup y NO recarga (nunca deja el correo roto). Idempotente, sin args.

set -u
MASTER=/usr/local/etc/postfix/master.cf
BEGIN="# BEGIN bulwark-mailip"
END="# END bulwark-mailip"
DBPHP=/usr/local/bulwark/cnf/db.php

[ -f "$MASTER" ] || { echo "mail_ip_transports: no existe $MASTER" >&2; exit 1; }

U=$(php -r "include \"$DBPHP\"; echo \$user;" 2>/dev/null)
P=$(php -r "include \"$DBPHP\"; echo \$pass;" 2>/dev/null)
H=$(php -r "include \"$DBPHP\"; echo \$host;" 2>/dev/null)

# Dominios con IPv4 y/o IPv6 dedicada: "vhostid<TAB>v4<TAB>v6"
ROWS=$(mysql -u"$U" -p"$P" -h"$H" -N -e \
    "SELECT vh_id_pk, IFNULL(vh_custom_ip_vc,''), IFNULL(vh_custom_ip6_vc,'')
     FROM bulwark_core.x_vhosts
     WHERE (vh_custom_ip_vc IS NOT NULL AND vh_custom_ip_vc<>'')
        OR (vh_custom_ip6_vc IS NOT NULL AND vh_custom_ip6_vc<>'')
       AND vh_deleted_ts IS NULL" 2>/dev/null)

BLOCK="$BEGIN
# Generado por mail_ip_transports.sh — NO editar a mano."
N=0
# leer fila a fila (los campos van separados por TAB)
OLDIFS=$IFS; IFS=$(printf '\n')
for row in $ROWS; do
    id=$(printf '%s' "$row" | awk -F'\t' '{print $1}')
    v4=$(printf '%s' "$row" | awk -F'\t' '{print $2}')
    v6=$(printf '%s' "$row" | awk -F'\t' '{print $3}')
    [ -n "$id" ] || continue
    name="smtpout-$id"
    ENTRY="$name unix - - n - - smtp"
    if [ -n "$v4" ] && printf '%s' "$v4" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$'; then
        ENTRY="$ENTRY
  -o smtp_bind_address=$v4"
    fi
    if [ -n "$v6" ] && printf '%s' "$v6" | grep -q ':'; then
        ENTRY="$ENTRY
  -o smtp_bind_address6=$v6"
    fi
    ENTRY="$ENTRY
  -o syslog_name=postfix-smtpout"
    BLOCK="$BLOCK
$ENTRY"
    N=$((N+1))
done
IFS=$OLDIFS
BLOCK="$BLOCK
$END"

cp -p "$MASTER" "$MASTER.bulwarkbak"
awk -v b="$BEGIN" -v e="$END" '
  $0==b {skip=1}
  skip && $0==e {skip=0; next}
  !skip {print}
' "$MASTER.bulwarkbak" > "$MASTER.tmp"
printf '%s\n' "$BLOCK" >> "$MASTER.tmp"
mv "$MASTER.tmp" "$MASTER"

if postfix check >/dev/null 2>&1; then
    postfix reload >/dev/null 2>&1
    echo "mail_ip_transports: OK ($N transporte(s) de dominio con IP dedicada)"
    exit 0
else
    cp -p "$MASTER.bulwarkbak" "$MASTER"
    echo "mail_ip_transports: 'postfix check' FALLÓ — master.cf restaurado, sin recargar" >&2
    exit 1
fi

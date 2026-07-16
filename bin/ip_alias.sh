#!/bin/sh
# ip_alias.sh — Gestiona alias de IP en la interfaz principal (multi-IP, Fase 1).
#
# Uso:  ip_alias.sh add <ipv4> | del <ipv4> | list
#
# - La interfaz se AUTODETECTA (ruta por defecto): el panel solo pasa la IP (menos superficie).
# - Aplica el cambio EN VIVO (ifconfig) y persiste en rc.conf con ifconfig_<if>_aliasN CONTIGUOS
#   (FreeBSD deja de leer al primer hueco), reflejando siempre el estado real de la interfaz.
# - Alias en /32 (255.255.255.255): práctica estándar para IPs secundarias en la misma subred.
# - NUNCA toca la IP PRIMARIA (la primera inet de la interfaz, gestionada por DHCP/ifconfig_<if>).
# - Valida el formato IPv4 (el panel además valida en PHP antes de invocar). IPv6: pendiente Fase 1+.

set -u

usage() { echo "uso: $0 add <ipv4> | del <ipv4> | list" >&2; exit 1; }

ACTION="${1:-}"; IP="${2:-}"

valid_ipv4() {
    printf '%s' "$1" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || return 1
    for o in $(printf '%s' "$1" | tr '.' ' '); do [ "$o" -le 255 ] 2>/dev/null || return 1; done
    return 0
}

IFACE=$(route -n get default 2>/dev/null | awk '/interface:/{print $2; exit}')
[ -n "$IFACE" ] || IFACE=$(netstat -rn 2>/dev/null | awk '/^default/{print $NF; exit}')
[ -n "$IFACE" ] || { echo "ip_alias: no se pudo detectar la interfaz por defecto" >&2; exit 2; }

# IP primaria = primera inet de la interfaz (no se toca nunca)
PRIMARY=$(ifconfig "$IFACE" inet 2>/dev/null | awk '/inet /{print $2; exit}')

# Reescribe los ifconfig_<IFACE>_aliasN de rc.conf desde el estado VIVO de la interfaz (contiguo).
rc_sync() {
    ALIASES=$(ifconfig "$IFACE" inet 2>/dev/null | awk '/inet /{print $2}' | tail -n +2)
    i=0
    while [ $i -lt 64 ]; do sysrc -x "ifconfig_${IFACE}_alias${i}" >/dev/null 2>&1; i=$((i+1)); done
    i=0
    for a in $ALIASES; do
        sysrc "ifconfig_${IFACE}_alias${i}=inet ${a} netmask 255.255.255.255" >/dev/null 2>&1
        i=$((i+1))
    done
}

case "$ACTION" in
  list)
      echo "interfaz: $IFACE"
      echo "primaria: $PRIMARY"
      echo "alias:"
      ifconfig "$IFACE" inet 2>/dev/null | awk '/inet /{print $2}' | tail -n +2 | sed 's/^/  /'
      ;;

  add)
      valid_ipv4 "$IP" || { echo "ip_alias: IPv4 inválida: '$IP'" >&2; exit 3; }
      [ "$IP" = "$PRIMARY" ] && { echo "ip_alias: '$IP' es la IP primaria (no es un alias)" >&2; exit 4; }
      if ifconfig "$IFACE" inet 2>/dev/null | awk '/inet /{print $2}' | grep -qx "$IP"; then
          echo "ip_alias: $IP ya está en $IFACE"
      else
          ifconfig "$IFACE" alias "$IP/32" || { echo "ip_alias: fallo al añadir alias $IP" >&2; exit 5; }
          echo "ip_alias: alias $IP añadido en $IFACE"
      fi
      rc_sync
      ;;

  del)
      valid_ipv4 "$IP" || { echo "ip_alias: IPv4 inválida: '$IP'" >&2; exit 3; }
      [ "$IP" = "$PRIMARY" ] && { echo "ip_alias: NO se elimina la IP primaria '$IP'" >&2; exit 4; }
      if ifconfig "$IFACE" inet 2>/dev/null | awk '/inet /{print $2}' | grep -qx "$IP"; then
          ifconfig "$IFACE" -alias "$IP" || { echo "ip_alias: fallo al quitar alias $IP" >&2; exit 5; }
          echo "ip_alias: alias $IP eliminado de $IFACE"
      else
          echo "ip_alias: $IP no estaba en $IFACE"
      fi
      rc_sync
      ;;

  *) usage ;;
esac
exit 0

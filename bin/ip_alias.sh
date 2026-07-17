#!/bin/sh
# ip_alias.sh — Gestiona alias de IP (IPv4 e IPv6) en la interfaz principal (multi-IP).
#
# Uso:  ip_alias.sh add <ip> | del <ip> | list
#
# - Autodetecta la FAMILIA por la dirección (contiene ':' -> IPv6).
# - Autodetecta la interfaz (ruta por defecto): el panel solo pasa la IP.
# - Aplica el cambio EN VIVO (ifconfig) y persiste en rc.conf con ifconfig_<if>_aliasN CONTIGUOS
#   (mezcla inet/inet6), reflejando siempre el estado real de la interfaz.
# - IPv4: alias /32 (255.255.255.255). IPv6: alias /64 (prefixlen 64).
# - NUNCA toca la IP PRIMARIA de cada familia (la primera inet / la primera inet6 no link-local).
# - link-local IPv6 (fe80::) se ignora siempre.

set -u

usage() { echo "uso: $0 add <ip> | del <ip> | list" >&2; exit 1; }
ACTION="${1:-}"; IP="${2:-}"

is_v6() { case "$1" in *:*) return 0 ;; *) return 1 ;; esac; }

valid_ip() {
    if is_v6 "$1"; then
        case "$1" in *[!0-9a-fA-F:]*) return 1 ;; esac; [ -n "$1" ]
    else
        printf '%s' "$1" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || return 1
        for o in $(printf '%s' "$1" | tr '.' ' '); do [ "$o" -le 255 ] 2>/dev/null || return 1; done
    fi
}

IFACE=$(route -n get default 2>/dev/null | awk '/interface:/{print $2; exit}')
[ -n "$IFACE" ] || IFACE=$(route -n get -inet6 default 2>/dev/null | awk '/interface:/{print $2; exit}')
[ -n "$IFACE" ] || IFACE=$(netstat -rn 2>/dev/null | awk '/^default/{print $NF; exit}')
[ -n "$IFACE" ] || { echo "ip_alias: no se pudo detectar la interfaz por defecto" >&2; exit 2; }

# IPs primarias (no se tocan): primera inet, primera inet6 NO link-local.
PRIMARY4=$(ifconfig "$IFACE" inet  2>/dev/null | awk '/inet /{print $2; exit}')
PRIMARY6=$(ifconfig "$IFACE" inet6 2>/dev/null | awk '/inet6 /{ip=$2; sub(/%.*/,"",ip); if(ip !~ /^fe80/){print ip; exit}}')

# Lista de alias IPv4 (todas las inet menos la primaria)
list_v4_aliases() { ifconfig "$IFACE" inet 2>/dev/null | awk '/inet /{print $2}' | tail -n +2; }
# Lista de alias IPv6 (todas las inet6 menos link-local y menos la primaria)
list_v6_aliases() {
    ifconfig "$IFACE" inet6 2>/dev/null | awk '/inet6 /{ip=$2; sub(/%.*/,"",ip); if(ip !~ /^fe80/) print ip}' \
        | awk -v p="$PRIMARY6" 'NR==1 && $0==p {next} {print}'
}

# Reescribe TODOS los ifconfig_<IFACE>_aliasN desde el estado vivo (v4 + v6, contiguos).
rc_sync() {
    i=0
    while [ $i -lt 128 ]; do sysrc -x "ifconfig_${IFACE}_alias${i}" >/dev/null 2>&1; i=$((i+1)); done
    i=0
    for a in $(list_v4_aliases); do
        sysrc "ifconfig_${IFACE}_alias${i}=inet ${a} netmask 255.255.255.255" >/dev/null 2>&1; i=$((i+1))
    done
    for a in $(list_v6_aliases); do
        sysrc "ifconfig_${IFACE}_alias${i}=inet6 ${a} prefixlen 64" >/dev/null 2>&1; i=$((i+1))
    done
}

has_ip() {  # ¿la IP está ya en la interfaz?
    if is_v6 "$1"; then
        ifconfig "$IFACE" inet6 2>/dev/null | awk '{ip=$2; sub(/%.*/,"",ip); print ip}' | grep -qx "$1"
    else
        ifconfig "$IFACE" inet 2>/dev/null | awk '/inet /{print $2}' | grep -qx "$1"
    fi
}

case "$ACTION" in
  list)
      echo "interfaz: $IFACE"
      echo "primaria IPv4: ${PRIMARY4:-—}   primaria IPv6: ${PRIMARY6:-—}"
      echo "alias IPv4:"; list_v4_aliases | sed 's/^/  /'
      echo "alias IPv6:"; list_v6_aliases | sed 's/^/  /'
      ;;

  add)
      valid_ip "$IP" || { echo "ip_alias: IP inválida: '$IP'" >&2; exit 3; }
      if is_v6 "$IP"; then
          [ "$IP" = "$PRIMARY6" ] && { echo "ip_alias: '$IP' es la IPv6 primaria" >&2; exit 4; }
          if has_ip "$IP"; then echo "ip_alias: $IP ya está en $IFACE"
          else ifconfig "$IFACE" inet6 "$IP" prefixlen 64 alias || { echo "ip_alias: fallo al añadir $IP" >&2; exit 5; }
               echo "ip_alias: alias IPv6 $IP añadido en $IFACE"; fi
      else
          [ "$IP" = "$PRIMARY4" ] && { echo "ip_alias: '$IP' es la IPv4 primaria" >&2; exit 4; }
          if has_ip "$IP"; then echo "ip_alias: $IP ya está en $IFACE"
          else ifconfig "$IFACE" "$IP/32" alias || { echo "ip_alias: fallo al añadir $IP" >&2; exit 5; }
               echo "ip_alias: alias IPv4 $IP añadido en $IFACE"; fi
      fi
      rc_sync
      ;;

  del)
      valid_ip "$IP" || { echo "ip_alias: IP inválida: '$IP'" >&2; exit 3; }
      if is_v6 "$IP"; then
          [ "$IP" = "$PRIMARY6" ] && { echo "ip_alias: NO se elimina la IPv6 primaria '$IP'" >&2; exit 4; }
          if has_ip "$IP"; then ifconfig "$IFACE" inet6 "$IP" -alias 2>&1 || { echo "ip_alias: fallo al quitar $IP" >&2; exit 5; }
               echo "ip_alias: alias IPv6 $IP eliminado"; else echo "ip_alias: $IP no estaba en $IFACE"; fi
      else
          [ "$IP" = "$PRIMARY4" ] && { echo "ip_alias: NO se elimina la IPv4 primaria '$IP'" >&2; exit 4; }
          if has_ip "$IP"; then ifconfig "$IFACE" "$IP" -alias || { echo "ip_alias: fallo al quitar $IP" >&2; exit 5; }
               echo "ip_alias: alias IPv4 $IP eliminado"; else echo "ip_alias: $IP no estaba en $IFACE"; fi
      fi
      rc_sync
      ;;

  *) usage ;;
esac
exit 0

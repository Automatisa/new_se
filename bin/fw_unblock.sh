#!/bin/sh
# fw_unblock.sh — Elimina una IP de la lista de bloqueos del cortafuegos.
#
# Uso (como root):
#   sh /usr/local/bulwark/bin/fw_unblock.sh <IP>
#   sh /usr/local/bulwark/bin/fw_unblock.sh 1.2.3.4
#   sh /usr/local/bulwark/bin/fw_unblock.sh 2001:db8::1
#   sh /usr/local/bulwark/bin/fw_unblock.sh 10.0.0.0/8
#
# Qué hace:
#   1. Valida el formato de la IP/CIDR
#   2. Elimina la entrada de x_fw_blocked en la BD (soft-delete)
#   3. Reconstruye la tabla pf bulwark_blocked desde la BD
#   4. Elimina la IP de la tabla pf bulwark_blocked directamente (inmediato)
#   5. También intenta eliminarla de la tabla sshguard por si estaba baneada allí
#
# Este script es para uso de emergencia desde SSH.
# Para gestión desde el panel usa: Firewall Admin → IPs bloqueadas → Eliminar

set -e

RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; NC='\033[0m'; BLD='\033[1m'
ok()  { printf "${GRN}[OK]${NC}  %s\n" "$*"; }
err() { printf "${RED}[ERR]${NC} %s\n" "$*" >&2; }
inf() { printf "${YLW}[..]${NC}  %s\n" "$*"; }

###############################################################################
# Verificar root
###############################################################################
if [ "$(id -u)" -ne 0 ]; then
    err "Este script debe ejecutarse como root."
    exit 1
fi

###############################################################################
# Verificar argumento
###############################################################################
if [ -z "$1" ]; then
    printf "${BLD}Uso:${NC} $0 <IP o CIDR>\n"
    printf "Ejemplos:\n"
    printf "  $0 1.2.3.4\n"
    printf "  $0 2001:db8::1\n"
    printf "  $0 10.0.0.0/8\n"
    printf "  $0 2001:db8::/32\n"
    exit 1
fi

IP="$1"

###############################################################################
# Validar formato IP/CIDR (IPv4, IPv6, con o sin prefijo CIDR)
###############################################################################
VALID_IPV4='^([0-9]{1,3}\.){3}[0-9]{1,3}(/([0-9]|[12][0-9]|3[02]))?$'
VALID_IPV6='^([0-9a-fA-F]{0,4}:){2,7}[0-9a-fA-F]{0,4}(/([0-9]{1,2}|1[01][0-9]|12[0-8]))?$'

if ! echo "$IP" | grep -qE "$VALID_IPV4"; then
    if ! echo "$IP" | grep -qE "$VALID_IPV6"; then
        err "Formato inválido: '$IP'"
        err "Se aceptan: IPv4, IPv6, CIDR (ej: 1.2.3.4, 2001:db8::1, 10.0.0.0/8)"
        exit 1
    fi
fi

printf "\n${BLD}=== Eliminar bloqueo: %s ===${NC}\n\n" "$IP"

###############################################################################
# Leer credenciales de la BD
###############################################################################
CNF="/usr/local/bulwark/cnf/db.php"
if [ ! -f "$CNF" ]; then
    err "No se encuentra $CNF"
    exit 1
fi

DB_HOST=$(grep "host"   "$CNF" | sed "s/.*= '//;s/'.*//")
DB_NAME=$(grep "dbname" "$CNF" | sed "s/.*= '//;s/'.*//")
DB_USER=$(grep "user"   "$CNF" | sed "s/.*= '//;s/'.*//")
DB_PASS=$(grep "pass"   "$CNF" | sed "s/.*= '//;s/'.*//")

mysql_q() {
    mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" "$DB_NAME" \
        --batch --skip-column-names -e "$1" 2>/dev/null
}

###############################################################################
# Comprobar si la IP existe en x_fw_blocked
###############################################################################
inf "Buscando '$IP' en x_fw_blocked..."

FOUND=$(mysql_q "SELECT COUNT(*) FROM x_fw_blocked WHERE fb_ip_vc='$IP' AND fb_deleted_ts IS NULL;")

if [ "$FOUND" = "0" ]; then
    printf "${YLW}[AVISO]${NC} La IP '%s' no está en la lista de bloqueos activos de la BD.\n" "$IP"
    printf "         Se intentará eliminar igualmente de las tablas pf.\n\n"
else
    inf "Eliminando de x_fw_blocked (soft-delete)..."
    mysql_q "UPDATE x_fw_blocked SET fb_deleted_ts=UNIX_TIMESTAMP(), fb_active_in=0
             WHERE fb_ip_vc='$IP' AND fb_deleted_ts IS NULL;"
    ok "Eliminada de la BD."
fi

###############################################################################
# Reconstruir tabla pf bulwark_blocked desde la BD
###############################################################################
inf "Reconstruyendo tabla pf bulwark_blocked desde la BD..."
if sh /usr/local/bulwark/bin/fw_block_apply.sh 2>/dev/null; then
    ok "Tabla pf bulwark_blocked actualizada."
else
    printf "${YLW}[AVISO]${NC} fw_block_apply.sh devolvió error (puede que pf no esté activo).\n"
fi

###############################################################################
# Eliminar directamente de las tablas pf (efecto inmediato)
###############################################################################
inf "Eliminando de la tabla pf bulwark_blocked..."
if pfctl -si 2>/dev/null | grep -q "^Status: Enabled"; then
    pfctl -t bulwark_blocked -T delete "$IP" 2>/dev/null \
        && ok "Eliminada de pf bulwark_blocked." \
        || printf "${YLW}[AVISO]${NC} La IP no estaba en la tabla pf bulwark_blocked.\n"

    inf "Comprobando tabla pf sshguard..."
    if pfctl -t sshguard -T show 2>/dev/null | grep -qF "$IP"; then
        pfctl -t sshguard -T delete "$IP" 2>/dev/null \
            && ok "Eliminada también de pf sshguard (estaba baneada por SSHGuard)."
    else
        printf "      No estaba en pf sshguard.\n"
    fi
else
    printf "${YLW}[AVISO]${NC} pf no está activo — solo se eliminó de la BD.\n"
fi

###############################################################################
# Actualizar también x_fw_auto_banned si aplica
###############################################################################
SGFOUND=$(mysql_q "SELECT COUNT(*) FROM x_fw_auto_banned WHERE fa_ip_vc='$IP' AND fa_active_in=1;" 2>/dev/null || echo 0)
if [ "$SGFOUND" != "0" ] && [ "$SGFOUND" != "" ]; then
    mysql_q "UPDATE x_fw_auto_banned SET fa_active_in=0 WHERE fa_ip_vc='$IP';" 2>/dev/null
    ok "Marcada como inactiva en x_fw_auto_banned."
fi

###############################################################################
# Estado final
###############################################################################
printf "\n${GRN}${BLD}Operación completada.${NC}\n"
printf "  IP desbloqueada : ${BLD}%s${NC}\n" "$IP"

# Mostrar tabla pf actual
COUNT=$(pfctl -t bulwark_blocked -T show 2>/dev/null | grep -E '[0-9a-fA-F]' | wc -l | tr -d ' \t')
printf "  IPs bloqueadas en pf ahora: ${BLD}%s${NC}\n\n" "$COUNT"

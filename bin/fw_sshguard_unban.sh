#!/bin/sh
# fw_sshguard_unban.sh — Desbanea la IP escrita en /var/bulwark/run/fw_unban_request.
# Invocado ÚNICAMENTE por privilege::run('fw_sshguard_unban'). Sin argumentos.
#
# Protocolo seguro de paso de argumento dinámico (IP):
#   1. El panel (zpanel) escribe la IP en REQUEST (root:bulwark 660 — solo el panel puede escribir)
#   2. Este script (ejecutado como root) lee y borra el archivo
#   3. Valida el contenido con regex estricta antes de pasarlo a pfctl
#   4. pfctl elimina la IP de la tabla sshguard (y de bulwark_blocked si aplica)

REQUEST="/var/bulwark/run/fw_unban_request"

# Regex de validación: IPv4 (con CIDR opcional) o IPv6 (con CIDR opcional)
VALID_IPV4='^([0-9]{1,3}\.){3}[0-9]{1,3}(/([0-9]|[12][0-9]|3[02]))?$'
VALID_IPV6='^([0-9a-fA-F]{0,4}:){2,7}[0-9a-fA-F]{0,4}(/([0-9]{1,2}|1[01][0-9]|12[0-8]))?$'

if [ ! -f "$REQUEST" ]; then
    echo "ERROR: No hay solicitud de desbaneo en $REQUEST." >&2
    exit 1
fi

# Leer primera línea, eliminar espacios, borrar fichero inmediatamente
IP=$(head -1 "$REQUEST" | tr -d '[:space:]')
rm -f "$REQUEST"

if [ -z "$IP" ]; then
    echo "ERROR: Solicitud de desbaneo vacía." >&2
    exit 1
fi

# Validar formato estricto antes de pasar a pfctl
if ! echo "$IP" | grep -qE "$VALID_IPV4"; then
    if ! echo "$IP" | grep -qE "$VALID_IPV6"; then
        echo "ERROR: Formato de IP inválido: $IP" >&2
        exit 1
    fi
fi

# Eliminar de la tabla sshguard (bans automáticos)
pfctl -t sshguard -T delete "$IP" 2>/dev/null || true

# Eliminar también de bulwark_blocked por si fue bloqueada manualmente
pfctl -t bulwark_blocked -T delete "$IP" 2>/dev/null || true

exit 0

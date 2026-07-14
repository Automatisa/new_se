#!/bin/sh
# sentora_mail_limit.sh — Envoltorio de sendmail que LIMITA el correo saliente de cada cuenta de
# hosting por hora. Objetivo de seguridad: que un PHP infectado NO pueda disparar spam masivo con
# mail() y quemar la IP del servidor en listas negras.
#
# Se instala como `sendmail_path` de PHP. Como PHP-FPM corre cada dominio con su propio usuario
# (h_<cuenta>), el emisor se identifica por el usuario Unix (INFALSIFICABLE, a diferencia del
# From). Cuenta los envíos por cuenta y hora en Redis; al superar el límite, descarta el mensaje.
#
# NO limita: el correo del sistema/panel (root, www, vmail…) ni las cuentas en la allowlist
# (dominios de alto volumen legítimo, p.ej. una tienda PrestaShop).
#
# Config (la gestiona el panel, www-writable):
#   /var/sentora/mail_limits/limit      -> nº máximo de correos por cuenta y hora (0 = ilimitado)
#   /var/sentora/mail_limits/whitelist  -> cuentas exentas (una por línea, sin el prefijo h_)

REAL="/usr/local/sbin/sendmail"
REDIS="/usr/local/bin/redis-cli"
CONF_DIR="/var/sentora/mail_limits"
LIMIT_FILE="$CONF_DIR/limit"
WL_FILE="$CONF_DIR/whitelist"
# Credencial ACL de Redis para este wrapper: usuario 'maillimit', que SOLO puede
# INCR/EXPIRE sobre las claves sentora:maillimit:* (no puede resetear ni leer nada más).
# Aunque el inquilino lea esta clave, no puede saltarse ni sabotear el resto de Redis.
RPASS_FILE="$CONF_DIR/redis_pass"

U=$(id -un 2>/dev/null)
ACCT=${U#h_}

# Solo se limitan las cuentas de hosting (usuario h_*). El resto pasa sin tocar.
case "$U" in
    h_*) ;;
    *) exec "$REAL" "$@" ;;
esac

# Allowlist por cuenta (alto volumen legítimo).
if [ -f "$WL_FILE" ] && grep -qxF "$ACCT" "$WL_FILE" 2>/dev/null; then
    exec "$REAL" "$@"
fi

LIMIT=$(head -1 "$LIMIT_FILE" 2>/dev/null | tr -cd '0-9')
[ -z "$LIMIT" ] && LIMIT=200
# 0 = sin límite
if [ "$LIMIT" -eq 0 ] 2>/dev/null; then
    exec "$REAL" "$@"
fi

HOUR=$(date +%Y%m%d%H)
KEY="sentora:maillimit:${ACCT}:${HOUR}"
# Auth ACL de Redis. La clave es hexadecimal (openssl rand -hex) => sin espacios ni
# metacaracteres, así que el split sin comillas de $RAUTH es seguro. NO usamos `set --`
# porque machacaría $@ (los argumentos de sendmail que se pasan al exec final).
RPASS=$(head -1 "$RPASS_FILE" 2>/dev/null)
if [ -n "$RPASS" ]; then
    RAUTH="--user maillimit -a $RPASS --no-auth-warning"
else
    RAUTH=""
fi
CNT=$([ -x "$REDIS" ] && "$REDIS" $RAUTH INCR "$KEY" 2>/dev/null)
[ -n "$CNT" ] && "$REDIS" $RAUTH EXPIRE "$KEY" 3700 >/dev/null 2>&1

if [ -n "$CNT" ] && [ "$CNT" -gt "$LIMIT" ] 2>/dev/null; then
    logger -t sentora-maillimit "BLOCKED acct=$ACCT count=$CNT limit=$LIMIT/h"
    cat > /dev/null   # consumir el mensaje de stdin
    exit 1            # PHP mail() devolverá false
fi

exec "$REAL" "$@"

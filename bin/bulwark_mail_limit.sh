#!/bin/sh
# bulwark_mail_limit.sh — Envoltorio de sendmail que LIMITA el correo saliente de cada cuenta de
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
#   /var/bulwark/mail_limits/limit      -> nº máximo de correos por cuenta y hora (0 = ilimitado)
#   /var/bulwark/mail_limits/whitelist  -> cuentas exentas (una por línea, sin el prefijo h_)

REAL="/usr/local/sbin/sendmail"
CONF_DIR="/var/bulwark/mail_limits"
LIMIT_FILE="$CONF_DIR/limit"
WL_FILE="$CONF_DIR/whitelist"
# El conteo lo hace un ayudante setgid (grupo maillimit). La cuenta de hosting NO tiene la
# credencial de Redis: no puede resetear su contador ni tocar el de otra cuenta (griefing).
# El ayudante deduce la cuenta del uid real, hace INCR+EXPIRE e imprime el contador.
HELPER="/usr/local/bulwark/bin/bulwark_maillimit_helper"

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

# El ayudante cuenta (INCR+EXPIRE) e imprime el contador de esta cuenta/hora. Si no está
# disponible o Redis falla, imprime vacío => fail-open (el correo pasa).
CNT=$([ -x "$HELPER" ] && "$HELPER" 2>/dev/null)

if [ -n "$CNT" ] && [ "$CNT" -gt "$LIMIT" ] 2>/dev/null; then
    logger -t bulwark-maillimit "BLOCKED acct=$ACCT count=$CNT limit=$LIMIT/h"
    cat > /dev/null   # consumir el mensaje de stdin
    exit 1            # PHP mail() devolverá false
fi

exec "$REAL" "$@"

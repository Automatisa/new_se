#!/bin/sh
# vhost_dir_add.sh — Crea el esqueleto de directorios de un dominio/subdominio con el propietario
# y permisos correctos de aislamiento (h_USERNAME:www, dirs setgid 2750), como ROOT.
#
# Motivo: el subárbol web/ del cliente es 2750 (no escribible por el grupo www), así que el panel
# (que corre como bulwark, en grupo www) NO puede crear ahí los directorios del dominio -> los dominios quedaban sin
# public_html y Apache devolvía 403. Este helper lo hace por doas como root con ownership correcto.
#
# Lee la orden "USERNAME|VH_DIRECTORY" desde /var/bulwark/run/vhost_diradd_req (root:bulwark 660).
# VH_DIRECTORY es el nombre de carpeta ya saneado por el panel (dominio con '.' -> '_').
# Idempotente: crea sólo lo que falte y reajusta ownership/permisos. Llamado via
# privilege::run('vhost_dir_add') desde contexto www.

REQ_FILE="/var/bulwark/run/vhost_diradd_req"
HOSTED_DIR="/var/bulwark/hostdata"
STATIC_WELCOME="/usr/local/bulwark/etc/static/pages/welcome.html"

[ -f "$REQ_FILE" ] || exit 1
LINE=$(cat "$REQ_FILE" | tr -d '\n\r')
rm -f "$REQ_FILE"

USERNAME=$(printf '%s' "$LINE" | cut -d'|' -f1)
VHDIR=$(printf '%s' "$LINE" | cut -d'|' -f2)

# Validación estricta (anti path-traversal): usuario y directorio sólo con caracteres seguros.
echo "$USERNAME" | grep -qE '^[a-z][a-z0-9_]{0,31}$'        || exit 2
echo "$VHDIR"    | grep -qE '^[a-z0-9][a-z0-9_.-]{0,253}$'  || exit 3
case "$VHDIR" in *..*|*/*) exit 4 ;; esac

SYSUSER="h_${USERNAME}"
HOSTDIR="${HOSTED_DIR}/${USERNAME}"
WEBBASE="${HOSTDIR}/web/${VHDIR}"

# El usuario de sistema y su home deben existir (los crea hosting_user_add.sh).
pw usershow "$SYSUSER" >/dev/null 2>&1 || exit 5
[ -d "$HOSTDIR" ] || exit 6

# Asegurar web/ (2750: www lo atraviesa por grupo, no escribe).
if [ ! -d "${HOSTDIR}/web" ]; then
    mkdir -p "${HOSTDIR}/web"
    chown "${SYSUSER}:www" "${HOSTDIR}/web"
    chmod 2750 "${HOSTDIR}/web"
fi

# Esqueleto del dominio.
for d in "" /public_html /tmp /logs /_errorpages /_cgi-bin; do
    if [ ! -d "${WEBBASE}${d}" ]; then
        mkdir -p "${WEBBASE}${d}"
    fi
done

# Página de bienvenida si public_html está vacío (sin índice).
if [ -d "${WEBBASE}/public_html" ] && [ -z "$(ls -A "${WEBBASE}/public_html" 2>/dev/null)" ]; then
    if [ -f "$STATIC_WELCOME" ]; then
        cp "$STATIC_WELCOME" "${WEBBASE}/public_html/index.html"
    fi
fi

# Ownership + permisos de aislamiento (h_USERNAME:www; dirs setgid 2750, ficheros 0640).
chown -R "${SYSUSER}:www" "$WEBBASE"
find "$WEBBASE" -type d -exec chmod 2750 {} + 2>/dev/null
find "$WEBBASE" -type f -exec chmod 0640 {} + 2>/dev/null

exit 0

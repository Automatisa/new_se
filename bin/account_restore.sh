#!/bin/sh
# account_restore.sh — Restaura los FICHEROS del home de una cuenta desde un backup .zip.
#
# Invocado ÚNICAMENTE por privilege::run('account_restore'). Sin argumentos: lee la orden de
# /var/bulwark/run/account_restore_req (root:bulwark 660), una sola línea:
#     USERNAME|/ruta/al/backup.zip
#
# Hace, como root:
#   1) valida USERNAME (regex) y que el .zip exista y esté en una ubicación permitida;
#   2) extrae SOLO el subárbol "USERNAME/..." del zip (web/, mail/, etc.) dentro de hostdata,
#      ignorando los .sql y panel_config.json (los gestiona PHP);
#   3) reaplica ownership/permisos de aislamiento (igual que hosting_perms_repair.sh):
#      raíz 2770 h_user:www; web/ setgid 2750 dirs, 0640 ficheros, h_user:www.
#
# NO toca la base de datos ni la config del panel: eso lo hace el motor PHP por separado.

REQ=/var/bulwark/run/account_restore_req
HOSTED_DIR=/var/bulwark/hostdata

[ -f "$REQ" ] || exit 1
LINE=$(head -1 "$REQ" 2>/dev/null | tr -d '\r\n')
rm -f "$REQ"

USERNAME=${LINE%%|*}
ZIP=${LINE#*|}

# Validación estricta del nombre de usuario
echo "$USERNAME" | grep -Eq '^[a-zA-Z0-9_-]+$' || exit 2
[ -n "$ZIP" ] && [ "$ZIP" != "$USERNAME" ] || exit 3

# El zip debe existir, ser fichero regular y estar en una ubicación permitida:
#   - copias locales del usuario:   /var/bulwark/hostdata/<user>/backups/
#   - staging de restauración:       /var/bulwark/hostdata/<user>/restore/
[ -f "$ZIP" ] || exit 4
case "$ZIP" in
    "$HOSTED_DIR/$USERNAME/backups/"*.zip) ;;
    "$HOSTED_DIR/$USERNAME/restore/"*.zip) ;;
    *) exit 5 ;;
esac

SYSUSER="h_${USERNAME}"
pw usershow "$SYSUSER" >/dev/null 2>&1 || exit 6
DEST="$HOSTED_DIR/$USERNAME"
[ -d "$DEST" ] || exit 7

# Anti zip-slip (defensa en profundidad, además de la protección propia de unzip):
# rechazar el archivo si CUALQUIER entrada contiene un componente '..' o una ruta
# absoluta — un backup legítimo del home nunca los tiene.
if /usr/bin/unzip -Z1 "$ZIP" 2>/dev/null | grep -qE '(^/|(^|/)\.\.(/|$))'; then
    echo "ERROR: el zip contiene rutas '..' o absolutas (posible zip-slip)." >&2
    exit 9
fi

# 2) Extraer solo el subárbol del home del usuario (USERNAME/...). -o sobrescribe.
#    Se excluyen explícitamente los .sql y el json de config (los procesa PHP).
/usr/bin/unzip -o -q "$ZIP" "${USERNAME}/*" -x "*.sql" "panel_config.json" -d "$HOSTED_DIR" >/dev/null 2>&1
RC=$?
# unzip devuelve 11 si no hay ficheros que coincidan; lo tratamos como "sin ficheros de home"
[ "$RC" -eq 0 ] || [ "$RC" -eq 11 ] || exit 8

# 3) Reaplicar aislamiento de permisos sobre el home restaurado
chown "${SYSUSER}:www" "$DEST" 2>/dev/null
chmod 2770 "$DEST" 2>/dev/null
if [ -d "${DEST}/web" ]; then
    chown "${SYSUSER}:www" "${DEST}/web" 2>/dev/null
    chmod 2750 "${DEST}/web" 2>/dev/null
    find "${DEST}/web" -mindepth 1 \( -type d -o -type f \) 2>/dev/null | while IFS= read -r p; do
        chown "${SYSUSER}:www" "$p" 2>/dev/null
        if [ -d "$p" ]; then chmod 2750 "$p" 2>/dev/null; else chmod 0640 "$p" 2>/dev/null; fi
    done
fi

exit 0

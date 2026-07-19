#!/bin/sh
# hosting_perms_repair.sh — Reaplica el aislamiento entre inquilinos a las cuentas de
# hosting EXISTENTES. Idempotente. Ejecutar como root.
#
# Para cada cuenta /var/bulwark/hostdata/<user>:
#   1) saca h_<user> del grupo www (para que no comparta grupo con los demás clientes);
#   2) directorio raíz del usuario -> 2770 h_<user>:www (www crea los dominios ahí);
#   3) subárbol web/ -> h_<user>:www, dirs 2750 (setgid), ficheros 0640 (aislado);
#   No toca mail/ (vmail) ni ssl/backups (ownership propio de servicios).

HOSTED_DIR="/var/bulwark/hostdata"

for HOSTDIR in "$HOSTED_DIR"/*; do
    [ -d "$HOSTDIR" ] || continue
    USERNAME=$(basename "$HOSTDIR")
    SYSUSER="h_${USERNAME}"
    pw usershow "$SYSUSER" >/dev/null 2>&1 || continue

    # 1) sacar del grupo www
    pw groupmod www -d "$SYSUSER" 2>/dev/null || true

    # 2) raíz del usuario: setgid + rwxrwx--- (www necesita crear dominios)
    chown "${SYSUSER}:www" "$HOSTDIR" 2>/dev/null
    chmod 2770 "$HOSTDIR" 2>/dev/null

    # 3) subárbol web/ (contenido servido): aislamiento estricto
    if [ -d "${HOSTDIR}/web" ]; then
        chown "${SYSUSER}:www" "${HOSTDIR}/web" 2>/dev/null
        chmod 2750 "${HOSTDIR}/web" 2>/dev/null
        find "${HOSTDIR}/web" -mindepth 1 \( -type d -o -type f \) 2>/dev/null | while IFS= read -r p; do
            chown "${SYSUSER}:www" "$p" 2>/dev/null
            if [ -d "$p" ]; then chmod 2750 "$p" 2>/dev/null; else chmod 0640 "$p" 2>/dev/null; fi
        done
    fi
    echo "  ${USERNAME}: aislamiento aplicado"
done
echo "Permisos de hosting reaplicados."

#!/bin/sh
# hosting_user_add.sh — Crea usuario de sistema h_USERNAME para una cuenta de hosting.
#
# Lee el nombre de cuenta desde /var/bulwark/run/hosting_useradd_req (root:zpanel 660).
# Validación estricta: solo letras minúsculas, dígitos y guión bajo, 1-32 caracteres.
# Es idempotente: si el usuario ya existe solo corrige la propiedad del directorio.
# Llamado via privilege::run('hosting_user_add') desde contexto www (doas).

REQ_FILE="/var/bulwark/run/hosting_useradd_req"
HOSTED_DIR="/var/bulwark/hostdata"

[ -f "$REQ_FILE" ] || exit 1

USERNAME=$(cat "$REQ_FILE" | tr -d '\n\r ')
rm -f "$REQ_FILE"

# Validación estricta del nombre de usuario del panel
echo "$USERNAME" | grep -qE '^[a-z][a-z0-9_]{0,31}$' || exit 2

SYSUSER="h_${USERNAME}"
HOSTDIR="${HOSTED_DIR}/${USERNAME}"

# Idempotente: si el usuario ya existe, solo corrige ownership/permisos y sale
if pw usershow "$SYSUSER" >/dev/null 2>&1; then
    # Aislamiento entre inquilinos: el usuario NO debe estar en el grupo www (si no,
    # podría leer los ficheros group-www de otros clientes). Apache (www) sirve los
    # estáticos porque es el GRUPO de los ficheros, no porque el cliente esté en www.
    pw groupmod www -d "$SYSUSER" 2>/dev/null || true
    if [ -d "$HOSTDIR" ]; then
        chown "${SYSUSER}:www" "$HOSTDIR"
        chmod 2770 "$HOSTDIR"
        chown -R "${SYSUSER}:www" "$HOSTDIR"
        [ -d "${HOSTDIR}/mail" ] && chown -R vmail:vmail "${HOSTDIR}/mail"
    fi
    exit 0
fi

# Crear grupo propio del usuario
pw groupadd -n "$SYSUSER" 2>/dev/null || true

# Crear usuario: sin shell de login, sin home real, SOLO en su propio grupo (NO en www:
# el aislamiento depende de que el cliente no comparta grupo con los demás).
pw useradd -n "$SYSUSER" \
           -g "$SYSUSER" \
           -s /usr/sbin/nologin \
           -d /nonexistent \
           -c "Bulwark hosting ${USERNAME}" \
           2>/dev/null || exit 3

# Ajustar propiedad del directorio de hosting.
# 2770 (setgid, rwxrwx---): dueño h_USERNAME + grupo www (para que el panel, que corre como
# www, pueda crear los subdirectorios de dominio); sin acceso para "otros" (los demás
# clientes, que NO están en www). El setgid propaga el grupo www a lo que se cree dentro.
if [ -d "$HOSTDIR" ]; then
    chown "${SYSUSER}:www" "$HOSTDIR"
    chmod 2770 "$HOSTDIR"
    chown -R "${SYSUSER}:www" "$HOSTDIR"
    # El directorio de correo es de vmail, no tocarlo
    [ -d "${HOSTDIR}/mail" ] && chown -R vmail:vmail "${HOSTDIR}/mail"
fi

exit 0

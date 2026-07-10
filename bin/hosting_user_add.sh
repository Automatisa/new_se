#!/bin/sh
# hosting_user_add.sh — Crea usuario de sistema h_USERNAME para una cuenta de hosting.
#
# Lee el nombre de cuenta desde /var/sentora/run/hosting_useradd_req (root:www 660).
# Validación estricta: solo letras minúsculas, dígitos y guión bajo, 1-32 caracteres.
# Es idempotente: si el usuario ya existe solo corrige la propiedad del directorio.
# Llamado via privilege::run('hosting_user_add') desde contexto www (doas).

REQ_FILE="/var/sentora/run/hosting_useradd_req"
HOSTED_DIR="/var/sentora/hostdata"

[ -f "$REQ_FILE" ] || exit 1

USERNAME=$(cat "$REQ_FILE" | tr -d '\n\r ')
rm -f "$REQ_FILE"

# Validación estricta del nombre de usuario del panel
echo "$USERNAME" | grep -qE '^[a-z][a-z0-9_]{0,31}$' || exit 2

SYSUSER="h_${USERNAME}"
HOSTDIR="${HOSTED_DIR}/${USERNAME}"

# Idempotente: si el usuario ya existe, solo corrige ownership/permisos y sale
if pw usershow "$SYSUSER" >/dev/null 2>&1; then
    if [ -d "$HOSTDIR" ]; then
        chown "${SYSUSER}:www" "$HOSTDIR"
        chmod 775 "$HOSTDIR"
        chown -R "${SYSUSER}:www" "$HOSTDIR"
        [ -d "${HOSTDIR}/mail" ] && chown -R vmail:vmail "${HOSTDIR}/mail"
    fi
    exit 0
fi

# Crear grupo propio del usuario
pw groupadd -n "$SYSUSER" 2>/dev/null || true

# Crear usuario: sin shell de login, sin home real, miembro del grupo www
pw useradd -n "$SYSUSER" \
           -g "$SYSUSER" \
           -G www \
           -s /usr/sbin/nologin \
           -d /nonexistent \
           -c "Sentora hosting ${USERNAME}" \
           2>/dev/null || exit 3

# Ajustar propiedad del directorio de hosting.
# El directorio raíz necesita 775 (grupo www con escritura) para que el panel
# (corriendo como www) pueda crear subdirectorios de dominio dentro de él.
if [ -d "$HOSTDIR" ]; then
    chown "${SYSUSER}:www" "$HOSTDIR"
    chmod 775 "$HOSTDIR"
    chown -R "${SYSUSER}:www" "$HOSTDIR"
    # El directorio de correo es de vmail, no tocarlo
    [ -d "${HOSTDIR}/mail" ] && chown -R vmail:vmail "${HOSTDIR}/mail"
fi

exit 0

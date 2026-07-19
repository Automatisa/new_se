#!/bin/sh
# hosting_user_del.sh — Elimina el usuario de sistema h_USERNAME de una cuenta borrada.
#
# Lee el nombre de cuenta desde /var/bulwark/run/hosting_userdel_req (root:bulwark 660).
# Validación estricta: solo letras minúsculas, dígitos y guión bajo, 1-32 caracteres.
# Es idempotente: si el usuario no existe simplemente sale con éxito.
# Llamado via privilege::run('hosting_user_del') desde contexto www (doas).

REQ_FILE="/var/bulwark/run/hosting_userdel_req"

[ -f "$REQ_FILE" ] || exit 1

USERNAME=$(cat "$REQ_FILE" | tr -d '\n\r ')
rm -f "$REQ_FILE"

# Validación estricta
echo "$USERNAME" | grep -qE '^[a-z][a-z0-9_]{0,31}$' || exit 2

SYSUSER="h_${USERNAME}"

# Eliminar usuario y grupo (silencioso si no existen)
pw userdel -n "$SYSUSER" 2>/dev/null || true
pw groupdel -n "$SYSUSER" 2>/dev/null || true

exit 0

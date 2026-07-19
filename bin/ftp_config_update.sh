#!/bin/sh
# Valida y aplica el nuevo config de ProFTPD.
# Llamado por privilege::run('proftpd_config_update') como root via doas.
# El panel (zpanel) escribe el contenido nuevo en TMPFILE antes de llamar a este script.

TMPFILE="/tmp/bulwark_proftpd_new.conf"
REAL_CONFIG="/usr/local/etc/bulwark/proftpd/proftpd-mysql.conf"
BACKUP="/tmp/bulwark_proftpd_backup.conf"

if [ ! -f "$TMPFILE" ]; then
    echo "ERROR: fichero temporal no encontrado" >&2
    exit 1
fi

# Validar sintaxis del nuevo config
if ! /usr/local/sbin/proftpd -t -c "$TMPFILE" 2>/dev/null; then
    rm -f "$TMPFILE"
    echo "ERROR: la sintaxis del config no es válida" >&2
    exit 2
fi

# Backup del config actual
cp "$REAL_CONFIG" "$BACKUP"

# Aplicar
cp "$TMPFILE" "$REAL_CONFIG"
chown root:www "$REAL_CONFIG"
chmod 640 "$REAL_CONFIG"
rm -f "$TMPFILE"

exit 0

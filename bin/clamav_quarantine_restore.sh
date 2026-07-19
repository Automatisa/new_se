#!/bin/sh
# Restaura un archivo de cuarentena a /var/mail/.
# El nombre del archivo a restaurar se lee de /var/bulwark/run/clamav_restore_request
# (escrito por PHP www) — nunca como argumento directo para evitar inyección de rutas.
QUARANTINE=/var/bulwark/clamav/quarantine
MAILDIR=/var/mail
REQUEST=/var/bulwark/run/clamav_restore_request

if [ ! -f "$REQUEST" ]; then
    echo "Error: no existe el archivo de solicitud $REQUEST" >&2
    exit 1
fi

FILENAME=$(cat "$REQUEST" 2>/dev/null)
rm -f "$REQUEST"

if [ -z "$FILENAME" ]; then
    echo "Error: solicitud de restauración vacía" >&2
    exit 1
fi

# Aceptar solo caracteres seguros (sin barras, sin nulos, sin espacios)
case "$FILENAME" in
    */*|*..*|'') echo "Error: nombre de archivo no válido: $FILENAME" >&2; exit 1 ;;
esac
if ! echo "$FILENAME" | grep -qE '^[A-Za-z0-9._@+\-]+$'; then
    echo "Error: caracteres no permitidos en el nombre: $FILENAME" >&2
    exit 1
fi

SRC="$QUARANTINE/$FILENAME"
if [ ! -f "$SRC" ]; then
    echo "Error: el archivo no existe en cuarentena: $FILENAME" >&2
    exit 1
fi

# Obtener el nombre original quitando el sufijo de colisión (.001, .002, …)
ORIGNAME=$(echo "$FILENAME" | sed 's/\.[0-9][0-9][0-9]$//')
DEST="$MAILDIR/$ORIGNAME"

# Evitar sobreescribir un buzón activo
if [ -f "$DEST" ]; then
    DEST="${DEST}.restored_$(date +%Y%m%d%H%M%S)"
fi

mv "$SRC" "$DEST" || { echo "Error: no se pudo mover $SRC a $DEST" >&2; exit 1; }
chown root:mail "$DEST"
chmod 644 "$DEST"

echo "Restaurado: $SRC -> $DEST"
exit 0

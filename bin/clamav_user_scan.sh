#!/bin/sh
# Escanea el directorio hostdata de un usuario concreto.
# Ejecutado como root vía doas desde el módulo clamav_user.
# El username se lee de /var/bulwark/run/scan_requests/{username}.req.

SCAN_REQUESTS=/var/bulwark/run/scan_requests
HOSTDATA=/var/bulwark/hostdata
POST_SCAN=/usr/local/bulwark/bin/clamav_post_scan.php
LOG=/var/bulwark/clamav/scan_results.log

# Buscar el fichero de solicitud más reciente
REQ_FILE=$(ls -t "$SCAN_REQUESTS"/*.req 2>/dev/null | head -1)
if [ -z "$REQ_FILE" ]; then
    exit 0
fi

USERNAME=$(cat "$REQ_FILE" | tr -cd 'a-zA-Z0-9_-')
rm -f "$REQ_FILE"

# Validación estricta: el username debe corresponder a un directorio real
# y no contener caracteres de traversal
if [ -z "$USERNAME" ] || [ ! -d "$HOSTDATA/$USERNAME" ]; then
    echo "$(date): clamav_user_scan: usuario inválido o no encontrado: '$USERNAME'" >> "$LOG"
    exit 1
fi

SCAN_DIR="$HOSTDATA/$USERNAME"
INFECTED_TMP="$SCAN_REQUESTS/${USERNAME}_infected_$$.tmp"

echo "=== Escaneo usuario $USERNAME: $(date) ===" >> "$LOG"

# Escanear solo el directorio del usuario, excluyendo cuarentena y logs
SCAN_OUTPUT=$(/usr/local/bin/clamdscan \
    --infected \
    --no-summary \
    $SCAN_DIR 2>/dev/null)

echo "$SCAN_OUTPUT" | grep ' FOUND$' > "$INFECTED_TMP"
INFECTED_COUNT=$(grep -c '' "$INFECTED_TMP" 2>/dev/null || echo 0)

echo "$SCAN_OUTPUT" | grep -v ' FOUND$' | grep 'ERROR\|error' >> "$LOG" 2>/dev/null || true

if [ "$INFECTED_COUNT" -gt 0 ]; then
    /usr/local/bin/php "$POST_SCAN" "$INFECTED_TMP" >> "$LOG" 2>&1
else
    rm -f "$INFECTED_TMP"
    echo "Usuario $USERNAME: sin infectados." >> "$LOG"
fi

echo "=== Fin escaneo $USERNAME: $(date) ===" >> "$LOG"
tail -1000 "$LOG" > "${LOG}.tmp" && mv "${LOG}.tmp" "$LOG"
chmod 644 "$LOG"

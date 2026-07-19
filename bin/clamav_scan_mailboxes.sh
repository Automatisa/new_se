#!/bin/sh
# Escanea las rutas configuradas con clamdscan y delega el procesamiento
# de archivos infectados al post-procesador PHP (cuarentena por usuario,
# email, Redis). Ejecutado como root por daemon(8) vía clamav_scan_launch.sh.
LOG=/var/bulwark/clamav/scan_results.log
QUARANTINE=/var/bulwark/clamav/quarantine
PATHS_CONF=/var/bulwark/clamav/scan_paths.conf
INFECTED_TMP=/var/bulwark/run/clamav_infected_$$.tmp
POST_SCAN=/usr/local/bulwark/bin/clamav_post_scan.php

mkdir -p "$QUARANTINE"
chmod 700 "$QUARANTINE"

# Leer rutas desde la configuración — whitelist estricta
SCAN_PATHS=""
if [ -f "$PATHS_CONF" ]; then
    while IFS= read -r line; do
        line=$(echo "$line" | tr -d '\r')
        [ -z "$line" ] && continue
        case "$line" in
            /var/mail|/var/bulwark/hostdata|/var/bulwark/vmail|/var/bulwark/temp|/var/bulwark/backups)
                [ -d "$line" ] && SCAN_PATHS="$SCAN_PATHS $line" ;;
        esac
    done < "$PATHS_CONF"
fi
[ -z "$SCAN_PATHS" ] && SCAN_PATHS="/var/mail"

echo "=== Escaneo iniciado: $(date) ===" >> "$LOG"
echo "=== Rutas: $SCAN_PATHS ===" >> "$LOG"

# Ejecutar escaneo — sin --move para que el post-procesador gestione el enrutamiento
# shellcheck disable=SC2086
SCAN_OUTPUT=$(/usr/local/bin/clamdscan \
    --infected \
    --no-summary \
    --exclude-dir=ssl \
    --exclude-dir=logs \
    $SCAN_PATHS 2>/dev/null)

# Extraer líneas de archivos infectados para el post-procesador
echo "$SCAN_OUTPUT" | grep ' FOUND$' > "$INFECTED_TMP"
INFECTED_COUNT=$(grep -c '' "$INFECTED_TMP" 2>/dev/null || echo 0)

# Log de errores de acceso (excluye los FOUND ya procesados por el post-proc)
echo "$SCAN_OUTPUT" | grep -v ' FOUND$' | grep 'ERROR\|error' >> "$LOG" 2>/dev/null || true

# Delegar cuarentena, email y Redis al post-procesador PHP
if [ "$INFECTED_COUNT" -gt 0 ]; then
    /usr/local/bin/php "$POST_SCAN" "$INFECTED_TMP" >> "$LOG" 2>&1
else
    rm -f "$INFECTED_TMP"
fi

echo "----------- SCAN SUMMARY -----------" >> "$LOG"
echo "Infected files: $INFECTED_COUNT" >> "$LOG"
echo "=== Escaneo finalizado: $(date) ===" >> "$LOG"

# Limitar log a las últimas 1000 líneas
tail -1000 "$LOG" > "${LOG}.tmp" && mv "${LOG}.tmp" "$LOG"
chmod 644 "$LOG"

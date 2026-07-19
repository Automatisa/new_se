#!/bin/sh
# pkg_upgrade.sh — Actualiza TODOS los paquetes pkg. Panel (updates) vía doas (admin, confirmación).
# Marcador running SÍNCRONO + fichero de resultado para que el panel muestre éxito/fallo.
OUT_DIR="/var/bulwark/updates"; RUN="$OUT_DIR/running"; LOG="$OUT_DIR/last_action.log"; RES="$OUT_DIR/last_result"
mkdir -p "$OUT_DIR"
printf 'pkg' > "$RUN"; chown root:www "$RUN" 2>/dev/null || true; chmod 644 "$RUN"
{
    logger -t bulwark-updates "pkg upgrade iniciado por el panel"
    ASSUME_ALWAYS_YES=yes; export ASSUME_ALWAYS_YES
    pkg update -q >/dev/null 2>&1
    pkg upgrade -y > "$LOG" 2>&1; RC=$?
    N=$(grep -cE 'Upgrading|Installing|Reinstalling' "$LOG" 2>/dev/null)
    logger -t bulwark-updates "pkg upgrade terminado (rc=$RC)"
    printf 'pkg|%s|%s|%s' "$RC" "$(date +%s)" "$N" > "$RES"; chown root:www "$RES" "$LOG" 2>/dev/null || true; chmod 644 "$RES" "$LOG"
    /usr/local/bulwark/bin/sys_update_check.sh >/dev/null 2>&1
    rm -f "$RUN"
} >/dev/null 2>&1 &
exit 0

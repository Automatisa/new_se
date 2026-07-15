#!/bin/sh
# pkg_upgrade.sh — Actualiza TODOS los paquetes pkg. Lo llama el panel (updates) vía doas (admin,
# con confirmación). Sin args. Corre EN SEGUNDO PLANO (puede tardar minutos) y refresca la caché.
OUT_DIR="/var/sentora/updates"; RUN="$OUT_DIR/running"; LOG="$OUT_DIR/last_action.log"
mkdir -p "$OUT_DIR"
{
    printf 'pkg' > "$RUN"; chown root:www "$RUN" 2>/dev/null; chmod 644 "$RUN"
    logger -t sentora-updates "pkg upgrade iniciado por el panel"
    ASSUME_ALWAYS_YES=yes; export ASSUME_ALWAYS_YES
    pkg update -q >/dev/null 2>&1
    pkg upgrade -y > "$LOG" 2>&1
    logger -t sentora-updates "pkg upgrade terminado (rc=$?)"
    chown root:www "$LOG" 2>/dev/null; chmod 644 "$LOG"
    /usr/local/sentora/bin/sys_update_check.sh >/dev/null 2>&1
    rm -f "$RUN"
} >/dev/null 2>&1 &
exit 0

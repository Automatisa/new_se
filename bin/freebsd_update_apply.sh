#!/bin/sh
# freebsd_update_apply.sh — Aplica parches de SEGURIDAD de la base FreeBSD (freebsd-update). Lo llama
# el panel (updates) vía doas (admin, confirmación). Sin args. Segundo plano + refresco de caché.
# Nota: userland en vivo; si tocan kernel puede requerir reinicio.
OUT_DIR="/var/sentora/updates"; RUN="$OUT_DIR/running"; LOG="$OUT_DIR/last_action.log"
mkdir -p "$OUT_DIR"
{
    printf 'base' > "$RUN"; chown root:www "$RUN" 2>/dev/null; chmod 644 "$RUN"
    logger -t sentora-updates "freebsd-update install iniciado por el panel"
    env PAGER=cat freebsd-update --not-running-from-cron fetch install > "$LOG" 2>&1
    logger -t sentora-updates "freebsd-update install terminado (rc=$?)"
    chown root:www "$LOG" 2>/dev/null; chmod 644 "$LOG"
    /usr/local/sentora/bin/sys_update_check.sh >/dev/null 2>&1
    rm -f "$RUN"
} >/dev/null 2>&1 &
exit 0

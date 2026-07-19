#!/bin/sh
# freebsd_update_apply.sh — Aplica parches de SEGURIDAD de la base FreeBSD. Panel vía doas (admin).
# Marcador running SÍNCRONO + resultado. Userland en vivo; kernel puede requerir reinicio.
OUT_DIR="/var/bulwark/updates"; RUN="$OUT_DIR/running"; LOG="$OUT_DIR/last_action.log"; RES="$OUT_DIR/last_result"
mkdir -p "$OUT_DIR"
printf 'base' > "$RUN"; chown root:www "$RUN" 2>/dev/null || true; chmod 644 "$RUN"
{
    logger -t bulwark-updates "freebsd-update install iniciado por el panel"
    env PAGER=cat freebsd-update --not-running-from-cron fetch install > "$LOG" 2>&1; RC=$?
    logger -t bulwark-updates "freebsd-update install terminado (rc=$RC)"
    printf 'base|%s|%s|' "$RC" "$(date +%s)" > "$RES"; chown root:www "$RES" "$LOG" 2>/dev/null || true; chmod 644 "$RES" "$LOG"
    /usr/local/bulwark/bin/sys_update_check.sh >/dev/null 2>&1
    rm -f "$RUN"
} >/dev/null 2>&1 &
exit 0

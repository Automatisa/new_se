#!/bin/sh
# sys_update_check.sh — Comprueba actualizaciones del sistema FreeBSD (paquetes pkg, avisos de
# seguridad VuXML y parches de la base) y escribe el resultado en un JSON de caché que lee el panel
# (módulo updates). Así la página NO ejecuta red: solo lee este fichero.
#
# El trabajo pesado va en 2º plano; el marcador 'running' se crea ANTES de forkar (síncrono) para
# que el panel vea "en curso" nada más volver de la acción (evita la carrera del autorrefresco).
# Lo ejecuta: el daemon diario (root) y el botón "Comprobar ahora" del panel (www vía doas). Sin args.

OUT_DIR="/var/bulwark/updates"
OUT="$OUT_DIR/status.json"
RUN="$OUT_DIR/running"
mkdir -p "$OUT_DIR"; chown root:www "$OUT_DIR" 2>/dev/null || true; chmod 755 "$OUT_DIR"

# Marcador de progreso SÍNCRONO (antes del &): garantiza que exista al volver la petición.
printf 'check' > "$RUN"; chown root:www "$RUN" 2>/dev/null || true; chmod 644 "$RUN"

{
    pkg update -q >/dev/null 2>&1
    PKG_N=$(pkg upgrade -n 2>/dev/null | grep -cE '^[[:space:]]+[^[:space:]].*->' )
    PKG_LIST=$(pkg upgrade -n 2>/dev/null | grep -E '^[[:space:]]+[^[:space:]].*->' | sed -E 's/^[[:space:]]+//' | head -40)
    AUDIT_RAW=$(pkg audit -F -q 2>/dev/null)
    AUDIT_N=$(printf '%s\n' "$AUDIT_RAW" | grep -c 'is vulnerable')
    AUDIT_LIST=$(printf '%s\n' "$AUDIT_RAW" | grep 'is vulnerable' | sed -E 's/ is vulnerable:.*//' | head -40)
    BASE_OUT=$(env PAGER=cat freebsd-update --not-running-from-cron fetch 2>&1)
    if printf '%s' "$BASE_OUT" | grep -qiE 'No updates needed|No updates are available'; then BASE_N=0
    elif printf '%s' "$BASE_OUT" | grep -qiE 'following files will be (updated|removed)|following files will be added|to be updated'; then BASE_N=1
    else BASE_N=0; fi

    # --- Panel (fork git): commits pendientes respecto al remoto + changelog ---
    PANEL_BEHIND=0; PANEL_LOCAL=""; PANEL_LOG=""
    if [ -d /usr/local/bulwark/.git ]; then
        git -C /usr/local/bulwark fetch --quiet 2>/dev/null
        PANEL_LOCAL=$(git -C /usr/local/bulwark rev-parse --short HEAD 2>/dev/null)
        PANEL_BEHIND=$(git -C /usr/local/bulwark rev-list --count HEAD..@{u} 2>/dev/null)
        PANEL_LOG=$(git -C /usr/local/bulwark log --oneline --no-decorate HEAD..@{u} 2>/dev/null | head -25)
    fi

    json_escape() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' | awk 'BEGIN{ORS="\\n"}{print}'; }
    TMP="$OUT.tmp.$$"
    {
        printf '{\n'
        printf '  "checked_ts": %s,\n' "$(date +%s)"
        printf '  "pkg_updatable": %s,\n' "${PKG_N:-0}"
        printf '  "pkg_list": "%s",\n'    "$(json_escape "$PKG_LIST")"
        printf '  "pkg_audit": %s,\n'     "${AUDIT_N:-0}"
        printf '  "audit_list": "%s",\n'  "$(json_escape "$AUDIT_LIST")"
        printf '  "base_patches": %s,\n'  "${BASE_N:-0}"
        printf '  "panel_local": "%s",\n' "$(json_escape "$PANEL_LOCAL")"
        printf '  "panel_behind": %s,\n'  "${PANEL_BEHIND:-0}"
        printf '  "panel_log": "%s"\n'    "$(json_escape "$PANEL_LOG")"
        printf '}\n'
    } > "$TMP"
    mv "$TMP" "$OUT"; chown root:www "$OUT" 2>/dev/null || true; chmod 644 "$OUT"

    # Clasificación de paquetes gestionados (pinning): refresca pkgpins.json en el mismo chequeo.
    [ -x /usr/local/bulwark/bin/pkg_pin.sh ] && /usr/local/bulwark/bin/pkg_pin.sh check >/dev/null 2>&1

    rm -f "$RUN"
} >/dev/null 2>&1 &

exit 0

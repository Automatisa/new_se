#!/bin/sh
# sys_update_check.sh — Comprueba actualizaciones del sistema FreeBSD (paquetes pkg, avisos de
# seguridad VuXML y parches de la base) y escribe el resultado en un JSON de caché que lee el panel
# (módulo updates). Así la página NO ejecuta red: solo lee este fichero.
#
# El trabajo se hace EN SEGUNDO PLANO (tarda ~15 s) y el script vuelve enseguida, para no bloquear
# la petición HTTP del panel. Marcador de progreso en $OUT_DIR/running mientras corre.
#
# Lo ejecuta: el daemon diario (root) y el botón "Comprobar ahora" del panel (www vía doas). Sin args.

OUT_DIR="/var/sentora/updates"
OUT="$OUT_DIR/status.json"
RUN="$OUT_DIR/running"
mkdir -p "$OUT_DIR"
chown root:www "$OUT_DIR" 2>/dev/null || true
chmod 755 "$OUT_DIR"

# Todo el trabajo pesado va a segundo plano con la salida a /dev/null (no retiene el pipe de doas).
{
    printf 'check' > "$RUN"; chown root:www "$RUN" 2>/dev/null; chmod 644 "$RUN"

    pkg update -q >/dev/null 2>&1
    PKG_N=$(pkg upgrade -n 2>/dev/null | grep -cE '^[[:space:]]+[^[:space:]].*->' )
    PKG_LIST=$(pkg upgrade -n 2>/dev/null | grep -E '^[[:space:]]+[^[:space:]].*->' | sed -E 's/^[[:space:]]+//' | head -40)

    AUDIT_RAW=$(pkg audit -F -q 2>/dev/null)
    AUDIT_N=$(printf '%s\n' "$AUDIT_RAW" | grep -c 'is vulnerable')
    AUDIT_LIST=$(printf '%s\n' "$AUDIT_RAW" | grep 'is vulnerable' | sed -E 's/ is vulnerable:.*//' | head -40)

    BASE_OUT=$(env PAGER=cat freebsd-update --not-running-from-cron fetch 2>&1)
    if printf '%s' "$BASE_OUT" | grep -qiE 'No updates needed|No updates are available'; then
        BASE_N=0
    elif printf '%s' "$BASE_OUT" | grep -qiE 'following files will be (updated|removed)|following files will be added|to be updated'; then
        BASE_N=1
    else
        BASE_N=0
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
        printf '  "base_patches": %s\n'   "${BASE_N:-0}"
        printf '}\n'
    } > "$TMP"
    mv "$TMP" "$OUT"
    chown root:www "$OUT" 2>/dev/null || true
    chmod 644 "$OUT"

    rm -f "$RUN"
} >/dev/null 2>&1 &

exit 0

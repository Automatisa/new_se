#!/bin/sh
# panel_update.sh — Actualiza el PANEL (git pull --ff-only) desde el remoto. Solo el admin lo lanza
# (doas). Guarda el HEAD previo para rollback. 2º plano + resultado. --ff-only es SEGURO: si hay
# cambios locales o no es fast-forward, aborta sin dejar estado parcial y se reporta el error.
# NOTA: las migraciones de BD NO son automáticas (el fork no tiene migraciones incrementales);
# si una versión requiere cambios de esquema, hay que aplicarlos aparte.
OUT_DIR="/var/bulwark/updates"; RUN="$OUT_DIR/running"; LOG="$OUT_DIR/last_action.log"; RES="$OUT_DIR/last_result"; PREV="$OUT_DIR/panel_prev_head"
REPO="/usr/local/bulwark"
mkdir -p "$OUT_DIR"
[ -d "$REPO/.git" ] || exit 0
printf 'panel' > "$RUN"; chown root:www "$RUN" 2>/dev/null || true; chmod 644 "$RUN"
{
    logger -t bulwark-updates "panel update (git pull) iniciado"
    git -C "$REPO" rev-parse HEAD > "$PREV" 2>/dev/null
    echo "== git fetch ==" > "$LOG"
    git -C "$REPO" fetch --quiet >> "$LOG" 2>&1
    echo "== git pull --ff-only ==" >> "$LOG"
    git -C "$REPO" pull --ff-only >> "$LOG" 2>&1; RC=$?
    if [ "$RC" -eq 0 ]; then
        echo "== regenerar doas.conf desde privilege.class.php ==" >> "$LOG"
        # Usuario real del panel: se lee del pool FPM (no se hardcodea), así un rename del usuario
        # del panel se propaga solo a las reglas doas.
        PANEL_USER=$(awk -F= '/^[[:space:]]*user[[:space:]]*=/{gsub(/[[:space:]]/,"",$2);print $2;exit}' /usr/local/etc/php-fpm.d/www.conf 2>/dev/null)
        [ -n "$PANEL_USER" ] || PANEL_USER=www
        php -r 'require "'"$REPO"'/dryden/sys/privilege.class.php"; echo privilege::doasRules("'"$PANEL_USER"'");' > "$OUT_DIR/doas.conf.new" 2>>"$LOG"
        # Guarda de sanidad: solo reemplazar si la generación tiene un nº razonable de reglas.
        if [ "$(grep -c "^permit nopass $PANEL_USER " "$OUT_DIR/doas.conf.new" 2>/dev/null)" -ge 40 ]; then
            install -o root -g wheel -m 600 "$OUT_DIR/doas.conf.new" /usr/local/etc/doas.conf
            echo "doas.conf regenerado" >> "$LOG"
        else
            echo "AVISO: doas.conf generado sospechoso; se mantiene el anterior" >> "$LOG"
        fi
        rm -f "$OUT_DIR/doas.conf.new"

        echo "== migraciones BD/config ==" >> "$LOG"
        if [ -f "$REPO/bin/db_migrate.php" ]; then
            php "$REPO/bin/db_migrate.php" >> "$LOG" 2>&1 || RC=$?
        fi
        echo "== fix permisos + reload php-fpm ==" >> "$LOG"
        [ -f "$REPO/bin/fix_permissions.php" ] && php "$REPO/bin/fix_permissions.php" >> "$LOG" 2>&1
        service php_fpm reload >> "$LOG" 2>&1
    fi
    logger -t bulwark-updates "panel update terminado (rc=$RC)"
    printf 'panel|%s|%s|' "$RC" "$(date +%s)" > "$RES"; chown root:www "$RES" "$LOG" 2>/dev/null || true; chmod 644 "$RES" "$LOG"
    /usr/local/bulwark/bin/sys_update_check.sh >/dev/null 2>&1
    rm -f "$RUN"
} >/dev/null 2>&1 &
exit 0

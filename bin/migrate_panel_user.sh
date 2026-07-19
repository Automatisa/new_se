#!/bin/sh
# migrate_panel_user.sh — Migra el panel a su PROPIO usuario del sistema, separándolo del genérico
# 'www' (Apache/estáticos). Objetivo de seguridad:
#   - Los SECRETOS del panel (db.php, security.php, backup.key, redis.pass) pasan a root:PANEL_USER
#     640 -> 'www' (Apache y cualquier contexto web genérico) YA NO puede leer la contraseña de BD ni
#     la clave criptográfica.
#   - doas (escalada a root) se concede SOLO a PANEL_USER -> un fallo en contexto 'www' ya no puede
#     ejecutar acciones root del panel.
# PANEL_USER es MIEMBRO del grupo 'www' -> el panel sigue leyendo todo lo group-www (resultados de
# los wrappers, hostdata, etc.) sin reescribir decenas de scripts.
# El DAEMON del panel ya corre como root (cron de /etc/crontab), no se toca.
# Idempotente. Ejecutar como root en el servidor. Rollback al final del fichero.
set -eu

# --- Nombre del usuario del sistema con el que corre el panel. Es el nombre del fork: 'bulwark'.
#     Puede fijarse por entorno: PANEL_USER=otro sh migrate_panel_user.sh (para renombrar, re-ejecutar
#     con el nuevo nombre y luego 'pw userdel' el anterior).
PANEL_USER="${PANEL_USER:-bulwark}"

PANEL_PATH="${PANEL_PATH:-/usr/local/bulwark}"
PANEL_DATA="${PANEL_DATA:-/var/bulwark}"
FPM_POOL="/usr/local/etc/php-fpm.d/www.conf"

info() { printf '\033[36m[panel-user]\033[0m %s\n' "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "Ejecuta como root."; exit 1; }
if ! echo "$PANEL_USER" | grep -qE '^[a-z_][a-z0-9_-]{0,31}$'; then
    echo "Nombre de usuario inválido: $PANEL_USER"; exit 1
fi

# 1. Usuario del panel (nologin) + miembro del grupo www.
if ! pw usershow "$PANEL_USER" >/dev/null 2>&1; then
    info "Creando usuario $PANEL_USER (nologin)..."
    pw useradd "$PANEL_USER" -d /nonexistent -s /usr/sbin/nologin -c "Bulwark panel process"
fi
pw groupmod www -m "$PANEL_USER"   # añadir al grupo www (lectura de lo compartido)
info "Grupos de $PANEL_USER: $(id -Gn "$PANEL_USER")"

# 2. Pool PHP-FPM del panel -> corre como PANEL_USER (el socket sigue siendo www.sock, listen.owner
#    www para que Apache conecte; NO se toca el vhost de Apache).
if [ -f "$FPM_POOL" ]; then
    info "Pool del panel -> user/group $PANEL_USER"
    sed -i '' \
        -e "s/^[[:space:]]*user[[:space:]]*=.*/user  = ${PANEL_USER}/" \
        -e "s/^[[:space:]]*group[[:space:]]*=.*/group = ${PANEL_USER}/" \
        "$FPM_POOL"
    # listen.owner/group deben seguir siendo www (Apache): asegúralo por si acaso.
    grep -q '^listen.owner' "$FPM_POOL" || echo 'listen.owner = www' >> "$FPM_POOL"
    grep -q '^listen.group' "$FPM_POOL" || echo 'listen.group = www' >> "$FPM_POOL"
fi

# 3. SECRETOS -> root:PANEL_USER 640 (www pierde la lectura). Solo chown/chmod: NUNCA se reescribe
#    el contenido (db.php conserva la contraseña real). El daemon los lee como root.
info "Secretos -> root:${PANEL_USER} 640"
for f in cnf/db.php cnf/security.php cnf/backup.key cnf/redis.pass; do
    if [ -f "$PANEL_PATH/$f" ]; then
        chown "root:${PANEL_USER}" "$PANEL_PATH/$f"
        chmod 640 "$PANEL_PATH/$f"
    fi
done

# 4. Runtime que ESCRIBE el panel -> PANEL_USER (grupo www para compartir lectura).
info "Runtime del panel -> ${PANEL_USER}"
if [ -d "$PANEL_DATA/sessions" ]; then
    chown "${PANEL_USER}:www" "$PANEL_DATA/sessions"
    # Purgar sesiones existentes: tras cambiar el usuario del panel, las sesiones viejas pueden
    # quedar indecodificables ("Failed to decode session object") -> el login da "Sesión expirada".
    # Borrarlas fuerza un re-login limpio (aceptable en una migración). Ver incidencia login 2026-07-19.
    rm -f "$PANEL_DATA"/sessions/sess_* 2>/dev/null || true
fi
[ -d "$PANEL_PATH/etc/tmp" ]         && chown -R "${PANEL_USER}:www" "$PANEL_PATH/etc/tmp"
if [ -f "$PANEL_DATA/logs/bulwark.log" ]; then
    chown "${PANEL_USER}:www" "$PANEL_DATA/logs/bulwark.log"; chmod 660 "$PANEL_DATA/logs/bulwark.log"
fi
# Directorio de ficheros de petición privilegiada: root:PANEL_USER 770 (el panel escribe las req,
# los wrappers root leen; www ya no lo necesita).
[ -d "$PANEL_DATA/run" ]             && { chown "root:${PANEL_USER}" "$PANEL_DATA/run"; chmod 770 "$PANEL_DATA/run"; }

# 5. Configs que el panel escribe DIRECTAMENTE (file_put_contents en contexto web). No son secretos:
#    se dejan legibles por sus consumidores (rspamd/clamav). El panel (dueño) puede reescribirlas.
info "Configs escritas por el panel -> ${PANEL_USER}:www"
for f in rspamd/ratelimit.conf rspamd/options.inc rspamd/phishing.conf \
         mail_limits/limit mail_limits/whitelist clamav/antivirus.conf; do
    [ -e "$PANEL_DATA/$f" ] && chown "${PANEL_USER}:www" "$PANEL_DATA/$f"
done
# Dirs donde el panel (re)crea esos ficheros: setgid www + escritura de grupo.
for d in rspamd mail_limits clamav cron; do
    [ -d "$PANEL_DATA/$d" ] && { chgrp www "$PANEL_DATA/$d"; chmod g+ws "$PANEL_DATA/$d"; }
done

# 6. doas -> PANEL_USER (fuente única: privilege.class.php). Se valida antes de instalar.
info "Regenerando doas.conf para ${PANEL_USER}"
TMP="$(mktemp)"
php -r "require '${PANEL_PATH}/dryden/sys/privilege.class.php'; echo privilege::doasRules('${PANEL_USER}');" > "$TMP"
if [ "$(grep -c "^permit nopass ${PANEL_USER} " "$TMP")" -ge 30 ] && doas -C "$TMP" >/dev/null 2>&1; then
    install -o root -g wheel -m 600 "$TMP" /usr/local/etc/doas.conf
    info "doas.conf regenerado y validado."
else
    echo "AVISO: doas.conf generado parece inválido; NO se instala (se mantiene el anterior)."
    rm -f "$TMP"; exit 1
fi
rm -f "$TMP"

# 7. Aplicar: recargar php-fpm (el master respawnea los workers del panel como PANEL_USER).
info "Recargando php-fpm..."
service php_fpm reload 2>/dev/null || service php_fpm restart

info "Hecho. El panel corre ahora como ${PANEL_USER}. Verifica: login, una acción privilegiada y logs."
# --- ROLLBACK (si algo va mal) --------------------------------------------------------------------
#   sed -i '' -e 's/^user  = '"$PANEL_USER"'/user  = www/' -e 's/^group = '"$PANEL_USER"'/group = www/' $FPM_POOL
#   for f in cnf/db.php cnf/security.php cnf/backup.key cnf/redis.pass; do chown root:www $PANEL_PATH/$f; done
#   php -r "require '$PANEL_PATH/dryden/sys/privilege.class.php'; echo privilege::doasRules('www');" > /usr/local/etc/doas.conf
#   service php_fpm reload

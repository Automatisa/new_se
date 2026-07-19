#!/bin/sh
# install_on_server.sh <NN> <ruta_repo_local> — Instala en el SERVIDOR una versión de PHP compilada
# con PREFIX propio y la deja operativa como servicio FPM independiente, lista para que el panel
# enrute dominios a ella. NO toca el PHP del sistema/panel. Lo invoca 03_deploy.sh (o a mano).
#
#   sh install_on_server.sh 83 /usr/local/php-repos/php83
set -eu

V="${1:?Uso: install_on_server.sh <NN: 81|82|83> <ruta_repo_local>}"
REPO_LOCAL="${2:?Falta la ruta local del repo pkg}"

PREFIX="/usr/local/php${V}"
VER_DOT="${V%?}.${V#?}"
REPO_CONF="/usr/local/etc/pkg/repos/bulwark-php${V}.conf"
FPM_BIN="${PREFIX}/sbin/php-fpm"
FPM_CONF="${PREFIX}/etc/php-fpm.conf"
POOL_DIR="${PREFIX}/etc/php-fpm.d"
PHP_INI="${PREFIX}/etc/php.ini"
PIDFILE="/var/run/php${V}-fpm.pid"
RC="/usr/local/etc/rc.d/php${V}_fpm"
SOCKDIR="/var/run/php-fpm"
WRAPPER="/usr/local/bulwark/bin/bulwark_mail_limit.sh -t -i"
TIMEZONE="$(cat /var/db/zoneinfo 2>/dev/null || echo UTC)"

info() { printf '\033[36m[php%s]\033[0m %s\n' "$V" "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "Ejecuta como root."; exit 1; }
[ -d "$REPO_LOCAL" ] || { echo "No existe el repo local: $REPO_LOCAL"; exit 1; }

# 1. Registrar el repo pkg local (file://) y instalar core + extensiones bajo el PREFIX.
info "Registrando repo local en $REPO_CONF"
cat > "$REPO_CONF" <<CONF
bulwark-php${V}: {
    url: "file://${REPO_LOCAL}",
    enabled: yes,
    priority: 100
}
CONF
info "Instalando php${V} + extensiones en ${PREFIX}..."
pkg update >/dev/null 2>&1 || true
# Instalar por NOMBRES EXPLÍCITOS de NUESTRO repo (no un glob 'php83*': eso cazaría también los
# php83-* OFICIALES de FreeBSD, que instalan en /usr/local y chocan con php84). Nuestro repo tiene
# prioridad alta y SOLO contiene php${V}-* (reubicados), así que esos nombres se sirven de aquí;
# sus dependencias (png, curl...) se resuelven del repo oficial (ya están en el servidor).
NAMES=$(pkg rquery -r "bulwark-php${V}" '%n' 2>/dev/null | sort -u)
[ -n "$NAMES" ] || { echo "ERROR: el repo bulwark-php${V} no lista paquetes."; exit 1; }
info "Paquetes a instalar ($(echo $NAMES | wc -w | tr -d ' ')): $(echo $NAMES | tr '\n' ' ')"
pkg install -y $NAMES
[ -x "$FPM_BIN" ] || { echo "ERROR: no existe $FPM_BIN tras instalar."; exit 1; }

# 2. php.ini de la versión (production + timezone + expose_php off + wrapper de correo).
[ -f "$PHP_INI" ] || cp "${PREFIX}/etc/php.ini-production" "$PHP_INI"
sed -i '' \
    -e "s|^;\{0,1\}[[:space:]]*date.timezone.*|date.timezone = ${TIMEZONE}|" \
    -e "s|^expose_php = On|expose_php = Off|" \
    "$PHP_INI"
if grep -qE '^;?[[:space:]]*sendmail_path[[:space:]]*=' "$PHP_INI"; then
    sed -i '' -e "s|^;\{0,1\}[[:space:]]*sendmail_path[[:space:]]*=.*|sendmail_path = \"${WRAPPER}\"|" "$PHP_INI"
else
    printf 'sendmail_path = "%s"\n' "$WRAPPER" >> "$PHP_INI"
fi

# 3. php-fpm.conf: pid propio + incluye los pools por dominio que escribe el panel.
mkdir -p "$POOL_DIR" "$SOCKDIR" /var/log/php-fpm
# Quitar el pool 'www' por defecto del paquete (escucha en 127.0.0.1:9000 como www; nadie lo usa,
# el panel enruta por sockets unix). Dejarlo sería un listener FastCGI sobrante.
rm -f "${POOL_DIR}/www.conf" "${POOL_DIR}/www.conf.default"
cat > "$FPM_CONF" <<CONF
[global]
pid = ${PIDFILE}
error_log = /var/log/php-fpm/php${V}-fpm.log
daemonize = yes
include = ${POOL_DIR}/*.conf
CONF

# 4. Pool "keepalive": el master necesita AL MENOS un pool para arrancar. Así php${V}_fpm queda
#    vivo aunque todavía no haya ningún dominio asignado a esta versión, y los reload posteriores
#    del panel (al asignar el primer dominio) funcionan y crean el socket. El panel solo gestiona
#    los pools bulwark_*.conf, así que NO borra este keepalive.
cat > "${POOL_DIR}/00-keepalive.conf" <<CONF
[keepalive]
user = www
group = www
listen = ${SOCKDIR}/php${V}_keepalive.sock
listen.owner = www
listen.group = www
listen.mode = 0660
pm = ondemand
pm.max_children = 1
pm.process_idle_timeout = 10s
CONF

# 5. Servicio rc.d independiente php${V}_fpm.
info "Creando servicio rc.d ${RC}"
cat > "$RC" <<RCEOF
#!/bin/sh
# PROVIDE: php${V}_fpm
# REQUIRE: LOGIN
# KEYWORD: shutdown
. /etc/rc.subr
name="php${V}_fpm"
rcvar="php${V}_fpm_enable"
command="${FPM_BIN}"
command_args="--fpm-config ${FPM_CONF} --pid ${PIDFILE}"
pidfile="${PIDFILE}"
# 'reload' = SIGUSR2 (recarga graceful de pools sin cortar peticiones). Lo usa el panel
# (privilege phpfpm_reload_svc) al asignar/mover dominios de versión.
extra_commands="reload"
sig_reload=USR2
load_rc_config \$name
: \${php${V}_fpm_enable:=no}
run_rc_command "\$1"
RCEOF
chmod 555 "$RC"
sysrc "php${V}_fpm_enable=YES" >/dev/null
service "php${V}_fpm" restart 2>/dev/null || service "php${V}_fpm" start

# 6. Verificación.
info "Instalada: $(${PREFIX}/bin/php -v 2>/dev/null | head -1)"
if service "php${V}_fpm" status >/dev/null 2>&1; then
    info "Servicio php${V}_fpm ACTIVO. El panel autodetecta la versión y aparecerá en Dominios -> PHP."
else
    echo "AVISO: php${V}_fpm no arrancó — revisa /var/log/php-fpm/php${V}-fpm.log"
fi

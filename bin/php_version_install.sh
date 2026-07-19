#!/bin/sh
# php_version_install.sh — Instala en el SERVIDOR una versión de PHP compilada con PREFIX propio
# (generada por php_multi_build.sh) y la deja operativa como servicio FPM independiente, lista para
# que el panel enrute dominios a ella. NO toca el PHP del sistema/panel.
#
#   Uso:  sh php_version_install.sh 81 [URL_o_ruta_del_repo/tarball]
#
#   - 81 = PHP 8.1
#   - 2º argumento (opcional): URL del tarball del repo (GitHub Release) o ruta local a un repo pkg
#     ya extraído. Si se omite, se usa REPO_BASE_URL/php${V}-fbsd15.txz.

set -eu

V="${1:?Uso: php_version_install.sh <ver sin punto: 81|83|84> [url_o_repo]}"
SRC="${2:-}"
REPO_BASE_URL="${REPO_BASE_URL:-https://github.com/Automatisa/new_se/releases/download/php-repo}"

PREFIX="/usr/local/php${V}"
VER_DOT="${V%?}.${V#?}"                       # 81 -> 8.1
REPO_LOCAL="/usr/local/poudriere-repos/php${V}"
REPO_CONF="/usr/local/etc/pkg/repos/bulwark-php${V}.conf"
FPM_BIN="${PREFIX}/sbin/php-fpm"
FPM_CONF="${PREFIX}/etc/php-fpm.conf"
POOL_DIR="${PREFIX}/etc/php-fpm.d"
PHP_INI="${PREFIX}/etc/php.ini"
PIDFILE="/var/run/php${V}-fpm.pid"
RC="/usr/local/etc/rc.d/php${V}_fpm"
WRAPPER="/usr/local/bulwark/bin/bulwark_mail_limit.sh -t -i"
TIMEZONE="$(cat /var/db/zoneinfo 2>/dev/null || echo UTC)"

info() { printf '\033[36m[php%s]\033[0m %s\n' "$V" "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "Ejecuta como root."; exit 1; }

# ---------------------------------------------------------------------------
# 1. Obtener el repo pkg (tarball remoto o ruta local ya extraída)
# ---------------------------------------------------------------------------
if [ -n "$SRC" ] && [ -d "$SRC" ]; then
    REPO_LOCAL="$SRC"                          # ya es un repo pkg extraído
else
    URL="${SRC:-${REPO_BASE_URL}/php${V}-fbsd15.txz}"
    info "Descargando repo: $URL"
    mkdir -p "$REPO_LOCAL"
    tmp="$(mktemp -t php${V}repo)"
    fetch -o "$tmp" "$URL"
    tar xaf "$tmp" -C "$REPO_LOCAL" --strip-components 1
    rm -f "$tmp"
fi

# ---------------------------------------------------------------------------
# 2. Registrar el repo y instalar core + extensiones bajo el PREFIX
# ---------------------------------------------------------------------------
info "Registrando repo pkg local en $REPO_CONF"
cat > "$REPO_CONF" <<CONF
bulwark-php${V}: {
    url: "file://${REPO_LOCAL}",
    enabled: yes,
    priority: 100
}
CONF

info "Instalando php${V} + extensiones (a ${PREFIX})..."
# Instala todo lo del repo de esa versión (core + extensiones compiladas con su PREFIX).
pkg install -yr "bulwark-php${V}" -g "php${V}*"

[ -x "$FPM_BIN" ] || { echo "ERROR: no existe $FPM_BIN tras la instalación."; exit 1; }

# ---------------------------------------------------------------------------
# 3. php.ini de la versión (base production + timezone + sendmail wrapper)
# ---------------------------------------------------------------------------
if [ ! -f "$PHP_INI" ]; then
    cp "${PREFIX}/etc/php.ini-production" "$PHP_INI"
fi
sed -i '' \
    -e "s|^;\{0,1\}[[:space:]]*date.timezone.*|date.timezone = ${TIMEZONE}|" \
    -e "s|^expose_php = On|expose_php = Off|" \
    "$PHP_INI"
if grep -qE '^;?[[:space:]]*sendmail_path[[:space:]]*=' "$PHP_INI"; then
    sed -i '' -e "s|^;\{0,1\}[[:space:]]*sendmail_path[[:space:]]*=.*|sendmail_path = \"${WRAPPER}\"|" "$PHP_INI"
else
    printf 'sendmail_path = "%s"\n' "$WRAPPER" >> "$PHP_INI"
fi

# ---------------------------------------------------------------------------
# 4. php-fpm.conf de la versión (pid propio, incluye pools del panel de esta versión)
# ---------------------------------------------------------------------------
mkdir -p "$POOL_DIR" /var/log/php-fpm
cat > "$FPM_CONF" <<CONF
[global]
pid = ${PIDFILE}
error_log = /var/log/php-fpm/php${V}-fpm.log
daemonize = yes
include = ${POOL_DIR}/*.conf
CONF

# ---------------------------------------------------------------------------
# 5. Servicio rc.d independiente php${V}_fpm
# ---------------------------------------------------------------------------
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
load_rc_config \$name
: \${php${V}_fpm_enable:=no}
run_rc_command "\$1"
RCEOF
chmod 555 "$RC"

sysrc "php${V}_fpm_enable=YES" >/dev/null
service "php${V}_fpm" restart 2>/dev/null || service "php${V}_fpm" start

# ---------------------------------------------------------------------------
# 6. Verificación
# ---------------------------------------------------------------------------
info "Versión instalada: $(${PREFIX}/bin/php -v 2>/dev/null | head -1)"
if service "php${V}_fpm" status >/dev/null 2>&1; then
    info "Servicio php${V}_fpm ACTIVO. Pools en ${POOL_DIR}/ (los genera el panel por dominio)."
else
    echo "AVISO: php${V}_fpm no arrancó — revisa /var/log/php-fpm/php${V}-fpm.log"
fi
info "Registra la versión '${VER_DOT}' en el ajuste php_versions_available del panel para que aparezca en el selector."

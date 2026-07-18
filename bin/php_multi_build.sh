#!/bin/sh
# php_multi_build.sh — Compila MÚLTIPLES versiones de PHP en FreeBSD, cada una en su propio PREFIX,
# usando poudriere. Produce, por versión, un repositorio pkg instalable (y opcionalmente un tarball
# para publicar en GitHub Releases / servidor propio).
#
# CONTEXTO (bug FreeBSD 224409): los paquetes pkg estándar php8x instalan todos en /usr/local
# (/usr/local/bin/php, /usr/local/sbin/php-fpm...) y CHOCAN entre sí -> no coexisten. La única forma
# limpia de tener varias versiones a la vez es compilarlas con PREFIX distinto por versión. poudriere
# permite fijar ese PREFIX por "set" en un make.conf, y compila core + FPM + extensiones de forma
# coherente dentro de una jail limpia (deps reproducibles, misma ABI).
#
# EJECUTAR EN UNA MÁQUINA DE BUILD (VM/host dedicado con la MISMA ABI que producción:
# FreeBSD:15:amd64). NO en el servidor de hosting. Tras el build, se instala en los servidores con
# php_version_install.sh (o pkg add del repo).
#
#   Uso:   sh php_multi_build.sh "81 83 84"
#   (lista de versiones sin punto: 81 = PHP 8.1, 84 = PHP 8.4, ...)

set -eu

VERSIONS="${1:-81 83 84}"
JAIL="fbsd15"
JAIL_VERSION="15.0-RELEASE"
ARCH="amd64"
PORTS_TREE="default"
POUD_D="/usr/local/etc/poudriere.d"
PKG_OUT="/usr/local/poudriere/data/packages"   # repos generados por poudriere
DIST_DIR="${DIST_DIR:-/root/php-dist}"          # tarballs listos para publicar

# Extensiones estándar para un hosting web (ajusta a tu gusto). Se prefijan por versión más abajo.
EXT_LIST="bcmath bz2 ctype curl dom exif fileinfo filter gd gettext iconv intl mbstring \
mysqli opcache openssl pdo pdo_mysql phar posix session simplexml soap sockets sqlite3 \
tokenizer xml xmlreader xmlwriter zip zlib"

info() { printf '\033[36m[php-build]\033[0m %s\n' "$*"; }

# ---------------------------------------------------------------------------
# 0. Prerrequisitos: poudriere + jail + árbol de ports
# ---------------------------------------------------------------------------
[ "$(id -u)" -eq 0 ] || { echo "Ejecuta como root."; exit 1; }

if ! command -v poudriere >/dev/null 2>&1; then
    info "Instalando poudriere..."
    pkg install -y poudriere-devel || pkg install -y poudriere
fi

if [ ! -f /usr/local/etc/poudriere.conf ]; then
    info "Configurando poudriere.conf mínimo (ZFS deshabilitado, distfiles compartidos)..."
    cat > /usr/local/etc/poudriere.conf <<'CONF'
NO_ZFS=yes
FREEBSD_HOST=https://download.freebsd.org
RESOLV_CONF=/etc/resolv.conf
BASEFS=/usr/local/poudriere
DISTFILES_CACHE=/usr/ports/distfiles
CHECK_CHANGED_OPTIONS=verbose
CCACHE_DIR=/var/cache/ccache
PARALLEL_JOBS=4
CONF
fi

if ! poudriere jail -l | awk '{print $1}' | grep -qx "$JAIL"; then
    info "Creando jail $JAIL ($JAIL_VERSION $ARCH)..."
    poudriere jail -c -j "$JAIL" -v "$JAIL_VERSION" -a "$ARCH"
fi

if ! poudriere ports -l | awk '{print $1}' | grep -qx "$PORTS_TREE"; then
    info "Creando árbol de ports '$PORTS_TREE' (git)..."
    poudriere ports -c -p "$PORTS_TREE" -m git
fi

mkdir -p "$POUD_D" "$DIST_DIR"

# ---------------------------------------------------------------------------
# 1. Por cada versión: make.conf del set con PREFIX propio + lista de paquetes + build
# ---------------------------------------------------------------------------
for V in $VERSIONS; do
    PREFIX="/usr/local/php${V}"
    SET="php${V}"
    MKCONF="${POUD_D}/${SET}-make.conf"
    PKGLIST="${POUD_D}/pkglist-${SET}"

    info "==== PHP ${V} -> PREFIX ${PREFIX} (set ${SET}) ===="

    # make.conf del set: fija PREFIX/LOCALBASE del set y opciones (FPM+CLI). Todo el grafo de esta
    # compilación (core + extensiones) se instala bajo este PREFIX de forma coherente.
    cat > "$MKCONF" <<CONF
# Generado por php_multi_build.sh — set ${SET}
PREFIX=${PREFIX}
DEFAULT_VERSIONS+=php=${V%?}.${V#?}
OPTIONS_SET=FPM CLI
OPTIONS_UNSET=CGI APACHE
CONF

    # Lista de paquetes: core + extensiones prefijadas por versión.
    {
        echo "lang/php${V}"
        for e in $EXT_LIST; do
            # nombre de puerto de extensión: la mayoría en lang/php${V}-<ext>, algunas en otras
            # categorías; poudriere resuelve por ORIGIN. Se listan como lang/ y si no existe el
            # ORIGIN, poudriere lo omite con aviso (revisa el log).
            echo "lang/php${V}-${e}"
        done
    } > "$PKGLIST"

    info "Compilando (poudriere bulk)... esto puede tardar bastante la 1ª vez."
    poudriere bulk -j "$JAIL" -p "$PORTS_TREE" -z "$SET" -f "$PKGLIST" || {
        echo "Aviso: algún puerto falló; revisa el log de poudriere. Continuando con lo compilado."
    }

    REPO="${PKG_OUT}/${JAIL}-${PORTS_TREE}-${SET}"
    if [ -d "$REPO" ]; then
        info "Repo pkg generado: ${REPO}"
        # Tarball para publicar (GitHub Release / servidor propio). Contiene el repo pkg completo.
        TARBALL="${DIST_DIR}/php${V}-${JAIL}.txz"
        tar caf "$TARBALL" -C "$(dirname "$REPO")" "$(basename "$REPO")"
        info "Tarball listo para publicar: ${TARBALL}"
    else
        echo "No se encontró el repo ${REPO} — revisa errores de compilación."
    fi
done

info "Hecho. Publica los .txz de ${DIST_DIR} como assets de GitHub Release (NO en el repo git)."
info "En los servidores: usa php_version_install.sh para instalar cada versión desde su repo."

#!/bin/sh
# 00_bootstrap.sh — Prepara la build box: swap, poudriere, jail, árbol de ports (quarterly) y el
# make.conf por versión (PREFIX+LOCALBASE propios = aislamiento total). Idempotente: re-ejecutable.
#
# Ejecutar como root EN LA BUILD BOX (192.168.1.110), NO en producción.
set -eu
cd "$(dirname "$0")"
. ./config.sh
resolve_branch

info() { printf '\033[36m[bootstrap]\033[0m %s\n' "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "Ejecuta como root."; exit 1; }

# ---------------------------------------------------------------------------
# 1. Swap extra (1 GB RAM es poco para compilar; icu/openssl/curl piden más).
# ---------------------------------------------------------------------------
if ! swapinfo 2>/dev/null | grep -q "$SWAPFILE"; then
    info "Creando swapfile ${SWAPFILE} (${SWAPFILE_SIZE_MB} MB)..."
    if ! truncate -s "${SWAPFILE_SIZE_MB}M" "$SWAPFILE" 2>/dev/null; then
        dd if=/dev/zero of="$SWAPFILE" bs=1m count="$SWAPFILE_SIZE_MB"
    fi
    chmod 600 "$SWAPFILE"
    mdconfig -a -t vnode -f "$SWAPFILE" -u 99 2>/dev/null || true
    swapon /dev/md99 2>/dev/null || true
    # Persistencia en el arranque.
    if ! grep -q "file=${SWAPFILE}" /etc/fstab 2>/dev/null; then
        printf 'md99\tnone\tswap\tsw,file=%s,late\t0\t0\n' "$SWAPFILE" >> /etc/fstab
    fi
else
    info "Swapfile ya activo."
fi
swapinfo -h

# ---------------------------------------------------------------------------
# 2. poudriere + git
# ---------------------------------------------------------------------------
if ! command -v poudriere >/dev/null 2>&1; then
    info "Instalando poudriere..."
    pkg install -y poudriere || pkg install -y poudriere-devel
fi
if ! command -v git >/dev/null 2>&1; then
    info "Instalando git..."
    pkg install -y git
fi

# ---------------------------------------------------------------------------
# 3. poudriere.conf afinado para 1 GB RAM / 1 CPU / UFS
# ---------------------------------------------------------------------------
mkdir -p "${POUD_BASE}/distfiles"
info "Escribiendo /usr/local/etc/poudriere.conf (NO_ZFS, sin tmpfs, ${PARALLEL_JOBS} job)..."
cat > /usr/local/etc/poudriere.conf <<CONF
# Generado por compilador_php/00_bootstrap.sh — build box pequeña (1 GB RAM, UFS).
NO_ZFS=yes
BASEFS=${POUD_BASE}
DISTFILES_CACHE=${POUD_BASE}/distfiles
FREEBSD_HOST=https://download.freebsd.org
RESOLV_CONF=/etc/resolv.conf
# CLAVE con 1 GB RAM: NO usar tmpfs para wrkdirs/data (o se agota la RAM y muere el build).
USE_TMPFS=no
PARALLEL_JOBS=${PARALLEL_JOBS}
# Clonar los ports por HTTPS (el git:// en 9418 suele estar bloqueado -> "Connection refused").
GIT_PORTS_URL=https://git.FreeBSD.org/ports.git
CONF

# ---------------------------------------------------------------------------
# 4. Jail de build (misma release/ABI que producción)
# ---------------------------------------------------------------------------
if ! poudriere jail -l 2>/dev/null | awk '{print $1}' | grep -qx "$JAIL"; then
    info "Creando jail ${JAIL} (${JAIL_VERSION} ${ARCH}) — descarga la base, tarda un poco..."
    poudriere jail -c -j "$JAIL" -v "$JAIL_VERSION" -a "$ARCH"
else
    info "Jail ${JAIL} ya existe."
fi

# ---------------------------------------------------------------------------
# 5. Árbol de ports en la rama quarterly (git)
# ---------------------------------------------------------------------------
if ! poudriere ports -l 2>/dev/null | awk '{print $1}' | grep -qx "$PORTS_TREE"; then
    info "Creando árbol de ports '${PORTS_TREE}' en la rama ${BRANCH} (git por HTTPS)..."
    poudriere ports -c -p "$PORTS_TREE" -m git -B "$BRANCH" -U https://git.FreeBSD.org/ports.git
else
    info "Árbol de ports '${PORTS_TREE}' ya existe (rama esperada: ${BRANCH})."
fi

# ---------------------------------------------------------------------------
# 6. make.conf por versión (set phpNN): PREFIX+LOCALBASE propios = aislamiento total.
#    Cada versión (core + TODAS sus dependencias) se compila bajo /usr/local/phpNN.
# ---------------------------------------------------------------------------
POUD_D="/usr/local/etc/poudriere.d"
mkdir -p "$POUD_D"
for V in $VERSIONS; do
    PREFIX="/usr/local/php${V}"
    SET="php${V}"
    MKCONF="${POUD_D}/${SET}-make.conf"
    info "make.conf del set ${SET} -> PREFIX ${PREFIX}"
    cat > "$MKCONF" <<CONF
# Generado por compilador_php/00_bootstrap.sh — set ${SET}
# PREFIX propio SOLO para los puertos de PHP (core lang/php${V} + extensiones php${V}-*). Las
# dependencias (indexinfo, png, curl, openssl, oniguruma...) se quedan en /usr/local ESTÁNDAR, así:
#  - las herramientas de build se encuentran en /usr/local/bin (si se reubican TODAS, indexinfo cae
#    en /usr/local/php${V}/bin y el port falla en run-depends: "indexinfo - not found"),
#  - las libs se comparten con el sistema (misma rama quarterly).
.if \${.CURDIR:T:Mphp${V}} != "" || \${.CURDIR:T:Mphp${V}-*} != ""
PREFIX=${PREFIX}
# PHPBASE: dónde vive PHP para las EXTENSIONES (phpize/php-config). Sin esto buscan en
# /usr/local/bin/phpize (no existe: el core está reubicado) y fallan en build-depends.
PHPBASE=${PREFIX}
.endif
DEFAULT_VERSIONS+=php=${V%?}.${V#?}
OPTIONS_SET=FPM CLI
OPTIONS_UNSET=CGI DEBUG
MAKE_JOBS_NUMBER=${MAKE_JOBS_NUMBER}
BATCH=yes
DISABLE_LICENSES=yes
CONF
done

info "Bootstrap completo. Rama ports: ${BRANCH}. Siguiente: sh 01_build.sh"

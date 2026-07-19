#!/bin/sh
# config.sh — Configuración del servidor de compilación de PHP (poudriere).
# Editar aquí y luego ejecutar 00_bootstrap.sh -> 01_build.sh -> 02_publish.sh -> 03_deploy.sh.
# Pensado para la build box 192.168.1.110 (FreeBSD 15, 1 GB RAM, 1 CPU, UFS, pkg quarterly).

# Versiones de PHP a compilar (SIN punto): 82 = PHP 8.2, 85 = PHP 8.5, ...
# NOTA: 8.4 ya es la versión del sistema (pkg oficial) en los servidores; NO hace falta compilarla.
# En 1 CPU/1 GB cada versión tarda HORAS: se pueden encadenar varias. Sobreescribible por entorno:
#   VERSIONS="82 85" sh 00_bootstrap.sh && VERSIONS="82 85" sh 01_build.sh ...
VERSIONS="${VERSIONS:-83}"

# Rama del árbol de ports. 'auto' = trimestre actual (p.ej. 2026Q3), para casar con pkg quarterly.
BRANCH="auto"

# Jail de build (misma release/ABI que producción).
JAIL="fbsd15"
JAIL_VERSION="15.0-RELEASE"
ARCH="amd64"
PORTS_TREE="quarterly"

# Extensiones de hosting. Se resuelve su ORIGIN real automáticamente desde el árbol de ports
# (cada una vive en su categoría: math/, graphics/, databases/, textproc/...), no hay que cablearlo.
# NOTA: openssl/hash/json/pcre/spl/random van SIEMPRE en el core (no son puerto aparte) -> no listar.
# intl (arrastra devel/icu) e imagick (ImageMagick) son builds MUY pesados: fuera por defecto en
# esta VM pequeña. Añádelos aquí si algún cliente los necesita y tienes tiempo/disco.
EXT_LIST="bcmath bz2 ctype curl dom exif fileinfo filter gd gettext iconv mbstring \
mysqli opcache pdo pdo_mysql phar posix session simplexml soap sockets sqlite3 \
tokenizer xml xmlreader xmlwriter zip zlib"

# Directorios de trabajo/salida.
POUD_BASE="/usr/local/poudriere"
DIST_DIR="/root/php-dist"          # donde 02_publish deja los .txz + sha256 listos para copiar

# Servidores destino (Modelo A: repo file:// copiado por scp).
DEST_SERVERS="192.168.1.109 192.168.1.200"
# La contraseña SSH NO se versiona (repo público). Ponla en compilador_php/config.local.sh
# (gitignored):   SSH_PASS="tu_password"    — o expórtala en el entorno antes de 03_deploy.sh.
SSH_PASS="${SSH_PASS:-}"

# Tuning para 1 GB RAM / 1 CPU (crítico: sin tmpfs, 1 job, con swap extra).
PARALLEL_JOBS=1
MAKE_JOBS_NUMBER=1
SWAPFILE="/swap0"
SWAPFILE_SIZE_MB=2048

# Overrides locales NO versionados (secretos como SSH_PASS). Debe ir tras las asignaciones de arriba.
[ -f ./config.local.sh ] && . ./config.local.sh

# --- helper: resuelve BRANCH 'auto' al trimestre actual (YYYYQn) ---
resolve_branch() {
    if [ "$BRANCH" = "auto" ]; then
        _Y=$(date +%Y); _M=$(date +%m); _M=${_M#0}   # quita cero inicial (POSIX sh, sin bashism 10#)
        _Q=$(( (_M - 1) / 3 + 1 ))
        BRANCH="${_Y}Q${_Q}"
    fi
}

#!/bin/sh
# 01_build.sh — Compila las versiones de config.sh con poudriere (cada una en su set/PREFIX).
# Produce un repositorio pkg por versión en ${POUD_BASE}/data/packages/<jail>-<ports>-phpNN.
# Ejecutar como root EN LA BUILD BOX. Largo (horas en 1 CPU): lánzalo en segundo plano si quieres:
#   nohup sh 01_build.sh > /root/build.log 2>&1 &
set -eu
cd "$(dirname "$0")"
. ./config.sh
resolve_branch

info() { printf '\033[36m[build]\033[0m %s\n' "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "Ejecuta como root."; exit 1; }

POUD_D="/usr/local/etc/poudriere.d"
PORTSDIR="${POUD_BASE}/ports/${PORTS_TREE}"

# Resuelve el ORIGIN real de una extensión (categoria/phpNN-ext) buscando en el árbol de ports.
# Las extensiones PHP están repartidas por categorías (math, graphics, databases, textproc, ...).
resolve_origin() {  # $1=version  $2=ext  -> imprime "categoria/phpNN-ext" o nada
    for d in "$PORTSDIR"/*/"php${1}-${2}"; do
        [ -d "$d" ] || continue
        echo "${d#${PORTSDIR}/}"
        return 0
    done
    return 1
}

for V in $VERSIONS; do
    SET="php${V}"
    PKGLIST="${POUD_D}/pkglist-${SET}"
    info "==== Compilando PHP ${V%?}.${V#?} (set ${SET}, rama ${BRANCH}) ===="

    # Lista de paquetes: core + extensiones (ORIGIN resuelto por categoría real). Una extensión que
    # no exista en esta rama se avisa y se salta; poudriere añade las dependencias por sí mismo.
    {
        echo "lang/php${V}"
        for e in $EXT_LIST; do
            if o=$(resolve_origin "$V" "$e"); then
                echo "$o"
            else
                echo "AVISO: sin ORIGIN para php${V}-${e} (se omite)" >&2
            fi
        done
    } > "$PKGLIST"
    info "pkglist (${SET}):"; cat "$PKGLIST" | sed 's/^/    /'

    # -z SET aplica el make.conf del set (PREFIX/LOCALBASE propios). -J 1:1 = 1 build, 1 job.
    poudriere bulk -j "$JAIL" -p "$PORTS_TREE" -z "$SET" -J "${PARALLEL_JOBS}:${MAKE_JOBS_NUMBER}" -f "$PKGLIST" || {
        echo "AVISO: algún puerto falló en ${SET}; revisa el log de poudriere. Se continúa con lo compilado."
    }

    REPO="${POUD_BASE}/data/packages/${JAIL}-${PORTS_TREE}-${SET}"
    if [ -d "$REPO" ]; then
        info "Repo pkg generado: ${REPO}"
    else
        echo "ERROR: no se generó ${REPO} — revisa errores de compilación."
    fi
done

info "Build terminado. Siguiente: sh 02_publish.sh (empaqueta) y sh 03_deploy.sh (copia+instala)."

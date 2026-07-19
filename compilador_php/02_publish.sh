#!/bin/sh
# 02_publish.sh — Empaqueta cada repo pkg generado en un .txz + sha256 en DIST_DIR.
# El .txz contiene el repo COMPLETO (paquetes + catálogo pkg), listo para copiar a los servidores
# (Modelo A, file://) o publicar en una URL (Modelo C). Ejecutar como root EN LA BUILD BOX.
set -eu
cd "$(dirname "$0")"
. ./config.sh

info() { printf '\033[36m[publish]\033[0m %s\n' "$*"; }
mkdir -p "$DIST_DIR"

for V in $VERSIONS; do
    SET="php${V}"
    REPO="${POUD_BASE}/data/packages/${JAIL}-${PORTS_TREE}-${SET}"
    [ -d "$REPO" ] || { echo "Salto ${SET}: no existe ${REPO}"; continue; }

    # Regenera el catálogo del repo por si acaso (idempotente).
    info "Regenerando catálogo de ${SET}..."
    poudriere pkgclean -j "$JAIL" -p "$PORTS_TREE" -z "$SET" -y >/dev/null 2>&1 || true
    pkg repo "$REPO" 2>/dev/null || true

    TARBALL="${DIST_DIR}/php${V}-${JAIL}.txz"
    info "Empaquetando ${SET} -> ${TARBALL}"
    tar caf "$TARBALL" -C "$(dirname "$REPO")" "$(basename "$REPO")"
    ( cd "$DIST_DIR" && sha256 -q "$(basename "$TARBALL")" > "$(basename "$TARBALL").sha256" )
    info "Listo: ${TARBALL} ($(du -h "$TARBALL" | cut -f1))"
done

info "Publicación lista en ${DIST_DIR}. Siguiente: sh 03_deploy.sh"

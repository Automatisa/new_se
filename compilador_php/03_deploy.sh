#!/bin/sh
# 03_deploy.sh — Copia el repo pkg de cada versión a los servidores destino (Modelo A: file://) y
# ejecuta el instalador allí. Requiere sshpass (se instala si falta). Ejecutar EN LA BUILD BOX.
#
# Para cada versión de config.sh y cada servidor de DEST_SERVERS:
#   1. rsync/scp del repo a /usr/local/php-repos/phpNN/ del servidor
#   2. scp de install_on_server.sh
#   3. ssh: sh install_on_server.sh NN /usr/local/php-repos/phpNN
set -eu
cd "$(dirname "$0")"
. ./config.sh

info() { printf '\033[36m[deploy]\033[0m %s\n' "$*"; }
command -v sshpass >/dev/null 2>&1 || pkg install -y sshpass
SSH="sshpass -p ${SSH_PASS} ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null"
SCP="sshpass -p ${SSH_PASS} scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null"

for V in $VERSIONS; do
    SET="php${V}"
    REPO="${POUD_BASE}/data/packages/${JAIL}-${PORTS_TREE}-${SET}"
    [ -d "$REPO" ] || { echo "Salto ${SET}: no existe ${REPO} (¿compilado?)"; continue; }

    # Repo PODADO: solo los paquetes php${V}-* (NO las dependencias del sistema). Así el repo que se
    # instala en los servidores NO ensombrece libs del sistema (png, curl...) en futuros pkg upgrade;
    # esas dependencias ya están en los servidores (las usa php84) y se resuelven del repo oficial.
    PRUNED="${DIST_DIR}/php${V}-repo"
    info "Podando repo php${V} (solo php${V}-*) en ${PRUNED} ..."
    rm -rf "$PRUNED"; mkdir -p "$PRUNED/All"
    cp "$REPO"/All/php${V}-*.pkg "$PRUNED/All/" 2>/dev/null || { echo "No hay paquetes php${V}-*"; continue; }
    pkg repo "$PRUNED" >/dev/null 2>&1 || { echo "ERROR generando catálogo de ${PRUNED}"; continue; }
    info "Repo podado: $(ls "$PRUNED"/All/php${V}-*.pkg | wc -l | tr -d " ") paquetes."

    for S in $DEST_SERVERS; do
        info "==== php${V} -> ${S} ===="
        DEST="/usr/local/php-repos/php${V}"
        $SSH "root@${S}" "rm -rf ${DEST}; mkdir -p ${DEST}"
        info "Copiando repo podado a ${S}:${DEST} ..."
        tar cf - -C "$PRUNED" . | $SSH "root@${S}" "tar xf - -C ${DEST}"
        $SCP ./install_on_server.sh "root@${S}:/root/install_on_server.sh" >/dev/null
        $SSH "root@${S}" "sh /root/install_on_server.sh ${V} ${DEST}"
    done
done

info "Despliegue terminado. Comprueba en cada servidor: service php${VERSIONS%% *}_fpm status"

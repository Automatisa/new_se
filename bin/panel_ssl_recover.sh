#!/bin/sh
# panel_ssl_recover.sh — Recuperación de certificado SSL del panel Bulwark
#
# Uso: ejecutar como root via SSH cuando el panel no es accesible por fallo del cert SSL.
#
#   sh /usr/local/bulwark/bin/panel_ssl_recover.sh
#
# Opciones:
#   1) Certificado autofirmado (inmediato, sin red, SAN correcto con IP y dominio)
#   2) Let's Encrypt via acme.sh (sin Python; requiere DNS público apuntando al servidor)

set -e

###############################################################################
# Colores
###############################################################################
RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; NC='\033[0m'; BLD='\033[1m'
ok()  { printf "${GRN}[OK]${NC}  %s\n" "$1"; }
err() { printf "${RED}[ERR]${NC} %s\n" "$1" >&2; }
inf() { printf "${YLW}[..]${NC}  %s\n" "$1"; }

###############################################################################
# Verificar root
###############################################################################
if [ "$(id -u)" -ne 0 ]; then
    err "Este script debe ejecutarse como root."
    exit 1
fi

###############################################################################
# Leer configuración de la BD de Bulwark
###############################################################################
CNF="/usr/local/bulwark/cnf/db.php"
if [ ! -f "$CNF" ]; then
    err "No se encuentra $CNF — ¿está Bulwark instalado en /usr/local/bulwark?"
    exit 1
fi

DB_HOST=$(grep "host"   "$CNF" | sed "s/.*= '//;s/'.*//")
DB_NAME=$(grep "dbname" "$CNF" | sed "s/.*= '//;s/'.*//")
DB_USER=$(grep "user"   "$CNF" | sed "s/.*= '//;s/'.*//")
DB_PASS=$(grep "pass"   "$CNF" | sed "s/.*= '//;s/'.*//")

mysql_q() {
    mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" "$DB_NAME" -se "$1" 2>/dev/null
}

DOMAIN=$(mysql_q "SELECT so_value_tx FROM x_settings WHERE so_name_vc='bulwark_domain';")
SERVER_IP=$(mysql_q "SELECT so_value_tx FROM x_settings WHERE so_name_vc='server_ip';")
BULWARK_ROOT=$(mysql_q "SELECT so_value_tx FROM x_settings WHERE so_name_vc='bulwark_root';")
HOSTED_DIR=$(mysql_q "SELECT so_value_tx FROM x_settings WHERE so_name_vc='hosted_dir';")
BULWARK_ROOT="${BULWARK_ROOT:-/usr/local/bulwark}"
HOSTED_DIR="${HOSTED_DIR:-/var/bulwark/hostdata/}"
RECOVERY_DIR="/usr/local/etc/bulwark/panel/recovery"
# Ruta donde sencrypt muestra los certs del panel (pestaña third_party)
SENCRYPT_PANEL_DIR="${HOSTED_DIR}zadmin/ssl/sencrypt/third_party/${DOMAIN}"

if [ -z "$DOMAIN" ]; then
    err "No se pudo leer bulwark_domain de la BD. Verifica credenciales en $CNF"
    exit 1
fi

# Localizar acme.sh
ACME=$(which acme.sh 2>/dev/null || echo "")
[ -z "$ACME" ] && [ -x "/usr/local/sbin/acme.sh" ] && ACME="/usr/local/sbin/acme.sh"
[ -z "$ACME" ] && [ -x "/root/.acme.sh/acme.sh" ]   && ACME="/root/.acme.sh/acme.sh"

printf "\n${BLD}=== Recuperación SSL del panel Bulwark ===${NC}\n"
printf "  Dominio del panel : ${BLD}%s${NC}\n" "$DOMAIN"
printf "  IP del servidor   : ${BLD}%s${NC}\n" "$SERVER_IP"
printf "  acme.sh           : %s\n" "${ACME:-no encontrado}"
printf "\n"

###############################################################################
# Menú de opciones
###############################################################################
printf "${BLD}Selecciona el tipo de certificado a generar:${NC}\n"
printf "  1) Autofirmado (inmediato, válido 1 año, SAN con dominio e IP)\n"
printf "  2) Let's Encrypt via acme.sh (sin Python; requiere DNS público + internet)\n"
printf "  q) Salir sin cambios\n"
printf "\nOpción: "
read OPCION

case "$OPCION" in
    1) MODE="selfsigned" ;;
    2) MODE="letsencrypt" ;;
    q|Q) inf "Saliendo sin cambios."; exit 0 ;;
    *) err "Opción no válida."; exit 1 ;;
esac

###############################################################################
# Función: aplicar el nuevo cert a la BD + vhosts + recargar Apache
###############################################################################
apply_cert() {
    local CERTFILE="$1"
    local KEYFILE="$2"

    inf "Verificando que el certificado y la clave coinciden..."
    CERT_MOD=$(openssl x509 -noout -modulus -in "$CERTFILE" 2>/dev/null | md5)
    KEY_MOD=$(openssl rsa  -noout -modulus -in "$KEYFILE"  2>/dev/null | md5)
    if [ -z "$CERT_MOD" ] || [ "$CERT_MOD" != "$KEY_MOD" ]; then
        # Intentar con EC (para certs ECDSA de acme.sh)
        KEY_MOD_EC=$(openssl ec -noout -pubout -in "$KEYFILE" 2>/dev/null | md5)
        CERT_MOD_EC=$(openssl x509 -noout -pubkey -in "$CERTFILE" 2>/dev/null | md5)
        if [ -z "$CERT_MOD_EC" ] || [ "$CERT_MOD_EC" != "$KEY_MOD_EC" ]; then
            err "El certificado y la clave no coinciden. Abortando."
            exit 1
        fi
    fi
    ok "Certificado y clave coinciden."

    # Permisos seguros
    chown root:www   "$CERTFILE" && chmod 640 "$CERTFILE"
    chown root:wheel "$KEYFILE"  && chmod 600 "$KEYFILE"

    # Actualizar panel_ssl_tx en la BD
    inf "Actualizando panel_ssl_tx en la BD..."
    SSL_TX="SSLEngine On\nSSLProtocol all -SSLv3 -TLSv1 -TLSv1.1\nSSLCipherSuite ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384\nSSLCertificateFile ${CERTFILE}\nSSLCertificateKeyFile ${KEYFILE}"
    mysql_q "UPDATE x_settings SET so_value_tx='${SSL_TX}' WHERE so_name_vc='panel_ssl_tx';"
    mysql_q "UPDATE x_settings SET so_value_tx='true' WHERE so_name_vc='apache_changed';"
    mysql_q "UPDATE x_settings SET so_value_tx='0'    WHERE so_name_vc='daemon_lastrun';"
    ok "BD actualizada."

    # Regenerar vhosts via daemon
    inf "Regenerando configuración de Apache (daemon)..."
    php "${BULWARK_ROOT}/bin/daemon.php" >/dev/null 2>&1 || true

    # Validar config de Apache
    inf "Validando sintaxis de Apache..."
    if ! apachectl -t 2>/dev/null; then
        err "La configuración de Apache tiene errores. Revisa /usr/local/etc/bulwark/apache/httpd-vhosts.conf"
        exit 1
    fi
    ok "Sintaxis correcta."

    # Recargar Apache
    inf "Recargando Apache..."
    service apache24 reload >/dev/null 2>&1
    ok "Apache recargado."

    printf "\n${GRN}${BLD}Recuperación completada.${NC}\n"
    printf "  Certificado : %s\n" "$CERTFILE"
    printf "  Clave       : %s\n" "$KEYFILE"
    openssl x509 -in "$CERTFILE" -noout -subject -dates -ext subjectAltName 2>/dev/null | sed 's/^/  /'
    printf "\n"
    printf "  Panel accesible en: ${BLD}https://%s/${NC}\n\n" "$DOMAIN"
}

###############################################################################
# Opción 1: Autofirmado
###############################################################################
if [ "$MODE" = "selfsigned" ]; then
    mkdir -p "$RECOVERY_DIR"
    CERTFILE="$RECOVERY_DIR/selfsigned.crt"
    KEYFILE="$RECOVERY_DIR/selfsigned.key"

    # SAN: incluye dominio, www.dominio, y la IP del servidor
    SAN="DNS:${DOMAIN},DNS:www.${DOMAIN}"
    if [ -n "$SERVER_IP" ]; then
        SAN="${SAN},IP:${SERVER_IP}"
    fi

    inf "Generando certificado autofirmado RSA 2048 / 1 año para ${DOMAIN}..."
    inf "SAN: $SAN"

    # Backup si ya existe
    if [ -f "$CERTFILE" ]; then
        cp "$CERTFILE" "${CERTFILE}.bak"
        cp "$KEYFILE"  "${KEYFILE}.bak"
    fi

    openssl req -x509 -newkey rsa:2048 -days 365 -nodes \
        -keyout "$KEYFILE" \
        -out    "$CERTFILE" \
        -subj   "/CN=${DOMAIN}/O=Bulwark Panel Recovery/C=ES" \
        -addext "subjectAltName=${SAN}" \
        2>/dev/null
    ok "Certificado autofirmado generado."

    # Instalar también en la ruta third_party de sencrypt para que aparezca en el módulo
    mkdir -p "$SENCRYPT_PANEL_DIR"
    cp "$CERTFILE" "${SENCRYPT_PANEL_DIR}/cert.pem"
    cp "$KEYFILE"  "${SENCRYPT_PANEL_DIR}/private.pem"
    chown -R www:www "$SENCRYPT_PANEL_DIR" 2>/dev/null || true
    chmod 640 "${SENCRYPT_PANEL_DIR}/cert.pem"
    chmod 600 "${SENCRYPT_PANEL_DIR}/private.pem"
    ok "Cert copiado a sencrypt third_party: $SENCRYPT_PANEL_DIR"

    apply_cert "$CERTFILE" "$KEYFILE"

###############################################################################
# Opción 2: Let's Encrypt via acme.sh (sin Python)
###############################################################################
elif [ "$MODE" = "letsencrypt" ]; then

    if [ -z "$ACME" ]; then
        err "acme.sh no está instalado."
        printf "  Instalación rápida (sin Python):\n"
        printf "    curl https://get.acme.sh | sh -s email=admin@%s\n" "$DOMAIN"
        printf "  O si ya lo tienes en /usr/local/sbin/acme.sh, verifica los permisos (chmod +x).\n"
        exit 1
    fi
    ok "acme.sh encontrado: $ACME"

    # Verificar que el dominio resuelve a la IP del servidor
    inf "Verificando que ${DOMAIN} resuelve a ${SERVER_IP}..."
    RESOLVED=$(drill "$DOMAIN" 2>/dev/null | awk '/^'"$DOMAIN"'[[:space:]].*[[:space:]]A[[:space:]]/{print $NF}' | head -1)
    if [ -z "$RESOLVED" ]; then
        RESOLVED=$(host "$DOMAIN" 2>/dev/null | awk '/has address/{print $NF}' | head -1)
    fi
    if [ -n "$RESOLVED" ] && [ "$RESOLVED" != "$SERVER_IP" ]; then
        printf "${YLW}[AVISO]${NC} El DNS de ${DOMAIN} resuelve a '${RESOLVED}' (esperado: '${SERVER_IP}').\n"
        printf "  Let's Encrypt fallará si el dominio no apunta al servidor.\n"
        printf "  ¿Continuar de todas formas? (s/N): "
        read CONTINUAR
        case "$CONTINUAR" in s|S|y|Y) ;; *) inf "Saliendo."; exit 0 ;; esac
    elif [ -z "$RESOLVED" ]; then
        printf "${YLW}[AVISO]${NC} No se pudo resolver ${DOMAIN} (drill/host no disponibles o sin DNS).\n"
        printf "  ¿Continuar de todas formas? (s/N): "
        read CONTINUAR
        case "$CONTINUAR" in s|S|y|Y) ;; *) inf "Saliendo."; exit 0 ;; esac
    else
        ok "DNS correcto: $DOMAIN → $SERVER_IP"
    fi

    # acme.sh usa un servidor HTTP temporal (standalone) en el puerto 80
    # Paramos Apache brevemente (~15-30 segundos)
    printf "\n${YLW}AVISO:${NC} Apache se detendrá brevemente (~30s) para el challenge HTTP-01.\n"
    printf "  ¿Continuar? (s/N): "
    read CONTINUAR2
    case "$CONTINUAR2" in s|S|y|Y) ;; *) inf "Saliendo."; exit 0 ;; esac

    inf "Deteniendo Apache..."
    service apache24 stop >/dev/null 2>&1 || true

    # Directorio de salida del cert
    LE_DIR="$RECOVERY_DIR/letsencrypt/${DOMAIN}"
    mkdir -p "$LE_DIR"

    CERTFILE="${LE_DIR}/fullchain.pem"
    KEYFILE="${LE_DIR}/privkey.pem"

    inf "Solicitando certificado Let's Encrypt para ${DOMAIN}..."
    if "$ACME" --issue \
        --standalone \
        -d "$DOMAIN" \
        -d "www.${DOMAIN}" \
        --server letsencrypt \
        --fullchain-file "$CERTFILE" \
        --key-file       "$KEYFILE" \
        2>&1; then
        ok "Let's Encrypt emitió el certificado."
    else
        err "acme.sh falló. Iniciando Apache con el cert anterior..."
        service apache24 start >/dev/null 2>&1 || true
        exit 1
    fi

    inf "Iniciando Apache con el nuevo certificado..."
    service apache24 start >/dev/null 2>&1

    # Instalar también en la ruta letsencrypt de sencrypt para que aparezca en el módulo
    # y para que OnDaemonDay.hook.php lo renueve automáticamente
    SENCRYPT_LE_DIR="${HOSTED_DIR}zadmin/ssl/sencrypt/letsencrypt/${DOMAIN}"
    mkdir -p "$SENCRYPT_LE_DIR"
    cp "$CERTFILE" "${SENCRYPT_LE_DIR}/cert.pem"
    cp "$KEYFILE"  "${SENCRYPT_LE_DIR}/private.pem"
    # acme.sh también genera chain.pem — intentar copiarlo si existe
    CHAIN_FILE="${LE_DIR}/ca.cer"
    [ -f "$CHAIN_FILE" ] && cp "$CHAIN_FILE" "${SENCRYPT_LE_DIR}/chain.pem"
    chown -R www:www "$SENCRYPT_LE_DIR" 2>/dev/null || true
    chmod 640 "${SENCRYPT_LE_DIR}/cert.pem"
    chmod 600 "${SENCRYPT_LE_DIR}/private.pem"
    ok "Cert copiado a sencrypt letsencrypt: $SENCRYPT_LE_DIR"

    apply_cert "$CERTFILE" "$KEYFILE"
fi

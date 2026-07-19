#!/bin/sh
# Instala un certificado SSL comercial subido via el panel.
# Lee cert y key desde /tmp/bulwark_ftp_cert_upload y /tmp/bulwark_ftp_key_upload.
# Valida, instala en /usr/local/etc/bulwark/proftpd/ y actualiza el config.
# Llamado por privilege::run('proftpd_cert_upload') como root via doas.

CERTFILE_TMP="/var/bulwark/run/bulwark_ftp_cert_upload"
KEYFILE_TMP="/var/bulwark/run/bulwark_ftp_key_upload"
CERTFILE_DEST="/usr/local/etc/bulwark/proftpd/commercial.crt"
KEYFILE_DEST="/usr/local/etc/bulwark/proftpd/commercial.key"
REAL_CONFIG="/usr/local/etc/bulwark/proftpd/proftpd-mysql.conf"

cleanup() {
    rm -f "$CERTFILE_TMP" "$KEYFILE_TMP"
}

if [ ! -f "$CERTFILE_TMP" ] || [ ! -f "$KEYFILE_TMP" ]; then
    echo "ERROR: ficheros temporales no encontrados" >&2
    exit 1
fi

# Tamaño máximo: 200 KB por fichero
CERTSIZE=$(stat -f%z "$CERTFILE_TMP" 2>/dev/null || stat -c%s "$CERTFILE_TMP" 2>/dev/null)
KEYSIZE=$(stat -f%z "$KEYFILE_TMP" 2>/dev/null || stat -c%s "$KEYFILE_TMP" 2>/dev/null)
if [ "$CERTSIZE" -gt 204800 ] || [ "$KEYSIZE" -gt 204800 ]; then
    echo "ERROR: fichero demasiado grande" >&2
    cleanup; exit 2
fi

# Validar que el certificado es un cert X.509 válido
if ! /usr/bin/openssl x509 -in "$CERTFILE_TMP" -noout 2>/dev/null; then
    echo "ERROR: el fichero de certificado no es válido" >&2
    cleanup; exit 3
fi

# Detectar tipo de clave (RSA o EC) y validar
if /usr/bin/openssl rsa -in "$KEYFILE_TMP" -noout 2>/dev/null; then
    KEY_TYPE="rsa"
elif /usr/bin/openssl ec -in "$KEYFILE_TMP" -noout 2>/dev/null; then
    KEY_TYPE="ec"
else
    echo "ERROR: el fichero de clave privada no es válido" >&2
    cleanup; exit 4
fi

# Verificar que cert y key coinciden
if [ "$KEY_TYPE" = "rsa" ]; then
    CERT_MOD=$(/usr/bin/openssl x509 -noout -modulus -in "$CERTFILE_TMP" 2>/dev/null | md5)
    KEY_MOD=$(/usr/bin/openssl rsa  -noout -modulus -in "$KEYFILE_TMP"  2>/dev/null | md5)
    if [ "$CERT_MOD" != "$KEY_MOD" ]; then
        echo "ERROR: el certificado y la clave privada no coinciden" >&2
        cleanup; exit 5
    fi
else
    # EC: comparar la clave pública extraída del cert vs la del keyfile
    CERT_PUB=$(/usr/bin/openssl x509 -noout -pubkey -in "$CERTFILE_TMP" 2>/dev/null)
    KEY_PUB=$(/usr/bin/openssl ec   -pubout -in "$KEYFILE_TMP" 2>/dev/null)
    if [ "$CERT_PUB" != "$KEY_PUB" ]; then
        echo "ERROR: el certificado EC y la clave privada no coinciden" >&2
        cleanup; exit 5
    fi
fi

# Instalar con permisos correctos
cp "$CERTFILE_TMP" "$CERTFILE_DEST"
cp "$KEYFILE_TMP"  "$KEYFILE_DEST"
chown root:www   "$CERTFILE_DEST" && chmod 640 "$CERTFILE_DEST"
chown root:wheel "$KEYFILE_DEST"  && chmod 600 "$KEYFILE_DEST"

# Actualizar las directivas en el config de ProFTPD según el tipo de clave.
# Antes solo se tocaban las directivas TLSRSA*, así que un certificado EC se
# validaba pero NO quedaba cableado. Ahora se fija el par correcto (RSA o EC),
# añadiendo la directiva si no existía.
cp "$REAL_CONFIG" "${REAL_CONFIG}.bak"

set_directive() {
    # $1 = nombre directiva, $2 = valor
    if grep -qE "^${1}[[:space:]]" "$REAL_CONFIG"; then
        sed -i '' "s|^${1}[[:space:]].*|${1} ${2}|" "$REAL_CONFIG"
    else
        echo "${1} ${2}" >> "$REAL_CONFIG"
    fi
}

if [ "$KEY_TYPE" = "rsa" ]; then
    set_directive TLSRSACertificateFile    "$CERTFILE_DEST"
    set_directive TLSRSACertificateKeyFile "$KEYFILE_DEST"
else
    set_directive TLSECCertificateFile     "$CERTFILE_DEST"
    set_directive TLSECCertificateKeyFile  "$KEYFILE_DEST"
fi

# Validar sintaxis del config resultante
if ! /usr/local/sbin/proftpd -t 2>/dev/null; then
    cp "${REAL_CONFIG}.bak" "$REAL_CONFIG"
    echo "ERROR: config no válido tras el cambio, revertido" >&2
    cleanup; exit 6
fi

cleanup
exit 0

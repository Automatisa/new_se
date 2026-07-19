#!/bin/sh
# Actualiza las rutas de certificado TLS en el config de ProFTPD.
# Lee las rutas desde /tmp/bulwark_ftp_cert y /tmp/bulwark_ftp_key.
# Llamado por privilege::run('proftpd_cert_paths_update') como root via doas.

TMPDIR="/tmp"
CERTPATH_FILE="$TMPDIR/bulwark_ftp_cert"
KEYPATH_FILE="$TMPDIR/bulwark_ftp_key"
REAL_CONFIG="/usr/local/etc/bulwark/proftpd/proftpd-mysql.conf"

if [ ! -f "$CERTPATH_FILE" ] || [ ! -f "$KEYPATH_FILE" ]; then
    echo "ERROR: ficheros temporales no encontrados" >&2
    exit 1
fi

CERTPATH=$(cat "$CERTPATH_FILE")
KEYPATH=$(cat "$KEYPATH_FILE")

# Validar que los ficheros existen
if [ ! -f "$CERTPATH" ]; then
    echo "ERROR: certificado no encontrado: $CERTPATH" >&2
    rm -f "$CERTPATH_FILE" "$KEYPATH_FILE"
    exit 2
fi
if [ ! -f "$KEYPATH" ]; then
    echo "ERROR: clave privada no encontrada: $KEYPATH" >&2
    rm -f "$CERTPATH_FILE" "$KEYPATH_FILE"
    exit 3
fi

# Validar que el certificado es válido
if ! /usr/bin/openssl x509 -in "$CERTPATH" -noout 2>/dev/null; then
    echo "ERROR: el fichero no es un certificado válido: $CERTPATH" >&2
    rm -f "$CERTPATH_FILE" "$KEYPATH_FILE"
    exit 4
fi

# Validar que cert y key coinciden
CERT_MOD=$(/usr/bin/openssl x509 -noout -modulus -in "$CERTPATH" 2>/dev/null | md5)
KEY_MOD=$(/usr/bin/openssl rsa -noout -modulus -in "$KEYPATH" 2>/dev/null | md5)
if [ "$CERT_MOD" != "$KEY_MOD" ]; then
    echo "ERROR: el certificado y la clave privada no coinciden" >&2
    rm -f "$CERTPATH_FILE" "$KEYPATH_FILE"
    exit 5
fi

# Backup del config actual
cp "$REAL_CONFIG" "${REAL_CONFIG}.bak"

# Actualizar las directivas en el config
sed -i '' \
    "s|^TLSRSACertificateFile.*|TLSRSACertificateFile $CERTPATH|" \
    "$REAL_CONFIG"
sed -i '' \
    "s|^TLSRSACertificateKeyFile.*|TLSRSACertificateKeyFile $KEYPATH|" \
    "$REAL_CONFIG"

# Validar que el config sigue siendo válido
if ! /usr/local/sbin/proftpd -t 2>/dev/null; then
    cp "${REAL_CONFIG}.bak" "$REAL_CONFIG"
    echo "ERROR: config no válido tras el cambio, revertido" >&2
    rm -f "$CERTPATH_FILE" "$KEYPATH_FILE"
    exit 6
fi

rm -f "$CERTPATH_FILE" "$KEYPATH_FILE"
exit 0

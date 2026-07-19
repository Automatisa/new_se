#!/bin/sh
# Genera un certificado TLS autofirmado para ProFTPD.
# Llamado por privilege::run('proftpd_cert_generate') como root via doas.

CERTFILE="/usr/local/etc/bulwark/proftpd/proftpd.crt"
KEYFILE="/usr/local/etc/bulwark/proftpd/proftpd.key"
HOSTNAME=$(hostname)

# Backup de los certificados actuales
cp "$CERTFILE" "${CERTFILE}.bak" 2>/dev/null
cp "$KEYFILE"  "${KEYFILE}.bak"  2>/dev/null

# Generar nuevo certificado autofirmado RSA 2048, 10 años
/usr/bin/openssl req -x509 -newkey rsa:2048 \
    -keyout "$KEYFILE" \
    -out    "$CERTFILE" \
    -days   3650 \
    -nodes \
    -subj   "/CN=${HOSTNAME}/O=Bulwark FTP Server/C=ES" \
    2>/dev/null

if [ $? -ne 0 ]; then
    # Restaurar backup si falló
    cp "${CERTFILE}.bak" "$CERTFILE" 2>/dev/null
    cp "${KEYFILE}.bak"  "$KEYFILE"  2>/dev/null
    echo "ERROR: fallo al generar el certificado" >&2
    exit 1
fi

# Permisos: crt legible por www (para info en panel), key solo root
chown root:www  "$CERTFILE" && chmod 640 "$CERTFILE"
chown root:wheel "$KEYFILE" && chmod 600 "$KEYFILE"

exit 0

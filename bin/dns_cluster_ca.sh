#!/bin/sh
# dns_cluster_ca.sh — CA propia del cluster DNS (Bulwark) para verificacion TLS FUERTE entre nodos
# (ajuste dns_cluster_tls_verify=ca), SIN depender de certificados publicos ni del DNS del propio
# cluster: el cert de cada nodo lleva su IP en el SAN y se verifica POR IP.
#
# Uso (como root):
#   dns_cluster_ca.sh init                Crea la CA del cluster (root key+cert, larga validez) si no existe.
#   dns_cluster_ca.sh issue <ip> [fqdn]   Emite key+cert de un nodo firmados por la CA (IP[,FQDN] en el SAN).
#   dns_cluster_ca.sh apply <ip>          Instala el cert del nodo <ip> en el Apache del panel y recarga.
#   dns_cluster_ca.sh show                Muestra rutas, validez y SAN de la CA y los certs emitidos.
#
# Flujo tipico (la CA vive en UN nodo emisor; su clave NO sale de ahi):
#   [emisor]  init ; issue <ip1> <fqdn1> ; issue <ip2> <fqdn2> ; ...
#   distribuir a cada nodo: su <ip>.key + <ip>.crt  y el ca.crt (publico)
#   [cada nodo] apply <su-ip>   y fijar el ajuste dns_cluster_ca_file=<.../ca.crt> + modo 'ca'
#
# Ficheros en $CA_DIR (root:bulwark; ca.key 600). NO subir ca.key al repo ni sacarla del emisor.
set -eu
CA_DIR="${CA_DIR:-/usr/local/etc/bulwark/cluster-ca}"
CA_DAYS="${CA_DAYS:-3650}"
NODE_DAYS="${NODE_DAYS:-3650}"
PANEL_SSL_CRT="${PANEL_SSL_CRT:-/usr/local/etc/bulwark/panel/recovery/selfsigned.crt}"
PANEL_SSL_KEY="${PANEL_SSL_KEY:-/usr/local/etc/bulwark/panel/recovery/selfsigned.key}"
CA_KEY="$CA_DIR/ca.key"
CA_CRT="$CA_DIR/ca.crt"

die() { echo "ERROR: $*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || die "ejecuta como root"
command -v openssl >/dev/null 2>&1 || die "falta openssl"

cmd_init() {
    if [ -f "$CA_KEY" ]; then echo "La CA ya existe en $CA_DIR (no se recrea)."; return 0; fi
    mkdir -p "$CA_DIR"; chmod 750 "$CA_DIR"
    openssl ecparam -genkey -name prime256v1 -out "$CA_KEY" 2>/dev/null
    openssl req -x509 -new -key "$CA_KEY" -sha256 -days "$CA_DAYS" \
        -out "$CA_CRT" -subj "/O=Bulwark/CN=Bulwark DNS Cluster CA"
    chmod 600 "$CA_KEY"; chmod 644 "$CA_CRT"
    chown -R root:bulwark "$CA_DIR" 2>/dev/null || true
    echo "CA creada en $CA_DIR (validez ${CA_DAYS} dias). Copia $CA_CRT a los demas nodos."
}

cmd_issue() {
    ip="${1:-}"; fqdn="${2:-}"
    [ -n "$ip" ] || die "uso: issue <ip> [fqdn]"
    [ -f "$CA_KEY" ] || die "no hay CA; ejecuta 'init' primero"
    san="IP:$ip"; [ -n "$fqdn" ] && san="$san,DNS:$fqdn"
    key="$CA_DIR/$ip.key"; crt="$CA_DIR/$ip.crt"; csr="$CA_DIR/$ip.csr"; ext="$CA_DIR/$ip.ext"
    openssl ecparam -genkey -name prime256v1 -out "$key" 2>/dev/null
    openssl req -new -key "$key" -out "$csr" -subj "/CN=$ip"
    printf 'subjectAltName=%s\nbasicConstraints=CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n' "$san" > "$ext"
    openssl x509 -req -in "$csr" -CA "$CA_CRT" -CAkey "$CA_KEY" -CAcreateserial \
        -days "$NODE_DAYS" -sha256 -extfile "$ext" -out "$crt" 2>/dev/null
    rm -f "$csr" "$ext"
    chmod 600 "$key"; chmod 644 "$crt"; chown root:bulwark "$key" "$crt" 2>/dev/null || true
    echo "Cert de nodo emitido: $crt (SAN: $san)."
    echo "  -> copia $crt y $key al nodo $ip (en su $CA_DIR) y ejecuta ahi: dns_cluster_ca.sh apply $ip"
}

cmd_apply() {
    ip="${1:-}"; [ -n "$ip" ] || die "uso: apply <ip>"
    key="$CA_DIR/$ip.key"; crt="$CA_DIR/$ip.crt"
    { [ -f "$key" ] && [ -f "$crt" ]; } || die "faltan $crt / $key (emitelos en el emisor y copialos a $CA_DIR)"
    cp "$crt" "$PANEL_SSL_CRT"; cp "$key" "$PANEL_SSL_KEY"
    chmod 644 "$PANEL_SSL_CRT"; chmod 600 "$PANEL_SSL_KEY"
    if apachectl configtest >/dev/null 2>&1; then
        service apache24 reload >/dev/null 2>&1 || service apache24 restart >/dev/null 2>&1 || true
        echo "Cert del nodo $ip instalado en el Apache del panel y recargado."
    else
        die "apachectl configtest fallo; no se recarga"
    fi
}

cmd_show() {
    if [ -f "$CA_CRT" ]; then
        echo "== CA =="; openssl x509 -in "$CA_CRT" -noout -subject -enddate 2>/dev/null
        openssl x509 -in "$CA_CRT" -noout -fingerprint -sha256 2>/dev/null
    else echo "(no hay CA en $CA_DIR)"; fi
    for c in "$CA_DIR"/*.crt; do
        [ -f "$c" ] || continue; [ "$c" = "$CA_CRT" ] && continue
        echo "== $c =="; openssl x509 -in "$c" -noout -subject -enddate 2>/dev/null
        openssl x509 -in "$c" -noout -ext subjectAltName 2>/dev/null | grep -iE "IP|DNS" || true
    done
}

case "${1:-}" in
    init)  cmd_init ;;
    issue) shift; cmd_issue "$@" ;;
    apply) shift; cmd_apply "$@" ;;
    show)  cmd_show ;;
    *) echo "uso: $0 {init|issue <ip> [fqdn]|apply <ip>|show}" >&2; exit 1 ;;
esac

#!/bin/sh
# dns_cluster_ca.sh — CA propia del cluster DNS (Bulwark) para verificacion TLS FUERTE entre nodos
# (ajuste dns_cluster_tls_verify=ca), SIN depender de certificados publicos ni del DNS del propio
# cluster: el cert de cada nodo lleva su IP en el SAN y se verifica POR IP.
#
# Uso (como root):
#   dns_cluster_ca.sh init                Crea la CA del cluster (root key+cert, larga validez) si no existe.
#   dns_cluster_ca.sh issue <ip> [fqdn]   Emite key+cert de un nodo firmados por la CA (IP[,FQDN] en el SAN).
#   dns_cluster_ca.sh issue-all           Emite el cert de TODOS los nodos del cluster (los lee del panel).
#   dns_cluster_ca.sh apply <ip>          Instala el cert del nodo <ip> en el Apache del panel y recarga.
#   dns_cluster_ca.sh renew               Renueva el cert de ESTE nodo (misma CA) y lo aplica.
#   dns_cluster_ca.sh renew-ca            Rota la CA (respalda la anterior); luego 'issue-all' + redistribuir.
#   dns_cluster_ca.sh check [dias]        Aviso de caducidad (cron): 0=OK, 1=caduca pronto, 2=caducado.
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
DBPHP="${DBPHP:-/usr/local/bulwark/cnf/db.php}"

# --- Helpers de BD (leen los nodos del cluster desde el propio panel) -------------------------
# Devuelve "ip fqdn" por línea de todos los nodos; db_self solo el nodo propio.
db_nodes() { [ -f "$DBPHP" ] || return 1; php -r 'require $argv[1]; $p=new PDO("mysql:host=".$host.";dbname=".$dbname,$user,$pass); foreach($p->query("SELECT nd_ip_vc,nd_name_vc FROM x_dns_nodes ORDER BY nd_is_self_in DESC,nd_name_vc") as $r){echo $r["nd_ip_vc"]." ".$r["nd_name_vc"]."\n";}' "$DBPHP" 2>/dev/null; }
db_self()  { [ -f "$DBPHP" ] || return 1; php -r 'require $argv[1]; $p=new PDO("mysql:host=".$host.";dbname=".$dbname,$user,$pass); $r=$p->query("SELECT nd_ip_vc,nd_name_vc FROM x_dns_nodes WHERE nd_is_self_in=1 LIMIT 1")->fetch(); if($r)echo $r["nd_ip_vc"]." ".$r["nd_name_vc"]."\n";' "$DBPHP" 2>/dev/null; }

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

# Emite el cert de TODOS los nodos del cluster (los lee del panel). La distribución de cada
# <ip>.{key,crt} a su nodo + el ca.crt sigue siendo manual (scp): el emisor no tiene acceso al resto.
cmd_issue_all() {
    [ -f "$CA_KEY" ] || die "no hay CA; ejecuta 'init' primero"
    n=0
    db_nodes | while read -r ip fqdn; do
        [ -n "$ip" ] || continue
        cmd_issue "$ip" "$fqdn"; n=$((n+1))
    done
    echo "Emitidos los certs de los nodos del cluster. Distribuye a cada nodo su <ip>.{key,crt} + el ca.crt."
}

# Renueva el cert de ESTE nodo (misma CA -> NO hay que redistribuir ca.crt) y lo aplica a Apache.
cmd_renew() {
    self="$(db_self)"; ip="$(printf '%s' "$self" | awk '{print $1}')"; fqdn="$(printf '%s' "$self" | awk '{print $2}')"
    [ -n "$ip" ] || die "no encuentro la IP propia (nd_is_self_in=1) en x_dns_nodes"
    cmd_issue "$ip" "$fqdn"
    cmd_apply "$ip"
    echo "Cert de este nodo renovado y aplicado. La CA no cambia (no hace falta redistribuir ca.crt)."
}

# Regenera la CA (rota su clave). OJO: invalida TODOS los certs de nodo -> hay que re-emitir
# (issue-all) y redistribuir ca.crt + los nuevos certs a todos los nodos. Guarda copia de la anterior.
cmd_renew_ca() {
    if [ -f "$CA_KEY" ]; then
        ts=$(date +%Y%m%d%H%M%S); mkdir -p "$CA_DIR/old"
        cp "$CA_KEY" "$CA_DIR/old/ca-$ts.key" 2>/dev/null; cp "$CA_CRT" "$CA_DIR/old/ca-$ts.crt" 2>/dev/null
        rm -f "$CA_KEY" "$CA_CRT"
        echo "CA anterior respaldada en $CA_DIR/old/ca-$ts.*"
    fi
    cmd_init
    echo "AVISO: la CA nueva invalida los certs de nodo existentes. Ejecuta 'issue-all' y redistribuye"
    echo "       el ca.crt nuevo + cada <ip>.{key,crt} a todos los nodos, y aplica 'apply' en cada uno."
}

# Chequeo de caducidad (para cron/monitor). Sale 0=OK, 1=alguno caduca pronto, 2=alguno caducado.
cmd_check() {
    warndays="${1:-30}"; sec=$((warndays*86400)); rc=0
    _one() {
        f="$1"; lbl="$2"; [ -f "$f" ] || { echo "  --    $lbl (no existe)"; return; }
        end=$(openssl x509 -in "$f" -noout -enddate 2>/dev/null | sed 's/notAfter=//')
        if ! openssl x509 -in "$f" -noout -checkend 0 >/dev/null 2>&1; then
            echo "  CADUCADO $lbl ($end)"; [ $rc -lt 2 ] && rc=2
        elif ! openssl x509 -in "$f" -noout -checkend "$sec" >/dev/null 2>&1; then
            echo "  AVISO    $lbl caduca pronto ($end)"; [ $rc -lt 1 ] && rc=1
        else
            echo "  OK       $lbl (caduca $end)"
        fi
    }
    echo "Caducidad de la CA y certificados en $CA_DIR (umbral aviso ${warndays}d):"
    _one "$CA_CRT" "CA del cluster"
    for c in "$CA_DIR"/*.crt; do [ "$c" = "$CA_CRT" ] || { [ -f "$c" ] && _one "$c" "$(basename "$c")"; }; done
    return $rc
}

# Firma el CSR de un nodo que se une (inscripción por CSR). La clave PRIVADA del nodo NO viaja: solo
# llega su CSR (público). Seguridad: la IP debe ser un nodo REGISTRADO+habilitado (x_dns_nodes) y el
# SAN se IMPONE aquí (IP+FQDN de la BD), NO se toma del CSR -> nadie obtiene un cert de una IP ajena.
# Escribe el cert firmado en <csr>.crt (640 root:bulwark, legible por la API). Uso: sign-csr <csr> <ip>
cmd_sign_csr() {
    csr="${1:-}"; ip="${2:-}"
    [ -f "$csr" ] || die "CSR no encontrado: $csr"
    [ -f "$CA_KEY" ] || die "no hay CA en este nodo (ejecuta 'init' en el nodo emisor)"
    printf '%s' "$ip" | grep -qE '^[0-9A-Fa-f.:]+$' || die "ip inválida"
    fqdn=$(php -r 'require $argv[1]; $p=new PDO("mysql:host=".$host.";dbname=".$dbname,$user,$pass); $s=$p->prepare("SELECT nd_name_vc FROM x_dns_nodes WHERE nd_ip_vc=? AND nd_enabled_in=1 LIMIT 1"); $s->execute([$argv[2]]); echo (string)$s->fetchColumn();' "$DBPHP" "$ip" 2>/dev/null)
    [ -n "$fqdn" ] || die "la IP $ip no es un nodo registrado/habilitado en el cluster"
    openssl req -in "$csr" -noout -verify >/dev/null 2>&1 || die "CSR inválido (firma no verifica)"
    san="IP:$ip,DNS:$fqdn"
    ext="$csr.ext"
    printf 'subjectAltName=%s\nbasicConstraints=CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n' "$san" > "$ext"
    out="$csr.crt"
    openssl x509 -req -in "$csr" -CA "$CA_CRT" -CAkey "$CA_KEY" -CAcreateserial -days "$NODE_DAYS" -sha256 -extfile "$ext" -out "$out" 2>/dev/null || { rm -f "$ext"; die "firma falló"; }
    rm -f "$ext"
    chmod 640 "$out"; chown root:bulwark "$out" 2>/dev/null || true
    echo "firmado: $out (SAN: $san)"
}

case "${1:-}" in
    init)      cmd_init ;;
    sign-csr)  shift; cmd_sign_csr "$@" ;;
    issue)     shift; cmd_issue "$@" ;;
    issue-all) cmd_issue_all ;;
    apply)     shift; cmd_apply "$@" ;;
    renew)     cmd_renew ;;
    renew-ca)  cmd_renew_ca ;;
    check)     shift; cmd_check "$@" ;;
    show)      cmd_show ;;
    *) echo "uso: $0 {init|issue <ip> [fqdn]|issue-all|apply <ip>|renew|renew-ca|check [dias]|show}" >&2; exit 1 ;;
esac

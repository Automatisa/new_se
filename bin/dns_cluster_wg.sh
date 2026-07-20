#!/bin/sh
# dns_cluster_wg.sh — Túnel WireGuard entre los nodos del cluster DNS (Bulwark). Mete la
# sincronización de la API y el AXFR DENTRO del túnel (cifrado + autenticado por claves de nodo),
# de modo que el canal entre nodos no viaja por Internet público. Los registros A del DNS siguen
# usando la IP PÚBLICA (nd_ip_vc); el transporte usa la IP del túnel (nd_sync_ip_vc).
#
# Uso (como root, en CADA nodo):
#   dns_cluster_wg.sh init <tunnel_ip/cidr> [listen_port]   Crea wg0 con esa IP de túnel y arranca.
#   dns_cluster_wg.sh peer <pubkey> <endpoint_host:port> <peer_tunnel_ip>   Añade un peer.
#   dns_cluster_wg.sh setself <tunnel_ip>   Fija nd_sync_ip_vc del PROPIO nodo (lo anuncia a la malla).
#   dns_cluster_wg.sh pubkey                Muestra la clave pública de este nodo.
#   dns_cluster_wg.sh show                  Estado del túnel (wg show) + IP de sync propia.
#
# Reparto de IPs de túnel sugerido: 10.99.0.X (una por nodo). El endpoint de un peer es su IP
# PÚBLICA:puerto (por ahí se establece el túnel una vez; luego el tráfico va cifrado).
set -eu
WG_IF="${WG_IF:-wg0}"
WG_DIR="${WG_DIR:-/usr/local/etc/wireguard}"
WG_CONF="$WG_DIR/$WG_IF.conf"
WG_PORT_DEF=51820
DBPHP="${DBPHP:-/usr/local/bulwark/cnf/db.php}"

die() { echo "ERROR: $*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || die "ejecuta como root"

ensure_tools() {
    kldload if_wg 2>/dev/null || true
    if ! command -v wg >/dev/null 2>&1; then
        echo "Instalando wireguard-tools..."; pkg install -y wireguard-tools >/dev/null 2>&1 || die "no se pudo instalar wireguard-tools"
    fi
}

# Levanta/re-aplica la interfaz SIN depender de wg-quick (que no está en wireguard-tools-lite):
# crea wgN con ifconfig, le pone la Address, y aplica [Interface]/[Peer] con 'wg setconf' (una
# versión "stripped" del conf sin la línea Address, que es directiva de wg-quick, no de wg).
_apply() {
    kldload if_wg 2>/dev/null || true
    ifconfig "$WG_IF" >/dev/null 2>&1 || ifconfig "$WG_IF" create >/dev/null 2>&1 || die "no se pudo crear $WG_IF"
    addr=$(grep -i '^[[:space:]]*Address' "$WG_CONF" | head -1 | sed 's/.*=[[:space:]]*//')
    [ -n "$addr" ] && ifconfig "$WG_IF" inet "$addr" 2>/dev/null || true
    strip="$WG_DIR/.$WG_IF.setconf"
    grep -iv '^[[:space:]]*Address' "$WG_CONF" > "$strip"
    wg setconf "$WG_IF" "$strip" || { rm -f "$strip"; die "wg setconf falló"; }
    rm -f "$strip"
    ifconfig "$WG_IF" up
    # Persistencia mínima entre reinicios (no hay rc 'wireguard' en tools-lite): rc.local re-aplica.
    _persist
}

_persist() {
    RCL=/etc/rc.local
    LINE="[ -x /usr/local/bulwark/bin/dns_cluster_wg.sh ] && /usr/local/bulwark/bin/dns_cluster_wg.sh up >/dev/null 2>&1"
    if [ ! -f "$RCL" ] || ! grep -q "dns_cluster_wg.sh up" "$RCL" 2>/dev/null; then
        [ -f "$RCL" ] || printf '#!/bin/sh\n' > "$RCL"
        echo "$LINE" >> "$RCL"; chmod 0755 "$RCL"
    fi
}

cmd_init() {
    addr="${1:-}"; port="${2:-$WG_PORT_DEF}"
    [ -n "$addr" ] || die "uso: init <tunnel_ip/cidr> [listen_port]  (ej: init 10.99.0.1/24)"
    ensure_tools
    mkdir -p "$WG_DIR"; chmod 700 "$WG_DIR"
    if [ ! -f "$WG_DIR/$WG_IF.key" ]; then
        (umask 077; wg genkey > "$WG_DIR/$WG_IF.key")
        wg pubkey < "$WG_DIR/$WG_IF.key" > "$WG_DIR/$WG_IF.pub"
    fi
    priv=$(cat "$WG_DIR/$WG_IF.key")
    if [ ! -f "$WG_CONF" ]; then
        cat > "$WG_CONF" <<CONF
[Interface]
Address = $addr
ListenPort = $port
PrivateKey = $priv
CONF
        chmod 600 "$WG_CONF"
    fi
    _apply
    echo "wg0 arriba con $addr (puerto $port). Clave pública de este nodo:"
    cat "$WG_DIR/$WG_IF.pub"
    echo "-> pásala a los otros nodos (peer) y añade allí este endpoint: <IP_PUBLICA_DE_ESTE_NODO>:$port"
}

cmd_peer() {
    pub="${1:-}"; endp="${2:-}"; tip="${3:-}"
    { [ -n "$pub" ] && [ -n "$endp" ] && [ -n "$tip" ]; } || die "uso: peer <pubkey> <endpoint_host:port> <peer_tunnel_ip>"
    [ -f "$WG_CONF" ] || die "primero 'init'"
    if grep -q "$pub" "$WG_CONF" 2>/dev/null; then echo "peer ya presente"; return 0; fi
    cat >> "$WG_CONF" <<CONF

[Peer]
PublicKey = $pub
AllowedIPs = $tip/32
Endpoint = $endp
PersistentKeepalive = 25
CONF
    _apply
    echo "peer añadido ($tip vía $endp)."
}

cmd_up() { [ -f "$WG_CONF" ] || die "no hay $WG_CONF (init primero)"; _apply; }

cmd_setself() {
    tip="${1:-}"; [ -n "$tip" ] || die "uso: setself <tunnel_ip>"
    [ -f "$DBPHP" ] || die "no encuentro $DBPHP"
    php -r 'require $argv[1]; $p=new PDO("mysql:host=".$host.";dbname=".$dbname,$user,$pass); $p->prepare("UPDATE x_dns_nodes SET nd_sync_ip_vc=? WHERE nd_is_self_in=1")->execute([$argv[2]]); echo "nd_sync_ip_vc(self)=".$argv[2]."\n";' "$DBPHP" "$tip"
}

cmd_pubkey() { [ -f "$WG_DIR/$WG_IF.pub" ] && cat "$WG_DIR/$WG_IF.pub" || die "no hay clave; ejecuta init"; }

cmd_show() {
    wg show 2>/dev/null || echo "(wg sin interfaces)"
    if [ -f "$DBPHP" ]; then
        php -r 'require $argv[1]; $p=new PDO("mysql:host=".$host.";dbname=".$dbname,$user,$pass); $r=$p->query("SELECT nd_sync_ip_vc FROM x_dns_nodes WHERE nd_is_self_in=1")->fetchColumn(); echo "sync_ip propio: ".($r?:"(no fijado)")."\n";' "$DBPHP" 2>/dev/null || true
    fi
}

case "${1:-}" in
    init)    shift; cmd_init "$@" ;;
    up)      cmd_up ;;
    peer)    shift; cmd_peer "$@" ;;
    setself) shift; cmd_setself "$@" ;;
    pubkey)  cmd_pubkey ;;
    show)    cmd_show ;;
    *) echo "uso: $0 {init <ip/cidr> [port]|peer <pubkey> <endpoint> <tunnel_ip>|setself <tunnel_ip>|pubkey|show}" >&2; exit 1 ;;
esac

<?php
/**
 * bin/api.php — API REST v1
 *
 * Autenticación : Bearer token (gestionado desde API Manager).
 * Scopes        : read | write | admin
 * Auditoría     : x_api_log (un registro por request).
 * Rate limiting : 60 req/min por token.
 */

$rawPath  = str_replace("\\", "/", dirname(__FILE__));
$rootPath = str_replace("/bin", "/", $rawPath);
chdir($rootPath);

require_once 'dryden/loader.inc.php';
require_once 'dryden/sys/privilege.class.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── helpers ───────────────────────────────────────────────────────────────────

function api_respond(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_auth_header(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION']))          return $_SERVER['HTTP_AUTHORIZATION'];
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    foreach (function_exists('getallheaders') ? getallheaders() : [] as $k => $v) {
        if (strtolower($k) === 'authorization') return $v;
    }
    return '';
}

// Autenticación DEDICADA del cluster DNS, independiente de los tokens de usuario y
// del kill-switch de la API de usuarios: exige el flag dns_cluster_enabled, el token
// COMPARTIDO del cluster (dns_cluster_token) y, opcionalmente, que la IP de origen sea
// un nodo peer registrado. Así, desactivar la API de usuarios NO aísla el DNS.
function cluster_auth(bool $requirePeerIp = true): void
{
    global $zdbh;
    if (ctrl_options::GetSystemOption('dns_cluster_enabled') !== 'true') {
        api_respond(403, ['error' => 'Forbidden', 'message' => 'El cluster DNS está desactivado en este nodo.', 'code' => 403]);
    }
    $expected = (string)ctrl_options::GetSystemOption('dns_cluster_token');
    $presented = '';
    if (preg_match('/^Bearer\s+(\S+)$/i', get_auth_header(), $mm)) { $presented = $mm[1]; }
    if ($expected === '' || !hash_equals($expected, $presented)) {
        header('WWW-Authenticate: Bearer realm="Bulwark Cluster"');
        api_respond(401, ['error' => 'Unauthorized', 'message' => 'Token de cluster ausente o inválido.', 'code' => 401]);
    }
    if ($requirePeerIp) {
        // El peer puede contactar por su IP pública o por la de SINCRONIZACIÓN (túnel WireGuard):
        // ambas cuentan como origen legítimo del cluster.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $st = $zdbh->prepare("SELECT COUNT(*) FROM x_dns_nodes WHERE (nd_ip_vc=:ip OR nd_sync_ip_vc=:ip2) AND nd_is_self_in=0 AND nd_enabled_in=1");
        $st->execute([':ip' => $ip, ':ip2' => $ip]);
        if ((int)$st->fetchColumn() === 0) {
            api_respond(403, ['error' => 'Forbidden', 'message' => 'La IP de origen no es un nodo del cluster.', 'code' => 403]);
        }
    }
}

// Scope levels: read < write < reseller < admin
function require_scope(string $required): void
{
    global $token_scope;
    $levels = ['read' => 0, 'write' => 1, 'reseller' => 2, 'admin' => 3];
    if (($levels[$token_scope] ?? -1) < ($levels[$required] ?? 99)) {
        api_respond(403, [
            'error'   => 'Forbidden',
            'message' => "Esta operación requiere scope '$required'. El token tiene scope '$token_scope'.",
            'code'    => 403,
        ]);
    }
}

// Devuelve fila de x_accounts o responde 404/403. Aplica filtro según scope del token.
function resolve_account(string $username): array
{
    global $zdbh, $token_user_fk, $token_is_reseller;
    $q = $zdbh->prepare(
        "SELECT a.ac_id_pk, a.ac_user_vc, a.ac_email_vc, a.ac_package_fk, a.ac_group_fk,
                a.ac_reseller_fk, a.ac_enabled_in, a.ac_created_ts,
                g.ug_name_vc  AS group_name,
                pk.pk_name_vc AS package_name,
                p.ud_fullname_vc, p.ud_address_tx, p.ud_postcode_vc, p.ud_phone_vc
         FROM x_accounts a
         LEFT JOIN x_groups   g  ON g.ug_id_pk   = a.ac_group_fk
         LEFT JOIN x_packages pk ON pk.pk_id_pk   = a.ac_package_fk
         LEFT JOIN x_profiles p  ON p.ud_user_fk  = a.ac_id_pk
         WHERE a.ac_user_vc = :u AND a.ac_deleted_ts IS NULL
         LIMIT 1"
    );
    $q->bindParam(':u', $username);
    $q->execute();
    $row = $q->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        api_respond(404, ['error' => 'Not Found', 'message' => "Usuario '$username' no encontrado.", 'code' => 404]);
    }
    if ($token_is_reseller) {
        // Reseller: puede acceder a su propia cuenta o a cuentas que él creó
        $is_self   = ((int)$row['ac_id_pk']      === $token_user_fk);
        $is_client = ((int)$row['ac_reseller_fk'] === $token_user_fk);
        if (!$is_self && !$is_client) {
            api_respond(403, ['error' => 'Forbidden', 'message' => 'Solo puedes gestionar cuentas de tus propios usuarios.', 'code' => 403]);
        }
    } elseif ($token_user_fk !== null) {
        // Token de usuario: solo su propia cuenta
        if ((int)$row['ac_id_pk'] !== $token_user_fk) {
            api_respond(403, ['error' => 'Forbidden', 'message' => 'Solo puedes acceder a tus propios recursos.', 'code' => 403]);
        }
    }
    return $row;
}

function format_account(array $r, bool $full = false): array
{
    $out = [
        'user_id'  => (int)$r['ac_id_pk'],
        'username' => $r['ac_user_vc'],
        'email'    => $r['ac_email_vc'],
        'group'    => $r['group_name']   ?? null,
        'package'  => $r['package_name'] ?? null,
        'enabled'  => (bool)(int)$r['ac_enabled_in'],
        'created'  => date('c', (int)$r['ac_created_ts']),
    ];
    if ($full) {
        $out['fullname'] = $r['ud_fullname_vc'] ?? '';
        $out['address']  = $r['ud_address_tx']  ?? '';
        $out['postcode'] = $r['ud_postcode_vc'] ?? '';
        $out['phone']    = $r['ud_phone_vc']    ?? '';
    }
    return $out;
}

// Devuelve fila de x_vhosts + propietario o responde 404/403. Aplica filtro según scope.
function resolve_domain(string $domain): array
{
    global $zdbh, $token_user_fk, $token_is_reseller;
    $q = $zdbh->prepare(
        "SELECT v.vh_id_pk, v.vh_name_vc, v.vh_acc_fk, a.ac_user_vc, a.ac_reseller_fk
           FROM x_vhosts v
           JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk
          WHERE v.vh_name_vc = :d AND v.vh_deleted_ts IS NULL AND v.vh_type_in = 1
          LIMIT 1"
    );
    $q->bindParam(':d', $domain);
    $q->execute();
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        api_respond(404, ['error' => 'Not Found', 'message' => "Dominio '$domain' no encontrado.", 'code' => 404]);
    }
    if ($token_is_reseller) {
        // Reseller: puede operar sobre sus propios dominios o los de sus clientes
        $is_own_domain    = ((int)$row['vh_acc_fk']      === $token_user_fk);
        $is_client_domain = ((int)$row['ac_reseller_fk'] === $token_user_fk);
        if (!$is_own_domain && !$is_client_domain) {
            api_respond(403, ['error' => 'Forbidden', 'message' => 'Solo puedes acceder a dominios de tu cuenta o de tus usuarios.', 'code' => 403]);
        }
    } elseif ($token_user_fk !== null) {
        if ((int)$row['vh_acc_fk'] !== $token_user_fk) {
            api_respond(403, ['error' => 'Forbidden', 'message' => 'Solo puedes acceder a dominios de tu propia cuenta.', 'code' => 403]);
        }
    }
    return $row;
}

// Añade el vhost a dns_hasupdates para que el daemon regenere su zona BIND.
function trigger_dns_update(int $vhost_id): void
{
    global $zdbh;
    $q = $zdbh->prepare("SELECT so_value_tx FROM x_settings WHERE so_name_vc='dns_hasupdates'");
    $q->execute();
    $current = (string)($q->fetchColumn() ?: '');
    $ids = $current !== '' ? explode(',', $current) : [];
    if (!in_array((string)$vhost_id, $ids, true)) {
        $ids[] = (string)$vhost_id;
        $zdbh->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='dns_hasupdates'")
             ->execute([':v' => implode(',', $ids)]);
    }
}

// Inserta un registro en x_dns. Devuelve el dn_id_pk generado. Todos los valores vienen de fuentes internas.
function insert_dns_record(int $acc_fk, string $domain_name, int $vhost_id, string $type, string $host, string $target, int $ttl, int $priority = 0, int $weight = 0, int $port = 0): int
{
    global $zdbh;
    $stmt = $zdbh->prepare(
        "INSERT INTO x_dns (dn_acc_fk, dn_name_vc, dn_vhost_fk, dn_type_vc, dn_host_vc, dn_target_vc,
                            dn_ttl_in, dn_priority_in, dn_weight_in, dn_port_in, dn_created_ts)
         VALUES (:acc, :name, :vid, :type, :host, :target, :ttl, :pri, :wgt, :prt, :ts)"
    );
    $stmt->execute([
        ':acc' => $acc_fk, ':name' => $domain_name, ':vid' => $vhost_id,
        ':type' => $type, ':host' => $host, ':target' => $target,
        ':ttl' => $ttl, ':pri' => $priority, ':wgt' => $weight, ':prt' => $port, ':ts' => time(),
    ]);
    return (int)$zdbh->lastInsertId();
}

// Valida un hostname DNS. Devuelve el valor limpio o null si es inválido.
// Rechaza: newlines, semicolons, default._domainkey (gestionado por /dkim/regenerate).
function validate_dns_host(string $host): ?string
{
    if ($host === '' || strlen($host) > 253)         return null;
    if (preg_match('/[\r\n\t\0; ]/', $host))         return null;
    if ($host === 'default._domainkey')              return null;
    if ($host === '@' || $host === '*')              return $host;
    $check = str_starts_with($host, '*.') ? substr($host, 2) : $host;
    // Labels: letras, dígitos, guiones, guiones bajos (SRV usa _tcp._sip)
    if (!preg_match('/^[a-zA-Z0-9_]([a-zA-Z0-9_\-]{0,61}[a-zA-Z0-9_])?(\.[a-zA-Z0-9_]([a-zA-Z0-9_\-]{0,61}[a-zA-Z0-9_])?)*$/', $check)) {
        return null;
    }
    return $host;
}

// Valida target según tipo. Devuelve array [target, priority, weight, port] limpio o null si inválido.
function validate_dns_target(string $type, string $target, int $priority, int $weight, int $port): ?array
{
    if (preg_match('/[\r\n\t\0]/', $target)) return null;

    $hostname_re = '/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/';

    switch ($type) {
        case 'A':
            if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return null;
            break;
        case 'AAAA':
            if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) return null;
            break;
        case 'CNAME':
        case 'NS':
            if ($target !== '@') {
                $t = rtrim($target, '.');
                if (!preg_match($hostname_re, $t) || strlen($t) > 253) return null;
            }
            break;
        case 'MX':
            if ($priority < 0 || $priority > 65535) return null;
            $t = rtrim($target, '.');
            if (!preg_match($hostname_re, $t) || strlen($t) > 253) return null;
            break;
        case 'SRV':
            if ($priority < 0 || $priority > 65535) return null;
            if ($weight   < 0 || $weight   > 65535) return null;
            if ($port     < 1 || $port     > 65535) return null;
            $t = rtrim($target, '.');
            if (!preg_match($hostname_re, $t) || strlen($t) > 253) return null;
            break;
        case 'TXT':
            if (strlen($target) > 2048) return null;
            break;
        case 'CAA':
            // Formato: <flag> <tag> "<value>"   ej: 0 issue "letsencrypt.org"
            if (!preg_match('/^\d+\s+(issue|issuewild|iodef)\s+"[^"\r\n]*"$/', $target)) return null;
            break;
        case 'NAPTR':
            // orden pref "flags" "service" "regexp" replacement
            if (!preg_match('/^\d+\s+\d+\s+"[^"]*"\s+"[^"]*"\s+"[^"]*"\s+\S+$/', $target)) return null;
            break;
        case 'SSHFP':
            // algoritmo fp-type hex  ej: 4 2 abc123...
            if (!preg_match('/^[1-4]\s+[12]\s+[0-9a-fA-F]{20,128}$/', $target)) return null;
            break;
        case 'TLSA':
            // uso selector matching hex  ej: 3 1 1 abc123...
            if (!preg_match('/^[0-3]\s+[01]\s+[012]\s+[0-9a-fA-F]{2,512}$/', $target)) return null;
            break;
        case 'URI':
            // prioridad peso "uri"  ej: 10 1 "https://example.com/"
            if (!preg_match('/^\d+\s+\d+\s+"[^"\r\n]*"$/', $target)) return null;
            break;
        default:
            return null;
    }
    return ['target' => $target, 'priority' => $priority, 'weight' => $weight, 'port' => $port];
}

// ── 1. Parsear ruta ───────────────────────────────────────────────────────────
// El kill-switch de la API de usuarios (api_rest_enabled) se comprueba MÁS ABAJO,
// después de resolver las rutas del cluster DNS, para que desactivar la API de
// usuarios NO aísle el DNS (el cluster tiene su propio interruptor dns_cluster_enabled).

$uri      = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$parts    = array_values(array_filter(explode('/', $uri), fn($s) => $s !== ''));
$v1_pos   = array_search('v1', $parts);
$segments = $v1_pos !== false ? array_slice($parts, $v1_pos + 1) : [];

$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$resource = $segments[0] ?? '';
$res_id   = isset($segments[1]) && $segments[1] !== '' ? $segments[1] : null;
$sub      = $segments[2] ?? '';
$sub2     = $segments[3] ?? '';
$sub3     = $segments[4] ?? '';

// ── 3. Auditoría: registro al finalizar la request ────────────────────────────

$al_token_id = null;
$al_resource = $method . ' /v1/' . $resource . ($res_id ? '/' . $res_id : '') . ($sub ? '/' . $sub : '') . ($sub2 ? '/' . $sub2 : '') . ($sub3 ? '/' . $sub3 : '');

register_shutdown_function(function () use (&$al_token_id, &$al_resource, $method) {
    global $zdbh;
    if (!$zdbh) return;
    $status = http_response_code() ?: 0;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
    try {
        $q = $zdbh->prepare(
            "INSERT INTO x_api_log (al_token_fk, al_method_vc, al_resource_vc, al_ip_vc, al_status_in, al_ts)
             VALUES (:tok, :meth, :res, :ip, :status, NOW())"
        );
        $q->execute([
            ':tok'    => $al_token_id,
            ':meth'   => $method,
            ':res'    => $al_resource,
            ':ip'     => $ip,
            ':status' => $status,
        ]);
    } catch (Throwable $e) { /* el log nunca rompe la respuesta */ }
});

// ── Cluster DNS: API dedicada, ANTES del auth de usuario. Auth propia (cluster_auth)
// e independiente del kill-switch de la API de usuarios: desactivar la API de usuarios
// no aísla el DNS. Rutas: GET /cluster/zones, GET /cluster/tsig, POST /cluster/nodes.
if ($resource === 'cluster') {
    if ($method === 'GET' && $res_id === 'zones') {
        cluster_auth(true);
        $rows = $zdbh->query("SELECT vh_name_vc FROM x_vhosts WHERE vh_type_in=1 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc")
                     ->fetchAll(PDO::FETCH_COLUMN);
        api_respond(200, ['zones' => array_values(array_map('strtolower', $rows))]);
    }
    if ($method === 'GET' && $res_id === 'tsig') {
        cluster_auth(false);
        api_respond(200, ['tsig' => ctrl_options::GetSystemOption('dns_tsig_key')]);
    }
    if ($method === 'GET' && $res_id === 'nodes') {
        // Registro de la malla: devuelve TODOS los nodos habilitados (incluido self) para que
        // cualquier nodo construya la malla completa (N nodos). Auth solo por token (sin exigir
        // IP de peer) para no bloquear el bootstrap de un nodo que aún no es peer conocido.
        cluster_auth(false);
        // Devuelve TODOS los nodos (incl. deshabilitados) con su estado 'enabled', para que
        // el pruning (tombstone) se propague por la malla. api_url SIEMPRE por IP: el cluster
        // no debe depender de su propio DNS (TLS entre nodos con VERIFYPEER=false).
        $rows = $zdbh->query("SELECT nd_name_vc, nd_ip_vc, nd_sync_ip_vc, nd_enabled_in FROM x_dns_nodes ORDER BY nd_name_vc")
                     ->fetchAll(PDO::FETCH_ASSOC);
        $nodes = [];
        foreach ($rows as $r) {
            $nodes[] = [
                'name'    => strtolower($r['nd_name_vc']),
                'ip'      => $r['nd_ip_vc'],
                // IP de sincronización (túnel WireGuard si lo hay) para que la malla la propague; el
                // transporte entre nodos la usa y los registros A del DNS siguen con la pública.
                'sync_ip' => (!empty($r['nd_sync_ip_vc']) ? $r['nd_sync_ip_vc'] : ''),
                'api_url' => 'https://' . $r['nd_ip_vc'] . '/bin/api.php',
                'enabled' => ((int)$r['nd_enabled_in'] === 1),
            ];
        }
        api_respond(200, ['nodes' => $nodes]);
    }
    if ($method === 'POST' && $res_id === 'nodes') {
        cluster_auth(false);
        $body      = json_decode(file_get_contents('php://input'), true) ?? [];
        $name      = strtolower(trim($body['name'] ?? ''));
        $ip        = trim($body['ip'] ?? '');
        $syncIp    = trim($body['sync_ip'] ?? '');
        $apiu      = trim($body['api_url'] ?? '');
        $panelHost = strtolower(trim($body['panel_host'] ?? ''));
        $nsHost    = strtolower(trim($body['ns_host'] ?? ''));
        if ($name === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            api_respond(422, ['error' => 'Unprocessable Entity', 'message' => 'name e ip válidos son obligatorios.', 'code' => 422]);
        }
        if ($syncIp !== '' && !filter_var($syncIp, FILTER_VALIDATE_IP)) { $syncIp = ''; }
        $syncVal = ($syncIp !== '' ? $syncIp : null);   // IP de sync (túnel); NULL = usar la pública
        $zdbh->prepare(
            "INSERT INTO x_dns_nodes (nd_name_vc, nd_ip_vc, nd_sync_ip_vc, nd_api_url_vc, nd_is_self_in, nd_enabled_in, nd_created_ts)
             VALUES (:n, :i, :s, :u, 0, 1, :ts)
             ON DUPLICATE KEY UPDATE nd_ip_vc=:i2, nd_sync_ip_vc=:s2, nd_api_url_vc=:u2, nd_enabled_in=1"
        )->execute([':n' => $name, ':i' => $ip, ':s' => $syncVal, ':u' => ($apiu ?: null), ':ts' => time(), ':i2' => $ip, ':s2' => $syncVal, ':u2' => ($apiu ?: null)]);

        // Añadir a la zona del proveedor los A del nuevo nodo (ns y panel), si procede
        $provider = strtolower((string)ctrl_options::GetSystemOption('dns_provider_domain'));
        $added = [];
        if ($provider !== '') {
            $pv = $zdbh->prepare("SELECT vh_id_pk, vh_acc_fk FROM x_vhosts WHERE vh_name_vc=:d AND vh_deleted_ts IS NULL LIMIT 1");
            $pv->execute([':d' => $provider]);
            if ($prow = $pv->fetch()) {
                $vid = (int)$prow['vh_id_pk']; $uid = (int)$prow['vh_acc_fk'];
                $suffix = '.' . $provider;
                $strip = function ($fqdn) use ($suffix) {
                    return (strlen($fqdn) > strlen($suffix) && substr($fqdn, -strlen($suffix)) === $suffix)
                        ? substr($fqdn, 0, -strlen($suffix)) : '';
                };
                // Cada entrada: [host-relativo, ttl, fqdn-del-NS-o-vacío]. El 3er campo marca la
                // entrada del NAMESERVER del nodo (para además darla de alta en el RRset NS).
                foreach ([[$strip($nsHost), 172800, $nsHost], [$strip($panelHost), 3600, '']] as $rec) {
                    $host = $rec[0]; $ttl = $rec[1]; $nsFqdn = $rec[2];
                    if ($host === '') { continue; }
                    // (1) Registro A: el nodo RECLAMA su propio ns/panel -> debe apuntar a SU IP. Si ya
                    // existe (p.ej. la zona base del primario pre-creó ns2 -> IP-del-primario como
                    // fallback de un solo nodo), se REAPUNTA; si no, se inserta. Antes se omitía si
                    // existía -> ns2 se quedaba en el primario y no había redundancia real de NS.
                    $ex = $zdbh->prepare("SELECT dn_id_pk, dn_target_vc FROM x_dns WHERE dn_vhost_fk=:v AND dn_type_vc='A' AND dn_host_vc=:h AND dn_deleted_ts IS NULL LIMIT 1");
                    $ex->execute([':v' => $vid, ':h' => $host]);
                    if ($erow = $ex->fetch()) {
                        if ((string)$erow['dn_target_vc'] !== $ip) {
                            $zdbh->prepare("UPDATE x_dns SET dn_target_vc=:ip WHERE dn_id_pk=:id")
                                 ->execute([':ip' => $ip, ':id' => (int)$erow['dn_id_pk']]);
                            $added[] = $host;
                        }
                    } else {
                        $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,dn_name_vc,dn_vhost_fk,dn_type_vc,dn_host_vc,dn_ttl_in,dn_target_vc,dn_created_ts)
                                        VALUES (:u,:name,:v,'A',:h,:ttl,:ip,:ts)")
                             ->execute([':u' => $uid, ':name' => $provider, ':v' => $vid, ':h' => $host, ':ttl' => $ttl, ':ip' => $ip, ':ts' => time()]);
                        $added[] = $host;
                    }
                    // (2) Registro NS de DELEGACIÓN: si esta entrada es el nameserver del nodo, que esté
                    // en el RRset NS de la zona (@ NS <fqdn>). Así ns3/ns4… son nameservers autoritativos
                    // de primera clase (no solo un A), y N nameservers públicos funcionan de verdad.
                    // Idempotente: solo inserta si no existe ya ese target.
                    if ($nsFqdn !== '') {
                        $exns = $zdbh->prepare("SELECT COUNT(*) FROM x_dns WHERE dn_vhost_fk=:v AND dn_type_vc='NS' AND dn_host_vc='@' AND dn_target_vc=:t AND dn_deleted_ts IS NULL");
                        $exns->execute([':v' => $vid, ':t' => $nsFqdn]);
                        if ((int)$exns->fetchColumn() === 0) {
                            $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,dn_name_vc,dn_vhost_fk,dn_type_vc,dn_host_vc,dn_ttl_in,dn_target_vc,dn_created_ts)
                                            VALUES (:u,:name,:v,'NS','@',172800,:t,:ts)")
                                 ->execute([':u' => $uid, ':name' => $provider, ':v' => $vid, ':t' => $nsFqdn, ':ts' => time()]);
                            $added[] = 'NS:' . $nsFqdn;
                        }
                    }
                }
                if ($added) {
                    // dns_hasupdates es una LISTA de IDs de vhost separada por comas (no un
                    // booleano). Poner 'true' era un no-op: no casaba ningún id y el daemon
                    // nunca reescribía la zona del proveedor con los A de ns/panel del nodo.
                    // Añadimos el id del vhost proveedor (sin pisar ids ya pendientes).
                    $cur = (string)ctrl_options::GetSystemOption('dns_hasupdates');
                    $ids = array_values(array_filter(array_map('trim', explode(',', $cur)), fn($x) => ctype_digit($x)));
                    if (!in_array((string)$vid, $ids, true)) { $ids[] = (string)$vid; }
                    $zdbh->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='dns_hasupdates'")
                         ->execute([':v' => implode(',', $ids)]);
                }
            }
        }
        api_respond(201, ['message' => 'Nodo registrado en el cluster.', 'node' => $name, 'records_added' => $added]);
    }
    // Inscripción por CSR: un nodo que se une manda su CSR (público); este nodo (que tiene la CA)
    // lo firma vía un wrapper root (la API-bulwark no puede leer la clave de la CA) y devuelve
    // {cert, ca}. La clave PRIVADA del solicitante nunca viaja. Fallback: si no hay CA aquí -> 503.
    if ($method === 'POST' && $res_id === 'sign-csr') {
        cluster_auth(false);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $csr  = (string)($body['csr'] ?? '');
        $ip   = trim((string)($body['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')));
        if (strpos($csr, 'BEGIN CERTIFICATE REQUEST') === false || !filter_var($ip, FILTER_VALIDATE_IP)) {
            api_respond(422, ['error' => 'Unprocessable Entity', 'message' => 'csr (PEM) e ip válidos son obligatorios.', 'code' => 422]);
        }
        $st = $zdbh->prepare("SELECT COUNT(*) FROM x_dns_nodes WHERE nd_ip_vc=:ip AND nd_enabled_in=1");
        $st->execute([':ip' => $ip]);
        if (!(int)$st->fetchColumn()) {
            api_respond(403, ['error' => 'Forbidden', 'message' => 'La IP no es un nodo registrado/habilitado del cluster.', 'code' => 403]);
        }
        $dir = '/var/bulwark/run/csr';
        if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
        $csrFile = $dir . '/' . bin2hex(random_bytes(8)) . '.csr';
        if (@file_put_contents($csrFile, $csr) === false) {
            api_respond(500, ['error' => 'Internal Server Error', 'message' => 'No se pudo escribir el CSR en el spool.', 'code' => 500]);
        }
        $signed = false;
        try { $signed = privilege::run('cluster_sign_csr', [$csrFile, $ip]); } catch (\Throwable $e) { $signed = false; }
        $crtFile = $csrFile . '.crt';
        $cert = (is_readable($crtFile)) ? (string)@file_get_contents($crtFile) : '';
        $caPath = (string)ctrl_options::GetSystemOption('dns_cluster_ca_file');
        if ($caPath === '' || !is_readable($caPath)) { $caPath = '/usr/local/etc/bulwark/cluster-ca/ca.crt'; }
        $ca = is_readable($caPath) ? (string)@file_get_contents($caPath) : '';
        @unlink($csrFile); @unlink($crtFile);
        if (strpos($cert, 'BEGIN CERTIFICATE') === false) {
            api_respond(503, ['error' => 'Service Unavailable', 'message' => 'La CA no está disponible en este nodo o la firma falló; usa el flujo manual (dns_cluster_ca.sh init/issue-all + scp).', 'code' => 503]);
        }
        api_respond(200, ['cert' => $cert, 'ca' => $ca]);
    }
    api_respond(404, ['error' => 'Not Found', 'message' => 'Ruta de cluster no encontrada.', 'code' => 404]);
}

// ── 4. API de usuarios habilitada ─────────────────────────────────────────────
// El cluster DNS ya se resolvió arriba (es independiente); este interruptor solo
// afecta a las rutas de la API de usuarios.
if (ctrl_options::GetSystemOption('api_rest_enabled') !== 'true') {
    $api_msg = ctrl_options::GetSystemOption('api_disabled_message');
    if (empty($api_msg)) {
        $api_msg = 'La API se encuentra temporalmente deshabilitada por el administrador del servidor.';
    }
    api_respond(503, ['error' => 'Service Unavailable', 'message' => $api_msg, 'code' => 503]);
}

// ── 5. Autenticación Bearer ───────────────────────────────────────────────────

if (!preg_match('/^Bearer\s+(\S+)$/i', get_auth_header(), $m)) {
    header('WWW-Authenticate: Bearer realm="Bulwark API v1"');
    api_respond(401, ['error' => 'Unauthorized', 'message' => 'Falta o es inválida la cabecera Authorization.', 'code' => 401]);
}
$raw_token = $m[1];

// Brute-force: bloquear IPs con más de 20 errores 401 en los últimos 10 minutos
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$bf = $zdbh->prepare(
    "SELECT COUNT(*) FROM x_api_log
      WHERE al_ip_vc = :ip AND al_status_in = 401
        AND al_ts >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
);
$bf->execute([':ip' => $client_ip]);
if ((int)$bf->fetchColumn() >= 20) {
    api_respond(429, ['error' => 'Too Many Requests', 'message' => 'Demasiados intentos fallidos. Espera 10 minutos.', 'code' => 429]);
}

// Autenticación por hash SHA-256 — el valor plain del token nunca se almacena en BD.
// También recuperamos at_allowed_ip_vc y at_expires_ts para validarlos a continuación.
$token_hash = hash('sha256', $raw_token);
$stmt = $zdbh->prepare(
    "SELECT t.at_id_pk, t.at_name_vc, t.at_scope_vc, t.at_user_fk,
            t.at_allowed_ip_vc, t.at_expires_ts,
            a.ac_group_fk, a.ac_api_allowed_in, a.ac_api_self_in, a.ac_api_revoked_in
       FROM x_api_tokens t
       LEFT JOIN x_accounts a ON a.ac_id_pk = t.at_user_fk
      WHERE t.at_token_hash_vc = :hash AND t.at_enabled_in = 1 AND t.at_deleted_ts IS NULL
        AND (t.at_user_fk IS NULL OR (a.ac_enabled_in = 1 AND a.ac_deleted_ts IS NULL))
      LIMIT 1"
);
$stmt->bindParam(':hash', $token_hash);
$stmt->execute();
$token_row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$token_row) {
    // Registrar fallo de autenticación inmediatamente (para que el brute-force lo cuente)
    try {
        $zdbh->prepare(
            "INSERT INTO x_api_log (al_token_fk, al_method_vc, al_resource_vc, al_ip_vc, al_status_in, al_ts)
             VALUES (NULL, :meth, :res, :ip, 401, NOW())"
        )->execute([':meth' => $method, ':res' => $al_resource, ':ip' => $client_ip]);
    } catch (Throwable $e) {}
    api_respond(401, ['error' => 'Unauthorized', 'message' => 'Token inválido o revocado.', 'code' => 401]);
}

$al_token_id       = (int)$token_row['at_id_pk'];
$token_scope       = $token_row['at_scope_vc'] ?? 'read';
$token_user_fk     = $token_row['at_user_fk'] !== null ? (int)$token_row['at_user_fk'] : null;
$token_is_reseller = ($token_scope === 'reseller');

// Validar expiración del token
if ($token_row['at_expires_ts'] !== null && strtotime($token_row['at_expires_ts']) <= time()) {
    try {
        $zdbh->prepare(
            "INSERT INTO x_api_log (al_token_fk, al_method_vc, al_resource_vc, al_ip_vc, al_status_in, al_ts)
             VALUES (:tok, :meth, :res, :ip, 401, NOW())"
        )->execute([':tok' => $al_token_id, ':meth' => $method, ':res' => $al_resource, ':ip' => $client_ip]);
    } catch (Throwable $e) {}
    api_respond(401, ['error' => 'Unauthorized', 'message' => 'El token utilizado ha expirado.', 'code' => 401]);
}

// Validar restricción de IP autorizada
$_allowed_ip = $token_row['at_allowed_ip_vc'];
if ($_allowed_ip !== null && $_allowed_ip !== '' && !in_array($_allowed_ip, ['0.0.0.0', '::'], true)) {
    if ($client_ip !== $_allowed_ip) {
        api_respond(403, [
            'error'   => 'Forbidden',
            'message' => 'La dirección IP de origen no está autorizada para este token.',
            'code'    => 403,
        ]);
    }
}

// Validar permisos de delegación según jerarquía de prioridad:
// 1. Revocación administrativa (máxima prioridad)
// 2. Permiso concedido por nivel superior (ac_api_allowed_in)
// 3. Auto-toggle del propio usuario (ac_api_self_in)
// Grupo 1 (Admin) siempre permitido si la API global está activa.
if ($token_user_fk !== null) {
    $acc_group = (int)($token_row['ac_group_fk'] ?? 3);
    if ($acc_group !== 1) {
        if ((int)($token_row['ac_api_revoked_in'] ?? 0) === 1) {
            api_respond(403, [
                'error'   => 'Forbidden',
                'message' => 'Acceso a la API revocado por el administrador del servidor.',
                'code'    => 403,
            ]);
        }
        if ((int)($token_row['ac_api_allowed_in'] ?? 0) !== 1) {
            api_respond(403, [
                'error'   => 'Forbidden',
                'message' => 'No dispone de autorización para utilizar la API.',
                'code'    => 403,
            ]);
        }
        if ((int)($token_row['ac_api_self_in'] ?? 1) !== 1) {
            api_respond(403, [
                'error'   => 'Forbidden',
                'message' => 'El acceso a la API está desactivado en tu cuenta.',
                'code'    => 403,
            ]);
        }
    }
}

// Actualizar último uso + IP de origen
$zdbh->prepare("UPDATE x_api_tokens SET at_lastused_ts = NOW(), at_last_ip_vc = :ip WHERE at_id_pk = :id")
     ->execute([':ip' => $client_ip, ':id' => $al_token_id]);

// ── 5. Rate limiting: 60 req/min por token ────────────────────────────────────

$rl = $zdbh->prepare(
    "SELECT COUNT(*) FROM x_api_log
      WHERE al_token_fk = :id AND al_ts >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
);
$rl->execute([':id' => $al_token_id]);
if ((int)$rl->fetchColumn() >= 60) {
    api_respond(429, ['error' => 'Too Many Requests', 'message' => 'Límite de 60 peticiones/min alcanzado.', 'code' => 429]);
}

// ── 6. Scope → métodos permitidos ────────────────────────────────────────────

$scope_methods = [
    'read'     => ['GET'],
    'write'    => ['GET', 'POST', 'PATCH', 'DELETE'],
    'reseller' => ['GET', 'POST', 'PATCH', 'DELETE'],
    'admin'    => ['GET', 'POST', 'PATCH', 'DELETE'],
];
// El status siempre es accesible
if ($resource !== 'status' && !in_array($method, $scope_methods[$token_scope] ?? [], true)) {
    api_respond(403, [
        'error'   => 'Forbidden',
        'message' => "El scope '$token_scope' no permite el método $method.",
        'code'    => 403,
    ]);
}

// ── 7. Recursos conocidos → 405 si método incorrecto ─────────────────────────

$known_resources = [
    'status'   => ['GET'],
    'packages' => ['GET'],
    'accounts' => ['GET', 'POST', 'PATCH', 'DELETE'],
    'domains'  => ['GET', 'POST', 'DELETE'],
    'system'   => ['GET', 'POST'],
];

if ($resource !== '' && isset($known_resources[$resource])
    && !in_array($method, $known_resources[$resource], true))
{
    header('Allow: ' . implode(', ', $known_resources[$resource]));
    api_respond(405, ['error' => 'Method Not Allowed', 'allowed' => $known_resources[$resource], 'code' => 405]);
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINTS
// ═════════════════════════════════════════════════════════════════════════════

// ── GET /v1/status ────────────────────────────────────────────────────────────

if ($method === 'GET' && $resource === 'status') {
    api_respond(200, [
        'status'    => 'ok',
        'api'       => 'Bulwark REST API v1',
        'token'     => $token_row['at_name_vc'],
        'scope'     => $token_scope,
        'timestamp' => date('c'),
    ]);
}

// ── GET /v1/packages ─────────────────────────────────────────────────────────

if ($method === 'GET' && $resource === 'packages') {
    if ($token_is_reseller) {
        // Reseller: solo sus propios paquetes
        $q = $zdbh->prepare(
            "SELECT pk_id_pk, pk_name_vc FROM x_packages WHERE pk_reseller_fk=:uid AND pk_deleted_ts IS NULL ORDER BY pk_name_vc"
        );
        $q->execute([':uid' => $token_user_fk]);
    } elseif ($token_user_fk !== null) {
        // Usuario: solo paquetes de su reseller (no exponer paquetes de admin de otros resellers)
        $rq = $zdbh->prepare("SELECT ac_reseller_fk FROM x_accounts WHERE ac_id_pk=:uid LIMIT 1");
        $rq->execute([':uid' => $token_user_fk]);
        $reseller_fk = (int)($rq->fetchColumn() ?: 1);
        $q = $zdbh->prepare(
            "SELECT pk_id_pk, pk_name_vc FROM x_packages WHERE pk_reseller_fk=:rfk AND pk_deleted_ts IS NULL ORDER BY pk_name_vc"
        );
        $q->execute([':rfk' => $reseller_fk]);
    } else {
        // Admin / token sin vincular: todos los paquetes
        $q = $zdbh->prepare(
            "SELECT pk_id_pk, pk_name_vc FROM x_packages WHERE pk_deleted_ts IS NULL ORDER BY pk_name_vc"
        );
        $q->execute();
    }
    $data = [];
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        $data[] = ['package_id' => (int)$r['pk_id_pk'], 'name' => $r['pk_name_vc']];
    }
    api_respond(200, ['data' => $data]);
}

// ═══════════════ ACCOUNTS ════════════════════════════════════════════════════

// ── GET /v1/accounts — lista paginada ────────────────────────────────────────

if ($method === 'GET' && $resource === 'accounts' && !$res_id) {
    require_scope('read');

    $page     = max(1, (int)($_GET['page']     ?? 1));
    $per_page = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    $search   = trim($_GET['search'] ?? '');
    $offset   = ($page - 1) * $per_page;

    $where  = "a.ac_deleted_ts IS NULL AND a.ac_user_vc != 'zadmin'";
    $params = [];

    if ($token_is_reseller) {
        // Reseller ve su propia cuenta y las que creó (ac_reseller_fk = su ID)
        $where .= " AND (a.ac_id_pk = :uid OR a.ac_reseller_fk = :uid)";
        $params[':uid'] = $token_user_fk;
    } elseif ($token_user_fk !== null) {
        $where .= " AND a.ac_id_pk = :uid";
        $params[':uid'] = $token_user_fk;
    }
    if ($search !== '') {
        $search_esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $where .= " AND a.ac_user_vc LIKE :search";
        $params[':search'] = '%' . $search_esc . '%';
    }

    $cq = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts a WHERE $where");
    $cq->execute($params);
    $total = (int)$cq->fetchColumn();

    $dq = $zdbh->prepare(
        "SELECT a.ac_id_pk, a.ac_user_vc, a.ac_email_vc, a.ac_enabled_in, a.ac_created_ts,
                g.ug_name_vc AS group_name, pk.pk_name_vc AS package_name
           FROM x_accounts a
           LEFT JOIN x_groups   g  ON g.ug_id_pk  = a.ac_group_fk
           LEFT JOIN x_packages pk ON pk.pk_id_pk  = a.ac_package_fk
          WHERE $where
          ORDER BY a.ac_user_vc
          LIMIT $per_page OFFSET $offset"
    );
    $dq->execute($params);

    $data = [];
    while ($r = $dq->fetch(PDO::FETCH_ASSOC)) {
        $data[] = [
            'user_id'  => (int)$r['ac_id_pk'],
            'username' => $r['ac_user_vc'],
            'email'    => $r['ac_email_vc'],
            'group'    => $r['group_name'],
            'package'  => $r['package_name'],
            'enabled'  => (bool)(int)$r['ac_enabled_in'],
            'created'  => date('c', (int)$r['ac_created_ts']),
        ];
    }

    api_respond(200, [
        'data'       => $data,
        'pagination' => [
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => $total,
            'pages'    => (int)ceil($total / max(1, $per_page)),
        ],
    ]);
}

// ── GET /v1/accounts/{username} ───────────────────────────────────────────────

if ($method === 'GET' && $resource === 'accounts' && $res_id && !$sub) {
    require_scope('read');
    $row = resolve_account($res_id);
    api_respond(200, format_account($row, true));
}

// ── POST /v1/accounts — crear usuario ────────────────────────────────────────

if ($method === 'POST' && $resource === 'accounts' && !$res_id) {
    require_scope('write');
    // Tokens de usuario (sin scope reseller) no pueden crear cuentas
    if ($token_user_fk !== null && !$token_is_reseller) {
        api_respond(403, ['error' => 'Forbidden', 'message' => 'Los tokens de usuario no pueden crear cuentas.', 'code' => 403]);
    }

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim(strtolower($body['username'] ?? ''));
    $email    = trim($body['email'] ?? '');

    if ($username === '') api_respond(400, ['error' => 'Bad Request', 'message' => 'El campo username es obligatorio.', 'code' => 400]);
    if ($email    === '') api_respond(400, ['error' => 'Bad Request', 'message' => 'El campo email es obligatorio.',    'code' => 400]);

    // Contraseña: auto-generada si no se envía
    $auto_pass = false;
    $password  = trim($body['password'] ?? '');
    if ($password === '') {
        $auto_pass = true;
        $chars  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $minlen = max(16, (int)ctrl_options::GetSystemOption('password_minlength'));
        do {
            $password = '';
            for ($i = 0; $i < $minlen; $i++) $password .= $chars[random_int(0, 61)];
        } while (!preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z])/', $password));
    }

    $fullname = trim($body['fullname'] ?? '');
    $address  = trim($body['address']  ?? '');
    $postcode = trim($body['postcode'] ?? '');
    $phone    = trim($body['phone']    ?? '');

    // Reseller que crea la cuenta: ID del reseller. Admin: usa ID 1 (zadmin)
    $creator_uid = $token_is_reseller ? $token_user_fk : 1;

    // Paquete (nombre o id; reseller solo puede asignar sus propios paquetes)
    if (!empty($body['package'])) {
        $pi = $body['package'];
        if ($token_is_reseller) {
            $pq = ctype_digit((string)$pi)
                ? $zdbh->prepare("SELECT pk_id_pk, pk_name_vc FROM x_packages WHERE pk_id_pk=:v AND pk_reseller_fk=:uid AND pk_deleted_ts IS NULL")
                : $zdbh->prepare("SELECT pk_id_pk, pk_name_vc FROM x_packages WHERE pk_name_vc=:v AND pk_reseller_fk=:uid AND pk_deleted_ts IS NULL LIMIT 1");
            $pq->execute([':v' => $pi, ':uid' => $token_user_fk]);
        } else {
            $pq = ctype_digit((string)$pi)
                ? $zdbh->prepare("SELECT pk_id_pk, pk_name_vc FROM x_packages WHERE pk_id_pk=:v AND pk_deleted_ts IS NULL")
                : $zdbh->prepare("SELECT pk_id_pk, pk_name_vc FROM x_packages WHERE pk_name_vc=:v AND pk_deleted_ts IS NULL LIMIT 1");
            $pq->execute([':v' => $pi]);
        }
        $pkg_row = $pq->fetch(PDO::FETCH_ASSOC);
        if (!$pkg_row) api_respond(422, ['error' => 'Unprocessable Entity', 'message' => "Paquete '$pi' no encontrado.", 'code' => 422]);
    } else {
        $reseller_fk_search = $token_is_reseller ? $token_user_fk : 1;
        $pq = $zdbh->prepare("SELECT pk_id_pk, pk_name_vc FROM x_packages WHERE pk_reseller_fk=:uid AND pk_name_vc != 'Administration' AND pk_deleted_ts IS NULL ORDER BY pk_id_pk LIMIT 1");
        $pq->execute([':uid' => $reseller_fk_search]);
        $pkg_row = $pq->fetch(PDO::FETCH_ASSOC);
        if (!$pkg_row) {
            $pq2 = $zdbh->prepare("SELECT pk_id_pk, pk_name_vc FROM x_packages WHERE pk_reseller_fk=:uid AND pk_deleted_ts IS NULL ORDER BY pk_id_pk LIMIT 1");
            $pq2->execute([':uid' => $reseller_fk_search]);
            $pkg_row = $pq2->fetch(PDO::FETCH_ASSOC);
        }
        if (!$pkg_row) api_respond(503, ['error' => 'Service Unavailable', 'message' => 'No hay paquetes disponibles.', 'code' => 503]);
    }

    // Grupo — resellers siempre crean Users; write no puede crear Admins/Resellers
    if ($token_is_reseller) {
        // Resellers solo pueden crear cuentas de usuario, sin excepción
        $gq     = $zdbh->prepare("SELECT ug_id_pk, ug_name_vc FROM x_groups WHERE ug_name_vc='Users' LIMIT 1");
        $gq->execute();
        $grp_row = $gq->fetch(PDO::FETCH_ASSOC);
        if (!$grp_row) api_respond(503, ['error' => 'Service Unavailable', 'message' => "Grupo 'Users' no encontrado.", 'code' => 503]);
    } elseif (!empty($body['group'])) {
        $gi = $body['group'];
        $gq = ctype_digit((string)$gi)
            ? $zdbh->prepare("SELECT ug_id_pk, ug_name_vc FROM x_groups WHERE ug_id_pk=:v")
            : $zdbh->prepare("SELECT ug_id_pk, ug_name_vc FROM x_groups WHERE ug_name_vc=:v LIMIT 1");
        $gq->execute([':v' => $gi]);
        $grp_row = $gq->fetch(PDO::FETCH_ASSOC);
        if (!$grp_row) api_respond(422, ['error' => 'Unprocessable Entity', 'message' => "Grupo '$gi' no encontrado.", 'code' => 422]);
        // Solo scope admin puede asignar grupos privilegiados
        if ($token_scope !== 'admin' && in_array($grp_row['ug_name_vc'], ['Administrators', 'Resellers'], true)) {
            api_respond(403, ['error' => 'Forbidden', 'message' => "Se requiere scope 'admin' para crear cuentas en el grupo '{$grp_row['ug_name_vc']}'.", 'code' => 403]);
        }
    } else {
        $gq = $zdbh->prepare("SELECT ug_id_pk, ug_name_vc FROM x_groups WHERE ug_name_vc='Users' LIMIT 1");
        $gq->execute();
        $grp_row = $gq->fetch(PDO::FETCH_ASSOC);
        if (!$grp_row) api_respond(503, ['error' => 'Service Unavailable', 'message' => "Grupo 'Users' no encontrado.", 'code' => 503]);
    }

    if (!class_exists('module_controller', false)) {
        require_once 'modules/manage_clients/code/controller.ext.php';
    }

    $result = module_controller::ExecuteCreateClient(
        $creator_uid, $username, (int)$pkg_row['pk_id_pk'], (int)$grp_row['ug_id_pk'],
        $fullname, $email, $address, $postcode, $phone, $password, 0, '', ''
    );

    if (!$result) {
        if      (module_controller::$alreadyexists)     $reason = 'El nombre de usuario ya existe.';
        elseif  (module_controller::$userblank)         $reason = 'El username no puede estar vacío.';
        elseif  (module_controller::$badname)           $reason = 'Username inválido (solo letras, números y guiones; sin guión al final).';
        elseif  (module_controller::$emailblank)        $reason = 'El email no puede estar vacío.';
        elseif  (module_controller::$bademail)          $reason = 'Dirección de email inválida.';
        elseif  (module_controller::$not_unique_email)  $reason = 'Ya existe una cuenta con ese email.';
        elseif  (module_controller::$passwordblank)     $reason = 'La contraseña no puede estar vacía.';
        elseif  (module_controller::$badpass)           $reason = 'La contraseña debe tener mayúscula, minúscula y número.';
        elseif  (module_controller::$badpasswordlength) $reason = 'Contraseña demasiado corta (mínimo ' . ctrl_options::GetSystemOption('password_minlength') . ' caracteres).';
        elseif  (module_controller::$packageblank)      $reason = 'Paquete no válido.';
        elseif  (module_controller::$groupblank)        $reason = 'Grupo no válido.';
        else                                            $reason = 'Error al crear la cuenta.';
        api_respond(422, ['error' => 'Unprocessable Entity', 'message' => $reason, 'code' => 422]);
    }

    $nq = $zdbh->prepare("SELECT ac_id_pk FROM x_accounts WHERE ac_user_vc=:u AND ac_deleted_ts IS NULL ORDER BY ac_id_pk DESC LIMIT 1");
    $nq->execute([':u' => $username]);
    $new_row = $nq->fetch(PDO::FETCH_ASSOC);

    $resp = [
        'user_id'  => $new_row ? (int)$new_row['ac_id_pk'] : null,
        'username' => $username,
        'email'    => $email,
        'group'    => $grp_row['ug_name_vc'],
        'package'  => $pkg_row['pk_name_vc'],
    ];
    if ($auto_pass) {
        $resp['password']      = $password;
        $resp['password_note'] = 'Contraseña auto-generada. Guárdala, no se volverá a mostrar.';
    }
    api_respond(201, $resp);
}

// ── PATCH /v1/accounts/{username} — actualizar ────────────────────────────────

if ($method === 'PATCH' && $resource === 'accounts' && $res_id && !$sub) {
    require_scope('write');
    $row  = resolve_account($res_id);
    $uid  = (int)$row['ac_id_pk'];
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $acc_set = []; $acc_val = [];
    $pro_set = []; $pro_val = [];

    if (array_key_exists('email', $body)) {
        $email = trim($body['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_respond(422, ['error' => 'Unprocessable Entity', 'message' => 'Email inválido.', 'code' => 422]);
        }
        $uq = $zdbh->prepare("SELECT COUNT(*) FROM x_accounts WHERE ac_email_vc=:e AND ac_id_pk != :id AND ac_deleted_ts IS NULL");
        $uq->execute([':e' => $email, ':id' => $uid]);
        if ((int)$uq->fetchColumn() > 0) {
            api_respond(422, ['error' => 'Unprocessable Entity', 'message' => 'Ese email ya está en uso.', 'code' => 422]);
        }
        $acc_set[] = 'ac_email_vc = :email';
        $acc_val[':email'] = $email;
    }

    if (array_key_exists('package', $body)) {
        // Solo reseller/admin pueden cambiar el paquete de una cuenta
        require_scope('reseller');
        $pi = $body['package'];
        if ($token_is_reseller) {
            // Reseller solo puede asignar sus propios paquetes
            $pq = ctype_digit((string)$pi)
                ? $zdbh->prepare("SELECT pk_id_pk FROM x_packages WHERE pk_id_pk=:v AND pk_reseller_fk=:uid AND pk_deleted_ts IS NULL")
                : $zdbh->prepare("SELECT pk_id_pk FROM x_packages WHERE pk_name_vc=:v AND pk_reseller_fk=:uid AND pk_deleted_ts IS NULL LIMIT 1");
            $pq->execute([':v' => $pi, ':uid' => $token_user_fk]);
        } else {
            $pq = ctype_digit((string)$pi)
                ? $zdbh->prepare("SELECT pk_id_pk FROM x_packages WHERE pk_id_pk=:v AND pk_deleted_ts IS NULL")
                : $zdbh->prepare("SELECT pk_id_pk FROM x_packages WHERE pk_name_vc=:v AND pk_deleted_ts IS NULL LIMIT 1");
            $pq->execute([':v' => $pi]);
        }
        $pkg = $pq->fetch(PDO::FETCH_ASSOC);
        if (!$pkg) api_respond(422, ['error' => 'Unprocessable Entity', 'message' => "Paquete '$pi' no encontrado o no autorizado.", 'code' => 422]);
        $acc_set[] = 'ac_package_fk = :pkg';
        $acc_val[':pkg'] = (int)$pkg['pk_id_pk'];
    }

    if (array_key_exists('enabled', $body)) {
        require_scope('reseller');
        $acc_set[] = 'ac_enabled_in = :enabled';
        $acc_val[':enabled'] = (bool)$body['enabled'] ? 1 : 0;
    }

    foreach (['fullname' => 'ud_fullname_vc', 'address' => 'ud_address_tx', 'postcode' => 'ud_postcode_vc', 'phone' => 'ud_phone_vc'] as $key => $col) {
        if (array_key_exists($key, $body)) {
            $pro_set[] = "$col = :$key";
            $pro_val[":$key"] = trim((string)$body[$key]);
        }
    }

    if (!$acc_set && !$pro_set) {
        api_respond(400, ['error' => 'Bad Request', 'message' => 'No se proporcionaron campos para actualizar.', 'code' => 400]);
    }

    if ($acc_set) {
        $acc_val[':uid'] = $uid;
        $zdbh->prepare("UPDATE x_accounts SET " . implode(', ', $acc_set) . " WHERE ac_id_pk = :uid")->execute($acc_val);
    }
    if ($pro_set) {
        $pro_val[':uid'] = $uid;
        $zdbh->prepare("UPDATE x_profiles SET " . implode(', ', $pro_set) . " WHERE ud_user_fk = :uid")->execute($pro_val);
    }

    api_respond(200, format_account(resolve_account($res_id), true));
}

// ── POST /v1/accounts/{username}/suspend ─────────────────────────────────────

if ($method === 'POST' && $resource === 'accounts' && $res_id && $sub === 'suspend') {
    require_scope('reseller');
    $row = resolve_account($res_id);
    if ((int)$row['ac_group_fk'] === 1) {
        api_respond(403, ['error' => 'Forbidden', 'message' => 'Las cuentas de administrador no pueden suspenderse mediante la API.', 'code' => 403]);
    }
    if ($token_user_fk !== null && (int)$row['ac_id_pk'] === $token_user_fk) {
        api_respond(403, ['error' => 'Forbidden', 'message' => 'No puedes suspender tu propia cuenta.', 'code' => 403]);
    }
    $zdbh->prepare("UPDATE x_accounts SET ac_enabled_in = 0 WHERE ac_id_pk = :id")
         ->execute([':id' => (int)$row['ac_id_pk']]);
    api_respond(200, ['username' => $res_id, 'enabled' => false, 'message' => 'Cuenta suspendida.']);
}

// ── POST /v1/accounts/{username}/unsuspend ────────────────────────────────────

if ($method === 'POST' && $resource === 'accounts' && $res_id && $sub === 'unsuspend') {
    require_scope('reseller');
    $row = resolve_account($res_id);
    if ((int)$row['ac_group_fk'] === 1) {
        api_respond(403, ['error' => 'Forbidden', 'message' => 'Las cuentas de administrador no pueden gestionarse mediante la API.', 'code' => 403]);
    }
    if ($token_user_fk !== null && (int)$row['ac_id_pk'] === $token_user_fk) {
        api_respond(403, ['error' => 'Forbidden', 'message' => 'No puedes reactivar tu propia cuenta así. Usa el panel de administración.', 'code' => 403]);
    }
    $zdbh->prepare("UPDATE x_accounts SET ac_enabled_in = 1 WHERE ac_id_pk = :id")
         ->execute([':id' => (int)$row['ac_id_pk']]);
    api_respond(200, ['username' => $res_id, 'enabled' => true, 'message' => 'Cuenta reactivada.']);
}

// ── DELETE /v1/accounts/{username} ────────────────────────────────────────────

if ($method === 'DELETE' && $resource === 'accounts' && $res_id && !$sub) {
    require_scope('reseller');
    $row = resolve_account($res_id);
    $uid = (int)$row['ac_id_pk'];
    if ($token_user_fk !== null && $uid === $token_user_fk) {
        api_respond(403, ['error' => 'Forbidden', 'message' => 'No puedes eliminar tu propia cuenta.', 'code' => 403]);
    }
    // Soft-delete (no borra archivos; el administrador puede recuperarlos desde el servidor)
    $ts = time();
    $zdbh->prepare("UPDATE x_accounts SET ac_deleted_ts = :ts WHERE ac_id_pk = :id")
         ->execute([':ts' => $ts, ':id' => $uid]);
    api_respond(200, ['username' => $res_id, 'deleted' => true, 'message' => 'Cuenta marcada como eliminada. Los archivos permanecen en el servidor.']);
}

// ── GET /v1/accounts/{username}/domains — dominios de un usuario ──────────────

if ($method === 'GET' && $resource === 'accounts' && $res_id && $sub === 'domains') {
    require_scope('read');
    $row = resolve_account($res_id);
    $uid = (int)$row['ac_id_pk'];

    $q = $zdbh->prepare(
        "SELECT vh_id_pk, vh_name_vc, vh_created_ts
           FROM x_vhosts
          WHERE vh_acc_fk = :uid AND vh_deleted_ts IS NULL AND vh_type_in = 1
          ORDER BY vh_name_vc"
    );
    $q->execute([':uid' => $uid]);

    $data = [];
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        $data[] = [
            'domain_id' => (int)$r['vh_id_pk'],
            'domain'    => $r['vh_name_vc'],
            'created'   => date('c', (int)$r['vh_created_ts']),
        ];
    }
    api_respond(200, ['username' => $res_id, 'data' => $data]);
}

// ═══════════════ DOMAINS ═════════════════════════════════════════════════════

// ── GET /v1/domains — lista paginada ─────────────────────────────────────────

if ($method === 'GET' && $resource === 'domains' && !$res_id) {
    require_scope('read');

    $page     = max(1, (int)($_GET['page']     ?? 1));
    $per_page = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    $offset   = ($page - 1) * $per_page;

    $where  = "v.vh_deleted_ts IS NULL AND v.vh_type_in = 1";
    $params = [];

    if ($token_is_reseller) {
        // Reseller: sus propios dominios + los de sus clientes
        $where .= " AND (v.vh_acc_fk = :uid OR v.vh_acc_fk IN (SELECT ac_id_pk FROM x_accounts WHERE ac_reseller_fk = :uid AND ac_deleted_ts IS NULL))";
        $params[':uid'] = $token_user_fk;
    } elseif ($token_user_fk !== null) {
        $where .= " AND v.vh_acc_fk = :uid";
        $params[':uid'] = $token_user_fk;
    } elseif (!empty($_GET['username'])) {
        // Admin puede filtrar por usuario
        $fu = $zdbh->prepare("SELECT ac_id_pk FROM x_accounts WHERE ac_user_vc=:u AND ac_deleted_ts IS NULL LIMIT 1");
        $fu->execute([':u' => trim($_GET['username'])]);
        $fu_row = $fu->fetch(PDO::FETCH_ASSOC);
        if (!$fu_row) api_respond(404, ['error' => 'Not Found', 'message' => "Usuario '{$_GET['username']}' no encontrado.", 'code' => 404]);
        $where .= " AND v.vh_acc_fk = :uid";
        $params[':uid'] = (int)$fu_row['ac_id_pk'];
    }

    $cq = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts v WHERE $where");
    $cq->execute($params);
    $total = (int)$cq->fetchColumn();

    $dq = $zdbh->prepare(
        "SELECT v.vh_id_pk, v.vh_name_vc, v.vh_created_ts, a.ac_user_vc
           FROM x_vhosts v
           JOIN x_accounts a ON a.ac_id_pk = v.vh_acc_fk
          WHERE $where
          ORDER BY v.vh_name_vc
          LIMIT $per_page OFFSET $offset"
    );
    $dq->execute($params);

    $data = [];
    while ($r = $dq->fetch(PDO::FETCH_ASSOC)) {
        $data[] = [
            'domain_id' => (int)$r['vh_id_pk'],
            'domain'    => $r['vh_name_vc'],
            'username'  => $r['ac_user_vc'],
            'created'   => date('c', (int)$r['vh_created_ts']),
        ];
    }

    api_respond(200, [
        'data'       => $data,
        'pagination' => [
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => $total,
            'pages'    => (int)ceil($total / max(1, $per_page)),
        ],
    ]);
}

// ── POST /v1/domains — crear dominio ─────────────────────────────────────────

if ($method === 'POST' && $resource === 'domains' && !$res_id) {
    require_scope('write');

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $domain = trim(strtolower($body['domain']   ?? ''));
    $uname  = trim($body['username'] ?? '');

    if ($domain === '') api_respond(400, ['error' => 'Bad Request', 'message' => 'El campo domain es obligatorio.',   'code' => 400]);
    if ($uname  === '') api_respond(400, ['error' => 'Bad Request', 'message' => 'El campo username es obligatorio.', 'code' => 400]);

    // Buscar usuario
    $uq = $zdbh->prepare("SELECT ac_id_pk, ac_reseller_fk FROM x_accounts WHERE ac_user_vc=:u AND ac_deleted_ts IS NULL LIMIT 1");
    $uq->execute([':u' => $uname]);
    $user_row = $uq->fetch(PDO::FETCH_ASSOC);
    if (!$user_row) api_respond(404, ['error' => 'Not Found', 'message' => "Usuario '$uname' no encontrado.", 'code' => 404]);

    $uid = (int)$user_row['ac_id_pk'];

    if ($token_is_reseller) {
        // Reseller: puede crear dominios para sí mismo o para sus clientes
        $is_own   = ($uid === $token_user_fk);
        $is_client = ((int)$user_row['ac_reseller_fk'] === $token_user_fk);
        if (!$is_own && !$is_client) {
            api_respond(403, ['error' => 'Forbidden', 'message' => 'Solo puedes crear dominios para tu cuenta o la de tus usuarios.', 'code' => 403]);
        }
    } elseif ($token_user_fk !== null && $uid !== $token_user_fk) {
        api_respond(403, ['error' => 'Forbidden', 'message' => 'Solo puedes crear dominios para tu propia cuenta.', 'code' => 403]);
    }

    if (!class_exists('module_controller', false)) {
        require_once 'modules/domains/code/controller.ext.php';
    }

    if (!module_controller::CheckCreateForErrors($domain)) {
        if      (module_controller::$alreadyexists) $reason = 'El dominio ya existe en el servidor.';
        elseif  (module_controller::$badname)       $reason = 'Nombre de dominio inválido.';
        elseif  (module_controller::$blank)         $reason = 'El campo domain está vacío.';
        elseif  (module_controller::$nosub)         $reason = 'El dominio padre no pertenece a este usuario.';
        else                                        $reason = 'El dominio no pasó la validación.';
        api_respond(422, ['error' => 'Unprocessable Entity', 'message' => $reason, 'code' => 422]);
    }

    $result = module_controller::ExecuteAddDomain($uid, $domain, str_replace('.', '_', $domain), false);
    if ($result) {
        api_respond(201, [
            'domain'   => $domain,
            'username' => $uname,
            'note'     => 'El vhost se aplicará en el próximo ciclo del daemon (máx. 5 min).',
        ]);
    }
    api_respond(500, ['error' => 'Internal Server Error', 'message' => 'No se pudo crear el dominio.', 'code' => 500]);
}

// ── DELETE /v1/domains/{domain} ───────────────────────────────────────────────

if ($method === 'DELETE' && $resource === 'domains' && $res_id && !$sub) {
    require_scope('write');

    $domain = strtolower(trim($res_id));

    // Buscar el dominio
    $dq = $zdbh->prepare(
        "SELECT vh_id_pk, vh_acc_fk FROM x_vhosts WHERE vh_name_vc=:d AND vh_deleted_ts IS NULL AND vh_type_in=1 LIMIT 1"
    );
    $dq->execute([':d' => $domain]);
    $vh = $dq->fetch(PDO::FETCH_ASSOC);

    if (!$vh) {
        api_respond(404, ['error' => 'Not Found', 'message' => "Dominio '$domain' no encontrado.", 'code' => 404]);
    }

    // Token de usuario: solo puede borrar sus propios dominios
    if ($token_user_fk !== null && (int)$vh['vh_acc_fk'] !== $token_user_fk) {
        api_respond(403, ['error' => 'Forbidden', 'message' => 'Solo puedes eliminar dominios de tu propia cuenta.', 'code' => 403]);
    }

    $ts = time();
    $zdbh->prepare("UPDATE x_vhosts SET vh_deleted_ts=:ts WHERE vh_id_pk=:id")->execute([':ts' => $ts, ':id' => (int)$vh['vh_id_pk']]);
    // Cascade: marcar subdominios eliminados también (escapar wildcards LIKE del dominio)
    $domain_esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $domain);
    $zdbh->prepare("UPDATE x_vhosts SET vh_deleted_ts=:ts WHERE vh_name_vc LIKE :pat AND vh_type_in=2 AND vh_deleted_ts IS NULL")
         ->execute([':ts' => $ts, ':pat' => '%.' . $domain_esc]);
    // Triggear daemon Apache
    $zdbh->prepare("UPDATE x_settings SET so_value_tx='true' WHERE so_name_vc='apache_changed'")->execute();

    api_respond(200, ['domain' => $domain, 'deleted' => true, 'message' => 'Dominio eliminado. Los archivos permanecen en el servidor.']);
}

// ═══════════════ DNS ═════════════════════════════════════════════════════════
//
// URL map (scope mínimo indicado):
//   GET    /v1/domains/{domain}/dns                → listar registros DNS      [read]
//   POST   /v1/domains/{domain}/dns                → crear zona completa + DKIM [write]
//   DELETE /v1/domains/{domain}/dns                → eliminar zona (soft-delete) [write]
//   POST   /v1/domains/{domain}/dkim/regenerate    → resetear clave DKIM        [write]

// ── GET /v1/domains/{domain}/dns ─────────────────────────────────────────────

if ($method === 'GET' && $resource === 'domains' && $res_id && $sub === 'dns' && !$sub2) {
    require_scope('read');
    $vh = resolve_domain(strtolower(trim($res_id)));

    $q = $zdbh->prepare(
        "SELECT dn_id_pk, dn_type_vc, dn_host_vc, dn_target_vc,
                dn_ttl_in, dn_priority_in, dn_created_ts
           FROM x_dns
          WHERE dn_vhost_fk = :vid AND dn_deleted_ts IS NULL
          ORDER BY dn_type_vc, dn_host_vc"
    );
    $q->execute([':vid' => (int)$vh['vh_id_pk']]);

    $records     = [];
    $dkim_status = 'none';
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        if ($r['dn_host_vc'] === 'default._domainkey' && $r['dn_type_vc'] === 'TXT') {
            $dkim_status = $r['dn_target_vc'] === 'PENDING' ? 'pending' : 'active';
        }
        $records[] = [
            'record_id' => (int)$r['dn_id_pk'],
            'type'      => $r['dn_type_vc'],
            'host'      => $r['dn_host_vc'],
            'target'    => $r['dn_target_vc'],
            'ttl'       => (int)$r['dn_ttl_in'],
            'priority'  => (int)$r['dn_priority_in'],
            'created'   => date('c', (int)$r['dn_created_ts']),
        ];
    }

    api_respond(200, [
        'domain'      => $vh['vh_name_vc'],
        'username'    => $vh['ac_user_vc'],
        'dkim_status' => $dkim_status,
        'total'       => count($records),
        'records'     => $records,
    ]);
}

// ── POST /v1/domains/{domain}/dns — crear zona completa + DKIM ───────────────

if ($method === 'POST' && $resource === 'domains' && $res_id && $sub === 'dns' && !$sub2) {
    require_scope('write');
    $vh  = resolve_domain(strtolower(trim($res_id)));
    $vid = (int)$vh['vh_id_pk'];
    $uid = (int)$vh['vh_acc_fk'];

    // Idempotencia: si ya hay registros (excepto DKIM) devolver 409
    $chk = $zdbh->prepare(
        "SELECT COUNT(*) FROM x_dns
          WHERE dn_vhost_fk = :vid AND dn_deleted_ts IS NULL
            AND dn_host_vc != 'default._domainkey'"
    );
    $chk->execute([':vid' => $vid]);
    if ((int)$chk->fetchColumn() > 0) {
        api_respond(409, [
            'error'   => 'Conflict',
            'message' => "El dominio '{$vh['vh_name_vc']}' ya tiene registros DNS activos. Usa GET /v1/domains/{$vh['vh_name_vc']}/dns para consultarlos.",
            'code'    => 409,
        ]);
    }

    $ip          = ctrl_options::GetSystemOption('server_ip') ?: ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');
    $domain_name = $vh['vh_name_vc'];

    // Plantillas específicas del usuario o globales (dc_acc_fk=0)
    $cntQ = $zdbh->prepare("SELECT COUNT(*) FROM x_dns_create WHERE dc_acc_fk=:uid");
    $cntQ->execute([':uid' => $uid]);
    if ((int)$cntQ->fetchColumn() > 0) {
        $tmpl = $zdbh->prepare("SELECT * FROM x_dns_create WHERE dc_acc_fk=:uid");
        $tmpl->execute([':uid' => $uid]);
    } else {
        $tmpl = $zdbh->prepare("SELECT * FROM x_dns_create WHERE dc_acc_fk=0");
        $tmpl->execute([]);
    }

    $inserted = 0;
    while ($t = $tmpl->fetch(PDO::FETCH_ASSOC)) {
        $target = str_replace([':IP:', ':DOMAIN:'], [$ip, $domain_name], $t['dc_target_vc']);
        insert_dns_record(
            $uid, $domain_name, $vid,
            $t['dc_type_vc'], $t['dc_host_vc'], $target,
            (int)$t['dc_ttl_in'], (int)($t['dc_priority_in'] ?? 0),
            (int)($t['dc_weight_in'] ?? 0), (int)($t['dc_port_in'] ?? 0)
        );
        $inserted++;
    }

    // DKIM: placeholder que el daemon sustituye por la clave RSA-2048 real en ≤5 min
    $dkimChk = $zdbh->prepare(
        "SELECT COUNT(*) FROM x_dns
          WHERE dn_vhost_fk=:vid AND dn_host_vc='default._domainkey' AND dn_deleted_ts IS NULL"
    );
    $dkimChk->execute([':vid' => $vid]);
    if ((int)$dkimChk->fetchColumn() === 0) {
        insert_dns_record($uid, $domain_name, $vid, 'TXT', 'default._domainkey', 'PENDING', 3600);
    }

    trigger_dns_update($vid);

    api_respond(201, [
        'domain'          => $domain_name,
        'username'        => $vh['ac_user_vc'],
        'records_created' => $inserted,
        'dkim_status'     => 'pending',
        'message'         => 'Zona DNS creada. El daemon aplicará los cambios en máx. 5 min. La clave DKIM estará activa en ≤5 min.',
    ]);
}

// ── DELETE /v1/domains/{domain}/dns — eliminar zona completa ──────────────────

if ($method === 'DELETE' && $resource === 'domains' && $res_id && $sub === 'dns' && !$sub2) {
    require_scope('write');
    $vh  = resolve_domain(strtolower(trim($res_id)));
    $vid = (int)$vh['vh_id_pk'];

    $ts  = time();
    $del = $zdbh->prepare(
        "UPDATE x_dns SET dn_deleted_ts=:ts WHERE dn_vhost_fk=:vid AND dn_deleted_ts IS NULL"
    );
    $del->execute([':ts' => $ts, ':vid' => $vid]);
    $deleted = $del->rowCount();

    trigger_dns_update($vid);

    api_respond(200, [
        'domain'          => $vh['vh_name_vc'],
        'records_deleted' => $deleted,
        'message'         => 'Zona DNS eliminada. El daemon aplicará los cambios en máx. 5 min.',
    ]);
}

// ── POST /v1/domains/{domain}/dkim/regenerate ─────────────────────────────────

if ($method === 'POST' && $resource === 'domains' && $res_id && $sub === 'dkim' && $sub2 === 'regenerate') {
    require_scope('write');
    $vh  = resolve_domain(strtolower(trim($res_id)));
    $vid = (int)$vh['vh_id_pk'];
    $uid = (int)$vh['vh_acc_fk'];

    // Requiere zona DNS activa
    $zoneChk = $zdbh->prepare(
        "SELECT COUNT(*) FROM x_dns
          WHERE dn_vhost_fk=:vid AND dn_deleted_ts IS NULL AND dn_host_vc != 'default._domainkey'"
    );
    $zoneChk->execute([':vid' => $vid]);
    if ((int)$zoneChk->fetchColumn() === 0) {
        api_respond(409, [
            'error'   => 'Conflict',
            'message' => "El dominio no tiene zona DNS activa. Crea primero la zona con POST /v1/domains/{$vh['vh_name_vc']}/dns.",
            'code'    => 409,
        ]);
    }

    $rec = $zdbh->prepare(
        "SELECT dn_id_pk, dn_target_vc FROM x_dns
          WHERE dn_vhost_fk=:vid AND dn_host_vc='default._domainkey'
            AND dn_type_vc='TXT' AND dn_deleted_ts IS NULL"
    );
    $rec->execute([':vid' => $vid]);
    $existing = $rec->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($existing['dn_target_vc'] === 'PENDING') {
            api_respond(409, [
                'error'   => 'Conflict',
                'message' => 'La clave DKIM ya está pendiente de generación. Espera ≤5 min.',
                'code'    => 409,
            ]);
        }
        $zdbh->prepare("UPDATE x_dns SET dn_target_vc='PENDING' WHERE dn_id_pk=:id")
             ->execute([':id' => (int)$existing['dn_id_pk']]);
    } else {
        insert_dns_record($uid, $vh['vh_name_vc'], $vid, 'TXT', 'default._domainkey', 'PENDING', 3600);
    }

    trigger_dns_update($vid);

    api_respond(200, [
        'domain'      => $vh['vh_name_vc'],
        'dkim_status' => 'pending',
        'message'     => 'Regeneración de clave DKIM solicitada. Estará activa en ≤5 min.',
    ]);
}

// ── POST /v1/domains/{domain}/dns/records — añadir registro individual ────────

if ($method === 'POST' && $resource === 'domains' && $res_id && $sub === 'dns' && $sub2 === 'records' && !$sub3) {
    require_scope('write');
    $vh  = resolve_domain(strtolower(trim($res_id)));
    $vid = (int)$vh['vh_id_pk'];
    $uid = (int)$vh['vh_acc_fk'];

    // Requiere que exista zona DNS
    $zoneChk = $zdbh->prepare(
        "SELECT COUNT(*) FROM x_dns WHERE dn_vhost_fk=:vid AND dn_deleted_ts IS NULL AND dn_host_vc != 'default._domainkey'"
    );
    $zoneChk->execute([':vid' => $vid]);
    if ((int)$zoneChk->fetchColumn() === 0) {
        api_respond(409, [
            'error'   => 'Conflict',
            'message' => "El dominio no tiene zona DNS. Crea primero la zona con POST /v1/domains/{$vh['vh_name_vc']}/dns.",
            'code'    => 409,
        ]);
    }

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $type     = strtoupper(trim($body['type']   ?? ''));
    $host_raw = trim($body['host']              ?? '');
    $target   = trim($body['target']            ?? '');
    $ttl      = isset($body['ttl']) ? max(60, min(604800, (int)$body['ttl'])) : 3600;
    $priority = max(0, (int)($body['priority'] ?? 0));
    $weight   = max(0, (int)($body['weight']   ?? 0));
    $port     = max(0, (int)($body['port']     ?? 0));

    $allowed_types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'NAPTR', 'SSHFP', 'TLSA', 'URI'];
    if (!in_array($type, $allowed_types, true)) {
        api_respond(422, [
            'error'   => 'Unprocessable Entity',
            'message' => "Tipo '$type' no válido. Tipos permitidos: " . implode(', ', $allowed_types) . '.',
            'code'    => 422,
        ]);
    }

    $host = validate_dns_host($host_raw);
    if ($host === null) {
        api_respond(422, [
            'error'   => 'Unprocessable Entity',
            'message' => $host_raw === 'default._domainkey'
                ? 'El registro DKIM se gestiona con POST /v1/domains/{domain}/dkim/regenerate.'
                : "El campo host '$host_raw' no es válido.",
            'code'    => 422,
        ]);
    }

    if ($target === '') {
        api_respond(422, ['error' => 'Unprocessable Entity', 'message' => 'El campo target es obligatorio.', 'code' => 422]);
    }

    $validated = validate_dns_target($type, $target, $priority, $weight, $port);
    if ($validated === null) {
        api_respond(422, [
            'error'   => 'Unprocessable Entity',
            'message' => "El valor de target no es válido para el tipo $type.",
            'code'    => 422,
        ]);
    }

    $newId = insert_dns_record(
        $uid, $vh['vh_name_vc'], $vid, $type, $host,
        $validated['target'], $ttl, $validated['priority'],
        $validated['weight'], $validated['port']
    );

    trigger_dns_update($vid);

    api_respond(201, [
        'record_id' => $newId,
        'domain'    => $vh['vh_name_vc'],
        'type'      => $type,
        'host'      => $host,
        'target'    => $validated['target'],
        'ttl'       => $ttl,
        'priority'  => $validated['priority'],
        'message'   => 'Registro añadido. El daemon actualizará la zona en máx. 5 min.',
    ]);
}

// ── DELETE /v1/domains/{domain}/dns/records/{id} — eliminar registro ──────────

if ($method === 'DELETE' && $resource === 'domains' && $res_id && $sub === 'dns' && $sub2 === 'records' && $sub3) {
    require_scope('write');
    $vh     = resolve_domain(strtolower(trim($res_id)));
    $vid    = (int)$vh['vh_id_pk'];
    $rec_id = (int)$sub3;

    if ($rec_id <= 0) {
        api_respond(400, ['error' => 'Bad Request', 'message' => 'ID de registro no válido.', 'code' => 400]);
    }

    // Verificar que el registro pertenece a este vhost y no está eliminado
    $rq = $zdbh->prepare(
        "SELECT dn_id_pk, dn_host_vc, dn_type_vc FROM x_dns
          WHERE dn_id_pk=:id AND dn_vhost_fk=:vid AND dn_deleted_ts IS NULL"
    );
    $rq->execute([':id' => $rec_id, ':vid' => $vid]);
    $rec = $rq->fetch(PDO::FETCH_ASSOC);

    if (!$rec) {
        api_respond(404, [
            'error'   => 'Not Found',
            'message' => "Registro DNS #$rec_id no encontrado en el dominio '{$vh['vh_name_vc']}'.",
            'code'    => 404,
        ]);
    }

    // El registro DKIM solo se gestiona por /dkim/regenerate
    if ($rec['dn_host_vc'] === 'default._domainkey' && $rec['dn_type_vc'] === 'TXT') {
        api_respond(403, [
            'error'   => 'Forbidden',
            'message' => 'El registro DKIM no puede eliminarse individualmente. Usa DELETE /v1/domains/{domain}/dns para eliminar la zona completa.',
            'code'    => 403,
        ]);
    }

    $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=:ts WHERE dn_id_pk=:id")
         ->execute([':ts' => time(), ':id' => $rec_id]);

    trigger_dns_update($vid);

    api_respond(200, [
        'record_id' => $rec_id,
        'domain'    => $vh['vh_name_vc'],
        'deleted'   => true,
        'message'   => 'Registro eliminado. El daemon actualizará la zona en máx. 5 min.',
    ]);
}

// ═══════════════ SYSTEM ══════════════════════════════════════════════════════
//
// URL map (todos scope admin):
//   GET  /v1/system/info               → carga, disco, PHP
//   GET  /v1/system/services           → estado de servicios
//   GET  /v1/system/disk               → uso de disco por usuario
//   GET  /v1/system/daemon/status      → config Apache pendiente?
//   POST /v1/system/daemon/run         → forzar regeneración vhosts
//   POST /v1/system/reload/{service}   → recarga apache|phpfpm (cooldown 5 min, máx 3/día)
//   GET  /v1/system/logs/{service}     → últimas N líneas de log

if ($resource === 'system') {
    require_scope('admin');

    // ── GET /v1/system/info ───────────────────────────────────────────────────

    if ($method === 'GET' && $res_id === 'info' && !$sub) {
        $load        = sys_getloadavg();
        $hosted_dir  = ctrl_options::GetSystemOption('hosted_dir') ?: '/var/bulwark/hostdata/';
        $disk_total  = (int)disk_total_space($hosted_dir);
        $disk_free   = (int)disk_free_space($hosted_dir);
        $disk_used   = $disk_total - $disk_free;

        api_respond(200, [
            'hostname'    => gethostname(),
            'php_version' => PHP_VERSION,
            'load_avg'    => [
                '1min'  => round($load[0], 2),
                '5min'  => round($load[1], 2),
                '15min' => round($load[2], 2),
            ],
            'disk' => [
                'path'        => $hosted_dir,
                'total_bytes' => $disk_total,
                'used_bytes'  => $disk_used,
                'free_bytes'  => $disk_free,
                'used_pct'    => $disk_total > 0 ? round(($disk_used / $disk_total) * 100, 1) : 0,
            ],
        ]);
    }

    // ── GET /v1/system/services ───────────────────────────────────────────────

    if ($method === 'GET' && $res_id === 'services' && !$sub) {
        // Apache: PID file + socket check (sin exec)
        $apache_pid_raw = @file_get_contents('/var/run/httpd.pid');
        $apache_pid     = $apache_pid_raw !== false ? (int)trim($apache_pid_raw) : null;
        $sock = @fsockopen('127.0.0.1', 80, $se, $ss, 2);
        $apache_port = is_resource($sock);
        if ($sock) fclose($sock);

        // PHP-FPM: PID file
        $fpm_pid_raw = @file_get_contents('/var/run/php-fpm.pid');
        $fpm_pid     = $fpm_pid_raw !== false ? (int)trim($fpm_pid_raw) : null;

        api_respond(200, [
            'services' => [
                [
                    'service'    => 'apache24',
                    'display'    => 'Apache HTTP Server',
                    'running'    => $apache_pid !== null && $apache_port,
                    'pid'        => $apache_pid,
                    'reloadable' => true,
                    'reload_url' => '/v1/system/reload/apache',
                ],
                [
                    'service'    => 'phpfpm',
                    'display'    => 'PHP-FPM',
                    'running'    => $fpm_pid !== null,
                    'pid'        => $fpm_pid,
                    'reloadable' => true,
                    'reload_url' => '/v1/system/reload/phpfpm',
                ],
            ],
        ]);
    }

    // ── GET /v1/system/disk ───────────────────────────────────────────────────

    if ($method === 'GET' && $res_id === 'disk' && !$sub) {
        $month = date('Ym');
        $q = $zdbh->prepare(
            "SELECT a.ac_user_vc, b.bd_diskamount_bi
               FROM x_accounts a
               LEFT JOIN x_bandwidth b ON b.bd_acc_fk = a.ac_id_pk AND b.bd_month_in = :month
              WHERE a.ac_deleted_ts IS NULL AND a.ac_user_vc != 'zadmin'
              ORDER BY b.bd_diskamount_bi DESC"
        );
        $q->execute([':month' => $month]);

        $data = [];
        while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            $bytes = (int)($r['bd_diskamount_bi'] ?? 0);
            $data[] = [
                'username'   => $r['ac_user_vc'],
                'used_bytes' => $bytes,
                'used_mb'    => round($bytes / 1048576, 2),
            ];
        }
        api_respond(200, ['month' => $month, 'data' => $data, 'note' => 'Valores registrados por el daemon de Bulwark.']);
    }

    // ── GET /v1/system/daemon/status ──────────────────────────────────────────

    if ($method === 'GET' && $res_id === 'daemon' && $sub === 'status') {
        $pending = ctrl_options::GetSystemOption('apache_changed') === 'true';
        api_respond(200, [
            'daemon_pending' => $pending,
            'message'        => $pending
                ? 'Hay cambios de vhosts pendientes de aplicar (el daemon los procesará en máx. 5 min).'
                : 'La configuración de Apache está al día.',
        ]);
    }

    // ── POST /v1/system/daemon/run ────────────────────────────────────────────

    if ($method === 'POST' && $res_id === 'daemon' && $sub === 'run') {
        $zdbh->prepare("UPDATE x_settings SET so_value_tx='true' WHERE so_name_vc='apache_changed'")->execute();
        api_respond(200, [
            'success' => true,
            'message' => 'Regeneración de vhosts Apache solicitada. El daemon la procesará en los próximos 5 minutos.',
        ]);
    }

    // ── POST /v1/system/reload/{service} ─────────────────────────────────────

    if ($method === 'POST' && $res_id === 'reload' && $sub) {
        // Mapa de alias → acción de privilege + nombre canónico
        $reload_map = [
            'apache'   => ['action' => 'apache_reload', 'label' => 'apache24'],
            'apache24' => ['action' => 'apache_reload', 'label' => 'apache24'],
            'phpfpm'   => ['action' => 'phpfpm_reload',  'label' => 'php_fpm'],
            'php-fpm'  => ['action' => 'phpfpm_reload',  'label' => 'php_fpm'],
        ];

        $svc = strtolower($sub);
        if (!isset($reload_map[$svc])) {
            api_respond(404, [
                'error'   => 'Not Found',
                'message' => "Servicio '$svc' no disponible. Servicios recargables: apache, phpfpm.",
                'code'    => 404,
            ]);
        }

        $action    = $reload_map[$svc]['action'];
        $svc_label = $reload_map[$svc]['label'];

        // Normalizar recurso en el log para que el cooldown sea consistente
        // independientemente del alias usado (apache vs apache24, etc.)
        $al_resource = "POST /v1/system/reload/$svc_label";

        // Cooldown: máximo 1 reload por servicio cada 5 minutos
        $cd = $zdbh->prepare(
            "SELECT COUNT(*) FROM x_api_log
              WHERE al_resource_vc = :res AND al_status_in = 200
              AND al_ts >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        );
        $cd->execute([':res' => $al_resource]);
        if ((int)$cd->fetchColumn() > 0) {
            api_respond(429, [
                'error'   => 'Too Many Requests',
                'message' => "Cooldown activo: espera 5 minutos entre reloads de $svc_label.",
                'code'    => 429,
            ]);
        }

        // Límite diario: máximo 3 reloads por servicio en 24 horas
        $dl = $zdbh->prepare(
            "SELECT COUNT(*) FROM x_api_log
              WHERE al_resource_vc = :res AND al_status_in = 200
              AND al_ts >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $dl->execute([':res' => $al_resource]);
        if ((int)$dl->fetchColumn() >= 3) {
            api_respond(429, [
                'error'   => 'Too Many Requests',
                'message' => "Límite de 3 reloads/día alcanzado para $svc_label. Reinténtalo mañana o actúa desde el servidor.",
                'code'    => 429,
            ]);
        }

        // PHP-FPM: enviar respuesta ANTES del reload para evitar que el worker
        // muera a mitad de la conexión FastCGI y Apache devuelva 503.
        // fastcgi_finish_request() cierra la conexión con Apache/cliente y deja
        // el proceso PHP corriendo en background para ejecutar el reload.
        if ($action === 'phpfpm_reload') {
            http_response_code(200);
            echo json_encode([
                'service' => $svc_label,
                'action'  => 'reload',
                'success' => true,
                'message' => "Reload de $svc_label solicitado. Los workers actuales terminarán sus peticiones antes de reiniciarse.",
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request(); // respuesta enviada al cliente
            }

            // El reload puede matar este worker — la respuesta ya está enviada
            try {
                privilege::run($action);
            } catch (RuntimeException $e) { /* no podemos responder, ya se envió */ }
            exit; // dispara el shutdown logger (status=200 ya fijado)
        }

        // Para todos los demás servicios (Apache): flujo normal
        try {
            [$exit, $stdout, $stderr] = privilege::run($action);
        } catch (RuntimeException $e) {
            api_respond(500, [
                'error'   => 'Internal Server Error',
                'message' => 'Error al ejecutar el reload: ' . $e->getMessage(),
                'code'    => 500,
            ]);
        }

        if ($exit !== 0) {
            api_respond(500, [
                'error'   => 'Internal Server Error',
                'message' => "El servicio retornó código $exit.",
                'detail'  => trim($stderr),
                'code'    => 500,
            ]);
        }

        api_respond(200, [
            'service' => $svc_label,
            'action'  => 'reload',
            'success' => true,
            'message' => "Servicio $svc_label recargado correctamente.",
        ]);
    }

    // ── GET /v1/system/logs/{service} ─────────────────────────────────────────

    if ($method === 'GET' && $res_id === 'logs' && $sub) {
        // Whitelist estricta de ficheros de log — nunca path dinámico
        $log_files = [
            'apache'   => '/var/log/httpd-error.log',
            'apache24' => '/var/log/httpd-error.log',
            'phpfpm'   => '/var/log/php-fpm.log',
            'php-fpm'  => '/var/log/php-fpm.log',
        ];

        $svc = strtolower($sub);
        if (!isset($log_files[$svc])) {
            api_respond(404, [
                'error'   => 'Not Found',
                'message' => "Log para '$svc' no disponible. Servicios: apache, phpfpm.",
                'code'    => 404,
            ]);
        }

        $logfile = $log_files[$svc];
        $lines   = max(1, min(100, (int)($_GET['lines'] ?? 50)));

        if (!is_readable($logfile)) {
            api_respond(503, [
                'error'   => 'Service Unavailable',
                'message' => "El archivo de log no es accesible: $logfile",
                'code'    => 503,
            ]);
        }

        $all  = file($logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $last = array_slice($all, -$lines);

        api_respond(200, [
            'service'  => $svc,
            'file'     => $logfile,
            'returned' => count($last),
            'data'     => array_values($last),
        ]);
    }
}

// ═══════════════ SSL ═════════════════════════════════════════════════════════
//
// URL map:
//   GET  /v1/domains/{domain}/ssl        → estado del certificado
//   POST /v1/domains/{domain}/ssl        → crear/emitir cert Let's Encrypt
//   POST /v1/domains/{domain}/ssl/renew  → forzar renovación inmediata

function checkDNSIsLive(string $domain, string $server_ip): bool
{
    if (!checkdnsrr($domain, 'A')) return false;
    $records = @dns_get_record($domain, DNS_A);
    if (!is_array($records)) return false;
    foreach ($records as $r) {
        if (isset($r['ip']) && $r['ip'] === $server_ip) return true;
    }
    return false;
}

// ── GET /v1/domains/{domain}/ssl ──────────────────────────────────────────────

if ($method === 'GET' && $resource === 'domains' && $res_id && $sub === 'ssl' && !$sub2) {
    require_scope('read');
    $vh       = resolve_domain(strtolower(trim($res_id)));
    $username = $vh['ac_user_vc'];
    $domain   = $vh['vh_name_vc'];

    // Datos extras del vhost (SSL)
    $vq = $zdbh->prepare("SELECT vh_ssl_tx, vh_ssl_port_in, vh_directory_vc FROM x_vhosts WHERE vh_id_pk=:id LIMIT 1");
    $vq->execute([':id' => (int)$vh['vh_id_pk']]);
    $vrow = $vq->fetch(PDO::FETCH_ASSOC);

    $accountDir  = ctrl_options::GetSystemOption('hosted_dir') . $username . '/ssl/sencrypt/letsencrypt/';
    $certloc     = $accountDir . $domain . '/';
    $certfile    = $certloc . 'cert.pem';
    $has_ssl_db  = !empty($vrow['vh_ssl_tx']);

    if (!file_exists($certfile)) {
        api_respond(200, [
            'domain'       => $domain,
            'ssl_active'   => $has_ssl_db,
            'has_cert'     => false,
            'is_letsencrypt' => false,
            'message'      => $has_ssl_db
                ? 'SSL activo en BD pero no se encontró cert.pem de Let\'s Encrypt.'
                : 'No hay certificado Let\'s Encrypt para este dominio.',
        ]);
    }

    $raw      = file_get_contents($certfile);
    $certdata = openssl_x509_parse($raw);

    if ($certdata === false) {
        api_respond(500, ['error' => 'Internal Server Error', 'message' => 'No se pudo parsear el certificado.', 'code' => 500]);
    }

    $expires_ts   = (int)($certdata['validTo_time_t']  ?? 0);
    $issued_ts    = (int)($certdata['validFrom_time_t'] ?? 0);
    $days_left    = $expires_ts > 0 ? (int)(($expires_ts - time()) / 86400) : 0;
    $issuer_cn    = $certdata['issuer']['CN'] ?? ($certdata['issuer']['O'] ?? 'Desconocido');
    $subject_cn   = $certdata['subject']['CN'] ?? $domain;
    $is_le        = stripos($issuer_cn, "Let's Encrypt") !== false
                    || stripos($issuer_cn, 'ISRG') !== false;

    $sans = [];
    if (isset($certdata['extensions']['subjectAltName'])) {
        foreach (explode(', ', $certdata['extensions']['subjectAltName']) as $entry) {
            if (str_starts_with($entry, 'DNS:')) $sans[] = substr($entry, 4);
        }
    }

    api_respond(200, [
        'domain'          => $domain,
        'ssl_active'      => $has_ssl_db,
        'has_cert'        => true,
        'is_letsencrypt'  => $is_le,
        'subject'         => $subject_cn,
        'issuer'          => $issuer_cn,
        'sans'            => $sans,
        'issued_at'       => date('c', $issued_ts),
        'expires_at'      => date('c', $expires_ts),
        'days_remaining'  => $days_left,
        'needs_renewal'   => $days_left < 30,
        'ssl_port'        => (int)($vrow['vh_ssl_port_in'] ?? 0),
    ]);
}

// ── POST /v1/domains/{domain}/ssl — emitir/crear certificado LE ───────────────

if ($method === 'POST' && $resource === 'domains' && $res_id && $sub === 'ssl' && !$sub2) {
    require_scope('write');
    $vh       = resolve_domain(strtolower(trim($res_id)));
    $username = $vh['ac_user_vc'];
    $domain   = $vh['vh_name_vc'];

    $vq = $zdbh->prepare("SELECT vh_ssl_tx, vh_ssl_port_in, vh_directory_vc, vh_type_in FROM x_vhosts WHERE vh_id_pk=:id LIMIT 1");
    $vq->execute([':id' => (int)$vh['vh_id_pk']]);
    $vrow = $vq->fetch(PDO::FETCH_ASSOC);

    $server_ip = ctrl_options::GetSystemOption('server_ip');

    if (!checkDNSIsLive($domain, $server_ip)) {
        api_respond(422, [
            'error'   => 'Unprocessable Entity',
            'message' => "El DNS de '$domain' no apunta a este servidor ($server_ip). Verifica los registros A antes de emitir el certificado.",
            'code'    => 422,
        ]);
    }

    $accountDir  = ctrl_options::GetSystemOption('hosted_dir') . $username . '/ssl/sencrypt/letsencrypt/';
    $certloc     = $accountDir . $domain . '/';
    $certfile    = $certloc . 'cert.pem';

    // Si ya existe cert válido y no expira en 30 días, no hacer nada
    if (file_exists($certfile)) {
        $existing = openssl_x509_parse(file_get_contents($certfile));
        if ($existing !== false) {
            $days_left = (int)(((int)($existing['validTo_time_t'] ?? 0) - time()) / 86400);
            if ($days_left >= 30) {
                api_respond(409, [
                    'error'   => 'Conflict',
                    'message' => "Ya existe un certificado válido con $days_left días restantes. Usa POST /v1/domains/{$domain}/ssl/renew para forzar la renovación.",
                    'code'    => 409,
                ]);
            }
        }
    }

    $vhpaths = ctrl_options::GetVhostPaths($username, $vrow['vh_directory_vc']);
    $webroot  = $vhpaths['public_html'];

    require_once 'modules/sencrypt/code/Lescript.php';
    date_default_timezone_set('UTC');

    try {
        $le = new Analogic\ACME\Lescript($accountDir, $certloc, $webroot, null);
        $le->initAccount();

        $is_subdomain = ((int)($vrow['vh_type_in'] ?? 0) === 2);
        if ($is_subdomain) {
            $le->signDomains([$domain]);
        } else {
            $le->signDomains([$domain, 'www.' . $domain]);
        }
    } catch (\Exception $e) {
        error_log('API SSL create: ' . $domain . ' — ' . $e->getMessage());
        api_respond(500, [
            'error'   => 'Internal Server Error',
            'message' => 'Error al emitir el certificado: ' . $e->getMessage(),
            'code'    => 500,
        ]);
    }

    // Leer el cert recién generado para confirmar y devolver info
    $certdata = file_exists($certfile) ? openssl_x509_parse(file_get_contents($certfile)) : false;
    $expires_at = $certdata ? date('c', (int)$certdata['validTo_time_t']) : null;

    api_respond(201, [
        'domain'      => $domain,
        'username'    => $username,
        'cert_path'   => $certfile,
        'expires_at'  => $expires_at,
        'message'     => "Certificado Let's Encrypt emitido correctamente. Activa SSL en el módulo Sencrypt para habilitarlo en Apache.",
    ]);
}

// ── POST /v1/domains/{domain}/ssl/renew — forzar renovación ──────────────────

if ($method === 'POST' && $resource === 'domains' && $res_id && $sub === 'ssl' && $sub2 === 'renew') {
    require_scope('write');
    $vh       = resolve_domain(strtolower(trim($res_id)));
    $username = $vh['ac_user_vc'];
    $domain   = $vh['vh_name_vc'];

    $vq = $zdbh->prepare("SELECT vh_directory_vc, vh_type_in FROM x_vhosts WHERE vh_id_pk=:id LIMIT 1");
    $vq->execute([':id' => (int)$vh['vh_id_pk']]);
    $vrow = $vq->fetch(PDO::FETCH_ASSOC);

    $server_ip  = ctrl_options::GetSystemOption('server_ip');
    $accountDir = ctrl_options::GetSystemOption('hosted_dir') . $username . '/ssl/sencrypt/letsencrypt/';
    $certloc    = $accountDir . $domain . '/';
    $certfile   = $certloc . 'cert.pem';

    if (!checkDNSIsLive($domain, $server_ip)) {
        api_respond(422, [
            'error'   => 'Unprocessable Entity',
            'message' => "El DNS de '$domain' no apunta a este servidor ($server_ip). No se puede renovar.",
            'code'    => 422,
        ]);
    }

    if (!file_exists($certfile)) {
        api_respond(404, [
            'error'   => 'Not Found',
            'message' => "No existe certificado Let's Encrypt previo para '$domain'. Usa POST /v1/domains/{$domain}/ssl para crear uno.",
            'code'    => 404,
        ]);
    }

    $vhpaths = ctrl_options::GetVhostPaths($username, $vrow['vh_directory_vc']);
    $webroot  = $vhpaths['public_html'];

    require_once 'modules/sencrypt/code/Lescript.php';
    date_default_timezone_set('UTC');

    try {
        $le = new Analogic\ACME\Lescript($accountDir, $certloc, $webroot, null);
        $le->initAccount();

        $is_subdomain = ((int)($vrow['vh_type_in'] ?? 0) === 2);
        if ($is_subdomain) {
            $le->signDomains([$domain]);
        } else {
            $le->signDomains([$domain, 'www.' . $domain]);
        }
    } catch (\Exception $e) {
        error_log('API SSL renew: ' . $domain . ' — ' . $e->getMessage());
        api_respond(500, [
            'error'   => 'Internal Server Error',
            'message' => 'Error al renovar el certificado: ' . $e->getMessage(),
            'code'    => 500,
        ]);
    }

    $certdata   = file_exists($certfile) ? openssl_x509_parse(file_get_contents($certfile)) : false;
    $expires_at = $certdata ? date('c', (int)$certdata['validTo_time_t']) : null;
    $days_left  = $certdata ? (int)(((int)$certdata['validTo_time_t'] - time()) / 86400) : 0;

    api_respond(200, [
        'domain'         => $domain,
        'username'       => $username,
        'cert_path'      => $certfile,
        'expires_at'     => $expires_at,
        'days_remaining' => $days_left,
        'message'        => "Certificado renovado correctamente.",
    ]);
}

// ── 501 fallback ──────────────────────────────────────────────────────────────

api_respond(501, ['error' => 'Not Implemented', 'message' => 'Endpoint no disponible.', 'code' => 501]);

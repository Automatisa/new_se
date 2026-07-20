<?php

/**
 * dns_cluster — Control plane del cluster DNS (Fase 2, modelo híbrido AXFR + API).
 *
 * Sincroniza la LISTA de zonas de cada nodo peer consultando su API REST
 * (GET /v1/domains) y la guarda en x_dns_remote_zones, para que WriteDNSNamedHook
 * declare esas zonas como `type secondary` (los DATOS los replica BIND por AXFR).
 * No usa el autoloader: requerir con require_once explícito (como privilege).
 */
class dns_cluster
{
    /**
     * Recorre los peers habilitados, actualiza x_dns_remote_zones si su lista de
     * zonas cambió, y marca dns_hasupdates para regenerar named.conf. Idempotente.
     * @return bool true si hubo cambios.
     */
    /**
     * Propaga la lista de nodos para formar la MALLA COMPLETA (N nodos): consulta
     * GET /v1/cluster/nodes de cada peer conocido y da de alta en x_dns_nodes los que
     * falten. Como todo nodo conoce al primario (registro), la malla converge en pocos
     * ciclos aunque un nodo nuevo se una a través de cualquier peer. Idempotente.
     * @return bool true si la malla cambió (nuevos peers).
     */
    static function SyncClusterNodes()
    {
        global $zdbh;
        if (ctrl_options::GetSystemOption('dns_cluster_enabled') !== 'true') {
            return false;
        }
        $token = (string)ctrl_options::GetSystemOption('dns_cluster_token');
        if ($token === '') {
            return false;
        }

        $self     = $zdbh->query("SELECT nd_name_vc, nd_ip_vc FROM x_dns_nodes WHERE nd_is_self_in=1 LIMIT 1")->fetch();
        $selfName = $self ? strtolower($self['nd_name_vc']) : '';
        $selfIp   = $self ? (string)$self['nd_ip_vc'] : '';

        $peers   = $zdbh->query("SELECT nd_id_pk, nd_name_vc, nd_ip_vc, nd_sync_ip_vc, nd_api_url_vc FROM x_dns_nodes WHERE nd_enabled_in=1 AND nd_is_self_in=0")->fetchAll();
        $changed = false;

        foreach ($peers as $peer) {
            // El TRANSPORTE usa la IP de SINCRONIZACIÓN (nd_sync_ip_vc: la del túnel WireGuard si lo
            // hay) con fallback a la pública. La IP pública (nd_ip_vc) es la que va en los registros
            // A del DNS. URL SIEMPRE por IP: el cluster no puede depender de su propio DNS.
            $sip = self::syncIp($peer);
            $url = 'https://' . $sip . '/bin/api.php';
            $pin = self::ensurePeerPin($peer['nd_id_pk'], $sip);
            $nodes = self::fetchPeerNodes($url, $token, $pin);
            if ($nodes === null) {
                continue;
            }
            foreach ($nodes as $n) {
                $name    = strtolower(trim($n['name'] ?? ''));
                $ip      = trim($n['ip'] ?? '');
                $syncip  = trim($n['sync_ip'] ?? '');
                $enabled = array_key_exists('enabled', $n) ? (bool)$n['enabled'] : true;
                if ($name === '' || !filter_var($ip, FILTER_VALIDATE_IP)) { continue; }
                if ($syncip !== '' && !filter_var($syncip, FILTER_VALIDATE_IP)) { $syncip = ''; }
                if ($name === $selfName || $ip === $selfIp) { continue; }  // nunca tocar el propio nodo

                $st = $zdbh->prepare("SELECT nd_id_pk, nd_ip_vc, nd_sync_ip_vc, nd_enabled_in FROM x_dns_nodes WHERE nd_name_vc=:n");
                $st->execute([':n' => $name]);
                $existing = $st->fetch();

                if (!$existing) {
                    // Alta con el estado reportado: si el peer lo da como tombstone, se crea
                    // deshabilitado (no resucita un nodo ya retirado).
                    $zdbh->prepare("INSERT INTO x_dns_nodes (nd_name_vc, nd_ip_vc, nd_sync_ip_vc, nd_api_url_vc, nd_is_self_in, nd_enabled_in, nd_created_ts)
                                    VALUES (:n, :i, :s, :u, 0, :e, :t)")
                         ->execute([':n' => $name, ':i' => $ip, ':s' => ($syncip !== '' ? $syncip : null), ':u' => 'https://' . $ip . '/bin/api.php', ':e' => ($enabled ? 1 : 0), ':t' => time()]);
                    $changed = true;
                    if ($enabled) {
                        echo "dns_cluster: nuevo nodo en la malla -> " . $name . " (" . $ip . ")\n";
                        self::logEvent("Nuevo nodo en la malla: " . $name . " (" . $ip . ")");
                    }
                } elseif (!$enabled && (int)$existing['nd_enabled_in'] === 1) {
                    // TOMBSTONE monotónico: la baja se propaga -> deshabilitar localmente y
                    // limpiar sus zonas remotas para que salga de named.conf.
                    $zdbh->prepare("UPDATE x_dns_nodes SET nd_enabled_in=0 WHERE nd_id_pk=:id")->execute([':id' => $existing['nd_id_pk']]);
                    $zdbh->prepare("DELETE FROM x_dns_remote_zones WHERE rz_node_fk=:id")->execute([':id' => $existing['nd_id_pk']]);
                    $changed = true;
                    echo "dns_cluster: nodo dado de baja en la malla -> " . $name . "\n";
                    self::logEvent("Nodo dado de baja (propagado en la malla): " . $name);
                } elseif ($enabled && (int)$existing['nd_enabled_in'] === 1
                          && ((string)$existing['nd_ip_vc'] !== $ip || (string)$existing['nd_sync_ip_vc'] !== $syncip)) {
                    // Nodo activo con IP pública o de sync cambiada: actualizar (NO reactiva tombstones).
                    $zdbh->prepare("UPDATE x_dns_nodes SET nd_ip_vc=:i, nd_sync_ip_vc=:s WHERE nd_id_pk=:id")
                         ->execute([':i' => $ip, ':s' => ($syncip !== '' ? $syncip : null), ':id' => $existing['nd_id_pk']]);
                    $changed = true;
                }
                // 'enabled' reportado sobre un tombstone local -> NO reactivar (sticky): solo
                // un join explícito (POST /cluster/nodes) o el CLI vuelven a activar un nodo.
            }
        }

        if ($changed) {
            // La malla cambió -> regenerar named.conf (allow-transfer/also-notify/secondary)
            // sin pisar ids de dominio pendientes.
            $cur = (string)ctrl_options::GetSystemOption('dns_hasupdates');
            if (trim($cur) === '') {
                $zdbh->exec("UPDATE x_settings SET so_value_tx='cluster' WHERE so_name_vc='dns_hasupdates'");
            }
        }
        return $changed;
    }

    static function SyncRemoteZones()
    {
        global $zdbh;
        $changed = false;

        // El cluster tiene su propio interruptor, independiente de la API de usuarios.
        if (ctrl_options::GetSystemOption('dns_cluster_enabled') !== 'true') {
            return false;
        }
        $token = (string)ctrl_options::GetSystemOption('dns_cluster_token');
        if ($token === '') {
            return false;
        }

        $peers = $zdbh->query("SELECT * FROM x_dns_nodes WHERE nd_enabled_in=1 AND nd_is_self_in=0")->fetchAll();
        foreach ($peers as $peer) {
            if (empty($peer['nd_ip_vc'])) {
                continue;
            }
            // URL por IP (DNS-independiente): el cluster es el propio DNS, no puede depender
            // de resolver el hostname del peer para sincronizar la lista de zonas.
            $sip     = self::syncIp($peer);
            $apiUrl  = 'https://' . $sip . '/bin/api.php';
            $pin     = self::ensurePeerPin($peer['nd_id_pk'], $sip);
            $domains = self::fetchPeerZones($apiUrl, $token, $pin);
            if ($domains === null) {
                error_log('dns_cluster: sin respuesta del peer ' . $peer['nd_name_vc']);
                continue;
            }

            $st = $zdbh->prepare("SELECT rz_domain_vc FROM x_dns_remote_zones WHERE rz_node_fk=:n");
            $st->execute([':n' => $peer['nd_id_pk']]);
            $stored = $st->fetchAll(PDO::FETCH_COLUMN);

            sort($domains);
            sort($stored);
            if ($domains !== $stored) {
                $zdbh->prepare("DELETE FROM x_dns_remote_zones WHERE rz_node_fk=:n")
                     ->execute([':n' => $peer['nd_id_pk']]);
                $ins = $zdbh->prepare("INSERT INTO x_dns_remote_zones (rz_node_fk, rz_domain_vc, rz_seen_ts) VALUES (:n, :d, :t)");
                foreach ($domains as $d) {
                    $ins->execute([':n' => $peer['nd_id_pk'], ':d' => $d, ':t' => time()]);
                }
                $changed = true;
                echo "dns_cluster: peer " . $peer['nd_name_vc'] . " → " . count($domains) . " zonas (cambio)\n";
                self::logEvent("Peer " . $peer['nd_name_vc'] . ": " . count($domains) . " zonas (cambio)");
            }
            $zdbh->prepare("UPDATE x_dns_nodes SET nd_last_sync_ts=:t WHERE nd_id_pk=:n")
                 ->execute([':t' => time(), ':n' => $peer['nd_id_pk']]);
        }

        if ($changed) {
            // Forzar la regeneración de named.conf (bloques `type secondary`) sin pisar los
            // IDs de dominio ya pendientes: dns_hasupdates es una lista de IDs de vhost, no un
            // booleano. Solo marcamos si está vacía; si ya tiene ids, el daemon regenera igual.
            $cur = (string)ctrl_options::GetSystemOption('dns_hasupdates');
            if (trim($cur) === '') {
                $zdbh->exec("UPDATE x_settings SET so_value_tx='cluster' WHERE so_name_vc='dns_hasupdates'");
            }
        }
        return $changed;
    }

    /**
     * IP por la que se ALCANZA a un peer para la sync (API) y el AXFR: la de sincronización
     * (nd_sync_ip_vc, p.ej. la del túnel WireGuard) si está definida; si no, la pública (nd_ip_vc).
     * La pública se sigue usando para los registros A del DNS (ns/panel), que ve el mundo.
     */
    static function syncIp($peer)
    {
        return (!empty($peer['nd_sync_ip_vc'])) ? (string)$peer['nd_sync_ip_vc'] : (string)$peer['nd_ip_vc'];
    }

    /**
     * Política TLS del canal de control del cluster, según el ajuste dns_cluster_tls_verify:
     *   off  -> sin verificar (dev / LAN de confianza). Comportamiento histórico.
     *   pin  -> SOLO acepta el cert cuya CLAVE PÚBLICA casa con $pin (CURLOPT_PINNEDPUBLICKEY);
     *           sirve para autofirmados pero fija UN cert concreto -> corta el MITM continuo.
     *           Sin pin todavía (TOFU pendiente) cae a 'off' en esta llamada; el capturador lo fija.
     *   ca   -> verificación completa (VERIFYPEER) contra la CA propia (dns_cluster_ca_file);
     *           por IP (SAN). Es la vía de producción sin depender del DNS del propio cluster.
     */
    static function applyTlsPolicy($ch, $pin = '')
    {
        $mode = strtolower((string)ctrl_options::GetSystemOption('dns_cluster_tls_verify'));
        if ($mode === 'ca') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            $ca = (string)ctrl_options::GetSystemOption('dns_cluster_ca_file');
            if ($ca !== '' && is_readable($ca)) {
                curl_setopt($ch, CURLOPT_CAINFO, $ca);
            }
            return;
        }
        // 'off' y 'pin': el transporte no valida cadena de CA (autofirmado); en 'pin' manda la huella.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if ($mode === 'pin' && $pin !== '' && defined('CURLOPT_PINNEDPUBLICKEY')) {
            curl_setopt($ch, CURLOPT_PINNEDPUBLICKEY, $pin);
        }
    }

    /**
     * Huella "sha256//BASE64" de la clave pública (SPKI) del cert que sirve un peer en IP:puerto.
     * Formato idéntico al que espera CURLOPT_PINNEDPUBLICKEY. '' si no se pudo obtener.
     */
    static function fetchCertPin($ip, $port = 443)
    {
        $ctx = stream_context_create(array('ssl' => array(
            'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false,
        )));
        $cli = @stream_socket_client("ssl://$ip:$port", $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if (!$cli) {
            return '';
        }
        $params = stream_context_get_params($cli);
        fclose($cli);
        $cert = isset($params['options']['ssl']['peer_certificate']) ? $params['options']['ssl']['peer_certificate'] : null;
        if (!$cert) {
            return '';
        }
        $pub = openssl_pkey_get_public($cert);
        if (!$pub) {
            return '';
        }
        $d = openssl_pkey_get_details($pub);
        if (empty($d['key'])) {
            return '';
        }
        // PEM de la clave pública -> DER (SPKI) -> sha256 -> base64.
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $d['key']));
        return 'sha256//' . base64_encode(hash('sha256', $der, true));
    }

    /**
     * Devuelve el pin del peer para el modo actual. En 'pin', si aún no hay huella guardada, la
     * captura (TOFU: confianza en el primer contacto) y la persiste en x_dns_nodes. En otros modos '' .
     */
    static function ensurePeerPin($nodeId, $ip)
    {
        global $zdbh;
        if (strtolower((string)ctrl_options::GetSystemOption('dns_cluster_tls_verify')) !== 'pin') {
            return '';
        }
        $st = $zdbh->prepare("SELECT nd_cert_pin_vc FROM x_dns_nodes WHERE nd_id_pk=:id");
        $st->execute(array(':id' => $nodeId));
        $pin = (string)$st->fetchColumn();
        if ($pin !== '') {
            return $pin;
        }
        $pin = self::fetchCertPin($ip);
        if ($pin !== '') {
            $zdbh->prepare("UPDATE x_dns_nodes SET nd_cert_pin_vc=:p WHERE nd_id_pk=:id")
                 ->execute(array(':p' => $pin, ':id' => (int)$nodeId));
            self::logEvent("Pin TLS capturado (TOFU) para nodo id " . (int)$nodeId . ": " . substr($pin, 0, 20) . "…");
        }
        return $pin;
    }

    /**
     * Lista de zonas (primary) que sirve un peer vía la API dedicada del cluster
     * (GET /v1/cluster/zones, autenticada con el token compartido), o null si error.
     */
    static function fetchPeerZones($apiUrl, $token, $pin = '')
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $url = rtrim($apiUrl, '/') . '/v1/cluster/zones';
        $ch  = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $token, 'Accept: application/json'),
        ));
        self::applyTlsPolicy($ch, $pin);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || $body === false) {
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['zones']) || !is_array($json['zones'])) {
            return null;
        }
        $out = array();
        foreach ($json['zones'] as $z) {
            if (!empty($z)) {
                $out[] = strtolower($z);
            }
        }
        return $out;
    }

    /**
     * Lista de nodos del cluster que conoce un peer (GET /v1/cluster/nodes), o null si error.
     * Cada elemento: array con 'name', 'ip', 'api_url'.
     */
    static function fetchPeerNodes($apiUrl, $token, $pin = '')
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $url = rtrim($apiUrl, '/') . '/v1/cluster/nodes';
        $ch  = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $token, 'Accept: application/json'),
        ));
        self::applyTlsPolicy($ch, $pin);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || $body === false) {
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['nodes']) || !is_array($json['nodes'])) {
            return null;
        }
        return $json['nodes'];
    }

    /**
     * Registra un evento del cluster en x_logs (visible en System Log). $user=0 => sistema
     * (daemon). El log nunca debe romper el flujo de sincronización.
     */
    static function logEvent($detail, $user = 0)
    {
        global $zdbh;
        try {
            $zdbh->prepare("INSERT INTO x_logs (lg_user_fk, lg_code_vc, lg_module_vc, lg_detail_tx) VALUES (:u, 'CLUSTER', 'dns_admin', :d)")
                 ->execute([':u' => (int)$user, ':d' => (string)$detail]);
        } catch (Exception $e) {
            // silencioso: la auditoría no debe abortar el cluster
        }
    }
}

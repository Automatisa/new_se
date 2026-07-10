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

        $peers   = $zdbh->query("SELECT nd_name_vc, nd_ip_vc, nd_api_url_vc FROM x_dns_nodes WHERE nd_enabled_in=1 AND nd_is_self_in=0")->fetchAll();
        $changed = false;

        foreach ($peers as $peer) {
            // URL SIEMPRE por IP: el cluster no puede depender de su propio DNS para
            // sincronizarse (auto-reparable si un api_url guardado quedó inalcanzable).
            $url = 'https://' . $peer['nd_ip_vc'] . '/bin/api.php';
            $nodes = self::fetchPeerNodes($url, $token);
            if ($nodes === null) {
                continue;
            }
            foreach ($nodes as $n) {
                $name    = strtolower(trim($n['name'] ?? ''));
                $ip      = trim($n['ip'] ?? '');
                $enabled = array_key_exists('enabled', $n) ? (bool)$n['enabled'] : true;
                if ($name === '' || !filter_var($ip, FILTER_VALIDATE_IP)) { continue; }
                if ($name === $selfName || $ip === $selfIp) { continue; }  // nunca tocar el propio nodo

                $st = $zdbh->prepare("SELECT nd_id_pk, nd_ip_vc, nd_enabled_in FROM x_dns_nodes WHERE nd_name_vc=:n");
                $st->execute([':n' => $name]);
                $existing = $st->fetch();

                if (!$existing) {
                    // Alta con el estado reportado: si el peer lo da como tombstone, se crea
                    // deshabilitado (no resucita un nodo ya retirado).
                    $zdbh->prepare("INSERT INTO x_dns_nodes (nd_name_vc, nd_ip_vc, nd_api_url_vc, nd_is_self_in, nd_enabled_in, nd_created_ts)
                                    VALUES (:n, :i, :u, 0, :e, :t)")
                         ->execute([':n' => $name, ':i' => $ip, ':u' => 'https://' . $ip . '/bin/api.php', ':e' => ($enabled ? 1 : 0), ':t' => time()]);
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
                } elseif ($enabled && (int)$existing['nd_enabled_in'] === 1 && (string)$existing['nd_ip_vc'] !== $ip) {
                    // Nodo activo con IP cambiada: actualizar (NO reactiva tombstones).
                    $zdbh->prepare("UPDATE x_dns_nodes SET nd_ip_vc=:i WHERE nd_id_pk=:id")->execute([':i' => $ip, ':id' => $existing['nd_id_pk']]);
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
            $apiUrl  = 'https://' . $peer['nd_ip_vc'] . '/bin/api.php';
            $domains = self::fetchPeerZones($apiUrl, $token);
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
     * Lista de zonas (primary) que sirve un peer vía la API dedicada del cluster
     * (GET /v1/cluster/zones, autenticada con el token compartido), o null si error.
     */
    static function fetchPeerZones($apiUrl, $token)
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
            // Los nodos del cluster suelen usar cert autofirmado del panel entre sí.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ));
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
    static function fetchPeerNodes($apiUrl, $token)
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
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ));
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

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
            if (empty($peer['nd_api_url_vc'])) {
                continue;
            }
            $domains = self::fetchPeerZones($peer['nd_api_url_vc'], $token);
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
}

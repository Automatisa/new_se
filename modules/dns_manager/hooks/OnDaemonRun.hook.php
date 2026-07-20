<?php
if (!class_exists('privilege')) {
    require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
}

echo fs_filehandler::NewLine() . "START DNS Manager Hook" . fs_filehandler::NewLine();

if (ui_module::CheckModuleEnabled('DNS Config')) {
    echo "DNS Manager module ENABLED..." . fs_filehandler::NewLine();

    // Generate pending DKIM keys first — may add entries to dns_hasupdates
    CheckDKIMKeysHook();
    // Always try to extract DS records — BIND generates keys asynchronously
    ExtractDSRecordsHook();

    // Cluster DNS (Fase 2): sincronizar la lista de zonas de los peers; si cambia,
    // marca dns_hasupdates para regenerar named.conf con los bloques `type secondary`.
    if (!class_exists('dns_cluster')) {
        require_once '/usr/local/bulwark/dryden/sys/dns_cluster.class.php';
    }
    dns_cluster::SyncClusterNodes();
    dns_cluster::SyncRemoteZones();

    if (!fs_director::CheckForEmptyValue(ctrl_options::GetSystemOption('dns_hasupdates'))) {
        echo "DNS Records have changed... Writing new/updated records..." . fs_filehandler::NewLine();
        WriteDNSZoneRecordsHook();
        WriteDNSNamedHook();
        ResetDNSRecordsUpatedHook();
        PurgeOldZoneDNSRecordsHook();
        ReloadBindHook();
    } else {
        echo "DNS Records have not changed...nothing to do." . fs_filehandler::NewLine();
    }
} else {
    echo "DNS Manager module DISABLED...nothing to do." . fs_filehandler::NewLine();
}

echo "END DNS Manager Hook." . fs_filehandler::NewLine();

// ── helpers ───────────────────────────────────────────────────────────────────

/**
 * Format a TXT record value for a zone file.
 * BIND wire protocol limits each string to 255 bytes; DKIM 2048-bit keys
 * (~392 chars) exceed that and must be split into multiple quoted chunks.
 */
function dnsFormatTXT($value, $chunkLen = 250)
{
    if (strlen($value) <= $chunkLen) {
        return '"' . $value . '"';
    }
    return '"' . implode('" "', str_split($value, $chunkLen)) . '"';
}

function TriggerDNSUpdateHook($domainId)
{
    global $zdbh;
    $current = (string)ctrl_options::GetSystemOption('dns_hasupdates');
    $list    = array_filter(array_map('trim', explode(',', $current)));
    if (!in_array((string)$domainId, $list)) {
        $list[]  = (string)$domainId;
        $newlist = implode(',', $list);
        $sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='dns_hasupdates'");
        $sql->bindParam(':v', $newlist);
        $sql->execute();
    }
}

// ── DKIM key management ───────────────────────────────────────────────────────

function CheckDKIMKeysHook()
{
    global $zdbh;

    // Find domains whose DKIM record is still the 'PENDING' placeholder
    $sql = $zdbh->prepare(
        "SELECT dn_id_pk, dn_vhost_fk, dn_name_vc FROM x_dns
         WHERE dn_host_vc   = 'default._domainkey'
           AND dn_type_vc   = 'TXT'
           AND dn_target_vc = 'PENDING'
           AND dn_deleted_ts IS NULL"
    );
    $sql->execute();
    $pending = $sql->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pending as $row) {
        $domain   = $row['dn_name_vc'];
        $recordId = $row['dn_id_pk'];
        $vhostId  = $row['dn_vhost_fk'];

        echo "Generating DKIM key for: $domain" . fs_filehandler::NewLine();

        $keydir = "/usr/local/etc/opendkim/keys/$domain";
        if (!is_dir($keydir)) {
            mkdir($keydir, 0700, true);
        }
        @chmod($keydir, 0700);
        // El daemon opendkim corre como 'mailnull'; las claves deben ser suyas (o de root) y no
        // escribibles por otros, o rechaza la clave ("key data is not secure") y tempfailea el correo.
        chown($keydir, 'mailnull');
        chgrp($keydir, 'mailnull');

        // Generate 2048-bit RSA key pair using PHP OpenSSL (daemon runs as root)
        $res = openssl_pkey_new([
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if (!$res) {
            echo "  ERROR: " . openssl_error_string() . fs_filehandler::NewLine();
            continue;
        }

        openssl_pkey_export($res, $privateKeyPem);
        $details = openssl_pkey_get_details($res);

        // Write private key — readable only by opendkim
        $privFile = "$keydir/default.private";
        file_put_contents($privFile, $privateKeyPem);
        chmod($privFile, 0600);
        chown($privFile, 'mailnull');
        chgrp($privFile, 'mailnull');

        // Build DKIM TXT value: strip PEM armor → bare base64 DER
        $pubB64    = preg_replace('/-----[^-]+-----|[\r\n]/', '', $details['key']);
        $dkimValue = "v=DKIM1; k=rsa; p=$pubB64";

        // Replace the placeholder with the real public key
        $upd = $zdbh->prepare("UPDATE x_dns SET dn_target_vc=:val WHERE dn_id_pk=:id");
        $upd->bindParam(':val', $dkimValue);
        $upd->bindParam(':id',  $recordId);
        $upd->execute();

        // Mark zone for rewrite so WriteDNSZoneRecordsHook includes the new key
        TriggerDNSUpdateHook($vhostId);

        echo "  OK — $privFile" . fs_filehandler::NewLine();
    }

    // Always rebuild OpenDKIM config to reflect current set of active domains
    UpdateOpenDKIMConfigHook();
}

function UpdateOpenDKIMConfigHook()
{
    global $zdbh;

    $sql = $zdbh->prepare(
        "SELECT dn_name_vc FROM x_dns
         WHERE dn_host_vc   = 'default._domainkey'
           AND dn_type_vc   = 'TXT'
           AND dn_target_vc != 'PENDING'
           AND dn_deleted_ts IS NULL"
    );
    $sql->execute();
    $domains = $sql->fetchAll(PDO::FETCH_COLUMN);

    $keyTable     = '';
    $signingTable = '';
    foreach ($domains as $domain) {
        $keyFile = "/usr/local/etc/opendkim/keys/$domain/default.private";
        if (!file_exists($keyFile)) continue;
        $keyTable     .= "default._domainkey.$domain\t$domain:default:$keyFile\n";
        $signingTable .= "*@$domain\tdefault._domainkey.$domain\n";
    }

    file_put_contents('/usr/local/etc/opendkim/KeyTable',    $keyTable);
    file_put_contents('/usr/local/etc/opendkim/SigningTable', $signingTable);

    privilege::run('dkim_reload');
    echo "OpenDKIM reloaded." . fs_filehandler::NewLine();
}

// ── zone file writing ─────────────────────────────────────────────────────────

function WriteDNSZoneRecordsHook()
{
    global $zdbh;

    $DomainsNeedingUpdate = explode(",", ctrl_options::GetSystemOption('dns_hasupdates'));

    $DomainsInDnsTable = [];
    $sql = $zdbh->prepare("SELECT dn_vhost_fk FROM x_dns WHERE dn_deleted_ts IS NULL GROUP BY dn_vhost_fk");
    $sql->execute();
    while ($r = $sql->fetch()) {
        $DomainsInDnsTable[] = $r['dn_vhost_fk'];
    }

    $DomainsToUpdate = array_intersect($DomainsNeedingUpdate, $DomainsInDnsTable);

    foreach ($DomainsToUpdate as $domain_id) {
        $domaininfo = $zdbh->prepare('SELECT vh_name_vc, vh_soaserial_vc FROM x_vhosts WHERE vh_id_pk=:domain');
        $domaininfo->bindparam(':domain', $domain_id);
        $domaininfo->execute();
        $domain     = $domaininfo->fetch();
        $DomainName = $domain['vh_name_vc'];
        $SoaSerial  = $domain['vh_soaserial_vc'];

        $SoaDate = date("Ymd");
        if (substr($SoaSerial, 0, 8) != $SoaDate) {
            $SoaSerial = $SoaDate . '00';
        } else {
            $SoaRev    = 1 + (int)substr($SoaSerial, 8, 2);
            $SoaSerial = $SoaDate . (($SoaRev < 10) ? '0' : '') . $SoaRev;
        }
        $updatesoa = $zdbh->prepare('UPDATE x_vhosts SET vh_soaserial_vc=:serial WHERE vh_id_pk=:domain');
        $updatesoa->bindparam(':serial', $SoaSerial);
        $updatesoa->bindparam(':domain', $domain_id);
        $updatesoa->execute();

        if (!is_dir(ctrl_options::GetSystemOption('zone_dir'))) {
            fs_director::CreateDirectory(ctrl_options::GetSystemOption('zone_dir'));
            fs_director::SetFileSystemPermissions(ctrl_options::GetSystemOption('zone_dir'));
        }

        $zone_file = ctrl_options::GetSystemOption('zone_dir') . $DomainName . ".txt";

        // Nameserver primario del SOA: nameserver compartido del panel (dns_ns1);
        // si no está configurado, se mantiene el vanity ns1.<dominio> como fallback.
        $soaNs = ctrl_options::GetSystemOption('dns_ns1');
        if (fs_director::CheckForEmptyValue($soaNs)) { $soaNs = 'ns1.' . $DomainName; }

        // $TTL uses single-quote to avoid PHP interpreting $TTL as variable
        $line  = '$TTL 10800' . fs_filehandler::NewLine();
        $line .= "@ IN SOA $soaNs.    postmaster.$DomainName. (" . fs_filehandler::NewLine();
        $line .= "    $SoaSerial  ;serial" . fs_filehandler::NewLine();
        $line .= "    " . ctrl_options::GetSystemOption('refresh_ttl') . "    ;refresh" . fs_filehandler::NewLine();
        $line .= "    " . ctrl_options::GetSystemOption('retry_ttl')   . "    ;retry" . fs_filehandler::NewLine();
        $line .= "    " . ctrl_options::GetSystemOption('expire_ttl')  . "   ;expire" . fs_filehandler::NewLine();
        $line .= "    " . ctrl_options::GetSystemOption('minimum_ttl') . " )    ;minimum TTL" . fs_filehandler::NewLine();

        $sql = $zdbh->prepare('SELECT * FROM x_dns WHERE dn_vhost_fk=:dnsrecord AND dn_deleted_ts IS NULL ORDER BY dn_type_vc');
        $sql->bindParam(':dnsrecord', $domain_id);
        $sql->execute();

        while ($rowdns = $sql->fetch()) {
            // Defense-in-depth: strip control characters regardless of insert origin.
            $host   = preg_replace('/[\r\n\t\0; ]/', '', (string)$rowdns['dn_host_vc']);
            $ttl    = max(60, (int)$rowdns['dn_ttl_in']);
            $target = preg_replace('/[\r\n\t\0]/',   '', (string)$rowdns['dn_target_vc']);
            $prio   = max(0,  (int)$rowdns['dn_priority_in']);

            // If the host is not a valid DNS name after stripping, skip the record entirely
            // rather than writing garbage into the zone file.
            $valid_host = ($host === '@' || $host === '*')
                || (bool)preg_match('/^[a-zA-Z0-9_\-\.\*]+$/', $host);
            if (!$valid_host || $host === '' || $target === '') {
                echo "  SKIPPED corrupted record id=" . $rowdns['dn_id_pk'] . " host=" . json_encode($rowdns['dn_host_vc']) . fs_filehandler::NewLine();
                continue;
            }

            switch ($rowdns['dn_type_vc']) {
                case "A":
                    $line .= "$host    $ttl    IN    A    $target" . fs_filehandler::NewLine();
                    break;
                case "AAAA":
                    $line .= "$host    $ttl    IN    AAAA    $target" . fs_filehandler::NewLine();
                    break;
                case "CNAME":
                    $suffix = ($target === '@') ? '' : '.';
                    $line .= "$host    $ttl    IN    CNAME   $target$suffix" . fs_filehandler::NewLine();
                    break;
                case "MX":
                    $line .= "$host    $ttl    IN    MX    $prio  $target." . fs_filehandler::NewLine();
                    break;
                case "TXT":
                    // Omit DKIM placeholder — zone will include the real key once daemon generates it
                    if ($host === 'default._domainkey' && $target === 'PENDING') break;
                    $line .= "$host    $ttl    IN    TXT    " . dnsFormatTXT(stripslashes($target)) . fs_filehandler::NewLine();
                    break;
                case "SPF":
                    // RFC 7208 (2014) retired the SPF RR type — emit only TXT
                    $line .= "$host    $ttl    IN    TXT    " . dnsFormatTXT(stripslashes($target)) . fs_filehandler::NewLine();
                    break;
                case "SRV":
                    $line .= "$host    $ttl    IN    SRV    $prio  " . (int)$rowdns['dn_weight_in'] . "  " . (int)$rowdns['dn_port_in'] . "  $target." . fs_filehandler::NewLine();
                    break;
                case "NS":
                    $line .= "$host    $ttl    IN    NS    $target." . fs_filehandler::NewLine();
                    break;
                case "CAA":
                    $line .= "$host    $ttl    IN    CAA    $target" . fs_filehandler::NewLine();
                    break;
            }
        }

        echo "Updating zone record: $DomainName" . fs_filehandler::NewLine();
        // SEC: 0644 (no 0777). Los datos de zona son públicos, pero NO deben ser
        // world-writable (un inquilino local podría reescribir el DNS). named los lee.
        fs_filehandler::UpdateFile($zone_file, 0644, $line);
    }
}

function WriteDNSNamedHook()
{
    global $zdbh;
    $domains = [];

    // Excluir zonas de cuentas deshabilitadas o cuyo reseller esté deshabilitado.
    // Suspendidos (propios o vía reseller) mantienen DNS activo.
    $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_deleted_ts IS NULL";
    if ($numrows = $zdbh->query($sql)) {
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare("
                SELECT d.dn_vhost_fk, d.dn_name_vc,
                       COALESCE(ds.dd_enabled_in, 0) AS dnssec_enabled
                FROM x_dns d
                JOIN x_vhosts v ON d.dn_vhost_fk = v.vh_id_pk
                JOIN x_accounts a ON v.vh_acc_fk = a.ac_id_pk
                LEFT JOIN x_accounts res ON a.ac_reseller_fk = res.ac_id_pk AND res.ac_deleted_ts IS NULL
                LEFT JOIN x_dns_dnssec ds ON ds.dd_vhost_fk = d.dn_vhost_fk
                WHERE d.dn_deleted_ts IS NULL
                  AND NOT (a.ac_enabled_in = 0 AND a.ac_suspended_in = 0)
                  AND NOT (res.ac_id_pk IS NOT NULL AND res.ac_enabled_in = 0 AND res.ac_suspended_in = 0)
                GROUP BY d.dn_vhost_fk, d.dn_name_vc, ds.dd_enabled_in
            ");
            $sql->execute();
            while ($rowdns = $sql->fetch()) {
                $domains[] = [
                    'name'   => $rowdns['dn_name_vc'],
                    'dnssec' => (bool)$rowdns['dnssec_enabled'],
                ];
            }
        }
    }

    if (!is_dir(ctrl_options::GetSystemOption('named_dir'))) {
        fs_director::CreateDirectory(ctrl_options::GetSystemOption('named_dir'));
        fs_director::SetFileSystemPermissions(ctrl_options::GetSystemOption('named_dir'));
    }

    $named_dir  = ctrl_options::GetSystemOption('named_dir');
    // named.conf       → vista internal: zonas sin DNSSEC (no inline-signing)
    // named-external.conf → vista external: zonas con DNSSEC en las habilitadas
    // Dos archivos separados porque inline-signing marca el archivo de zona como
    // writeable y BIND rechaza el mismo archivo en múltiples declaraciones.
    $internal_file = $named_dir . ctrl_options::GetSystemOption('named_conf');
    $external_file = $named_dir . 'named-external.conf';

    echo "Updating $internal_file + $external_file" . fs_filehandler::NewLine();

    // ── Cluster DNS (Fase 2): clave TSIG + peers (allow-transfer / also-notify / secondary) ──
    $tsig = ctrl_options::GetSystemOption('dns_tsig_key'); // "nombre secreto_base64"
    $tsigName = ''; $tsigSecret = '';
    if (!fs_director::CheckForEmptyValue($tsig)) {
        $parts = preg_split('/\s+/', trim($tsig), 2);
        $tsigName   = $parts[0];
        $tsigSecret = isset($parts[1]) ? $parts[1] : '';
    }
    // IP de SINCRONIZACIÓN de cada peer (nd_sync_ip_vc = túnel WireGuard si lo hay; si no, la
    // pública). El AXFR/NOTIFY entre nodos va por ahí; los registros A del DNS usan la pública.
    $peers = [];
    if ($pst = $zdbh->query("SELECT nd_ip_vc, nd_sync_ip_vc FROM x_dns_nodes WHERE nd_enabled_in=1 AND nd_is_self_in=0")) {
        while ($p = $pst->fetch()) {
            $sip = !empty($p['nd_sync_ip_vc']) ? $p['nd_sync_ip_vc'] : $p['nd_ip_vc'];
            if (!empty($sip)) { $peers[] = $sip; }
        }
    }
    $keyClause = ($tsigName !== '') ? ' key "' . $tsigName . '"' : '';

    // Contenido de allow-transfer. En BIND una address-match-list NO admite "IP key x"
    // combinado: se restringe por la clave TSIG (el secondary firma su AXFR con ella).
    // Sin TSIG, se listan las IPs de los peers; sin peers, el allow_xfer configurado.
    if ($peers && $tsigName !== '') {
        $xferInner   = 'key "' . $tsigName . '";';
        $notifyInner = implode('; ', $peers) . ';';
    } elseif ($peers) {
        $xferInner   = implode('; ', $peers) . ';';
        $notifyInner = implode('; ', $peers) . ';';
    } else {
        $xferInner   = ctrl_options::GetSystemOption('allow_xfer') . ';';
        $notifyInner = '';
    }

    // Declaración de la clave TSIG al principio de ambas vistas
    $keyBlock = '';
    if ($tsigName !== '' && $tsigSecret !== '') {
        $keyBlock  = 'key "' . $tsigName . '" {' . fs_filehandler::NewLine();
        $keyBlock .= "\talgorithm hmac-sha256;" . fs_filehandler::NewLine();
        $keyBlock .= "\tsecret \"$tsigSecret\";" . fs_filehandler::NewLine();
        $keyBlock .= '};' . fs_filehandler::NewLine() . fs_filehandler::NewLine();
    }

    $lineInternal = $keyBlock;
    $lineExternal = $keyBlock;

    $localNames = array_map(function ($e) { return $e['name']; }, $domains);

    foreach ($domains as $entry) {
        $domain   = $entry['name'];
        $dnssec   = $entry['dnssec'];
        $zoneFile = ctrl_options::GetSystemOption('zone_dir') . $domain . ".txt";
        echo "CHECKING ZONE FILE: $zoneFile..." . fs_filehandler::NewLine();

        $retval = ctrl_system::systemCommand(
            ctrl_options::GetSystemOption('named_checkzone'),
            [$domain, $zoneFile]
        );

        if ($retval == 0) {
            echo "Syntax check passed. Adding $domain" . fs_filehandler::NewLine();

            // External: all zones — DNSSEC-enabled ones get inline-signing
            $lineExternal .= "zone \"$domain\" IN {" . fs_filehandler::NewLine();
            $lineExternal .= "\ttype primary;" . fs_filehandler::NewLine();
            $lineExternal .= "\tfile \"$zoneFile\";" . fs_filehandler::NewLine();
            $lineExternal .= "\tallow-transfer { $xferInner };" . fs_filehandler::NewLine();
            if ($notifyInner !== '') {
                $lineExternal .= "\talso-notify { $notifyInner };" . fs_filehandler::NewLine();
            }
            if ($dnssec) {
                $keyDir = '/var/bulwark/named/keys/' . $domain;
                if (!is_dir($keyDir)) {
                    mkdir($keyDir, 0750, true);
                    chown($keyDir, 'bind');
                    chgrp($keyDir, 'bind');
                }
                $lineExternal .= "\tdnssec-policy \"bulwark-dnssec\";" . fs_filehandler::NewLine();
                $lineExternal .= "\tinline-signing yes;" . fs_filehandler::NewLine();
                $lineExternal .= "\tkey-directory \"$keyDir\";" . fs_filehandler::NewLine();
                echo "  DNSSEC signing enabled for $domain" . fs_filehandler::NewLine();
            }
            $lineExternal .= "};" . fs_filehandler::NewLine();

            // Internal: only non-DNSSEC zones (DNSSEC zones resolved via recursion
            // to avoid "writeable file already in use" conflict with inline-signing)
            if (!$dnssec) {
                $lineInternal .= "zone \"$domain\" IN {" . fs_filehandler::NewLine();
                $lineInternal .= "\ttype primary;" . fs_filehandler::NewLine();
                $lineInternal .= "\tfile \"$zoneFile\";" . fs_filehandler::NewLine();
                $lineInternal .= "\tallow-transfer { $xferInner };" . fs_filehandler::NewLine();
                if ($notifyInner !== '') {
                    $lineInternal .= "\talso-notify { $notifyInner };" . fs_filehandler::NewLine();
                }
                $lineInternal .= "};" . fs_filehandler::NewLine();
            }
        } else {
            echo "Syntax ERROR. Skipping $domain." . fs_filehandler::NewLine();
        }
    }

    // ── Zonas remotas: secondary (AXFR) desde los peers que las sirven como primary ──
    $slaveDir = rtrim(ctrl_options::GetSystemOption('zone_dir'), '/') . '/slave/';
    if (!is_dir($slaveDir)) {
        @mkdir($slaveDir, 0770, true);
        @chown($slaveDir, 'bind'); @chgrp($slaveDir, 'bind');
    }
    if ($rz = $zdbh->query("SELECT n.nd_ip_vc, n.nd_sync_ip_vc, z.rz_domain_vc
                            FROM x_dns_remote_zones z
                            JOIN x_dns_nodes n ON n.nd_id_pk = z.rz_node_fk
                            WHERE n.nd_enabled_in = 1 AND n.nd_is_self_in = 0")) {
        while ($row = $rz->fetch()) {
            $rdom = $row['rz_domain_vc'];
            // master por la IP de SINCRONIZACIÓN (túnel si lo hay); AXFR va por ahí.
            $mip  = !empty($row['nd_sync_ip_vc']) ? $row['nd_sync_ip_vc'] : $row['nd_ip_vc'];
            // No declarar como secondary una zona que ya servimos como primary (local)
            if (in_array($rdom, $localNames, true)) { continue; }
            $blk  = "zone \"$rdom\" IN {" . fs_filehandler::NewLine();
            $blk .= "\ttype secondary;" . fs_filehandler::NewLine();
            $blk .= "\tmasters { $mip$keyClause; };" . fs_filehandler::NewLine();
            $blk .= "\tfile \"" . $slaveDir . $rdom . ".txt\";" . fs_filehandler::NewLine();
            $blk .= "};" . fs_filehandler::NewLine();
            $lineInternal .= $blk;
            $lineExternal .= $blk;
        }
    }

    // SEC: named.conf contiene el SECRETO TSIG del cluster -> 0640 y grupo bind (nunca
    // world-readable, era 0777). named lee por owner/grupo; el daemon (root) lo escribe.
    fs_filehandler::UpdateFile($internal_file, 0640, $lineInternal);
    fs_filehandler::UpdateFile($external_file, 0640, $lineExternal);
    @chgrp($internal_file, 'bind');
    @chgrp($external_file, 'bind');
}

function ResetDNSRecordsUpatedHook()
{
    global $zdbh;
    $sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx=NULL WHERE so_name_vc='dns_hasupdates'");
    $sql->execute();
}

function PurgeOldZoneDNSRecordsHook()
{
    global $zdbh;
    $domains = [];
    $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_deleted_ts IS NULL";
    if ($numrows = $zdbh->query($sql)) {
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare("SELECT dn_name_vc FROM x_dns WHERE dn_deleted_ts IS NULL GROUP BY dn_name_vc");
            $sql->execute();
            while ($rowvhost = $sql->fetch()) {
                $domains[] = $rowvhost['dn_name_vc'];
            }
        }
    }
    $zonefiles = scandir(ctrl_options::GetSystemOption('zone_dir'));
    foreach ($zonefiles as $zonefile) {
        if ($zonefile === '.' || $zonefile === '..') continue;
        $path = ctrl_options::GetSystemOption('zone_dir') . $zonefile;
        // No tocar subdirectorios (p.ej. slave/ con las copias AXFR del cluster) ni
        // ficheros que no sean zonas .txt del panel.
        if (is_dir($path)) continue;
        if (substr($zonefile, -4) !== '.txt') continue;
        if (!in_array(substr($zonefile, 0, -4), $domains)) {
            if (file_exists($path)) {
                echo "Purging old zone record: " . substr($zonefile, 0, -4) . fs_filehandler::NewLine();
                unlink($path);
            }
        }
    }
}

function ReloadBindHook()
{
    echo "Reloading BIND now..." . fs_filehandler::NewLine();
    privilege::run('bind_reload');
}

// ── DNSSEC DS record extraction ───────────────────────────────────────────────

/**
 * Reads BIND-generated KSK .key files from each DNSSEC-enabled zone's
 * key-directory, computes the DS record in PHP (RFC 4034 §5.1 + §6.1),
 * and stores it in x_dns_dnssec.dd_ds_txt for display in the panel.
 *
 * Called on every daemon run (not just when dns_hasupdates is set) because
 * BIND generates and rotates keys asynchronously after inline-signing is
 * configured, independent of the PHP zone update cycle.
 *
 * No exec() — pure PHP OpenSSL + hash().
 */
function ExtractDSRecordsHook()
{
    global $zdbh;

    $sql = $zdbh->prepare("
        SELECT ds.dd_id_pk, ds.dd_vhost_fk, v.vh_name_vc
        FROM x_dns_dnssec ds
        JOIN x_vhosts v ON ds.dd_vhost_fk = v.vh_id_pk
        WHERE ds.dd_enabled_in = 1
          AND v.vh_deleted_ts IS NULL
    ");
    $sql->execute();
    $zones = $sql->fetchAll(PDO::FETCH_ASSOC);

    foreach ($zones as $zone) {
        $domain = $zone['vh_name_vc'];
        $ddId   = $zone['dd_id_pk'];
        $keyDir = '/var/bulwark/named/keys/' . $domain;

        if (!is_dir($keyDir)) continue;

        // BIND names KSK files: K<domain>.+<alg3digits>+<keytag>.key
        $keyFiles = glob($keyDir . '/K' . $domain . '.+*.key');
        if (empty($keyFiles)) continue;

        $dsText = null;
        $keyTag = null;

        foreach ($keyFiles as $keyFile) {
            $content = @file_get_contents($keyFile);
            if ($content === false) continue;

            // Match the DNSKEY RR line for the KSK (flags = 257)
            $pattern = '/^' . preg_quote($domain, '/') . '\.\s+\d+\s+IN\s+DNSKEY\s+257\s+(\d+)\s+(\d+)\s+([A-Za-z0-9+\/=\s]+)/m';
            if (!preg_match($pattern, $content, $m)) continue;

            $protocol  = (int)$m[1];
            $algorithm = (int)$m[2];
            $keyB64    = preg_replace('/\s+/', '', $m[3]);
            $keyBin    = base64_decode($keyB64, true);
            if ($keyBin === false || strlen($keyBin) === 0) continue;

            // RDATA = flags(2 bytes BE) | protocol(1 byte) | algorithm(1 byte) | key
            $rdata = pack('n', 257) . pack('C', $protocol) . pack('C', $algorithm) . $keyBin;

            // Key tag: RFC 4034 §6.1 (for all algorithms except 1/DH)
            $ac = 0;
            for ($i = 0, $len = strlen($rdata); $i < $len; $i++) {
                $ac += ($i & 1) ? ord($rdata[$i]) : (ord($rdata[$i]) << 8);
            }
            $ac  += ($ac >> 16) & 0xFFFF;
            $tag  = $ac & 0xFFFF;

            // Wire-format domain name: each label prefixed by its length, terminated with \x00
            $wire = '';
            foreach (explode('.', rtrim($domain, '.')) as $label) {
                $wire .= chr(strlen($label)) . $label;
            }
            $wire .= "\x00";

            // DS digest: SHA-256 of (wire_name || RDATA) — digest type 2
            $digest = strtoupper(hash('sha256', $wire . $rdata));
            $dsText = "$tag $algorithm 2 $digest";
            $keyTag = $tag;
            break;
        }

        if ($dsText !== null) {
            $upd = $zdbh->prepare(
                "UPDATE x_dns_dnssec SET dd_ds_txt=:ds, dd_keytag_in=:tag WHERE dd_id_pk=:id"
            );
            $upd->bindParam(':ds',  $dsText);
            $upd->bindParam(':tag', $keyTag);
            $upd->bindParam(':id',  $ddId);
            $upd->execute();
            echo "  DS record for $domain: $dsText" . fs_filehandler::NewLine();
        }
    }
}

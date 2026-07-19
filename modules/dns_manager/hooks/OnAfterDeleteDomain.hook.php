<?php
if (!class_exists('privilege')) {
    require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
}

DeleteDNSRecordsForDeletedDomain();
CleanupDKIMForDeletedDomains();

function DeleteDNSRecordsForDeletedDomain() {
    global $zdbh;
    $deleteddomains = [];
    $sql = "SELECT COUNT(*) FROM x_vhosts WHERE vh_deleted_ts IS NOT NULL";
    if ($numrows = $zdbh->query($sql)) {
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_deleted_ts IS NOT NULL");
            $sql->execute();
            while ($rowvhost = $sql->fetch()) {
                $deleteddomains[] = $rowvhost['vh_id_pk'];
            }
        }
    }
    foreach ($deleteddomains as $deleteddomain) {
        $numrows = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_vhost_fk=:deleteddomain AND dn_deleted_ts IS NULL");
        $numrows->bindParam(':deleteddomain', $deleteddomain);
        $numrows->execute();
        $result = $numrows->fetch();

        if ($result) {
            $sql = $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=:time WHERE dn_vhost_fk=:deleteddomain");
            $sql->bindParam(':deleteddomain', $deleteddomain);
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->execute();
            TriggerDNSUpdate($result['dn_vhost_fk']);
        }
    }
}

function CleanupDKIMForDeletedDomains() {
    global $zdbh;

    // Get domain names for all deleted vhosts that had DKIM keys
    $sql = $zdbh->prepare(
        "SELECT DISTINCT v.vh_name_vc
         FROM x_vhosts v
         WHERE v.vh_deleted_ts IS NOT NULL"
    );
    $sql->execute();
    $domains = $sql->fetchAll(PDO::FETCH_COLUMN);

    $rebuilt = false;
    foreach ($domains as $domain) {
        $keydir = "/usr/local/etc/opendkim/keys/$domain";
        if (is_dir($keydir)) {
            // Remove key files then directory
            foreach (glob("$keydir/*") ?: [] as $f) {
                unlink($f);
            }
            rmdir($keydir);
            $rebuilt = true;
        }
    }

    // Rebuild OpenDKIM config if any keys were removed
    if ($rebuilt) {
        RebuildOpenDKIMConfig();
    }
}

function RebuildOpenDKIMConfig() {
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
}

function TriggerDNSUpdate($id) {
    global $zdbh;
    $GetRecords = ctrl_options::GetSystemOption('dns_hasupdates');
    $records = explode(",", $GetRecords);
    foreach ($records as $record) {
        $RecordArray[] = $record;
    }
    if (!in_array($id, $RecordArray)) {
        $newlist = $GetRecords . "," . $id;
        $newlist = str_replace(",,", ",", $newlist);
        $sql = "UPDATE x_settings SET so_value_tx=:newlist WHERE so_name_vc='dns_hasupdates'";
        $sql = $zdbh->prepare($sql);
        $sql->bindParam(':newlist', $newlist);
        $sql->execute();
        return true;
    }
}

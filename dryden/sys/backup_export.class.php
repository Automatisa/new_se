<?php

/**
 * backup_export — Exporta la configuración del panel de UNA cuenta a JSON (fuente única
 * usada por el backup manual y por el programado). Cada consulta se filtra por la columna de
 * propiedad de la cuenta, de modo que el export queda estrictamente acotado a ese usuario.
 * Ver FIX-104. Incluye hashes propios (restore idéntico); excluye tokens API, bandwidth y logs.
 */
class sys_backup_export
{
    public static function run($zdbh, $userid)
    {
        $userid = (int)$userid;
        $collections = array(
            'vhosts'          => array('x_vhosts',          'vh_acc_fk'),
            'dns'             => array('x_dns',             'dn_acc_fk'),
            'dns_create'      => array('x_dns_create',      'dc_acc_fk'),
            'mailboxes'       => array('x_mailboxes',       'mb_acc_fk'),
            'aliases'         => array('x_aliases',         'al_acc_fk'),
            'forwarders'      => array('x_forwarders',      'fw_acc_fk'),
            'distlists'       => array('x_distlists',       'dl_acc_fk'),
            'ftpaccounts'     => array('x_ftpaccounts',     'ft_acc_fk'),
            'mysql_databases' => array('x_mysql_databases', 'my_acc_fk'),
            'mysql_users'     => array('x_mysql_users',     'mu_acc_fk'),
            'mysql_dbmap'     => array('x_mysql_dbmap',     'mm_acc_fk'),
            'cronjobs'        => array('x_cronjobs',        'ct_acc_fk'),
            'htaccess'        => array('x_htaccess',        'ht_acc_fk'),
        );
        $out = array(
            'bulwark_backup_format' => 1,
            'generated_ts'          => time(),
            'account_id'            => $userid,
        );
        try {
            $s = $zdbh->prepare("SELECT * FROM x_accounts WHERE ac_id_pk = :id");
            $s->execute(array(':id' => $userid));
            $out['account'] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) { $out['account'] = null; }
        try {
            $s = $zdbh->prepare("SELECT * FROM x_profiles WHERE ud_user_fk = :id");
            $s->execute(array(':id' => $userid));
            $out['profile'] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) { $out['profile'] = null; }
        foreach ($collections as $key => $def) {
            list($table, $fk) = $def;
            try {
                $s = $zdbh->prepare("SELECT * FROM `$table` WHERE `$fk` = :id");
                $s->execute(array(':id' => $userid));
                $out[$key] = $s->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $out[$key] = array(); }
        }

        // MIGRACIÓN: IPs del servidor de ORIGEN, para que el restore reescriba A/AAAA/SPF a la IP del
        // servidor DESTINO si difieren (si no, tras migrar el DNS apuntaría a la IP vieja). Se guardan
        // la primaria (server_ip/server_ip6) y las dedicadas de los vhosts de la cuenta.
        $out['server_ip']  = (string)ctrl_options::GetSystemOption('server_ip');
        $out['server_ip6'] = (string)ctrl_options::GetSystemOption('server_ip6');
        $src4 = array(); $src6 = array();
        if ($out['server_ip']  !== '') { $src4[$out['server_ip']]  = true; }
        if ($out['server_ip6'] !== '') { $src6[$out['server_ip6']] = true; }
        foreach ((array)$out['vhosts'] as $v) {
            if (!empty($v['vh_custom_ip_vc']))  { $src4[$v['vh_custom_ip_vc']]  = true; }
            if (!empty($v['vh_custom_ip6_vc'])) { $src6[$v['vh_custom_ip6_vc']] = true; }
        }
        $out['source_ips4'] = array_keys($src4);
        $out['source_ips6'] = array_keys($src6);

        return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

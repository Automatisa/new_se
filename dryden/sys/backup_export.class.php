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
            'sentora_backup_format' => 1,
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
        return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

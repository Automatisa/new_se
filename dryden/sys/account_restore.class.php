<?php

/**
 * account_restore — Motor de restauración de una cuenta desde un backup .zip de Bulwark.
 *
 * Fase 1 (restore desde fichero ya en el servidor). Restaura, de forma selectiva:
 *   - FICHEROS del home (web/, mail/, ...): vía el script privilegiado account_restore.sh
 *     (root), que extrae y reaplica el aislamiento de permisos.
 *   - BASES DE DATOS: extrae los .sql del zip e importa en la BD del usuario (creándola y
 *     registrándola si faltara), con el cliente mysql por --defaults-extra-file (sin exponer
 *     la contraseña en la línea de comandos).
 *   - CONFIG del panel (panel_config.json): reinserta de forma IDEMPOTENTE las filas que
 *     falten (dominios, DNS, correo, FTP, cron, htaccess), acotadas a la cuenta; el daemon
 *     del panel materializa después named.conf / pools / postfix desde la BD.
 *
 * Seguridad: todo se acota a $userid/$username (la copia ya es de una sola cuenta). No usa
 * exec(): las llamadas a binarios van por proc_open con argv (bypass_shell), sin shell.
 */
class sys_account_restore
{
    private static $log = array();

    private static function log($msg) { self::$log[] = $msg; }
    public  static function getLog()  { return self::$log; }

    /**
     * Ejecuta un binario con argv (sin shell). Devuelve [exitcode, stdout, stderr].
     * $stdinFile: ruta a un fichero cuyo contenido se envía por stdin (para mysql < dump).
     */
    private static function run(array $argv, $stdinFile = null)
    {
        $desc = array(
            0 => ($stdinFile !== null) ? array('file', $stdinFile, 'r') : array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $p = proc_open($argv, $desc, $pipes);
        if (!is_resource($p)) return array(127, '', 'proc_open failed');
        if ($stdinFile === null) fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($p);
        return array($code, $out, $err);
    }

    /** Fichero de credenciales temporal 0600 para el cliente mysql (host/user/pass). */
    private static function mysqlDefaultsFile()
    {
        global $host, $user, $pass;
        $cnf = tempnam(sys_get_temp_dir(), 'bulwark_rst') . '.cnf';
        file_put_contents($cnf, "[client]\nhost=\"" . $host . "\"\nuser=\"" . $user . "\"\npassword=\"" . $pass . "\"\n");
        @chmod($cnf, 0600);
        return $cnf;
    }

    /**
     * Valida el zip y devuelve un manifiesto: [ok, username, has_config, sql_files[], error].
     * $expectUser: nombre de usuario esperado (el dueño de la copia). El zip debe contener el
     * subárbol "<user>/" y, deseablemente, panel_config.json.
     */
    public static function inspect($zipPath, $expectUser)
    {
        $res = array('ok' => false, 'has_config' => false, 'sql_files' => array(), 'error' => '');
        if (!is_file($zipPath) || !is_readable($zipPath)) { $res['error'] = 'El archivo no existe o no es legible.'; return $res; }
        $za = new ZipArchive();
        if ($za->open($zipPath) !== true) { $res['error'] = 'No es un ZIP válido.'; return $res; }
        $prefix = $expectUser . '/';
        $hasHome = false;
        for ($i = 0; $i < $za->numFiles; $i++) {
            $name = $za->getNameIndex($i);
            if ($name === 'panel_config.json') { $res['has_config'] = true; }
            elseif (strpos($name, $prefix) === 0) { $hasHome = true; }
            elseif (substr($name, -4) === '.sql' && strpos($name, '/') === false) { $res['sql_files'][] = $name; }
        }
        $za->close();
        if (!$hasHome && !$res['has_config']) { $res['error'] = 'El ZIP no corresponde a una copia de esta cuenta.'; return $res; }
        $res['ok'] = true;
        return $res;
    }

    /** Restaura los ficheros del home vía el script privilegiado. */
    public static function restoreFiles($username, $zipPath)
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) { self::log('Usuario inválido.'); return false; }
        $reqFile = '/var/bulwark/run/account_restore_req';
        if (@file_put_contents($reqFile, $username . '|' . $zipPath . "\n") === false) {
            self::log('No se pudo escribir la orden de restore.'); return false;
        }
        @chmod($reqFile, 0660);
        list($code, , $err) = privilege::run('account_restore');
        if ($code !== 0) { self::log('Restore de ficheros falló (código ' . (int)$code . '): ' . $err); return false; }
        self::log('Ficheros del home restaurados.');
        return true;
    }

    /**
     * Importa las BD del zip. Para cada .sql, determina la BD destino cruzando con la lista
     * de BD de la config (my_name_vc que sea prefijo del nombre de fichero), crea la BD si
     * falta (y la registra en x_mysql_databases) e importa con el cliente mysql.
     */
    public static function restoreDatabases($userid, $zipPath, array $dbNames)
    {
        global $zdbh;
        // Ampliar la lista de nombres de BD con los de panel_config.json (por si la BD fue
        // borrada del panel y no aparece en $dbNames pasado por el llamador).
        $cfgJson = @file_get_contents('zip://' . $zipPath . '#panel_config.json');
        if ($cfgJson !== false) {
            $cfg = json_decode($cfgJson, true);
            if (!empty($cfg['mysql_databases'])) {
                foreach ($cfg['mysql_databases'] as $d) {
                    if (!empty($d['my_name_vc']) && !in_array($d['my_name_vc'], $dbNames, true)) $dbNames[] = $d['my_name_vc'];
                }
            }
        }
        $za = new ZipArchive();
        if ($za->open($zipPath) !== true) { self::log('No se pudo abrir el ZIP para las BD.'); return false; }
        $tmpDir = sys_get_temp_dir() . '/bulwark_rst_' . getmypid();
        @mkdir($tmpDir, 0700);
        $cnf = self::mysqlDefaultsFile();
        $done = 0;
        for ($i = 0; $i < $za->numFiles; $i++) {
            $name = $za->getNameIndex($i);
            if (substr($name, -4) !== '.sql' || strpos($name, '/') !== false) continue;
            // mapear fichero -> nombre de BD (el my_name_vc más largo que sea prefijo)
            $target = '';
            foreach ($dbNames as $db) {
                if (strpos($name, $db . '_') === 0 && strlen($db) > strlen($target)) $target = $db;
            }
            if ($target === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $target)) { self::log('Omitido (BD desconocida): ' . $name); continue; }
            // extraer el .sql a tmp
            $sqlPath = $tmpDir . '/' . basename($name);
            copy('zip://' . $zipPath . '#' . $name, $sqlPath);
            @chmod($sqlPath, 0600);
            // asegurar que la BD existe y está registrada
            self::ensureDatabase($userid, $target);
            // importar: mysql --defaults-extra-file=cnf DBNAME < dump.sql
            list($code, , $err) = self::run(
                array('/usr/local/bin/mysql', '--defaults-extra-file=' . $cnf, $target),
                $sqlPath
            );
            @unlink($sqlPath);
            if ($code !== 0) { self::log('Import de ' . $target . ' falló: ' . trim($err)); continue; }
            self::log('BD importada: ' . $target);
            $done++;
        }
        $za->close();
        @unlink($cnf);
        @rmdir($tmpDir);
        return $done;
    }

    /** Crea la BD en MySQL si no existe y la registra en x_mysql_databases (idempotente). */
    private static function ensureDatabase($userid, $dbname)
    {
        global $zdbh;
        // ¿registrada ya para esta cuenta?
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_mysql_databases WHERE my_name_vc=:n AND my_acc_fk=:u AND my_deleted_ts IS NULL");
        $q->execute(array(':n' => $dbname, ':u' => (int)$userid));
        $registered = (int)$q->fetchColumn() > 0;
        // crear la BD física si falta (DDL con las creds root del panel)
        try { $zdbh->exec("CREATE DATABASE IF NOT EXISTS `$dbname` DEFAULT CHARACTER SET 'utf8' COLLATE 'utf8_general_ci'"); }
        catch (Exception $e) { self::log('No se pudo crear la BD ' . $dbname . ': ' . $e->getMessage()); }
        if (!$registered) {
            $ins = $zdbh->prepare("INSERT INTO x_mysql_databases (my_acc_fk, my_name_vc, my_created_ts) VALUES (:u,:n,:t)");
            try { $ins->execute(array(':u' => (int)$userid, ':n' => $dbname, ':t' => time())); self::log('BD registrada en el panel: ' . $dbname); }
            catch (Exception $e) { /* la tabla puede tener más columnas NOT NULL; no crítico para el import */ }
        }
    }

    /**
     * Reinserta de forma idempotente las filas de config que falten, acotadas a la cuenta.
     * El daemon del panel materializa después los servicios desde estas tablas.
     * $tables: mapa key => [tabla, fk_cuenta, columna_clave_natural].
     */
    public static function restoreConfig($userid, $zipPath)
    {
        global $zdbh;
        $json = @file_get_contents('zip://' . $zipPath . '#panel_config.json');
        if ($json === false) { self::log('El ZIP no contiene panel_config.json.'); return false; }
        $cfg = json_decode($json, true);
        if (!is_array($cfg)) { self::log('panel_config.json ilegible.'); return false; }

        $userid = (int)$userid;
        $total  = 0;

        // ── MIGRACIÓN: remapeo de IPs. Si el servidor de ORIGEN (guardado en el backup) tiene IP
        //    distinta a la del DESTINO, se reescriben los registros A/AAAA/SPF cuyo objetivo sea una IP
        //    del origen -> la IP primaria del destino. Así, tras migrar, el DNS apunta al servidor nuevo
        //    (las IP dedicadas no se restauran; el usuario las reasigna después si quiere).
        $dst4 = (string)ctrl_options::GetSystemOption('server_ip');
        $dst6 = (string)ctrl_options::GetSystemOption('server_ip6');
        $src4 = array_values(array_filter(array_map('strval', (array)($cfg['source_ips4'] ?? array()))));
        $src6 = array_values(array_filter(array_map('strval', (array)($cfg['source_ips6'] ?? array()))));
        if (!$src4 && !empty($cfg['server_ip']))  { $src4 = array((string)$cfg['server_ip']); }   // backups antiguos
        if (!$src6 && !empty($cfg['server_ip6'])) { $src6 = array((string)$cfg['server_ip6']); }
        // ordenar por longitud desc para que ip4:X.X.X.200 se reemplace antes que X.X.X.2 (evita solapes)
        usort($src4, function ($a, $b) { return strlen($b) - strlen($a); });
        usort($src6, function ($a, $b) { return strlen($b) - strlen($a); });
        $nRemap = 0;

        // ── 1. VHOSTS (endurecido): validar el nombre de dominio, rechazar dominios de OTRA cuenta y
        //    (sub)dominios que cuelguen de otra cuenta (anti-robo), DERIVAR el directorio (no confiar en
        //    el del backup -> path traversal) y usar ALLOWLIST de columnas (se DESCARTAN vh_ssl_tx,
        //    vh_custom_tx, vh_custom_ip*, puertos: no se restauran del backup). Se mapea el vh_id_pk del
        //    JSON -> id real nuevo para remapear después las referencias del DNS.
        $vhostMap = array();
        if (!empty($cfg['vhosts']) && is_array($cfg['vhosts'])) {
            foreach ($cfg['vhosts'] as $row) {
                if (!is_array($row)) continue;
                $name  = strtolower(trim((string)($row['vh_name_vc'] ?? '')));
                $oldId = isset($row['vh_id_pk']) ? (int)$row['vh_id_pk'] : 0;
                if ($name === '' || !fs_director::IsValidDomainName($name)) { self::log("restore: vhost con nombre inválido '$name' omitido"); continue; }
                $ex = $zdbh->prepare("SELECT vh_id_pk, vh_acc_fk FROM x_vhosts WHERE vh_name_vc=:n AND vh_deleted_ts IS NULL LIMIT 1");
                $ex->execute(array(':n' => $name));
                $exist = $ex->fetch(PDO::FETCH_ASSOC);
                if ($exist) {
                    if ((int)$exist['vh_acc_fk'] === $userid) { if ($oldId) $vhostMap[$oldId] = (int)$exist['vh_id_pk']; }
                    else { self::log("restore: vhost '$name' pertenece a otra cuenta, omitido"); }
                    continue;
                }
                if (self::isUnderOtherAccount($name, $userid)) { self::log("restore: '$name' cuelga de un dominio de otra cuenta, omitido"); continue; }
                $dir = str_replace('.', '_', $name);
                if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,253}$/', $dir) || strpos($dir, '..') !== false) continue;
                $newId = self::insertReturnId('x_vhosts', array(
                    'vh_acc_fk'       => $userid,
                    'vh_name_vc'      => $name,
                    'vh_directory_vc' => $dir,
                    'vh_type_in'      => ((int)($row['vh_type_in'] ?? 1) === 2) ? 2 : 1,
                    'vh_enabled_in'   => 1,
                    'vh_created_ts'   => time(),
                ));
                if ($newId) { $total++; if ($oldId) $vhostMap[$oldId] = $newId; }
            }
        }

        // ── 2. DNS (endurecido): REMAPEAR dn_vhost_fk al id nuevo y RECHAZAR si no mapea (evita inyectar
        //    registros en la zona de OTRA cuenta). Validar el tipo. Forzar dn_acc_fk. Allowlist de columnas.
        if (!empty($cfg['dns']) && is_array($cfg['dns'])) {
            $allowed = array_filter(explode(' ', strtoupper((string)ctrl_options::GetSystemOption('allowed_types'))));
            $dnsCols = self::tableColumns('x_dns');
            foreach ($cfg['dns'] as $row) {
                if (!is_array($row)) continue;
                $oldVh = isset($row['dn_vhost_fk']) ? (int)$row['dn_vhost_fk'] : 0;
                if (!isset($vhostMap[$oldVh])) { self::log('restore: registro DNS de un vhost no restaurado, omitido'); continue; }
                $type = strtoupper(trim((string)($row['dn_type_vc'] ?? '')));
                if ($allowed && !in_array($type, $allowed, true)) { self::log("restore: tipo DNS '$type' no permitido, omitido"); continue; }
                // MIGRACIÓN: reescribir el objetivo si es una IP del origen -> IP del destino.
                $tgt = (string)($row['dn_target_vc'] ?? '');
                if ($type === 'A' && $dst4 !== '' && in_array($tgt, $src4, true) && $tgt !== $dst4) {
                    $tgt = $dst4; $nRemap++;
                } elseif ($type === 'AAAA' && $dst6 !== '' && in_array($tgt, $src6, true) && $tgt !== $dst6) {
                    $tgt = $dst6; $nRemap++;
                } elseif ($type === 'TXT' || $type === 'SPF') {
                    foreach ($src4 as $s) { if ($s !== '' && $dst4 !== '' && $s !== $dst4 && strpos($tgt, 'ip4:' . $s) !== false) { $tgt = str_replace('ip4:' . $s, 'ip4:' . $dst4, $tgt); $nRemap++; } }
                    foreach ($src6 as $s) { if ($s !== '' && $dst6 !== '' && $s !== $dst6 && strpos($tgt, 'ip6:' . $s) !== false) { $tgt = str_replace('ip6:' . $s, 'ip6:' . $dst6, $tgt); $nRemap++; } }
                }
                $ins = array(
                    'dn_acc_fk'        => $userid,
                    'dn_name_vc'       => substr((string)($row['dn_name_vc'] ?? ''), 0, 255),
                    'dn_vhost_fk'      => (int)$vhostMap[$oldVh],
                    'dn_type_vc'       => substr($type, 0, 50),
                    'dn_host_vc'       => substr((string)($row['dn_host_vc'] ?? '@'), 0, 100),
                    'dn_ttl_in'        => (int)($row['dn_ttl_in'] ?? 3600),
                    'dn_target_vc'     => substr($tgt, 0, 2000),
                    'dn_texttarget_tx' => isset($row['dn_texttarget_tx']) ? $row['dn_texttarget_tx'] : null,
                    'dn_priority_in'   => isset($row['dn_priority_in']) ? (int)$row['dn_priority_in'] : null,
                    'dn_weight_in'     => isset($row['dn_weight_in']) ? (int)$row['dn_weight_in'] : null,
                    'dn_port_in'       => isset($row['dn_port_in']) ? (int)$row['dn_port_in'] : null,
                    'dn_created_ts'    => time(),
                );
                if (self::rowExists('x_dns', $dnsCols, $ins)) continue;
                if (self::insertReturnId('x_dns', $ins)) $total++;
            }
        }

        // ── 3. Resto de tablas de la cuenta: forzar el FK de propiedad + DESCARTAR PK y columnas de RUTA
        //    peligrosas (se derivan/reconcilian; no se confían del backup). No incluye vhosts ni dns.
        $tables = array(
            'mailboxes'   => array('x_mailboxes',   'mb_acc_fk',  array()),
            'aliases'     => array('x_aliases',     'al_acc_fk',  array()),
            'forwarders'  => array('x_forwarders',  'fw_acc_fk',  array()),
            'distlists'   => array('x_distlists',   'dl_acc_fk',  array()),
            'ftpaccounts' => array('x_ftpaccounts', 'ft_acc_fk',  array('ft_directory_vc')), // ruta -> no confiar
            'cronjobs'    => array('x_cronjobs',    'ct_acc_fk',  array('ct_fullpath_vc')),   // ruta -> no confiar
            'htaccess'    => array('x_htaccess',    'ht_acc_fk',  array()),
        );
        foreach ($tables as $key => $def) {
            if (empty($cfg[$key]) || !is_array($cfg[$key])) continue;
            list($table, $fk, $drop) = $def;
            $cols = self::tableColumns($table);
            if (!$cols) continue;
            foreach ($cfg[$key] as $row) {
                if (!is_array($row)) continue;
                foreach ($drop as $d) { unset($row[$d]); }     // descartar columnas de ruta peligrosas
                $row[$fk] = $userid;                            // forzar propiedad a esta cuenta
                if (self::rowExists($table, $cols, $row)) continue;
                if (self::insertRow($table, $cols, $row)) $total++;
            }
        }

        self::log("Config reinsertada (endurecida): $total filas nuevas, $nRemap objetivo(s) DNS/SPF remapeados a la IP del destino. El daemon reconciliará los servicios.");
        return $total;
    }

    // Inserta una fila YA saneada (allowlist) y devuelve el id nuevo, o 0.
    private static function insertReturnId($table, array $use)
    {
        global $zdbh;
        if (!$use) return 0;
        $names = array_keys($use);
        $ph = array_map(function ($n) { return ':' . $n; }, $names);
        try {
            $st = $zdbh->prepare("INSERT INTO `$table` (`" . implode('`,`', $names) . "`) VALUES (" . implode(',', $ph) . ")");
            foreach ($use as $k => $v) $st->bindValue(':' . $k, $v);
            $st->execute();
            return (int)$zdbh->lastInsertId();
        } catch (Exception $e) { self::log("INSERT en $table omitido: " . $e->getMessage()); return 0; }
    }

    // ¿$name es (o cuelga de) un dominio de OTRA cuenta? -> bloquea el robo de (sub)dominios en la restauración.
    private static function isUnderOtherAccount($name, $userid)
    {
        global $zdbh;
        foreach ($zdbh->query("SELECT vh_name_vc, vh_acc_fk FROM x_vhosts WHERE vh_type_in=1 AND vh_deleted_ts IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = strtolower($r['vh_name_vc']);
            if (($name === $d || substr($name, -(strlen($d) + 1)) === '.' . $d) && (int)$r['vh_acc_fk'] !== (int)$userid) {
                return true;
            }
        }
        return false;
    }

    /**
     * ¿Existe ya una fila equivalente? Idempotencia genérica: compara TODAS las columnas de
     * identidad (las de la fila que existen en la tabla, salvo la PK y las de timestamp), con
     * <=> (igualdad null-safe de MySQL). Evita duplicar filas sin clave natural única (DNS, cron).
     */
    private static function rowExists($table, array $cols, array $row)
    {
        global $zdbh;
        $where = array(); $vals = array(); $k = 0;
        foreach ($row as $col => $v) {
            if (!in_array($col, $cols, true)) continue;
            if (preg_match('/_id_pk$/', $col)) continue;      // ignorar PK
            if (preg_match('/_ts$/', $col))    continue;      // ignorar timestamps (volátiles)
            $ph = ':v' . ($k++);
            $where[] = "`$col` <=> $ph";
            $vals[$ph] = $v;
        }
        if (!$where) return false;
        try {
            $st = $zdbh->prepare("SELECT COUNT(*) FROM `$table` WHERE " . implode(' AND ', $where));
            $st->execute($vals);
            return (int)$st->fetchColumn() > 0;
        } catch (Exception $e) { return false; }
    }

    private static function tableColumns($table)
    {
        global $zdbh;
        try {
            $c = $zdbh->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
            return $c ?: array();
        } catch (Exception $e) { return array(); }
    }

    /** INSERT de una fila filtrando a columnas reales de la tabla (omite la PK autoincrement). */
    private static function insertRow($table, array $cols, array $row)
    {
        global $zdbh;
        $use = array();
        foreach ($row as $k => $v) {
            if (!in_array($k, $cols, true)) continue;
            if (preg_match('/_id_pk$/', $k)) continue; // no forzar la PK
            $use[$k] = $v;
        }
        if (!$use) return false;
        $names = array_keys($use);
        $ph = array_map(function ($n) { return ':' . $n; }, $names);
        $sql = "INSERT INTO `$table` (`" . implode('`,`', $names) . "`) VALUES (" . implode(',', $ph) . ")";
        try {
            $st = $zdbh->prepare($sql);
            foreach ($use as $k => $v) $st->bindValue(':' . $k, $v);
            $st->execute();
            return true;
        } catch (Exception $e) { self::log("INSERT en $table omitido: " . $e->getMessage()); return false; }
    }
}

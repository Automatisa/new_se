<?php

/**
 * backup_runner — Ejecuta la copia de UNA cuenta (ficheros + BD + config del panel) y la guarda
 * en local y/o la envía al FTP remoto según $mode. Centraliza la lógica que antes vivía inline en
 * backup_admin/OnDaemonDay, para que la usen tanto el daemon como la cola (spool) del programador.
 *
 * No usa exec(): comprime y vuelca con proc_open (array + bypass_shell). El .zip temporal se crea
 * en una carpeta compartida, así que ANTES comprueba el guard de disco (sys_backup_retention).
 */
class sys_backup_runner
{
    /**
     * Hace la copia de una cuenta. $mode: 'local' | 'remote' | 'both'.
     * Devuelve array(bool $ok, string $mensaje).
     */
    public static function runAccount($userid, $mode = 'both')
    {
        global $zdbh;
        $userid = (int)$userid;
        if (!in_array($mode, array('local', 'remote', 'both'), true)) $mode = 'both';

        // Datos de la cuenta
        $acc = $zdbh->prepare("SELECT ac_user_vc FROM x_accounts WHERE ac_id_pk=:id AND ac_enabled_in=1 AND ac_deleted_ts IS NULL");
        $acc->execute(array(':id' => $userid));
        $row = $acc->fetch(PDO::FETCH_ASSOC);
        if (!$row) return array(false, 'Cuenta no encontrada o deshabilitada.');
        $username = $row['ac_user_vc'];
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_\-]{0,63}$/', $username)) {
            return array(false, 'Nombre de cuenta inválido.');
        }

        $homedir  = ctrl_options::GetSystemOption('hosted_dir') . $username;
        $temp_dir = ctrl_options::GetSystemOption('temp_dir');
        if ($temp_dir === '' || $temp_dir === false) $temp_dir = ctrl_options::GetSystemOption('bulwark_root') . 'etc/tmp/';
        $zip_exe  = ctrl_options::GetSystemOption('zip_exe');
        $backupname = $username . "_" . date("M-d-Y_His", time());
        $dbstamp    = date("dmy_Gi", time());
        $zipfull    = $temp_dir . $backupname . ".zip";

        // Credenciales de BD para mysqldump (sin exponerlas en la línea de comandos).
        $host = $user = $pass = '';
        @include(ctrl_options::GetSystemOption('bulwark_root') . 'cnf/db.php');

        // GUARD DE DISCO: no llenar el HD con muchos temporales simultáneos.
        if (class_exists('sys_backup_retention') && !sys_backup_retention::tempSpaceGuard($username, $temp_dir)) {
            return array(false, 'Espacio temporal insuficiente en el servidor (pospuesta).');
        }

        // 1) Ficheros del home (excluye backups/)
        $zip_argv = array($zip_exe, '-r9', $temp_dir . $backupname, $username . '/', '--exclude=' . $username . '/backups/*');
        self::runProc($zip_argv, dirname($homedir));
        @chmod($zipfull, 0600);
        if (!file_exists($zipfull)) return array(false, 'No se pudo crear el archivo de copia.');

        // 2) Bases de datos MySQL de la cuenta
        $q = $zdbh->prepare("SELECT my_name_vc FROM x_mysql_databases WHERE my_acc_fk=:uid AND my_deleted_ts IS NULL");
        $q->execute(array(':uid' => $userid));
        while ($db = $q->fetch(PDO::FETCH_ASSOC)) {
            $db_name = $db['my_name_vc'];
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_\-]{0,63}$/', $db_name)) continue;
            $sql_path = $temp_dir . $db_name . "_" . $dbstamp . ".sql";
            $cnf_path = tempnam(sys_get_temp_dir(), 'bulwark_bu') . '.cnf';
            @file_put_contents($cnf_path, "[mysqldump]\nhost={$host}\nuser={$user}\npassword={$pass}\n");
            @chmod($cnf_path, 0600);
            self::runProc(array(ctrl_options::GetSystemOption('mysqldump_exe'), '--defaults-extra-file=' . $cnf_path, '--no-create-db', $db_name), null, $sql_path);
            @unlink($cnf_path);
            self::runProc(array($zip_exe, $temp_dir . $backupname, $db_name . "_" . $dbstamp . ".sql"), $temp_dir);
            @unlink($sql_path);
        }

        // 3) Config del panel (panel_config.json) al zip
        if (class_exists('sys_backup_export')) {
            $cfgfile = $temp_dir . "panel_config.json";
            if (@file_put_contents($cfgfile, sys_backup_export::run($zdbh, $userid)) !== false) {
                @chmod($cfgfile, 0600);
                self::runProc(array($zip_exe, '-j', $temp_dir . $backupname, $cfgfile), $temp_dir);
                @unlink($cfgfile);
            }
        }

        $msgs = array();

        // 4) Envío remoto (mode remote/both) + retención remota
        if (($mode === 'remote' || $mode === 'both') && class_exists('sys_backup_remote')) {
            $dest = sys_backup_remote::getDestination($userid);
            if ($dest && (int)$dest['bd_enabled_in'] === 1 && !empty($dest['bd_host_vc'])) {
                $t0 = time();
                list($rok, $rmsg) = sys_backup_remote::uploadWithRetry($dest, $zipfull);
                sys_backup_remote::recordStatus($userid, ($rok ? 'OK: ' : 'ERROR: ') . $rmsg);
                if ($rok && class_exists('sys_backup_retention')) {
                    $maxR = sys_backup_retention::getMaxRemote($userid);
                    if ($maxR > 0) {
                        $pr = sys_backup_remote::enforceRemoteRetention($dest, $maxR, $username);
                        if ($pr > 0) $rmsg .= " (retención: $pr borradas)";
                    }
                }
                if (class_exists('sys_backup_log')) {
                    sys_backup_log::record($userid, 'remote',
                        $dest['bd_host_vc'] . ':' . (int)$dest['bd_port_in'] . ' (' . $dest['bd_type_vc'] . ')',
                        $backupname . '.zip', @filesize($zipfull), 0, $rok, $rmsg, time() - $t0);
                }
                $msgs[] = 'remoto ' . ($rok ? 'OK' : 'ERROR');
            } elseif ($mode === 'remote') {
                @unlink($zipfull);
                return array(false, 'No hay destino remoto (FTP) activo.');
            }
        }

        // 5) Guardado local (mode local/both) con retención de paquete + cuota de disco
        if (($mode === 'local' || $mode === 'both')) {
            $backupdir = $homedir . "/backups/";
            if (!is_dir($backupdir)) { @mkdir($backupdir, 0700, true); }
            @chmod($backupdir, 0700);
            $zipbytes = @filesize($zipfull);
            $maxLocal = class_exists('sys_backup_retention') ? sys_backup_retention::getMaxLocal($userid) : 0;
            if ($maxLocal > 0) sys_backup_retention::enforceLocal($username, $userid, max(0, $maxLocal - 1));
            if (class_exists('sys_backup_retention') && sys_backup_retention::wouldExceedQuota($username, $userid, $zipbytes)) {
                $msgs[] = 'local OMITIDA (cuota de disco)';
            } else {
                @copy($zipfull, $backupdir . $backupname . ".zip");
                fs_director::SetFileSystemPermissions($backupdir . $backupname . ".zip", 0600);
                if ($maxLocal > 0) sys_backup_retention::enforceLocal($username, $userid, $maxLocal);
                if (class_exists('sys_backup_log')) {
                    sys_backup_log::record($userid, 'local', 'disco (home)', $backupname . '.zip',
                        $zipbytes, 0, true, 'Copia automática guardada en ' . $username . '/backups/', 0);
                }
                $msgs[] = 'local OK';
            }
        }

        @unlink($zipfull); // limpiar temporal en cualquier modo
        return array(true, 'Copia de ' . $username . ': ' . implode(', ', $msgs));
    }

    /** Lanza un proceso (array argv, sin shell). $cwd opcional; $stdoutFile redirige stdout a fichero. */
    private static function runProc(array $argv, $cwd = null, $stdoutFile = null)
    {
        $desc = array(0 => array('pipe', 'r'), 2 => array('pipe', 'w'));
        $desc[1] = $stdoutFile ? array('file', $stdoutFile, 'w') : array('pipe', 'w');
        $p = proc_open($argv, $desc, $pipes, $cwd, null, array('bypass_shell' => true));
        if (is_resource($p)) {
            fclose($pipes[0]);
            if (isset($pipes[1])) fclose($pipes[1]);
            if (isset($pipes[2])) fclose($pipes[2]);
            proc_close($p);
        }
    }
}

<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 *
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
class module_controller extends ctrl_module
{

    static $deleteok;
    static $backupok;
    static $filenotexist;

    static function ListBackUps($userid)
    {
        $currentuser = ctrl_users::GetUserDetail($userid);
        $userid = $currentuser['userid'];
        $username = $currentuser['username'];
        $res = array();
        $dirFiles = array();
        $backupdir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/backups/";
        if ($handle = opendir($backupdir)) {
            while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != ".." && stristr($file, "_") && substr($file, -4) == ".zip") {
                    $dirFiles[] = $file;
                }
            }
        }
        closedir($handle);
        if (!fs_director::CheckForEmptyValue($dirFiles)) {
            sort($dirFiles);
            foreach ($dirFiles as $file) {
                $filesize = fs_director::ShowHumanFileSize(filesize($backupdir . $file));
                $filedate = date("F d Y H:i:s", filemtime($backupdir . $file));
                array_push($res, array('backupfile' => substr($file, 0, -4),
                    'created' => $filedate,
                    'filesize' => $filesize));
            }
        }
        self::array_sort_by_column($res, 'created');
        return $res;
    }

    static function array_sort_by_column(&$arr, $col, $dir = SORT_ASC)
    {
        $sort_col = array();
        foreach ($arr as $key => $row) {
            $sort_col[$key] = $row[$col];
        }
        array_multisort($sort_col, $dir, $arr);
    }

    static function CheckHasData($userid)
    {
        $currentuser = ctrl_users::GetUserDetail($userid);
        $datafolder = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/";
        $dirFiles = array();
        if ($handle = opendir($datafolder)) {
            while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != "..") {
                    $dirFiles[] = $file;
                }
            }
        }
        closedir($handle);
        if (!fs_director::CheckForEmptyValue($dirFiles)) {
            return true;
        }
        return false;
    }

    static function ExecuteBackup($userid, $download = 0)
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail($userid);
        runtime_hook::Execute('OnBeforeCreateBackup');

        runtime_hook::Execute('OnAfterCreateBackup');
    }

    static function ExecuteDeleteBackup($username, $file)
    {
        runtime_hook::Execute('OnBeforeDeleteBackup');
        $backup_file_to_delete = ctrl_options::GetSystemOption('hosted_dir') . $username . "/backups/" . $file . ".zip";
        unlink($backup_file_to_delete);
        runtime_hook::Execute('OnAfterDeleteBackup');
    }

    static function ExecuteCreateBackupDirectory($username)
    {
        $backupdir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/backups/";
        if (!is_dir($backupdir)) {
            fs_director::CreateDirectory($backupdir);
        }
    }

    static function CheckPurgeDate()
    {
        if (strtolower(ctrl_options::GetSystemOption('purge_bu')) == "true") {
            return ctrl_options::GetSystemOption('purge_date');
        } else {
            return false;
        }
    }

    static function doBackup()
    {
        runtime_csfr::Protect();
        global $zdbh;
        global $controller;
        $userid = $controller->GetControllerRequest('FORM', 'inBackUp');
        $download = $controller->GetControllerRequest('FORM', 'inDownLoad');
        self::ExecuteBackup($userid, $download);
        self::$backupok = true;
    }

    static function doDeleteBackup()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $userid = $currentuser['userid'];
        $username = $currentuser['username'];
        $files = self::ListBackUps($userid);
        $deleted = false;
        foreach ($files as $file) {
            if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inDelete_' . $file['backupfile'] . '')) ||
                    !fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inDelete_' . $file['backupfile'] . '_x')) ||
                    !fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inDelete_' . $file['backupfile'] . '_y'))) {
                self::ExecuteDeleteBackup($username, $file['backupfile']);
                $deleted = true;
            }
        }
        // PRG: evita reenvío del POST al refrescar o dar "atrás"
        if (!headers_sent()) {
            $suffix = $deleted ? '&deleted=1' : '';
            header('Location: ./?module=backupmgr' . $suffix);
            exit();
        }
    }

    /**
     * Restaura la cuenta del usuario actual desde una de SUS copias (ficheros + BD + config).
     * La copia se valida contra la lista real de backups del usuario (evita traversal/ajenos).
     */
    static function doRestoreBackup()
    {
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $userid   = (int)$currentuser['userid'];
        $username = $currentuser['username'];
        $name     = $controller->GetControllerRequest('FORM', 'inRestore');

        // El nombre debe coincidir EXACTAMENTE con una copia listada del usuario.
        $valid = false;
        foreach (self::ListBackUps($userid) as $f) {
            if ($f['backupfile'] === $name) { $valid = true; break; }
        }
        if (!$valid) {
            $_SESSION['bk_restore_flash'] = array('err', 'La copia indicada no existe.');
        } else {
            $zip = ctrl_options::GetSystemOption('hosted_dir') . $username . '/backups/' . $name . '.zip';

            // Nombres de BD actuales del usuario (para mapear los .sql del zip).
            $dbNames = array();
            $q = $zdbh->prepare("SELECT my_name_vc FROM x_mysql_databases WHERE my_acc_fk=:u AND my_deleted_ts IS NULL");
            $q->execute(array(':u' => $userid));
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $d) $dbNames[] = $d;

            // Orden: ficheros -> bases de datos -> config del panel.
            $filesOk = sys_account_restore::restoreFiles($username, $zip);
            $dbN     = sys_account_restore::restoreDatabases($userid, $zip, $dbNames);
            $cfgN    = sys_account_restore::restoreConfig($userid, $zip);

            $msg = 'Restauración: ficheros ' . ($filesOk ? 'OK' : 'ERROR')
                 . ' · BD importadas ' . (int)$dbN
                 . ' · config reinsertada ' . (int)$cfgN . ' filas.';
            $_SESSION['bk_restore_flash'] = array($filesOk ? 'ok' : 'err', $msg);
        }
        if (!headers_sent()) { header('Location: ./?module=backupmgr'); exit(); }
    }

    // ---------------------------------------------------------------------------
    //  Fase 2 — Destino remoto de copias (FTPS) por cuenta
    // ---------------------------------------------------------------------------

    /** Guarda/actualiza el destino remoto de la cuenta actual. La contraseña solo se
     *  re-cifra si se envía una nueva (en blanco = conservar la existente). */
    static function doSaveDestination()
    {
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $cu = ctrl_users::GetUserDetail();
        $uid = (int)$cu['userid'];

        $host = self::cleanHost($controller->GetControllerRequest('FORM', 'inDestHost'));
        $port = (int)$controller->GetControllerRequest('FORM', 'inDestPort'); if ($port <= 0) $port = 21;
        $user = trim((string)$controller->GetControllerRequest('FORM', 'inDestUser'));
        $pass = (string)$controller->GetControllerRequest('FORM', 'inDestPass');
        $path = trim((string)$controller->GetControllerRequest('FORM', 'inDestPath')); if ($path === '') $path = '/';
        list($type, $verify, $usePin) = self::securityToDb((string)$controller->GetControllerRequest('FORM', 'inDestSecurity'));
        $enabled = $controller->GetControllerRequest('FORM', 'inDestEnabled') ? 1 : 0;

        // No permitir ACTIVAR el envío remoto sin host: dejaría el destino "activo" pero inútil,
        // y el botón "Copia remota" seguiría deshabilitado (estado confuso). Se avisa.
        $warn = '';
        if ($enabled && $host === '') {
            $enabled = 0;
            $warn = ' AVISO: NO se activó el envío remoto porque falta el HOST del servidor FTP. Rellénalo y vuelve a guardar.';
        }

        $exists = $zdbh->prepare("SELECT bd_id_pk, bd_pass_tx, bd_certsha_vc FROM x_backup_destinations WHERE bd_acc_fk=:u LIMIT 1");
        $exists->execute(array(':u' => $uid));
        $row = $exists->fetch(PDO::FETCH_ASSOC);
        $encPass = ($pass !== '') ? sys_backup_remote::encrypt($pass) : ($row ? $row['bd_pass_tx'] : '');

        // TOFU: si el modo es "fijar certificado", capturar la clave pública del servidor ahora.
        $certsha = null; $pinMsg = '';
        if ($usePin && $host !== '') {
            $plainPass = ($pass !== '') ? $pass : ($row ? sys_backup_remote::decrypt($row['bd_pass_tx']) : '');
            $cap = sys_backup_remote::capturePin(array('bd_host_vc'=>$host,'bd_port_in'=>$port,'bd_user_vc'=>$user,'password'=>$plainPass,'bd_path_vc'=>$path));
            if (!empty($cap['pin'])) {
                $certsha = $cap['pin'];
                $pinMsg = ' Certificado fijado (huella SHA-256): ' . $cap['fp'];
            } else {
                // No se pudo capturar: conservar el pin anterior (si lo había) y avisar.
                $certsha = $row ? ($row['bd_certsha_vc'] ?? null) : null;
                $pinMsg = ' (AVISO: no se pudo fijar el certificado: ' . ($cap['error'] ?? 'error') . ')';
            }
        }

        if ($row) {
            $u = $zdbh->prepare("UPDATE x_backup_destinations SET bd_type_vc=:t,bd_host_vc=:h,bd_port_in=:p,bd_user_vc=:us,bd_pass_tx=:pw,bd_path_vc=:pa,bd_tlsverify_in=:v,bd_certsha_vc=:cs,bd_enabled_in=:e WHERE bd_acc_fk=:u");
            $u->execute(array(':t'=>$type,':h'=>$host,':p'=>$port,':us'=>$user,':pw'=>$encPass,':pa'=>$path,':v'=>$verify,':cs'=>$certsha,':e'=>$enabled,':u'=>$uid));
        } else {
            $i = $zdbh->prepare("INSERT INTO x_backup_destinations (bd_acc_fk,bd_type_vc,bd_host_vc,bd_port_in,bd_user_vc,bd_pass_tx,bd_path_vc,bd_tlsverify_in,bd_certsha_vc,bd_enabled_in,bd_created_ts) VALUES (:u,:t,:h,:p,:us,:pw,:pa,:v,:cs,:e,:ts)");
            $i->execute(array(':u'=>$uid,':t'=>$type,':h'=>$host,':p'=>$port,':us'=>$user,':pw'=>$encPass,':pa'=>$path,':v'=>$verify,':cs'=>$certsha,':e'=>$enabled,':ts'=>time()));
        }
        $_SESSION['bk_restore_flash'] = array($warn ? 'err' : 'ok', 'Destino remoto guardado.' . $pinMsg . $warn);
        if (!headers_sent()) { header('Location: ./?module=backupmgr&tab=conn'); exit(); }
    }

    /** Prueba la conexión/subida al destino remoto de la cuenta actual. */
    static function doTestDestination()
    {
        global $controller;
        runtime_csfr::Protect();
        $cu  = ctrl_users::GetUserDetail();
        $uid = (int)$cu['userid'];

        $secRaw = (string)$controller->GetControllerRequest('FORM', 'inDestSecurity');
        list($secType, $secVerify, $usePin) = self::securityToDb($secRaw);

        // Recordar lo introducido para repoblar el formulario tras la prueba (la contraseña no).
        $_SESSION['bk_dest_form'] = array(
            'bd_host_vc'      => self::cleanHost($controller->GetControllerRequest('FORM', 'inDestHost')),
            'bd_port_in'      => (int)$controller->GetControllerRequest('FORM', 'inDestPort'),
            'bd_user_vc'      => trim((string)$controller->GetControllerRequest('FORM', 'inDestUser')),
            'bd_path_vc'      => trim((string)$controller->GetControllerRequest('FORM', 'inDestPath')),
            'bd_type_vc'      => $secType,
            'bd_tlsverify_in' => $secVerify,
            '_sec'            => $secRaw, // preservar la opción de seguridad elegida en el desplegable
            'bd_enabled_in'   => $controller->GetControllerRequest('FORM', 'inDestEnabled') ? 1 : 0,
        );

        // Probar los valores del FORMULARIO (lo que el usuario acaba de escribir), sin exigir
        // guardar antes. Si el campo host va vacío, se prueba el destino guardado.
        $host = self::cleanHost($controller->GetControllerRequest('FORM', 'inDestHost'));
        if ($host === '') {
            $dest = sys_backup_remote::getDestination($uid);
            if (!$dest || empty($dest['bd_host_vc'])) {
                $_SESSION['bk_restore_flash'] = array('err', 'Rellena al menos el host (o guarda el destino) antes de probar.');
                if (!headers_sent()) { header('Location: ./?module=backupmgr'); exit(); }
                return;
            }
        } else {
            $pass = (string)$controller->GetControllerRequest('FORM', 'inDestPass');
            if ($pass === '') { // campo en blanco: usar la contraseña guardada, si la hay
                $saved = sys_backup_remote::getDestination($uid);
                $pass  = ($saved && isset($saved['password'])) ? $saved['password'] : '';
            }
            $dest = array(
                'bd_type_vc'      => $secType,
                'bd_host_vc'      => $host,
                'bd_port_in'      => ((int)$controller->GetControllerRequest('FORM', 'inDestPort')) ?: 21,
                'bd_user_vc'      => trim((string)$controller->GetControllerRequest('FORM', 'inDestUser')),
                'password'        => $pass,
                'bd_path_vc'      => (trim((string)$controller->GetControllerRequest('FORM', 'inDestPath')) ?: '/'),
                'bd_tlsverify_in' => $secVerify,
            );
        }

        if ($usePin && ($dest['bd_type_vc'] ?? 'ftps') !== 'ftp') {
            // Modo "fijar certificado": la prueba captura el cert del servidor y muestra su huella.
            $cap = sys_backup_remote::capturePin($dest);
            if (!empty($cap['pin'])) {
                $msg = 'certificado del servidor (SHA-256): ' . $cap['fp'] . '. Se fijará al Guardar.';
                $ok = true;
            } else {
                $msg = ($cap['error'] ?? 'no se pudo leer el certificado');
                $ok = false;
            }
        } else {
            list($ok, $msg) = sys_backup_remote::testConnection($dest);
        }
        sys_backup_remote::recordStatus($uid, ($ok ? 'OK: ' : 'ERROR: ') . $msg);
        sys_backup_log::record(
            $uid, 'test',
            ($dest['bd_host_vc'] ?? '') . ':' . (int)($dest['bd_port_in'] ?? 21) . ' (' . ($dest['bd_type_vc'] ?? '') . ')',
            '', 0, 0, $ok, $msg, 0
        );
        $_SESSION['bk_restore_flash'] = array($ok ? 'ok' : 'err',
            ($ok ? '✓ Conexión correcta — ' : '✗ Fallo de conexión — ') . $msg);
        if (!headers_sent()) { header('Location: ./?module=backupmgr&tab=conn'); exit(); }
    }

    /** HTML del panel de configuración del destino remoto (placeholder <@ RemoteDestPanel @>). */
    /** Sanea el host tecleado: quita TODO espacio (incluido interno/unicode), un scheme
     *  pegado al copiar (ftp://, ftps://, sftp://), barras/puertos finales y lo pasa a minúsculas. */
    static function cleanHost($h)
    {
        $h = (string)$h;
        // eliminar cualquier carácter de espacio (espacios, tabs, NBSP, saltos de línea)
        $h = preg_replace('/\s+/u', '', $h);
        $h = preg_replace('#^[a-z]+://#i', '', $h); // quitar scheme si se pegó una URL
        $h = rtrim($h, "/");                          // barra final
        if (strpos($h, '/') !== false) $h = substr($h, 0, strpos($h, '/')); // ruta pegada
        if (($c = strpos($h, ':')) !== false) $h = substr($h, 0, $c);       // :puerto pegado
        return strtolower($h);
    }

    /** Mapea el desplegable "Seguridad" -> array(bd_type_vc, bd_tlsverify_in, usePin). */
    static function securityToDb($sec)
    {
        switch ($sec) {
            case 'ftp_plain':   return array('ftp',  0, false);
            case 'ftps_strict': return array('ftps', 2, false); // CA + hostname
            case 'ftps_pin':
            default:            return array('ftps', 0, true);  // fijar certificado (TOFU)
        }
    }

    /** Inverso: (tipo, pin) guardados -> valor del desplegable. */
    static function dbToSecurity($type, $pin)
    {
        if ($type === 'ftp') return 'ftp_plain';
        return ($pin !== '' && $pin !== null) ? 'ftps_pin' : 'ftps_strict';
    }

    static function getRemoteDestPanel()
    {
        global $zdbh;
        $cu = ctrl_users::GetUserDetail();
        $q = $zdbh->prepare("SELECT * FROM x_backup_destinations WHERE bd_acc_fk=:u LIMIT 1");
        $q->execute(array(':u' => (int)$cu['userid']));
        $d = $q->fetch(PDO::FETCH_ASSOC) ?: array();
        // Tras "Probar conexión" (que no guarda), repoblar el formulario con lo que el usuario
        // escribió para que no se pierda (la contraseña no se repuebla, por seguridad).
        if (!empty($_SESSION['bk_dest_form'])) {
            $d = array_merge($d, $_SESSION['bk_dest_form']);
            unset($_SESSION['bk_dest_form']);
        }
        $h = function ($k, $def = '') use ($d) { return htmlspecialchars(isset($d[$k]) && $d[$k] !== null ? $d[$k] : $def, ENT_QUOTES); };
        $chk = function ($k, $def) use ($d) { $v = isset($d[$k]) ? (int)$d[$k] : $def; return $v ? 'checked' : ''; };
        $csrf = self::getCSFR_Tag();
        $status = !empty($d['bd_laststatus_vc'])
            ? '<p><small>Última prueba/envío: <b>' . htmlspecialchars($d['bd_laststatus_vc'], ENT_QUOTES) . '</b>'
              . (!empty($d['bd_last_ts']) ? ' (' . date('d/m/Y H:i', (int)$d['bd_last_ts']) . ')' : '') . '</small></p>'
            : '';

        $html  = '<div class="zform_wrapper"><h2>Destino remoto de copias (FTPS)</h2>';
        $html .= '<p><small>Si lo activas, cada copia se subirá cifrada por FTPS a este servidor. La contraseña se guarda cifrada (AES-256).</small></p>' . $status;
        $html .= '<form method="post" action="./?module=backupmgr&action=SaveDestination">' . $csrf;
        $html .= '<table class="table">';
        $html .= '<tr><th style="width:200px">Servidor (host)</th><td><input class="form-control" type="text" name="inDestHost" value="' . $h('bd_host_vc') . '" placeholder="ftp.midestino.com"></td></tr>';
        $html .= '<tr><th>Puerto</th><td><input class="form-control" type="number" name="inDestPort" value="' . $h('bd_port_in', '21') . '" style="max-width:120px"></td></tr>';
        $html .= '<tr><th>Usuario</th><td><input class="form-control" type="text" name="inDestUser" value="' . $h('bd_user_vc') . '"></td></tr>';
        $html .= '<tr><th>Contraseña</th><td><input class="form-control" type="password" name="inDestPass" value="" placeholder="' . (!empty($d['bd_pass_tx']) ? '•••••• (dejar en blanco para conservar)' : '') . '" autocomplete="new-password"></td></tr>';
        $html .= '<tr><th>Ruta remota</th><td><input class="form-control" type="text" name="inDestPath" value="' . $h('bd_path_vc', '/') . '" placeholder="/backups/"></td></tr>';
        // Desplegable de seguridad: la opción elegida tras "Probar" (_sec) o la derivada de BD.
        $curSec = !empty($d['_sec']) ? $d['_sec'] : self::dbToSecurity($d['bd_type_vc'] ?? 'ftps', $d['bd_certsha_vc'] ?? '');
        $sel = function ($v) use ($curSec) { return $curSec === $v ? ' selected' : ''; };
        $html .= '<tr valign="top"><th>Seguridad</th><td><select name="inDestSecurity">'
               . '<option value="ftps_pin"' . $sel('ftps_pin') . '>FTPS — fijar el certificado del servidor (recomendado): confía este servidor y avisa si el certificado cambia</option>'
               . '<option value="ftps_strict"' . $sel('ftps_strict') . '>FTPS estricto — el certificado debe ser de una CA de confianza y coincidir con el nombre</option>'
               . '<option value="ftp_plain"' . $sel('ftp_plain') . '>FTP sin cifrar (texto plano) — credenciales y datos en claro; solo red interna</option>'
               . '</select>'
               . '<br><small>"Fijar el certificado" acepta cualquier certificado del servidor (incluido autofirmado o de otro nombre) la primera vez y a partir de ahí exige que sea el mismo — detecta suplantaciones. Se captura al guardar.</small>';
        if (!empty($d['bd_certsha_vc'])) {
            $html .= '<br><small>Certificado fijado actualmente. Para re-fijarlo (si cambió a propósito), vuelve a elegir "fijar el certificado" y guarda.</small>';
        }
        $html .= '</td></tr>';
        $html .= '<tr><th>Activar envío remoto</th><td><input type="checkbox" name="inDestEnabled" value="1" ' . $chk('bd_enabled_in', 0) . '></td></tr>';
        $html .= '</table>';
        $html .= '<button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Guardar destino</button> ';
        $html .= '<button class="btn btn-secondary" type="submit" formaction="./?module=backupmgr&action=TestDestination"><i class="bi bi-plug me-1"></i>Probar conexión</button>';
        $html .= '</form></div>';
        return $html;
    }

    /** Borra TODO el registro de copias de la cuenta actual (botón "Borrar registro"). */
    static function doClearBackupLog()
    {
        runtime_csfr::Protect();
        $cu = ctrl_users::GetUserDetail();
        $n  = sys_backup_log::clearForUser((int)$cu['userid']);
        $_SESSION['bk_restore_flash'] = array('ok', 'Registro de copias borrado (' . (int)$n . ' entradas).');
        if (!headers_sent()) { header('Location: ./?module=backupmgr&tab=log'); exit(); }
    }

    /** Guarda la programación de copias automáticas de la cuenta actual. */
    static function doSaveSchedule()
    {
        global $zdbh, $controller;
        runtime_csfr::Protect();
        $cu  = ctrl_users::GetUserDetail();
        $uid = (int)$cu['userid'];

        $enabled = $controller->GetControllerRequest('FORM', 'inSchedEnabled') ? 1 : 0;
        $freq = (string)$controller->GetControllerRequest('FORM', 'inSchedFreq');
        if (!in_array($freq, array('daily', 'weekly', 'monthly'), true)) $freq = 'daily';
        $hour = max(0, min(23, (int)$controller->GetControllerRequest('FORM', 'inSchedHour')));
        $dow  = max(0, min(6,  (int)$controller->GetControllerRequest('FORM', 'inSchedDow')));
        $dom  = max(1, min(28, (int)$controller->GetControllerRequest('FORM', 'inSchedDom')));
        $dest = (string)$controller->GetControllerRequest('FORM', 'inSchedDest');
        if (!in_array($dest, array('local', 'remote', 'both'), true)) $dest = 'local';
        $next = sys_backup_scheduler::computeNextRun($freq, $hour, $dow, $dom);

        $sql = $zdbh->prepare(
            "INSERT INTO x_backup_schedule (bs_acc_fk,bs_enabled_in,bs_freq_vc,bs_hour_in,bs_dow_in,bs_dom_in,bs_dest_vc,bs_next_run_ts)
             VALUES (:a,:e,:f,:h,:w,:d,:t,:n)
             ON DUPLICATE KEY UPDATE bs_enabled_in=:e2,bs_freq_vc=:f2,bs_hour_in=:h2,bs_dow_in=:w2,bs_dom_in=:d2,bs_dest_vc=:t2,bs_next_run_ts=:n2");
        $sql->execute(array(
            ':a' => $uid, ':e' => $enabled, ':f' => $freq, ':h' => $hour, ':w' => $dow, ':d' => $dom, ':t' => $dest, ':n' => $next,
            ':e2' => $enabled, ':f2' => $freq, ':h2' => $hour, ':w2' => $dow, ':d2' => $dom, ':t2' => $dest, ':n2' => $next,
        ));
        $_SESSION['bk_restore_flash'] = array('ok', $enabled
            ? 'Copia automática guardada. Próxima ejecución: ' . date('d/m/Y H:i', $next) . '.'
            : 'Copia automática desactivada.');
        if (!headers_sent()) { header('Location: ./?module=backupmgr&tab=auto'); exit(); }
    }

    /** Panel de configuración de copias automáticas (placeholder <@ SchedulePanel @>). */
    static function getSchedulePanel()
    {
        global $zdbh;
        $cu  = ctrl_users::GetUserDetail();
        $uid = (int)$cu['userid'];
        $q = $zdbh->prepare("SELECT * FROM x_backup_schedule WHERE bs_acc_fk=:a LIMIT 1");
        $q->execute(array(':a' => $uid));
        $s = $q->fetch(PDO::FETCH_ASSOC) ?: array();
        $g = function ($k, $d = '') use ($s) { return isset($s[$k]) && $s[$k] !== null ? $s[$k] : $d; };
        $freq = $g('bs_freq_vc', 'daily');
        $dest = $g('bs_dest_vc', 'local');
        $enabled = (int)$g('bs_enabled_in', 0);
        $hour = (int)$g('bs_hour_in', 3);
        $selF = function ($v) use ($freq) { return $freq === $v ? ' selected' : ''; };
        $selD = function ($v) use ($dest) { return $dest === $v ? ' selected' : ''; };
        $hasRemote = self::GetHasRemoteDest();
        $csrf = self::getCSFR_Tag();

        $html  = '<div class="zform_wrapper"><h2>Copias automáticas (programadas)</h2>';
        $html .= '<p><small>El servidor hará la copia por ti a la hora elegida. Para no sobrecargar, '
               . 'las copias de todos los clientes se procesan por bloques (unas pocas cada pocos minutos).</small></p>';
        if (!empty($s['bs_last_run_ts'])) {
            $html .= '<p><small>Última ejecución automática: <b>' . date('d/m/Y H:i', (int)$s['bs_last_run_ts']) . '</b></small></p>';
        }
        if ($enabled && !empty($s['bs_next_run_ts'])) {
            $html .= '<p><small>Próxima ejecución programada: <b>' . date('d/m/Y H:i', (int)$s['bs_next_run_ts']) . '</b></small></p>';
        }
        $html .= '<form method="post" action="./?module=backupmgr&action=SaveSchedule">' . $csrf . '<table class="table">';
        $html .= '<tr><th style="width:220px">Activar copia automática</th><td><input type="checkbox" name="inSchedEnabled" value="1" ' . ($enabled ? 'checked' : '') . '></td></tr>';
        $html .= '<tr><th>Frecuencia</th><td><select name="inSchedFreq">'
               . '<option value="daily"' . $selF('daily') . '>Diaria</option>'
               . '<option value="weekly"' . $selF('weekly') . '>Semanal</option>'
               . '<option value="monthly"' . $selF('monthly') . '>Mensual</option></select></td></tr>';
        $html .= '<tr><th>Hora (0-23)</th><td><input class="form-control" type="number" min="0" max="23" name="inSchedHour" value="' . $hour . '" style="max-width:100px"></td></tr>';
        $html .= '<tr><th>Día de la semana <small>(solo semanal)</small></th><td><select name="inSchedDow">';
        $dias = array('Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado');
        foreach ($dias as $i => $nm) { $html .= '<option value="' . $i . '"' . ((int)$g('bs_dow_in', 1) === $i ? ' selected' : '') . '>' . $nm . '</option>'; }
        $html .= '</select></td></tr>';
        $html .= '<tr><th>Día del mes <small>(solo mensual, 1-28)</small></th><td><input class="form-control" type="number" min="1" max="28" name="inSchedDom" value="' . (int)$g('bs_dom_in', 1) . '" style="max-width:100px"></td></tr>';
        $html .= '<tr><th>Destino</th><td><select name="inSchedDest">'
               . '<option value="local"' . $selD('local') . '>Local (en el servidor)</option>';
        if ($hasRemote) {
            $html .= '<option value="remote"' . $selD('remote') . '>Remoto (FTP)</option>'
                   . '<option value="both"' . $selD('both') . '>Ambos</option>';
        }
        $html .= '</select>' . ($hasRemote ? '' : '<br><small>Configura un destino remoto en la pestaña "Conexión remota" para poder elegir FTP.</small>') . '</td></tr>';
        $html .= '</table><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Guardar programación</button></form></div>';
        return $html;
    }

    /** Panel del registro persistente de copias (placeholder <@ BackupLogPanel @>). */
    static function getBackupLogPanel()
    {
        $cu  = ctrl_users::GetUserDetail();
        $uid = (int)$cu['userid'];
        $perPage = 20;
        $total = sys_backup_log::countForUser($uid);

        $html = '<div class="zgrid_wrapper"><h2>Registro de copias de seguridad</h2>';
        if ($total <= 0) {
            $html .= '<p><small>Aún no hay registros. Aquí aparecerán las copias locales, los envíos remotos y las pruebas de conexión (se conservan las últimas ' . sys_backup_log::MAX_PER_ACCOUNT . ').</small></p></div>';
            return $html;
        }
        $pages = (int)ceil($total / $perPage);
        $page  = isset($_GET['bkpage']) ? (int)$_GET['bkpage'] : 1;
        $page  = max(1, min($page, $pages));
        $rows  = sys_backup_log::listForUser($uid, $page, $perPage);

        $html .= '<p><small>Se conservan las últimas ' . sys_backup_log::MAX_PER_ACCOUNT . ' operaciones (' . $total . ' registradas).</small></p>';
        $html .= '<table class="table table-striped"><tr>'
               . '<th>Fecha</th><th>Tipo</th><th>Destino</th><th>Fichero</th>'
               . '<th>Tamaño</th><th>Int.</th><th>Resultado</th><th>Detalle</th></tr>';
        $tipoTxt = array('local' => 'Local', 'remote' => 'Remoto', 'test' => 'Prueba');
        foreach ($rows as $r) {
            $ok   = ($r['bl_result_vc'] === 'ok');
            $badge = $ok ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">ERROR</span>';
            $tipo = isset($tipoTxt[$r['bl_action_vc']]) ? $tipoTxt[$r['bl_action_vc']] : htmlspecialchars((string)$r['bl_action_vc'], ENT_QUOTES);
            $dur  = (int)$r['bl_duration_in'] > 0 ? ' <small>(' . (int)$r['bl_duration_in'] . 's)</small>' : '';
            $html .= '<tr>'
                   . '<td nowrap>' . date('d/m/Y H:i', (int)$r['bl_ts_in']) . '</td>'
                   . '<td>' . $tipo . '</td>'
                   . '<td>' . htmlspecialchars((string)$r['bl_dest_vc'], ENT_QUOTES) . '</td>'
                   . '<td>' . htmlspecialchars((string)$r['bl_file_vc'], ENT_QUOTES) . '</td>'
                   . '<td nowrap>' . sys_backup_log::humanSize($r['bl_size_in']) . '</td>'
                   . '<td>' . (int)$r['bl_attempts_in'] . '</td>'
                   . '<td nowrap>' . $badge . $dur . '</td>'
                   . '<td><small>' . htmlspecialchars((string)$r['bl_message_tx'], ENT_QUOTES) . '</small></td>'
                   . '</tr>';
        }
        $html .= '</table>';

        // Paginador
        if ($pages > 1) {
            $html .= '<nav><ul class="pagination pagination-sm">';
            $link = function ($p, $label, $disabled = false, $active = false) {
                $cls = 'page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '');
                return '<li class="' . $cls . '"><a class="page-link" href="./?module=backupmgr&tab=log&bkpage=' . (int)$p . '">' . $label . '</a></li>';
            };
            $html .= $link($page - 1, '&laquo;', $page <= 1);
            for ($p = 1; $p <= $pages; $p++) $html .= $link($p, (string)$p, false, $p === $page);
            $html .= $link($page + 1, '&raquo;', $page >= $pages);
            $html .= '</ul></nav>';
        }

        // Botón "Borrar registro" (con confirmación y CSRF)
        $html .= '<form method="post" action="./?module=backupmgr&action=ClearBackupLog" '
               . 'onsubmit="return confirm(\'¿Borrar TODO el registro de copias? Esta acción no se puede deshacer.\');">'
               . self::getCSFR_Tag()
               . '<button class="btn btn-danger" type="submit"><i class="bi bi-trash me-1"></i>Borrar registro</button>'
               . '</form>';
        $html .= '</div>';
        return $html;
    }

    static function GetHasData()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::CheckHasData($currentuser['userid']);
    }

    /** ¿Hay un destino remoto (FTP) activo? Controla el botón "Copia remota". */
    static function GetHasRemoteDest()
    {
        global $zdbh;
        $cu = ctrl_users::GetUserDetail();
        $q = $zdbh->prepare("SELECT COUNT(*) FROM x_backup_destinations WHERE bd_acc_fk=:u AND bd_enabled_in=1 AND bd_host_vc IS NOT NULL AND bd_host_vc<>''");
        $q->execute(array(':u' => (int)$cu['userid']));
        return ((int)$q->fetchColumn() > 0);
    }

    static function GetBackUpList()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ListBackUps($currentuser['userid']);
    }

    static function GetFileLocation()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $filelocation = $currentuser['username'] . "/backups/";
        return $filelocation;
    }

    static function getUserID()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $userid = $currentuser['userid'];
        return $userid;
    }

    static function GetDiskAllowed()
    {
        global $controller;
        if (strtolower(ctrl_options::GetSystemOption('disk_bu')) == "true")
            return true;
        return false;
    }

    static function GetPurgeDate()
    {
        return self::CheckPurgeDate();
    }

    static function getCreateBackupDirectory()
    {
        $currentuser = ctrl_users::GetUserDetail();
        if (self::ExecuteCreateBackupDirectory($currentuser['username']))
            return true;
        return false;
    }

    static function GetBUOption($name)
    {
        global $zdbh;
        // $result = $zdbh->query("SELECT bus_value_tx FROM x_backup_settings WHERE bus_name_vc = '$name'")->Fetch();
        $sql = $zdbh->prepare("SELECT bus_value_tx FROM x_backup_settings WHERE bus_name_vc = :name");
        $sql->bindParam(':name', $name);
        $sql->execute();
        $result = $sql->fetch();
        if ($result) {
            return $result['bus_value_tx'];
        } else {
            return false;
        }
    }

    static function getResult()
    {
        // Mensaje flash de la restauración (PRG desde doRestoreBackup).
        if (!empty($_SESSION['bk_restore_flash'])) {
            list($type, $msg) = $_SESSION['bk_restore_flash'];
            unset($_SESSION['bk_restore_flash']);
            return ui_sysmessage::shout($msg, $type === 'ok' ? 'zannounceok' : 'zannounceerror');
        }
        if (!fs_director::CheckForEmptyValue(self::$filenotexist)) {
            return ui_sysmessage::shout("There was an error saving your backup!", "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$deleteok)) {
            return ui_sysmessage::shout("Backup deleted successfully!", "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$backupok)) {
            return ui_sysmessage::shout("Backup completed successfully!", "zannounceok");
        }
        return;
    }

}

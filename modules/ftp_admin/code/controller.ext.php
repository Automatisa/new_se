<?php

/**
 * @package ftp_admin
 * @version 200
 */
class module_controller extends ctrl_module
{
    static $ok;

    // ----------------------------------------------------------------
    // Settings section (original)
    // ----------------------------------------------------------------

    static function getConfig()
    {
        global $zdbh;
        $sql = "SELECT * FROM x_settings WHERE so_module_vc=:m AND so_usereditable_en='true' ORDER BY so_cleanname_vc";
        $numrows = $zdbh->prepare($sql);
        $module  = ui_module::GetModuleName();
        $numrows->bindParam(':m', $module);
        $numrows->execute();
        if ($numrows->fetchColumn() == 0) return false;

        $sql2 = $zdbh->prepare($sql);
        $sql2->bindParam(':m', $module);
        $sql2->execute();
        $res = array();
        while ($row = $sql2->fetch()) {
            if (ctrl_options::CheckForPredefinedOptions($row['so_defvalues_tx'])) {
                $fieldhtml = ctrl_options::OuputSettingMenuField($row['so_name_vc'], $row['so_defvalues_tx'], $row['so_value_tx']);
            } else {
                $fieldhtml = ctrl_options::OutputSettingTextArea($row['so_name_vc'], $row['so_value_tx']);
            }
            $res[] = array(
                'cleanname'   => ui_language::translate($row['so_cleanname_vc']),
                'name'        => $row['so_name_vc'],
                'description' => ui_language::translate($row['so_desc_tx']),
                'value'       => $row['so_value_tx'],
                'fieldhtml'   => $fieldhtml,
            );
        }
        return $res ?: false;
    }

    static function doUpdateConfig()
    {
        global $zdbh, $controller;
        runtime_csfr::Protect();

        $validators = array(
            'ftp_php'          => function($v) {
                return in_array($v, array('proftpd.php', 'proftpd-mysql.php'), true);
            },
            'ftp_db'           => function($v) {
                return (bool) preg_match('/^[a-zA-Z0-9_]{1,64}$/', $v);
            },
            'ftp_service'      => function($v) {
                return (bool) preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $v);
            },
            'ftp_service_root' => function($v) {
                return (bool) preg_match('/^\/[a-zA-Z0-9\/_.-]+\/$/', $v);
            },
            'ftp_config_file'  => function($v) {
                $real = realpath($v);
                return $real !== false
                    && strpos($real . '/', '/usr/local/etc/bulwark/') === 0;
            },
        );

        $sql    = "SELECT * FROM x_settings WHERE so_module_vc=:m AND so_usereditable_en='true'";
        $module = ui_module::GetModuleName();
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':m', $module);
        $numrows->execute();
        if ($numrows->fetchColumn() == 0) return;

        $sql2 = $zdbh->prepare($sql);
        $sql2->bindParam(':m', $module);
        $sql2->execute();
        while ($row = $sql2->fetch()) {
            $fieldName = $row['so_name_vc'];
            $value = $controller->GetControllerRequest('FORM', $fieldName);
            if (fs_director::CheckForEmptyValue($value)) continue;
            if (isset($validators[$fieldName]) && !$validators[$fieldName]($value)) continue;
            $upd = $zdbh->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc=:n");
            $upd->bindParam(':v', $value);
            $upd->bindParam(':n', $fieldName);
            $upd->execute();
        }
        self::$ok = true;
    }

    // ----------------------------------------------------------------
    // Status & info
    // ----------------------------------------------------------------

    static function getFtpRunning()
    {
        $fp = @fsockopen('127.0.0.1', 21, $errno, $errstr, 2);
        if ($fp !== false) { fclose($fp); return true; }
        return false;
    }

    static function getFtpStatusBadge()
    {
        if (self::getFtpRunning()) {
            return '<span class="badge bg-success" style="font-size:13px;">&#x25CF; Activo</span>';
        }
        return '<span class="badge bg-danger" style="font-size:13px;">&#x25CF; Inactivo</span>';
    }

    static function getFtpAccountCount()
    {
        global $zdbh;
        $db = ctrl_options::GetSystemOption('ftp_db');
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $db)) return '—';
        try {
            $row = $zdbh->query("SELECT COUNT(*) FROM `{$db}`.`ftpuser`")->fetch(PDO::FETCH_NUM);
            return (int)$row[0];
        } catch (Exception $e) {
            return '—';
        }
    }

    static function getFtpPort()
    {
        $cfg = ctrl_options::GetSystemOption('ftp_config_file');
        if (!$cfg || !file_exists($cfg)) return '—';
        $content = file_get_contents($cfg);
        if (preg_match('/^Port\s+(\d+)/m', $content, $m)) {
            return $m[1];
        }
        return '—';
    }

    static function getFtpTlsEnabled()
    {
        $cfg = ctrl_options::GetSystemOption('ftp_config_file');
        if (!$cfg || !file_exists($cfg)) return '—';
        $content = file_get_contents($cfg);
        if (preg_match('/TLSEngine\s+(on|off)/i', $content, $m)) {
            return strtolower($m[1]) === 'on'
                ? '<span class="badge bg-success">TLS activado</span>'
                : '<span class="badge bg-warning">TLS desactivado</span>';
        }
        return '—';
    }

    private static function getConfigContent()
    {
        $cfg = ctrl_options::GetSystemOption('ftp_config_file');
        if (!$cfg || !file_exists($cfg)) return null;
        return file_get_contents($cfg);
    }

    private static function getCertPath()
    {
        $content = self::getConfigContent();
        if (!$content) return null;
        if (preg_match('/^TLSRSACertificateFile\s+(\S+)/m', $content, $m)) return trim($m[1]);
        return null;
    }

    private static function getKeyPath()
    {
        $content = self::getConfigContent();
        if (!$content) return null;
        if (preg_match('/^TLSRSACertificateKeyFile\s+(\S+)/m', $content, $m)) return trim($m[1]);
        return null;
    }

    static function getFtpCertPath()
    {
        return self::getCertPath() ?? '—';
    }

    static function getFtpKeyPath()
    {
        return self::getKeyPath() ?? '—';
    }

    static function getFtpSencryptCerts()
    {
        $hostedDir = ctrl_options::GetSystemOption('hosted_dir');
        if (!$hostedDir) return false;
        $hostedDir = rtrim($hostedDir, '/') . '/';

        $results = array();

        // Let's Encrypt certs via sencrypt
        $lePath = $hostedDir . '*/ssl/sencrypt/letsencrypt/*/cert.pem';
        $leCerts = glob($lePath) ?: array();
        foreach ($leCerts as $certFile) {
            $keyFile = dirname($certFile) . '/privkey.pem';
            if (!file_exists($keyFile)) continue;
            $domain = basename(dirname($certFile));
            $raw = @file_get_contents($certFile);
            $parsed = $raw ? @openssl_x509_parse($raw) : false;
            $expiry = ($parsed && isset($parsed['validTo_time_t']))
                ? date('d/m/Y', $parsed['validTo_time_t'])
                : '?';
            $daysLeft = ($parsed && isset($parsed['validTo_time_t']))
                ? (int)(($parsed['validTo_time_t'] - time()) / 86400)
                : null;
            $results[] = array(
                'type'      => 'letsencrypt',
                'domain'    => $domain,
                'certfile'  => $certFile,
                'keyfile'   => $keyFile,
                'expiry'    => $expiry,
                'daysleft'  => $daysLeft,
                'label'     => 'Let\'s Encrypt — ' . htmlspecialchars($domain) . ' (caduca: ' . $expiry . ')',
            );
        }

        // SSL comercial / terceros via sencrypt
        $tpPath = $hostedDir . '*/ssl/sencrypt/third_party/*/cert.pem';
        $tpCerts = glob($tpPath) ?: array();
        foreach ($tpCerts as $certFile) {
            $keyFile = dirname($certFile) . '/privkey.pem';
            if (!file_exists($keyFile)) continue;
            $domain = basename(dirname($certFile));
            $raw = @file_get_contents($certFile);
            $parsed = $raw ? @openssl_x509_parse($raw) : false;
            $expiry = ($parsed && isset($parsed['validTo_time_t']))
                ? date('d/m/Y', $parsed['validTo_time_t'])
                : '?';
            $results[] = array(
                'type'      => 'third_party',
                'domain'    => $domain,
                'certfile'  => $certFile,
                'keyfile'   => $keyFile,
                'expiry'    => $expiry,
                'daysleft'  => null,
                'label'     => 'SSL comercial — ' . htmlspecialchars($domain) . ' (caduca: ' . $expiry . ')',
            );
        }

        return count($results) > 0 ? $results : false;
    }

    static function doUploadCert()
    {
        runtime_csfr::Protect();

        $certTmp = isset($_FILES['inCertFile']['tmp_name']) ? $_FILES['inCertFile']['tmp_name'] : '';
        $keyTmp  = isset($_FILES['inKeyFile']['tmp_name'])  ? $_FILES['inKeyFile']['tmp_name']  : '';

        if (empty($certTmp) || !is_uploaded_file($certTmp) ||
            empty($keyTmp)  || !is_uploaded_file($keyTmp)) {
            $_SESSION['ftp_admin_msg'] = 'upload_missing';
            header('Location: ./?module=ftp_admin');
            die();
        }

        // Tamaño máximo 200 KB por fichero
        if (filesize($certTmp) > 204800 || filesize($keyTmp) > 204800) {
            $_SESSION['ftp_admin_msg'] = 'upload_toobig';
            header('Location: ./?module=ftp_admin');
            die();
        }

        // Validación básica de formato PEM en PHP antes de llamar al script root
        $certContent = file_get_contents($certTmp);
        $keyContent  = file_get_contents($keyTmp);
        if (strpos($certContent, '-----BEGIN CERTIFICATE-----') === false &&
            strpos($certContent, '-----BEGIN X509 CERTIFICATE-----') === false) {
            $_SESSION['ftp_admin_msg'] = 'upload_bad_cert';
            header('Location: ./?module=ftp_admin');
            die();
        }
        if (strpos($keyContent, 'PRIVATE KEY-----') === false) {
            $_SESSION['ftp_admin_msg'] = 'upload_bad_key';
            header('Location: ./?module=ftp_admin');
            die();
        }

        // Escribir en /var/bulwark/run (no /tmp, world-writable) con 0600: el material de
        // clave privada no debe quedar legible por otros ni expuesto a symlink/TOCTOU en /tmp.
        file_put_contents('/var/bulwark/run/bulwark_ftp_cert_upload', $certContent);
        @chmod('/var/bulwark/run/bulwark_ftp_cert_upload', 0600);
        file_put_contents('/var/bulwark/run/bulwark_ftp_key_upload',  $keyContent);
        @chmod('/var/bulwark/run/bulwark_ftp_key_upload', 0600);

        self::runAndRedirect('proftpd_cert_upload', 'upload_ok', 'upload_err');
    }

    static function doUpdateCertPaths()
    {
        runtime_csfr::Protect();
        global $controller;
        $certPath = trim($controller->GetControllerRequest('FORM', 'inCertPath'));
        $keyPath  = trim($controller->GetControllerRequest('FORM', 'inKeyPath'));

        if (empty($certPath) || empty($keyPath)) {
            $_SESSION['ftp_admin_msg'] = 'cert_paths_empty';
            header('Location: ./?module=ftp_admin');
            die();
        }

        // Validar que las rutas son absolutas y no contienen secuencias peligrosas
        if (!preg_match('/^\/[^\x00]+$/', $certPath) || !preg_match('/^\/[^\x00]+$/', $keyPath)
            || strpos($certPath, '..') !== false || strpos($keyPath, '..') !== false) {
            $_SESSION['ftp_admin_msg'] = 'cert_paths_invalid';
            header('Location: ./?module=ftp_admin');
            die();
        }

        // Escribir rutas en ficheros temporales para el script privilegiado
        file_put_contents('/tmp/bulwark_ftp_cert', $certPath);
        file_put_contents('/tmp/bulwark_ftp_key', $keyPath);

        self::runAndRedirect('proftpd_cert_paths_update', 'cert_paths_ok', 'cert_paths_err');
    }

    static function getFtpCertInfo()
    {
        $path = self::getCertPath();
        if (!$path || !file_exists($path)) return '—';
        $raw = @file_get_contents($path);
        if (!$raw) return '—';
        $parsed = @openssl_x509_parse($raw);
        if (!$parsed) return '—';
        $cn      = $parsed['subject']['CN'] ?? '—';
        $expiry  = isset($parsed['validTo_time_t'])
                 ? date('d/m/Y', $parsed['validTo_time_t'])
                 : '—';
        $daysLeft = isset($parsed['validTo_time_t'])
                  ? (int)(($parsed['validTo_time_t'] - time()) / 86400)
                  : null;
        $badge = '';
        if ($daysLeft !== null) {
            if ($daysLeft < 30) {
                $badge = ' <span class="badge bg-danger">Caduca en ' . $daysLeft . ' días</span>';
            } elseif ($daysLeft < 90) {
                $badge = ' <span class="badge bg-warning">Caduca en ' . $daysLeft . ' días</span>';
            } else {
                $badge = ' <span class="badge bg-success">Válido ' . $daysLeft . ' días más</span>';
            }
        }
        return 'CN=' . htmlspecialchars($cn) . ' &nbsp;|&nbsp; Caduca: ' . $expiry . $badge;
    }

    static function doGenerateCert()
    {
        runtime_csfr::Protect();
        self::runAndRedirect('proftpd_cert_generate', 'cert_ok', 'cert_err');
    }

    static function getFtpConfigContent()
    {
        $cfg = ctrl_options::GetSystemOption('ftp_config_file');
        if (!$cfg || !file_exists($cfg)) return false;
        return htmlspecialchars(file_get_contents($cfg), ENT_QUOTES, 'UTF-8');
    }

    static function isReloadOk()    { return !fs_director::CheckForEmptyValue(self::$reloadOk); }
    static function isReloadError() { return !fs_director::CheckForEmptyValue(self::$reloadError); }
    static function isRestartOk()   { return !fs_director::CheckForEmptyValue(self::$restartOk); }
    static function isRestartError(){ return !fs_director::CheckForEmptyValue(self::$restartError); }

    // ----------------------------------------------------------------
    // Actions
    // ----------------------------------------------------------------

    private static function runAndRedirect($action, $msgOk, $msgErr)
    {
        // Enviar el redirect al navegador inmediatamente (antes del comando lento)
        // fastcgi_finish_request() cierra la conexión FastCGI y PHP sigue en background.
        $_SESSION['ftp_admin_msg'] = $msgOk;
        session_write_close();
        header('Location: ./?module=ftp_admin');
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        // A partir de aquí el navegador ya tiene el redirect; ejecutamos el comando
        ignore_user_abort(true);
        try {
            list($code) = privilege::run($action);
            if ($code !== 0) {
                // Reabrir sesión para corregir el mensaje
                session_start();
                $_SESSION['ftp_admin_msg'] = $msgErr;
                session_write_close();
            }
        } catch (Exception $e) {
            session_start();
            $_SESSION['ftp_admin_msg'] = $msgErr;
            session_write_close();
        }
        exit();
    }

    static function doSaveConfig()
    {
        runtime_csfr::Protect();
        global $controller;
        $content = $controller->GetControllerRequest('FORM', 'inFtpConfig');
        if (empty(trim($content))) {
            $_SESSION['ftp_admin_msg'] = 'config_empty';
            header('Location: ./?module=ftp_admin');
            die();
        }
        file_put_contents('/tmp/bulwark_proftpd_new.conf', $content);
        self::runAndRedirect('proftpd_config_update', 'config_saved', 'config_err');
    }

    static function doReloadFtp()
    {
        runtime_csfr::Protect();
        self::runAndRedirect('proftpd_reload', 'reload_ok', 'reload_err');
    }

    static function doRestartFtp()
    {
        runtime_csfr::Protect();
        self::runAndRedirect('proftpd_restart', 'restart_ok', 'restart_err');
    }

    // ----------------------------------------------------------------
    // Result messages
    // ----------------------------------------------------------------

    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your settings have been saved successfully!"));
        }
        if (isset($_SESSION['ftp_admin_msg'])) {
            $msg = $_SESSION['ftp_admin_msg'];
            unset($_SESSION['ftp_admin_msg']);
            $messages = array(
                'reload_ok'    => array("ProFTPD ha recargado la configuración correctamente.", "zannounceok"),
                'reload_err'   => array("Error al recargar ProFTPD. Comprueba los logs del sistema.", "zannounceerror"),
                'restart_ok'   => array("ProFTPD se ha reiniciado correctamente.", "zannounceok"),
                'restart_err'  => array("Error al reiniciar ProFTPD. Comprueba los logs del sistema.", "zannounceerror"),
                'config_saved' => array("Configuración guardada correctamente. Recarga o reinicia el servicio para aplicarla.", "zannounceok"),
                'config_err'   => array("Error al guardar: la sintaxis del config no es válida. No se han aplicado cambios.", "zannounceerror"),
                'config_empty' => array("El contenido del config no puede estar vacío.", "zannounceerror"),
                'cert_ok'           => array("Certificado TLS regenerado correctamente. Reinicia ProFTPD para aplicarlo.", "zannounceok"),
                'cert_err'          => array("Error al generar el certificado. Comprueba los logs del sistema.", "zannounceerror"),
                'cert_paths_ok'     => array("Rutas del certificado TLS actualizadas. Reinicia ProFTPD para aplicarlas.", "zannounceok"),
                'cert_paths_err'    => array("Error al actualizar las rutas: verifica que el certificado y la clave son válidos y coinciden.", "zannounceerror"),
                'cert_paths_empty'  => array("Debes especificar la ruta del certificado y de la clave privada.", "zannounceerror"),
                'cert_paths_invalid'=> array("Las rutas especificadas no son válidas.", "zannounceerror"),
                'upload_ok'         => array("Certificado SSL comercial instalado correctamente. Reinicia ProFTPD para aplicarlo.", "zannounceok"),
                'upload_err'        => array("Error al instalar el certificado: verifica que el cert y la clave coinciden y son ficheros PEM válidos.", "zannounceerror"),
                'upload_missing'    => array("Debes seleccionar el fichero de certificado y el de clave privada.", "zannounceerror"),
                'upload_toobig'     => array("Los ficheros no deben superar 200 KB.", "zannounceerror"),
                'upload_bad_cert'   => array("El fichero seleccionado como certificado no tiene formato PEM válido.", "zannounceerror"),
                'upload_bad_key'    => array("El fichero seleccionado como clave privada no tiene formato PEM válido.", "zannounceerror"),
            );
            if (isset($messages[$msg])) {
                return ui_sysmessage::shout($messages[$msg][0], $messages[$msg][1]);
            }
        }
        return;
    }
}

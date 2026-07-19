<?php

/**
 * backup_remote — Destino remoto de copias (Fase 2). Cifrado de credenciales en reposo y
 * subida del .zip por FTPS (curl, en streaming) al destino configurado por cuenta.
 *
 * Seguridad:
 *   - La contraseña del servidor remoto se guarda CIFRADA (AES-256-GCM) en bd_pass_tx; la
 *     clave maestra vive fuera de la BD en cnf/backup.key (0600), generada al vuelo si falta.
 *   - La subida usa curl con FTPS (CURLUSESSL_ALL). La verificación del certificado es
 *     configurable (bd_tlsverify_in) para admitir servidores internos con cert autofirmado.
 *   - No usa exec(): la transferencia es php-curl nativo, en streaming desde el fichero.
 */
class sys_backup_remote
{
    const KEYFILE = '/usr/local/bulwark/cnf/backup.key';

    /** Devuelve la clave maestra binaria (32 bytes), creándola si no existe. */
    private static function masterKey()
    {
        if (is_readable(self::KEYFILE)) {
            $raw = trim((string)file_get_contents(self::KEYFILE));
            $key = @hex2bin($raw);
            if ($key !== false && strlen($key) === 32) return $key;
        }
        // generar y persistir (0600)
        $key = random_bytes(32);
        @file_put_contents(self::KEYFILE, bin2hex($key));
        @chmod(self::KEYFILE, 0600);
        return $key;
    }

    /** Cifra una cadena → base64(iv|tag|ciphertext). '' si la entrada es vacía. */
    public static function encrypt($plain)
    {
        if ($plain === '' || $plain === null) return '';
        $key = self::masterKey();
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) return '';
        return base64_encode($iv . $tag . $ct);
    }

    /** Descifra base64(iv|tag|ciphertext) → texto plano, o '' si falla. */
    public static function decrypt($blob)
    {
        if ($blob === '' || $blob === null) return '';
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 28) return '';
        $key = self::masterKey();
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? '' : $pt;
    }

    /** Carga el destino habilitado de una cuenta como array (con la pass ya descifrada), o null. */
    public static function getDestination($userid)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT * FROM x_backup_destinations WHERE bd_acc_fk=:u LIMIT 1");
        $q->execute(array(':u' => (int)$userid));
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['password'] = self::decrypt($row['bd_pass_tx']);
        return $row;
    }

    /**
     * Sube un fichero al destino por FTP/FTPS con curl (streaming). Devuelve [ok, mensaje].
     * $dest: array con bd_type_vc, bd_host_vc, bd_port_in, bd_user_vc, password, bd_path_vc,
     * bd_tlsverify_in.
     */
    public static function upload($dest, $localFile, $connectTimeout = 20, $maxTime = 0)
    {
        if (!is_file($localFile)) return array(false, 'El fichero local no existe.');
        $host = trim((string)$dest['bd_host_vc']);
        $port = (int)$dest['bd_port_in'] ?: 21;
        $user = (string)$dest['bd_user_vc'];
        $pass = (string)$dest['password'];
        $path = '/' . ltrim((string)$dest['bd_path_vc'], '/');
        if ($path === '' || substr($path, -1) !== '/') $path .= '/';
        if ($host === '') return array(false, 'Host remoto vacío.');

        $remote = 'ftp://' . $host . ':' . $port . $path . rawurlencode(basename($localFile));
        $fp = fopen($localFile, 'rb');
        if ($fp === false) return array(false, 'No se pudo abrir el fichero local.');

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL           => $remote,
            CURLOPT_UPLOAD        => true,
            CURLOPT_INFILE        => $fp,
            CURLOPT_INFILESIZE    => filesize($localFile),
            CURLOPT_USERPWD       => $user . ':' . $pass,
            CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
            CURLOPT_CONNECTTIMEOUT => (int)$connectTimeout,
            CURLOPT_TIMEOUT        => (int)$maxTime,   // 0 = sin límite (subida real de ficheros grandes)
            CURLOPT_NOSIGNAL       => true,
        ));
        if (($dest['bd_type_vc'] ?? 'ftps') !== 'ftp') {
            curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
            self::applyTls($ch, $dest);
        }
        $ok  = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_errno($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok) return array(true, 'Subida completada a ' . $host . $path);
        return array(false, self::curlFtpError($code, $err, $host, $port));
    }

    /**
     * Traduce un código de error de curl (FTP/FTPS) a un mensaje claro y accionable en
     * castellano. Añade entre paréntesis el detalle técnico original para diagnóstico.
     */
    public static function curlFtpError($code, $rawErr, $host = '', $port = 0)
    {
        $where = $host !== '' ? ($host . ($port ? ':' . $port : '')) : 'el servidor';
        switch ((int)$code) {
            case 6:  // CURLE_COULDNT_RESOLVE_HOST
                $m = 'No se pudo resolver el nombre «' . $host . '». Revisa que esté bien escrito o el DNS del servidor.'; break;
            case 7:  // CURLE_COULDNT_CONNECT
                $m = 'No se pudo conectar a ' . $where . '. El puerto puede estar cerrado o bloqueado por un firewall.'; break;
            case 28: // CURLE_OPERATION_TIMEDOUT
                $m = 'Tiempo de espera agotado con ' . $where . '. El servidor no responde (firewall, puerto de datos pasivo bloqueado o red lenta).'; break;
            case 64: // CURLE_USE_SSL_FAILED
                $m = 'El servidor FTP no admite cifrado TLS (rechazó AUTH TLS). Selecciona «FTP sin cifrar» o activa TLS (ssl_enable) en el servidor de destino.'; break;
            case 35: // CURLE_SSL_CONNECT_ERROR
                $m = 'Falló el handshake TLS con ' . $where . ' (versión de TLS o cifrados incompatibles).'; break;
            case 51: // CURLE_PEER_FAILED_VERIFICATION
                $m = 'El nombre del certificado no coincide con «' . $host . '». Usa el modo «fijar el certificado» si el FTP presenta el cert de otro dominio.'; break;
            case 60: // CURLE_SSL_CACERT / peer cert cannot be authenticated
                $m = 'El certificado del servidor no es de una CA de confianza (o es autofirmado). Usa el modo «fijar el certificado» o instala la CA correcta.'; break;
            case 90: // CURLE_SSL_PINNEDPUBKEYNOTMATCH
                $m = 'El certificado del servidor NO coincide con el fijado. Posible suplantación (MITM) o el servidor cambió de certificado: verifica y vuelve a fijarlo si es legítimo.'; break;
            case 67: // CURLE_LOGIN_DENIED
                $m = 'Usuario o contraseña incorrectos: el servidor rechazó el inicio de sesión.'; break;
            case 9:  // CURLE_REMOTE_ACCESS_DENIED
                $m = 'Acceso denegado a la ruta remota. Comprueba que la carpeta existe y que el usuario tiene permiso.'; break;
            case 25: // CURLE_UPLOAD_FAILED
                $m = 'El servidor rechazó la subida. Suele ser falta de permisos de escritura o cuota llena en la ruta de destino.'; break;
            case 78: // CURLE_REMOTE_FILE_NOT_FOUND
                $m = 'Ruta remota no encontrada en el servidor.'; break;
            case 55: // CURLE_SEND_ERROR
            case 56: // CURLE_RECV_ERROR
                $m = 'La conexión se interrumpió durante la transferencia con ' . $where . '. Puede reintentarse.'; break;
            default:
                $m = 'Fallo de transferencia con ' . $where . '.'; break;
        }
        $raw = trim((string)$rawErr);
        return $m . ($raw !== '' ? ' (detalle: ' . $raw . ' [curl ' . (int)$code . '])' : ' [curl ' . (int)$code . ']');
    }

    /**
     * Sube con REINTENTOS y verificación de integridad. Para las copias reales (no el test):
     * la red puede fallar de forma transitoria o cortar la transferencia a medias. Reintenta
     * $attempts veces con backoff lineal, y tras cada subida OK comprueba que el fichero remoto
     * tiene el tamaño completo (detecta truncados silenciosos). Devuelve [ok, mensaje].
     */
    public static function uploadWithRetry($dest, $localFile, $attempts = null, $baseDelaySec = null)
    {
        // Configurable por ajuste del sistema (backup_remote_retries / _retrydelay); defaults 3/5.
        if ($attempts === null) {
            $attempts = class_exists('ctrl_options') ? (int)ctrl_options::GetSystemOption('backup_remote_retries') : 0;
            if ($attempts <= 0) $attempts = 3;
        }
        if ($baseDelaySec === null) {
            $baseDelaySec = class_exists('ctrl_options') ? (int)ctrl_options::GetSystemOption('backup_remote_retrydelay') : 0;
            if ($baseDelaySec <= 0) $baseDelaySec = 5;
        }
        $attempts = max(1, (int)$attempts);
        $localSize = @filesize($localFile);
        $lastMsg = '';
        for ($i = 1; $i <= $attempts; $i++) {
            list($ok, $msg) = self::upload($dest, $localFile);
            if ($ok) {
                // Verificar integridad: el tamaño remoto debe coincidir (si el servidor lo
                // reporta). Si no se puede obtener, se acepta (best-effort, no romper el backup).
                $remote = self::remoteSize($dest, basename($localFile));
                if ($remote === null || $localSize === false || $remote === (int)$localSize) {
                    // Sidecar de integridad de CONTENIDO: sube <copia>.sha256 con el hash local.
                    // Se verifica al restaurar/descargar (detecta corrupción, no solo truncado).
                    $hash = @hash_file('sha256', $localFile);
                    if ($hash !== false) {
                        $sidecar = $localFile . '.sha256';
                        if (@file_put_contents($sidecar, $hash . '  ' . basename($localFile) . "\n") !== false) {
                            self::upload($dest, $sidecar); // best-effort: no rompe el backup si falla
                            @unlink($sidecar);
                        }
                    }
                    return array(true, $msg . ($i > 1 ? " (al intento $i)" : ''));
                }
                $msg = "El fichero remoto quedó incompleto ($remote de $localSize bytes): transferencia truncada, se reintenta.";
            }
            $lastMsg = $msg;
            if ($i < $attempts) {
                sleep($baseDelaySec * $i); // backoff lineal: 5s, 10s, 15s...
            }
        }
        return array(false, "No se pudo completar la subida tras $attempts intentos. Último error: $lastMsg");
    }

    /** Tamaño del fichero remoto en bytes (comando SIZE vía curl), o null si no se puede saber. */
    private static function remoteSize($dest, $filename)
    {
        $host = trim((string)$dest['bd_host_vc']);
        $port = (int)$dest['bd_port_in'] ?: 21;
        $path = '/' . ltrim((string)$dest['bd_path_vc'], '/');
        if ($path === '' || substr($path, -1) !== '/') $path .= '/';
        if ($host === '') return null;
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => 'ftp://' . $host . ':' . $port . $path . rawurlencode($filename),
            CURLOPT_USERPWD        => (string)$dest['bd_user_vc'] . ':' . (string)$dest['password'],
            CURLOPT_NOBODY         => true,   // solo metadatos -> curl hace SIZE
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_NOSIGNAL       => true,
        ));
        if (($dest['bd_type_vc'] ?? 'ftps') !== 'ftp') {
            curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
            self::applyTls($ch, $dest);
        }
        $okc  = curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);
        if ($okc === false || $size < 0) return null;
        return (int)$size;
    }

    /** Lista los nombres de fichero del directorio remoto (solo nombres), o array vacío. */
    private static function listRemote($dest)
    {
        $host = trim((string)$dest['bd_host_vc']);
        $port = (int)$dest['bd_port_in'] ?: 21;
        $path = '/' . ltrim((string)$dest['bd_path_vc'], '/');
        if (substr($path, -1) !== '/') $path .= '/';
        if ($host === '') return array();
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => 'ftp://' . $host . ':' . $port . $path,
            CURLOPT_USERPWD        => (string)$dest['bd_user_vc'] . ':' . (string)$dest['password'],
            CURLOPT_DIRLISTONLY    => true,   // solo nombres (NLST)
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_NOSIGNAL       => true,
        ));
        if (($dest['bd_type_vc'] ?? 'ftps') !== 'ftp') {
            curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
            self::applyTls($ch, $dest);
        }
        $out = curl_exec($ch);
        curl_close($ch);
        if ($out === false || $out === '') return array();
        $names = preg_split('/\r\n|\r|\n/', trim((string)$out));
        // quedarnos solo con el basename (algunos servidores devuelven rutas)
        return array_filter(array_map(function ($n) { return basename(trim($n)); }, $names));
    }

    /** Fecha de modificación (epoch) del fichero remoto vía MDTM, o null si no se puede saber. */
    private static function remoteMtime($dest, $filename)
    {
        $host = trim((string)$dest['bd_host_vc']);
        $port = (int)$dest['bd_port_in'] ?: 21;
        $path = '/' . ltrim((string)$dest['bd_path_vc'], '/');
        if (substr($path, -1) !== '/') $path .= '/';
        if ($host === '') return null;
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => 'ftp://' . $host . ':' . $port . $path . rawurlencode($filename),
            CURLOPT_USERPWD        => (string)$dest['bd_user_vc'] . ':' . (string)$dest['password'],
            CURLOPT_NOBODY         => true,
            CURLOPT_FILETIME       => true,
            CURLOPT_RETURNTRANSFER => true,   // no volcar la respuesta a stdout
            CURLOPT_HEADER         => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_NOSIGNAL       => true,
        ));
        if (($dest['bd_type_vc'] ?? 'ftps') !== 'ftp') {
            curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
            self::applyTls($ch, $dest);
        }
        curl_exec($ch);
        // NOBODY sobre FTP puede devolver false aun con MDTM correcto: fiarse del FILETIME.
        $ft = curl_getinfo($ch, CURLINFO_FILETIME);
        curl_close($ch);
        return ($ft !== null && (int)$ft > 0) ? (int)$ft : null;
    }

    /** Borra un fichero remoto (comando DELE). Devuelve true si el servidor lo aceptó. */
    private static function deleteRemote($dest, $filename)
    {
        $host = trim((string)$dest['bd_host_vc']);
        $port = (int)$dest['bd_port_in'] ?: 21;
        $path = '/' . ltrim((string)$dest['bd_path_vc'], '/');
        if (substr($path, -1) !== '/') $path .= '/';
        if ($host === '' || $filename === '') return false;
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => 'ftp://' . $host . ':' . $port . $path,
            CURLOPT_USERPWD        => (string)$dest['bd_user_vc'] . ':' . (string)$dest['password'],
            CURLOPT_QUOTE          => array('DELE ' . $path . $filename), // ruta ABSOLUTA: QUOTE corre antes del CWD
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_NOSIGNAL       => true,
        ));
        if (($dest['bd_type_vc'] ?? 'ftps') !== 'ftp') {
            curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
            self::applyTls($ch, $dest);
        }
        $ok = curl_exec($ch);
        curl_close($ch);
        return ($ok !== false);
    }

    /**
     * Poda las copias remotas de una cuenta para conservar solo las $max más recientes.
     * Filtra por el prefijo del backup (<username>_*.zip), determina la antigüedad por MDTM
     * y borra las sobrantes (junto a su sidecar .sha256). Best-effort: los ficheros cuya fecha
     * no se puede determinar NO se borran (seguridad ante servidores sin MDTM). Devuelve nº borrados.
     */
    public static function enforceRemoteRetention($dest, $max, $username)
    {
        $max = (int)$max;
        if ($max <= 0) return 0; // 0 = ilimitado
        $files = self::listRemote($dest);
        if (!$files) return 0;
        $re = '/^' . preg_quote((string)$username, '/') . '_.*\.zip$/';
        $bk = array();
        foreach ($files as $f) {
            if (!preg_match($re, $f)) continue;
            $mt = self::remoteMtime($dest, $f);
            if ($mt !== null && $mt > 0) $bk[$f] = $mt; // solo los que sabemos fechar
        }
        if (count($bk) <= $max) return 0;
        arsort($bk); // más recientes primero
        $keep = array_slice(array_keys($bk), 0, $max);
        $deleted = 0;
        foreach (array_keys($bk) as $f) {
            if (in_array($f, $keep, true)) continue;
            if (self::deleteRemote($dest, $f)) {
                $deleted++;
                self::deleteRemote($dest, $f . '.sha256'); // sidecar best-effort
            }
        }
        return $deleted;
    }

    /** Configura la verificación TLS de curl: pinning (TOFU) si hay pin fijado, o CA+hostname. */
    private static function applyTls($ch, $dest)
    {
        $pin = trim((string)($dest['bd_certsha_vc'] ?? ''));
        if ($pin !== '') {
            // TOFU: acepta el cert del servidor (aunque sea autofirmado o de otro nombre) SOLO si
            // su clave pública coincide con la fijada -> detecta MITM aunque tenga cert válido de CA.
            curl_setopt($ch, CURLOPT_PINNEDPUBLICKEY, $pin);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        } else {
            $lvl = isset($dest['bd_tlsverify_in']) ? (int)$dest['bd_tlsverify_in'] : 2;
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $lvl >= 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $lvl >= 2 ? 2 : 0);
        }
    }

    /**
     * Captura la clave pública del certificado del servidor (para fijarla, TOFU). Devuelve
     * array('pin' => 'sha256//...', 'fp' => 'AA:BB:...') o null si no se pudo. Se conecta con
     * verificación desactivada (es la 1ª vez que confías el servidor) y extrae el cert.
     */
    public static function capturePin($dest)
    {
        $host = trim((string)$dest['bd_host_vc']);
        $port = (int)$dest['bd_port_in'] ?: 21;
        $path = '/' . ltrim((string)($dest['bd_path_vc'] ?? '/'), '/');
        if (substr($path, -1) !== '/') $path .= '/';
        if ($host === '') return null;
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => 'ftp://' . $host . ':' . $port . $path,
            CURLOPT_USERPWD        => (string)$dest['bd_user_vc'] . ':' . (string)$dest['password'],
            CURLOPT_USE_SSL        => CURLUSESSL_ALL,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CERTINFO       => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_NOSIGNAL       => true,
        ));
        curl_exec($ch);
        $info = curl_getinfo($ch);
        $err  = curl_error($ch);
        $code = curl_errno($ch);
        curl_close($ch);
        if (empty($info['certinfo'][0]['Cert'])) {
            // Si falló la conexión/TLS, dar el mensaje preciso (p.ej. servidor sin TLS = curl 64).
            $reason = $code ? self::curlFtpError($code, $err, $host, $port)
                            : 'no se pudo leer el certificado del servidor';
            return array('pin' => null, 'fp' => null, 'error' => $reason);
        }
        $pem = $info['certinfo'][0]['Cert'];
        $pub = @openssl_pkey_get_public($pem);
        if (!$pub) return array('pin' => null, 'fp' => null, 'error' => 'certificado ilegible');
        $det = openssl_pkey_get_details($pub);
        if (empty($det['key'])) return array('pin' => null, 'fp' => null, 'error' => 'sin clave pública');
        $spkiDer = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $det['key']));
        $pin = 'sha256//' . base64_encode(hash('sha256', $spkiDer, true));
        $fp  = openssl_x509_fingerprint($pem, 'sha256');
        $fp  = $fp ? strtoupper(rtrim(chunk_split($fp, 2, ':'), ':')) : '';
        return array('pin' => $pin, 'fp' => $fp, 'error' => null);
    }

    /** Prueba de conexión/subida: sube un fichero diminuto de test y lo deja (o informa). */
    public static function testConnection($dest)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'bulwark_bktest');
        file_put_contents($tmp, "bulwark backup destination test " . date('c') . "\n");
        // Timeout CORTO para el test (conectar 8s, total 12s): así "Probar conexión" no
        // cuelga la petición 20s y devuelve el error rápido en vez de que el proxy la corte.
        $r = self::upload($dest, $tmp, 8, 12);
        @unlink($tmp);
        return $r;
    }

    /** Registra el resultado de la última subida en la fila del destino. */
    public static function recordStatus($userid, $okMsg)
    {
        global $zdbh;
        $u = $zdbh->prepare("UPDATE x_backup_destinations SET bd_laststatus_vc=:s, bd_last_ts=:t WHERE bd_acc_fk=:u");
        $u->execute(array(':s' => substr((string)$okMsg, 0, 250), ':t' => time(), ':u' => (int)$userid));
    }
}

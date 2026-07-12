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
    const KEYFILE = '/usr/local/sentora/cnf/backup.key';

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
        // FTPS salvo que se pida FTP plano explícito. Nivel de verificación (bd_tlsverify_in):
        //   2 = completa (CA + hostname)   1 = solo CA (ignora que el nombre no coincida)
        //   0 = ninguna (acepta autofirmados). Por defecto 2.
        if (($dest['bd_type_vc'] ?? 'ftps') !== 'ftp') {
            $lvl = isset($dest['bd_tlsverify_in']) ? (int)$dest['bd_tlsverify_in'] : 2;
            curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $lvl >= 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $lvl >= 2 ? 2 : 0);
        }
        $ok  = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_errno($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok) return array(true, 'Subida completada a ' . $host . $path);
        return array(false, 'Error de subida (' . $code . '): ' . $err);
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
                    return array(true, $msg . ($i > 1 ? " (al intento $i)" : ''));
                }
                $msg = "subida incompleta (remoto $remote != local $localSize bytes)";
            }
            $lastMsg = $msg;
            if ($i < $attempts) {
                sleep($baseDelaySec * $i); // backoff lineal: 5s, 10s, 15s...
            }
        }
        return array(false, "Fallo tras $attempts intentos: $lastMsg");
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
            $lvl = isset($dest['bd_tlsverify_in']) ? (int)$dest['bd_tlsverify_in'] : 2;
            curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $lvl >= 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $lvl >= 2 ? 2 : 0);
        }
        $okc  = curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);
        if ($okc === false || $size < 0) return null;
        return (int)$size;
    }

    /** Prueba de conexión/subida: sube un fichero diminuto de test y lo deja (o informa). */
    public static function testConnection($dest)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sentora_bktest');
        file_put_contents($tmp, "sentora backup destination test " . date('c') . "\n");
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

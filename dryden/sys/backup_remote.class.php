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
    public static function upload($dest, $localFile)
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
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => 0,        // sin límite: ficheros grandes
            CURLOPT_NOSIGNAL       => true,
        ));
        // FTPS salvo que se pida FTP plano explícito
        if (($dest['bd_type_vc'] ?? 'ftps') !== 'ftp') {
            curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
            $verify = (int)($dest['bd_tlsverify_in'] ?? 1) === 1;
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
        }
        $ok  = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_errno($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok) return array(true, 'Subida completada a ' . $host . $path);
        return array(false, 'Error de subida (' . $code . '): ' . $err);
    }

    /** Prueba de conexión/subida: sube un fichero diminuto de test y lo deja (o informa). */
    public static function testConnection($dest)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sentora_bktest');
        file_put_contents($tmp, "sentora backup destination test " . date('c') . "\n");
        $r = self::upload($dest, $tmp);
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

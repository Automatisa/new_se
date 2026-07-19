<?php
/**
 * Antispam Admin module — global settings + stats
 */

// La clase 'privilege' vive en dryden/sys/ pero el autoloader la busca como
// dryden/privilege.class.php, así que hay que requerirla explícitamente (igual
// que clamav_admin, dns_admin, etc.). Sin esto, privilege::run() lanza
// "Error: Class privilege not found" (no lo captura catch(Exception)) -> 500.
require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';

class module_controller extends ctrl_module
{
    const REDIS_HOST = '127.0.0.1';
    const REDIS_PORT = 6379;
    const RSPAMD_API = 'http://127.0.0.1:11334';

    const PHISHING_CONF   = '/var/bulwark/rspamd/phishing.conf';
    const PHISHING_REDIRS = '/var/bulwark/rspamd/phishing_redirectors.map';
    const PHISHING_STRICT = '/var/bulwark/rspamd/phishing_strict_domains.map';

    static $ok_msg;
    static $err_msg;
    static $test_result = null;

    private static function redis()
    {
        static $r = null;
        if ($r === null) {
            $r = new Redis();
            if (!@$r->connect(self::REDIS_HOST, self::REDIS_PORT, 2)) {
                $r = null;
                throw new RuntimeException('Cannot connect to Redis');
            }
            $rp = @file_get_contents('/usr/local/bulwark/cnf/redis.pass');
            if ($rp !== false && trim($rp) !== '') { try { $r->auth(['panel', trim($rp)]); } catch (Exception $e) {} }
        }
        return $r;
    }

    private static function rspamdApi($path, $method = 'GET', $body = null)
    {
        // Comprobación rápida de conectividad (1 s) antes del HTTP request completo.
        // file_get_contents no tiene timeout de conexión real; si rspamd está arrancando
        // puede bloquear hasta el default_socket_timeout del sistema (60 s → 504).
        $sock = @fsockopen('127.0.0.1', 11334, $errno, $errstr, 1.0);
        if (!$sock) return null;
        fclose($sock);

        $opts = [
            'http' => [
                'method'        => $method,
                'timeout'       => 3,
                'ignore_errors' => true,
                'header'        => "Content-Type: application/json\r\n",
            ]
        ];
        if ($body !== null) $opts['http']['content'] = json_encode($body);
        $ctx = stream_context_create($opts);
        $raw = @file_get_contents(self::RSPAMD_API . $path, false, $ctx);
        return $raw ? json_decode($raw, true) : null;
    }

    // ----------------------------------------------------------------
    // Actions
    // ----------------------------------------------------------------

    private static function saveOption($name, $value)
    {
        if (ctrl_options::GetSystemOption($name) !== false) {
            ctrl_options::SetSystemOption($name, $value);
        } else {
            ctrl_options::SetSystemOption($name, $value, true);
        }
    }

    static function doSaveGlobal()
    {
        runtime_csfr::Protect();
        global $controller;
        $score  = $controller->GetControllerRequest('FORM', 'inScore');
        $action = $controller->GetControllerRequest('FORM', 'inAction');

        $score  = min(20, max(1, (float)$score));
        $action = in_array($action, ['tag', 'junk', 'reject']) ? $action : 'junk';

        self::saveOption('antispam_score',  (string)$score);
        self::saveOption('antispam_action', $action);

        try {
            $r = self::redis();
            $r->hMSet('bulwark:antispam:global', ['score' => $score, 'action' => $action]);
        } catch (Exception $e) {
            error_log('antispam_admin: Redis error: ' . $e->getMessage());
        }

        self::$ok_msg = 'Global antispam settings saved.';
    }

    // ---- Rate-limit de SALIDA (editable desde el panel) --------------------------------------
    const RATELIMIT_CONF = '/var/bulwark/rspamd/ratelimit.conf';

    /** Escribe /var/bulwark/rspamd/ratelimit.conf (www-writable, incluido por rspamd) desde los
     *  ajustes guardados. Devuelve true si se escribió. */
    private static function writeRatelimitConf()
    {
        $enabled = ctrl_options::GetSystemOption('ratelimit_enabled');
        $enabled = ($enabled === '0') ? '0' : '1';
        $maxRcpt = max(1, (int)(ctrl_options::GetSystemOption('ratelimit_max_rcpt')  ?: 100));
        $uRate   = max(1, (int)(ctrl_options::GetSystemOption('ratelimit_user_rate')  ?: 300));
        $uBurst  = max(1, (int)(ctrl_options::GetSystemOption('ratelimit_user_burst') ?: 300));
        $dRate   = max(1, (int)(ctrl_options::GetSystemOption('ratelimit_domain_rate') ?: 200));
        $dir = dirname(self::RATELIMIT_CONF);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        if ($enabled === '0') {
            $conf = "# Rate-limit de salida DESACTIVADO desde el panel (Antispam).\n";
        } else {
            $conf  = "# Generado por el panel (Antispam -> Límite de envío). NO editar a mano.\n";
            $conf .= "max_rcpt = {$maxRcpt};\n";
            $conf .= "rates {\n";
            // Por usuario autenticado (correo por submission/587).
            $conf .= "    user {\n        bucket {\n            burst = {$uBurst};\n            rate = \"{$uRate} / 1h\";\n        }\n    }\n";
            // Por dominio del remitente: cubre el correo LOCAL (PHP mail()/PHPMailer), que no
            // tiene usuario autenticado. Así una web comprometida queda capada por su dominio.
            $conf .= "    senderdomain {\n        selector = \"smtp_from.domain\";\n        bucket {\n            burst = {$dRate};\n            rate = \"{$dRate} / 1h\";\n        }\n    }\n";
            $conf .= "}\n";
        }
        return @file_put_contents(self::RATELIMIT_CONF, $conf) !== false;
    }

    static function doSaveRatelimit()
    {
        runtime_csfr::Protect();
        global $controller;
        $enabled = $controller->GetControllerRequest('FORM', 'inRlEnabled') ? '1' : '0';
        $maxRcpt = max(1, min(100000, (int)$controller->GetControllerRequest('FORM', 'inRlMaxRcpt')));
        $uRate   = max(1, min(1000000, (int)$controller->GetControllerRequest('FORM', 'inRlUserRate')));
        $uBurst  = max(1, min(1000000, (int)$controller->GetControllerRequest('FORM', 'inRlUserBurst')));
        $dRate   = max(1, min(1000000, (int)$controller->GetControllerRequest('FORM', 'inRlDomainRate')));
        self::saveOption('ratelimit_enabled',   $enabled);
        self::saveOption('ratelimit_max_rcpt',  (string)$maxRcpt);
        self::saveOption('ratelimit_user_rate', (string)$uRate);
        self::saveOption('ratelimit_user_burst', (string)$uBurst);
        self::saveOption('ratelimit_domain_rate', (string)$dRate);
        if (!self::writeRatelimitConf()) {
            self::$err_msg = 'No se pudo escribir la configuración del rate-limit.';
            return;
        }
        if (!class_exists('privilege')) { require_once '/usr/local/bulwark/dryden/sys/privilege.class.php'; }
        try {
            privilege::run('rspamd_restart', [], true);
            self::$ok_msg = 'Límite de envío guardado y aplicado (rspamd recargado).';
        } catch (Exception $e) {
            self::$err_msg = 'Guardado, pero no se pudo recargar rspamd: ' . $e->getMessage();
        }
    }

    static function getRlEnabled()   { return ctrl_options::GetSystemOption('ratelimit_enabled') === '0' ? '' : 'checked'; }
    static function getRlMaxRcpt()   { return htmlspecialchars((string)(ctrl_options::GetSystemOption('ratelimit_max_rcpt')  ?: '100'), ENT_QUOTES); }
    static function getRlUserRate()  { return htmlspecialchars((string)(ctrl_options::GetSystemOption('ratelimit_user_rate')  ?: '300'), ENT_QUOTES); }
    static function getRlUserBurst() { return htmlspecialchars((string)(ctrl_options::GetSystemOption('ratelimit_user_burst') ?: '300'), ENT_QUOTES); }
    static function getRlDomainRate() { return htmlspecialchars((string)(ctrl_options::GetSystemOption('ratelimit_domain_rate') ?: '200'), ENT_QUOTES); }

    // ---- Límite DURO de mail() por cuenta (wrapper sendmail_path) --------------------------------
    // Complementa el rate-limit de rspamd: aquí PHP-FPM corre cada dominio como h_<cuenta>, así que
    // el emisor es INFALSIFICABLE. El wrapper /usr/local/bulwark/bin/bulwark_mail_limit.sh lee estos
    // dos ficheros (www-writable) y descarta el correo de una cuenta que supere el límite/hora.
    const MAILLIMIT_DIR   = '/var/bulwark/mail_limits';
    const MAILLIMIT_LIMIT = '/var/bulwark/mail_limits/limit';
    const MAILLIMIT_WL    = '/var/bulwark/mail_limits/whitelist';

    static function doSaveMailLimit()
    {
        runtime_csfr::Protect();
        global $controller;
        // 0 = ilimitado; tope alto de seguridad. La allowlist se gestiona aparte (Add/Remove).
        $limit = max(0, min(1000000, (int)$controller->GetControllerRequest('FORM', 'inMlLimit')));

        if (!is_dir(self::MAILLIMIT_DIR)) { @mkdir(self::MAILLIMIT_DIR, 0755, true); }
        if (@file_put_contents(self::MAILLIMIT_LIMIT, (string)$limit . "\n") === false) {
            self::$err_msg = 'No se pudo escribir ' . self::MAILLIMIT_LIMIT . ' (¿permisos www?).';
            return;
        }
        self::saveOption('maillimit_per_hour', (string)$limit);
        self::$ok_msg = $limit === 0
            ? 'Límite de mail() por cuenta DESACTIVADO (0 = ilimitado).'
            : 'Límite de mail() guardado: ' . $limit . ' correos/hora por cuenta.';
    }

    static function getMlLimit()
    {
        $v = is_readable(self::MAILLIMIT_LIMIT) ? trim(@file_get_contents(self::MAILLIMIT_LIMIT)) : '';
        if ($v === '' || !ctype_digit($v)) $v = (string)(ctrl_options::GetSystemOption('maillimit_per_hour') ?: '200');
        return htmlspecialchars($v, ENT_QUOTES);
    }

    // ---- Allowlist de mail(): gestión por cuenta con Añadir/Eliminar ----------------------------

    /** Cuentas actualmente exentas (fichero whitelist), como array normalizado. */
    private static function mlReadWhitelist()
    {
        $wl = [];
        if (is_readable(self::MAILLIMIT_WL)) {
            foreach (preg_split('/\s+/', trim((string)@file_get_contents(self::MAILLIMIT_WL))) as $a) {
                $a = strtolower(trim($a));
                if ($a !== '') $wl[$a] = true;
            }
        }
        $wl = array_keys($wl);
        sort($wl);
        return $wl;
    }

    private static function mlWriteWhitelist(array $wl)
    {
        if (!is_dir(self::MAILLIMIT_DIR)) { @mkdir(self::MAILLIMIT_DIR, 0755, true); }
        return @file_put_contents(self::MAILLIMIT_WL, $wl ? implode("\n", $wl) . "\n" : '') !== false;
    }

    /** Cuentas de hosting reales (activas), como set [cuenta => true]. */
    private static function mlValidAccounts()
    {
        global $zdbh;
        $valid = [];
        try {
            $q = $zdbh->prepare("SELECT ac_user_vc FROM x_accounts WHERE ac_enabled_in=1 AND ac_deleted_ts IS NULL");
            $q->execute();
            while ($r = $q->fetch()) $valid[strtolower($r['ac_user_vc'])] = true;
        } catch (Exception $e) {}
        return $valid;
    }

    static function doAddMailLimitAcct()
    {
        runtime_csfr::Protect();
        global $controller;
        $acct = preg_replace('/^h_/', '', strtolower(trim((string)$controller->GetControllerRequest('FORM', 'inMlAcct'))));
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $acct) || !isset(self::mlValidAccounts()[$acct])) {
            self::$err_msg = 'Cuenta no válida.';
            return;
        }
        $wl = self::mlReadWhitelist();
        if (!in_array($acct, $wl, true)) $wl[] = $acct;
        sort($wl);
        if (!self::mlWriteWhitelist($wl)) { self::$err_msg = 'No se pudo escribir la allowlist (¿permisos www?).'; return; }
        self::$ok_msg = 'Cuenta "' . htmlspecialchars($acct, ENT_QUOTES, 'UTF-8') . '" añadida a las exentas.';
    }

    static function doRemoveMailLimitAcct()
    {
        runtime_csfr::Protect();
        global $controller;
        $acct = preg_replace('/^h_/', '', strtolower(trim((string)$controller->GetControllerRequest('FORM', 'inMlAcct'))));
        $wl = array_values(array_filter(self::mlReadWhitelist(), function ($a) use ($acct) { return $a !== $acct; }));
        if (!self::mlWriteWhitelist($wl)) { self::$err_msg = 'No se pudo escribir la allowlist (¿permisos www?).'; return; }
        self::$ok_msg = 'Cuenta "' . htmlspecialchars($acct, ENT_QUOTES, 'UTF-8') . '" quitada de las exentas.';
    }

    /** Campo con autocompletado (datalist) de las cuentas AÚN NO exentas + botón Añadir.
     *  Escala a cientos de cuentas: se busca escribiendo, no hay que recorrer un desplegable. */
    static function getMlAddForm()
    {
        $wl    = array_flip(self::mlReadWhitelist());
        $valid = self::mlValidAccounts();          // ya en minúsculas
        $avail = [];
        foreach (array_keys($valid) as $u) { if (!isset($wl[$u])) $avail[] = $u; }
        sort($avail);

        if (!$avail) {
            return '<p class="text-muted" style="margin:0 0 10px;">Todas las cuentas están ya exentas o no hay cuentas disponibles.</p>';
        }
        $csrf = self::getCSFR_Tag();
        $opts = '';
        foreach ($avail as $u) {
            $opts .= '<option value="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '">';
        }
        return '<form method="post" action="./?module=antispam_admin&action=AddMailLimitAcct&tab=config">'
             . $csrf
             . '<div class="input-group input-group-sm" style="max-width:420px;">'
             . '<span class="input-group-text"><i class="bi bi-search"></i></span>'
             . '<input type="text" name="inMlAcct" list="mlAcctList" autocomplete="off" class="form-control" '
             . 'placeholder="Escribe para buscar una cuenta...">'
             . '<button type="submit" class="btn btn-success"><i class="bi bi-plus-lg me-1"></i>Añadir</button>'
             . '</div>'
             . '<datalist id="mlAcctList">' . $opts . '</datalist>'
             . '<div class="form-text" style="margin-top:4px;">' . count($avail) . ' cuenta(s) disponible(s). Empieza a escribir y elige de las sugerencias.</div>'
             . '</form>';
    }

    /** Badge con el nº de cuentas exentas para la cabecera de la tarjeta. */
    static function getMlExemptBadge()
    {
        $n = count(self::mlReadWhitelist());
        return '<span class="badge ' . ($n ? 'bg-primary' : 'bg-secondary') . '">' . $n . ' exenta' . ($n === 1 ? '' : 's') . '</span>';
    }

    /** Lista de cuentas exentas, cada una con su botón Eliminar. Con buscador + paginación cliente
     *  (se activan solos si hay muchas) para que 50+ cuentas no generen una página larguísima. */
    static function getMlExemptList()
    {
        $wl = self::mlReadWhitelist();
        $csrf = self::getCSFR_Tag();
        $h  = '<hr style="margin:12px 0;">';
        if (!$wl) {
            return $h . '<p class="text-muted" style="margin:0;"><i class="bi bi-info-circle me-1"></i>Ninguna cuenta exenta: todas están limitadas.</p>';
        }
        $h .= '<div id="mlExemptWrap">';
        $h .= '<input type="text" id="mlExemptSearch" class="form-control form-control-sm" placeholder="Filtrar cuentas exentas..." '
            . 'autocomplete="off" style="max-width:260px;margin-bottom:8px;display:none;" onkeyup="mlExemptRender()">';
        $h .= '<div id="mlExemptCount" class="form-text" style="margin:0 0 6px;"></div>';
        $h .= '<div style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;">';
        $h .= '<table class="table table-hover table-sm align-middle" style="margin:0;"><tbody id="mlExemptBody">';
        foreach ($wl as $u) {
            $esc = htmlspecialchars($u, ENT_QUOTES, 'UTF-8');
            $h .= '<tr class="mlExemptRow">'
                . '<td style="padding:8px 12px;"><i class="bi bi-person-check text-muted me-2"></i>' . $esc . '</td>'
                . '<td style="text-align:right;padding:6px 12px;">'
                . '<form method="post" action="./?module=antispam_admin&action=RemoveMailLimitAcct&tab=config" style="display:inline;">'
                . $csrf
                . '<input type="hidden" name="inMlAcct" value="' . $esc . '">'
                . '<button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Eliminar</button>'
                . '</form></td></tr>';
        }
        $h .= '</tbody></table></div>';
        $h .= '<div id="mlExemptPager" class="d-flex justify-content-center mt-2"></div>';
        $h .= '</div>';
        return $h;
    }

    static function doRestartRspamd()
    {
        runtime_csfr::Protect();
        if (!class_exists('privilege')) {
            require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
        }
        try {
            privilege::run('rspamd_restart', [], true);
            self::$ok_msg = 'Servicio reiniciado.';
        } catch (Exception $e) {
            self::$err_msg = 'Error al reiniciar: ' . $e->getMessage();
        }
    }

    static function doToggleAntispam()
    {
        runtime_csfr::Protect();
        $current = ctrl_options::GetSystemOption('antispam_enabled');
        $enabling = ($current === 'false');
        $new      = $enabling ? 'true' : 'false';
        self::saveOption('antispam_enabled', $new);
        try {
            self::redis()->hSet('bulwark:antispam:global', 'enabled', $enabling ? '1' : '0');
        } catch (Exception $e) {
            error_log('antispam_admin: Redis error on toggle: ' . $e->getMessage());
        }
        if (!class_exists('privilege')) {
            require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
        }
        try {
            privilege::run($enabling ? 'rspamd_start' : 'rspamd_stop', [], true);
            self::$ok_msg = $enabling ? 'Servicio arrancado.' : 'Servicio parado.';
        } catch (Exception $e) {
            self::$err_msg = 'Error al cambiar estado del servicio: ' . $e->getMessage();
        }
    }

    static function doAddGlobalList()
    {
        runtime_csfr::Protect();
        global $controller;
        $address = trim($controller->GetControllerRequest('FORM', 'inAddress'));
        $type    = $controller->GetControllerRequest('FORM', 'inType');

        if (!filter_var($address, FILTER_VALIDATE_EMAIL) && !preg_match('/^@[a-z0-9.\-]+\.[a-z]{2,}$/i', $address)) {
            self::$err_msg = 'Invalid email or domain.';
            return;
        }
        if (!in_array($type, ['white', 'black'])) { self::$err_msg = 'Invalid type.'; return; }

        try {
            $r = self::redis();
            $r->sAdd('bulwark:antispam:global:' . $type, $address);
            self::$ok_msg = 'Address added to global ' . $type . 'list.';
        } catch (Exception $e) {
            self::$err_msg = 'Redis error: ' . $e->getMessage();
        }
    }

    static function doRemoveGlobalList()
    {
        runtime_csfr::Protect();
        global $controller;
        $address = trim($controller->GetControllerRequest('FORM', 'inAddress'));
        $type    = $controller->GetControllerRequest('FORM', 'inType');
        if (!in_array($type, ['white', 'black'])) { self::$err_msg = 'Invalid type.'; return; }

        try {
            $r = self::redis();
            $r->sRem('bulwark:antispam:global:' . $type, $address);
            self::$ok_msg = 'Address removed.';
        } catch (Exception $e) {
            self::$err_msg = 'Redis error: ' . $e->getMessage();
        }
    }

    private static function extractReceivedMeta(string $raw): array
    {
        $meta = [];
        // Formato estándar: "from hostname ([IP])" o "from hostname (hostname [IP])"
        if (preg_match('/^Received:.*?from\s+([a-zA-Z0-9.\-]+)\s+\(\[(\d{1,3}(?:\.\d{1,3}){3})\]\)/mi', $raw, $m)) {
            $meta['hostname'] = $m[1];
            $meta['ip']       = $m[2];
        } elseif (preg_match('/^Received:.*?from\s+([a-zA-Z0-9.\-]+)\s+\([^\)]*\[(\d{1,3}(?:\.\d{1,3}){3})\]/mi', $raw, $m)) {
            $meta['hostname'] = $m[1];
            $meta['ip']       = $m[2];
        } elseif (preg_match('/^Received:.*?\[(\d{1,3}(?:\.\d{1,3}){3})\]/mi', $raw, $m)) {
            $meta['ip'] = $m[1];
        }
        // Envelope from
        if (preg_match('/^Return-[Pp]ath:\s*<([^>]+)>/mi', $raw, $m)) {
            $meta['from'] = $m[1];
        }
        // Envelope to
        if (preg_match('/^(?:Envelope-to|X-Original-To|Delivered-To):\s*(\S+)/mi', $raw, $m)) {
            $meta['rcpt'] = trim($m[1]);
        }
        return $meta;
    }

    static function doTestMessage()
    {
        runtime_csfr::Protect();
        global $controller;
        $raw = $controller->GetControllerRequest('FORM', 'inRawEmail');
        if (empty(trim($raw))) {
            self::$err_msg = 'Paste email content to test.';
            return;
        }
        $sock = @fsockopen('127.0.0.1', 11334, $errno, $errstr, 1.0);
        if (!$sock) { self::$err_msg = 'rspamd is not running.'; return; }
        fclose($sock);

        $meta   = self::extractReceivedMeta($raw);
        $hdrs   = "Content-Type: text/plain\r\nQueue-Id: bulwark-test\r\n";
        if (!empty($meta['ip']))       $hdrs .= 'IP: '       . $meta['ip']       . "\r\n";
        if (!empty($meta['hostname'])) $hdrs .= 'Hostname: ' . $meta['hostname'] . "\r\n";
        if (!empty($meta['from']))     $hdrs .= 'From: '     . $meta['from']     . "\r\n";
        if (!empty($meta['rcpt']))     $hdrs .= 'Rcpt: '     . $meta['rcpt']     . "\r\n";

        $opts = ['http' => [
            'method'        => 'POST',
            'timeout'       => 10,
            'ignore_errors' => true,
            'header'        => $hdrs,
            'content'       => $raw,
        ]];
        $resp = @file_get_contents(self::RSPAMD_API . '/checkv2', false, stream_context_create($opts));
        if (!$resp) { self::$err_msg = 'No response from rspamd /checkv2.'; return; }
        self::$test_result = json_decode($resp, true);
        if (!self::$test_result) { self::$err_msg = 'Could not parse rspamd response.'; }
    }

    static function doFlushGreylist()
    {
        runtime_csfr::Protect();
        try {
            $r     = self::redis();
            $keys  = $r->keys('rg*');
            $count = 0;
            if ($keys) { $r->del($keys); $count = count($keys); }
            self::$ok_msg = 'Greylist cleared (' . $count . ' entries removed).';
        } catch (Exception $e) {
            self::$err_msg = 'Redis error: ' . $e->getMessage();
        }
    }

    // ----------------------------------------------------------------
    // Template getters
    // ----------------------------------------------------------------

    static function getResult()
    {
        if (self::$err_msg)
            return ui_sysmessage::shout(ui_language::translate(self::$err_msg), 'zannounceerror');
        if (self::$ok_msg)
            return ui_sysmessage::shout(ui_language::translate(self::$ok_msg), 'zannounceok');
        return '';
    }

    static function getAntispamEnabled()
    {
        $val = ctrl_options::GetSystemOption('antispam_enabled');
        return ($val === false || $val === 'true');
    }

    private static function isRspamdRunning()
    {
        $pidFile = '/var/run/rspamd/rspamd.pid';
        if (!file_exists($pidFile)) return false;
        $pid = (int)trim(@file_get_contents($pidFile));
        if ($pid <= 0) return false;
        return file_exists('/proc/' . $pid) || @posix_kill($pid, 0) !== false
            || (file_exists('/proc') === false && $pid > 0 && file_exists('/var/run/rspamd/rspamd.pid'));
    }

    static function getToggleButton()
    {
        $enabled = self::getAntispamEnabled();
        $label   = $enabled ? 'Parar servicio' : 'Arrancar servicio';
        $class   = $enabled ? 'btn-danger' : 'btn-success';
        $csrf    = self::getCSFR_Tag();
        return '<form method="post" action="./?module=antispam_admin&action=ToggleAntispam&tab=status" style="display:inline;">'
             . $csrf
             . '<button type="submit" class="btn ' . $class . '">' . $label . '</button></form>';
    }

    static function getRestartButton()
    {
        $enabled = self::getAntispamEnabled();
        if (!$enabled) return '';
        $csrf = self::getCSFR_Tag();
        return '<form method="post" action="./?module=antispam_admin&action=RestartRspamd&tab=status" style="display:inline;">'
             . $csrf
             . '<button type="submit" class="btn btn-warning" onclick="return confirm(\'Reiniciar rspamd ahora?\')"><i class="bi bi-arrow-repeat me-1"></i>Reiniciar servicio</button></form>';
    }

    static function getAntispamStatusBadge()
    {
        $enabled = self::getAntispamEnabled();
        return $enabled
            ? '<span class="badge bg-success" style="font-size:14px;padding:6px 12px;">ACTIVE</span>'
            : '<span class="badge bg-danger"  style="font-size:14px;padding:6px 12px;">STOPPED</span>';
    }

    static function getGlobalScore()
    {
        return ctrl_options::GetSystemOption('antispam_score') ?: '6.0';
    }

    static function getGlobalAction()
    {
        return ctrl_options::GetSystemOption('antispam_action') ?: 'junk';
    }

    static function getGlobalActionSelect()
    {
        $cur  = self::getGlobalAction();
        $opts = ['tag' => 'Tag subject [SPAM]', 'junk' => 'Move to Junk', 'reject' => 'Reject message'];
        $html = '<select name="inAction" class="form-control">';
        foreach ($opts as $val => $label) {
            $sel   = ($cur === $val) ? ' selected' : '';
            $html .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
        }
        return $html . '</select>';
    }

    private static function formatUptime(int $seconds): string
    {
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($d > 0) return "{$d}d {$h}h {$m}m";
        if ($h > 0) return "{$h}h {$m}m {$s}s";
        return "{$m}m {$s}s";
    }

    static function getRspamdUnavailableMessage()
    {
        if (!self::getAntispamEnabled()) return '';
        return '<div class="zgrid_wrapper"><div class="alert alert-warning">'
             . 'Cannot connect to rspamd API (127.0.0.1:11334). Check that rspamd is running.'
             . '</div></div>';
    }

    static function getRspamdStats()
    {
        $stat = self::rspamdApi('/stat');
        if (!$stat) return false;
        return [[
            'version'    => htmlspecialchars($stat['version'] ?? '?', ENT_QUOTES, 'UTF-8'),
            'scanned'    => number_format((int)($stat['scanned']   ?? 0)),
            'learned'    => number_format((int)($stat['learned']   ?? 0)),
            'spam_count' => number_format((int)($stat['actions']['add header'] ?? 0)),
            'reject'     => number_format((int)($stat['actions']['reject']     ?? 0)),
            'ham_count'  => number_format(max(0,
                            (int)($stat['scanned'] ?? 0)
                            - (int)($stat['actions']['add header'] ?? 0)
                            - (int)($stat['actions']['reject']     ?? 0))),
            'uptime'     => self::formatUptime((int)($stat['uptime'] ?? 0)),
        ]];
    }

    static function getRspamdSummary()
    {
        $stat = self::rspamdApi('/stat');
        if (!$stat) return '<span class="text-danger">rspamd not reachable on 127.0.0.1:11334</span>';
        $learned  = number_format((int)($stat['learned'] ?? 0));
        try {
            $count = count(self::redis()->keys('bulwark:antispam:*@*'));
        } catch (Exception $e) {
            $count = '?';
        }
        return 'Learned messages: <strong>' . $learned . '</strong> &nbsp;|&nbsp; Active mailboxes in Redis: <strong>' . $count . '</strong>';
    }

    static function getTestMessageForm()
    {
        $csrf = self::getCSFR_Tag();
        return '<form method="post" action="./?module=antispam_admin&action=TestMessage&tab=test">'
             . $csrf
             . '<div class="mb-3"><div class="col-sm-12">'
             . '<textarea name="inRawEmail" class="form-control" rows="7" style="font-family:monospace;font-size:12px;"'
             . ' placeholder="Pega aquí las cabeceras o el email completo (formato RFC 2822)..."></textarea>'
             . '</div></div>'
             . '<div class="mb-3"><div class="col-sm-12">'
             . '<button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Analizar mensaje</button>'
             . '</div></div>'
             . '</form>';
    }

    static function getTestSummary()
    {
        if (self::$test_result === null) return '';
        $score    = number_format((float)(self::$test_result['score'] ?? 0), 2);
        $required = number_format((float)(self::$test_result['required_score'] ?? 0), 2);
        $action   = htmlspecialchars(self::$test_result['action'] ?? '?', ENT_QUOTES, 'UTF-8');
        $map      = ['reject' => 'danger', 'add header' => 'warning', 'rewrite subject' => 'warning',
                     'greylist' => 'info', 'soft reject' => 'info', 'no action' => 'success'];
        $cls      = $map[$action] ?? 'default';
        return '<div class="alert alert-' . $cls . '" style="margin-top:10px;">'
             . '<strong>Score:</strong> ' . $score . ' / ' . $required . ' &nbsp;&nbsp; '
             . '<strong>Acción:</strong> <span class="badge bg-' . $cls . '">' . $action . '</span>'
             . '</div>';
    }

    static function getTestSymbols()
    {
        if (self::$test_result === null) return false;
        $symbols = self::$test_result['symbols'] ?? [];
        if (empty($symbols)) return false;
        uasort($symbols, function($a, $b) {
            return abs((float)($b['score'] ?? 0)) > abs((float)($a['score'] ?? 0)) ? 1 : -1;
        });
        $out = [];
        foreach ($symbols as $name => $data) {
            $score = (float)($data['score'] ?? 0);
            if ($score == 0.0) continue;
            $out[] = [
                'sym_name'  => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                'sym_score' => ($score > 0 ? '+' : '') . number_format($score, 3),
                'sym_desc'  => htmlspecialchars($data['description'] ?? '', ENT_QUOTES, 'UTF-8'),
                'sym_class' => $score > 0 ? 'danger' : 'success',
            ];
        }
        return $out ?: false;
    }

    static function getActiveTab()
    {
        if (self::$test_result !== null) return 'test';
        if (self::$err_msg && !empty($_POST['inRawEmail'])) return 'test';
        return '';
    }

    // ----------------------------------------------------------------
    // Phishing module
    // ----------------------------------------------------------------

    static function doSavePhishing()
    {
        runtime_csfr::Protect();
        global $controller;
        $openphish  = $controller->GetControllerRequest('FORM', 'inPhishOpenphish') === '1';
        $redirectors = trim($controller->GetControllerRequest('FORM', 'inPhishRedirectors') ?? '');
        $strict      = trim($controller->GetControllerRequest('FORM', 'inPhishStrict') ?? '');

        $conf  = "# Generado por Bulwark antispam_admin — no editar manualmente\n";
        $conf .= 'openphish_enabled = ' . ($openphish ? 'true' : 'false') . ";\n";
        $conf .= 'openphish_map = "https://raw.githubusercontent.com/openphish/public_feed/refs/heads/main/feed.txt";' . "\n\n";
        $conf .= "exceptions {\n    REDIRECTOR_FALSE = [\"" . self::PHISHING_REDIRS . "\"];\n}\n\n";
        $conf .= "strict_domains {\n    PHISHED_STRICT = [\"" . self::PHISHING_STRICT . "\"];\n}\n";

        if (@file_put_contents(self::PHISHING_CONF, $conf) === false) {
            self::$err_msg = 'Error al escribir ' . self::PHISHING_CONF . ' (permisos www:www 750?)';
            return;
        }

        $redirLines = self::sanitizeMapLines($redirectors);
        if (@file_put_contents(self::PHISHING_REDIRS, implode("\n", $redirLines) . "\n") === false) {
            self::$err_msg = 'Error al escribir mapa de redirectores.';
            return;
        }

        $strictLines = self::sanitizeMapLines($strict);
        if (@file_put_contents(self::PHISHING_STRICT, implode("\n", $strictLines) . "\n") === false) {
            self::$err_msg = 'Error al escribir mapa de dominios protegidos.';
            return;
        }

        try {
            self::redis()->hSet('bulwark:antispam:phishing', 'openphish_enabled', $openphish ? '1' : '0');
        } catch (Exception $e) {}

        privilege::run('rspamd_reload');
        self::$ok_msg = 'Configuración de phishing guardada' . ($openphish ? ' — OpenPhish activado.' : ' — OpenPhish desactivado.');
    }

    private static function sanitizeMapLines(string $raw): array
    {
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (preg_match('/^[a-zA-Z0-9.\-]+$/', $line)) {
                $out[] = $line;
            }
        }
        return $out;
    }

    static function getPhishingConfig()
    {
        $openphish = false;
        try {
            $openphish = self::redis()->hGet('bulwark:antispam:phishing', 'openphish_enabled') === '1';
        } catch (Exception $e) {}

        $redirectors = is_readable(self::PHISHING_REDIRS)
            ? trim(@file_get_contents(self::PHISHING_REDIRS))
            : "t.co\nbit.ly\ngoo.gl\ntinyurl.com\now.ly\nbuff.ly";
        $strict = is_readable(self::PHISHING_STRICT)
            ? trim(@file_get_contents(self::PHISHING_STRICT))
            : '';

        $chk = $openphish ? ' checked' : '';

        $h  = '<form method="post" action="./?module=antispam_admin&action=SavePhishing&tab=phishing">';
        $h .= self::getCSFR_Tag();

        $h .= '<div class="card">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Feed OpenPhish</h4></div>';
        $h .= '<div class="card-body">';
        $h .= '<p class="text-muted">Lista pública de URLs de phishing activas, actualizada continuamente. '
            . 'Genera el símbolo <code>PHISHED_OPENPHISH</code> (peso&nbsp;7.5 → acción <em>add header</em> al superar 6, <em>reject</em> al superar 15).</p>';
        $h .= '<div class="mb-0">'
            . '<div class="col-sm-9"><div class="checkbox"><label>'
            . '<input type="checkbox" name="inPhishOpenphish" value="1"' . $chk . '>&nbsp; Activar feed OpenPhish'
            . '</label></div></div></div>';
        $h .= '</div></div>';

        $h .= '<div class="card" style="margin-top:15px;">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Dominios protegidos <small>(strict)</small></h4></div>';
        $h .= '<div class="card-body">';
        $h .= '<p class="text-muted">Si el texto visible de un enlace menciona uno de estos dominios pero el href apunta a otro sitio, '
            . 'rspamd emite <code>PHISHED_STRICT</code> (peso&nbsp;10.0) <strong>siempre</strong>, sin importar otros checks. '
            . 'Útil para bancos, administraciones públicas, dominios propios críticos. Un dominio por línea.</p>';
        $h .= '<div class="mb-0"><div class="col-sm-9">'
            . '<textarea name="inPhishStrict" class="form-control" rows="6" style="font-family:monospace;font-size:13px;">'
            . htmlspecialchars($strict, ENT_QUOTES, 'UTF-8')
            . '</textarea></div></div>';
        $h .= '</div></div>';

        $h .= '<div class="card" style="margin-top:15px;">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Redirectores legítimos</h4></div>';
        $h .= '<div class="card-body">';
        $h .= '<p class="text-muted">Acortadores y redirectores conocidos (t.co, bit.ly…). Cuando el href de un enlace pasa por uno '
            . 'de estos dominios, rspamd emite <code>REDIRECTOR_FALSE</code> (peso&nbsp;0) en lugar de <code>PHISHED_URL</code>. '
            . 'Un dominio por línea.</p>';
        $h .= '<div class="mb-0"><div class="col-sm-9">'
            . '<textarea name="inPhishRedirectors" class="form-control" rows="6" style="font-family:monospace;font-size:13px;">'
            . htmlspecialchars($redirectors, ENT_QUOTES, 'UTF-8')
            . '</textarea></div></div>';
        $h .= '</div></div>';

        $h .= '<div class="mb-3 mt-2">'
            . '<div class="col-sm-9 offset-sm-3">'
            . '<button type="submit" class="btn btn-primary">'
            . '<span class="bi bi-floppy"></span>&nbsp; Guardar configuración phishing'
            . '</button></div></div>';
        $h .= '</form>';

        return $h;
    }

    static function getBayesStats()
    {
        $stat = self::rspamdApi('/stat');
        if (!$stat || empty($stat['statfiles'])) return false;
        $ham = 0; $spam = 0;
        foreach ($stat['statfiles'] as $sf) {
            if ($sf['class'] === 'ham')  $ham  = (int)($sf['used'] ?? 0);
            if ($sf['class'] === 'spam') $spam = (int)($sf['used'] ?? 0);
        }
        $learned = (int)($stat['total_learns'] ?? 0);
        return [[
            'bayes_ham'     => number_format($ham),
            'bayes_spam'    => number_format($spam),
            'bayes_learned' => number_format($learned),
        ]];
    }

    static function getGreylistStats()
    {
        try {
            $keys  = self::redis()->keys('rg*');
            $count = number_format(is_array($keys) ? count($keys) : 0);
        } catch (Exception $e) {
            $count = '?';
        }
        $csrf  = self::getCSFR_Tag();
        return '<p>Entradas en greylist: <strong>' . $count . '</strong></p>'
             . '<form method="post" action="./?module=antispam_admin&action=FlushGreylist&tab=greylist" style="display:inline;">'
             . $csrf
             . '<button type="submit" class="btn btn-warning btn-sm"'
             . ' onclick="return confirm(\'Limpiar greylist?\')"><i class="bi bi-x-circle me-1"></i>Limpiar greylist</button>'
             . '</form>';
    }

    static function getMessageHistory()
    {
        $data = self::rspamdApi('/history?rows=200');
        if (!$data || empty($data['rows'])) {
            return '<p class="text-muted">No hay historial disponible.</p>';
        }

        $actionLabel = [
            'no action'   => ['label' => 'Aceptado',    'cls' => 'success'],
            'add header'  => ['label' => 'Marcado',     'cls' => 'warning'],
            'soft reject' => ['label' => 'Greylist',    'cls' => 'info'],
            'reject'      => ['label' => 'Rechazado',   'cls' => 'danger'],
            'greylist'    => ['label' => 'Greylist',    'cls' => 'info'],
        ];

        $html  = '<div style="overflow-x:auto;">';
        $html .= '<table class="table table-striped table-sm" id="asHistoryTbl" style="font-size:12px;">';
        $html .= '<thead><tr style="background:#f5f5f5;">'
               . '<th>Fecha</th><th>De</th><th>Para</th>'
               . '<th>IP</th><th>Asunto</th><th>Score</th><th>Acción</th>'
               . '</tr></thead><tbody id="asHistoryBody">';

        foreach ($data['rows'] as $row) {
            $date   = date('d/m/Y H:i', (int)($row['unix_time'] ?? 0));
            $from   = htmlspecialchars($row['sender_mime'] ?? $row['sender_smtp'] ?? '-', ENT_QUOTES, 'UTF-8');
            $rcptArr = !empty($row['rcpt_smtp']) ? $row['rcpt_smtp'] : ($row['rcpt_mime'] ?? []);
            $rcpt   = htmlspecialchars(implode(', ', (array)$rcptArr) ?: '-', ENT_QUOTES, 'UTF-8');
            $ip     = htmlspecialchars($row['ip'] ?? '-', ENT_QUOTES, 'UTF-8');
            $subj   = htmlspecialchars($row['subject'] ?? '-', ENT_QUOTES, 'UTF-8');
            $score  = number_format((float)($row['score'] ?? 0), 2);
            $req    = number_format((float)($row['required_score'] ?? 15), 2);
            $action = $row['action'] ?? 'unknown';
            $info   = $actionLabel[$action] ?? ['label' => htmlspecialchars($action, ENT_QUOTES, 'UTF-8'), 'cls' => 'default'];
            $scoreStyle = $action === 'reject'     ? 'color:#c0392b;font-weight:bold;'
                        : ($action === 'add header' ? 'color:#d68910;font-weight:bold;'
                        : ($action === 'soft reject'? 'color:#2471a3;font-weight:bold;'
                        :                             'color:#333;'));

            $html .= '<tr>'
                   . "<td style='white-space:nowrap;'>$date</td>"
                   . "<td style='max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' title='$from'>$from</td>"
                   . "<td style='max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' title='$rcpt'>$rcpt</td>"
                   . "<td style='white-space:nowrap;'>$ip</td>"
                   . "<td style='max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' title='$subj'>$subj</td>"
                   . "<td style='white-space:nowrap;$scoreStyle'><strong>$score</strong> <small class='text-muted'>/ $req</small></td>"
                   . "<td><span class='badge bg-{$info['cls']}'>{$info['label']}</span></td>"
                   . '</tr>';
        }

        $html .= '</tbody></table></div>';
        $html .= '<div id="asHistoryPager" style="margin-top:8px;"></div>';
        return $html;
    }

    // ---- Spamhaus DQS ------------------------------------------------

    const SPAMHAUS_RBL_FILE = '/var/bulwark/rspamd/rbl.conf';

    private static function writeSpamhausRbl(string $key): bool
    {
        $content = "# Bulwark: Spamhaus DQS — generado automáticamente, no editar.\n"
                 . "rbls {\n"
                 . "  spamhaus {\n"
                 . "    enabled = false;\n"
                 . "  }\n"
                 . "  spamhaus_dqs_zen {\n"
                 . "    rbl = \"{$key}.zen.dq.spamhaus.net\";\n"
                 . "    checks = [\"from\", \"received\"];\n"
                 . "    symbols_prefixes {\n"
                 . "      received = \"RECEIVED_DQS\";\n"
                 . "      from     = \"RBL_DQS\";\n"
                 . "    }\n"
                 . "    returncodes {\n"
                 . "      SPAMHAUS_SBL     = \"127.0.0.2\";\n"
                 . "      SPAMHAUS_SBL_CSS = \"127.0.0.3\";\n"
                 . "      SPAMHAUS_XBL     = [\"127.0.0.4\", \"127.0.0.5\", \"127.0.0.6\", \"127.0.0.7\"];\n"
                 . "      SPAMHAUS_PBL     = [\"127.0.0.10\", \"127.0.0.11\"];\n"
                 . "      SPAMHAUS_DROP    = \"127.0.0.9\";\n"
                 . "    }\n"
                 . "  }\n"
                 . "  spamhaus_dqs_dbl {\n"
                 . "    rbl    = \"{$key}.dbl.dq.spamhaus.net\";\n"
                 . "    no_ip  = true;\n"
                 . "    checks = [\"emails\", \"replyto\", \"urls\"];\n"
                 . "    returncodes {\n"
                 . "      DBL_SPAM    = \"127.0.1.2\";\n"
                 . "      DBL_PHISH   = \"127.0.1.4\";\n"
                 . "      DBL_MALWARE = \"127.0.1.5\";\n"
                 . "      DBL_ABUSE   = \"127.0.1.6\";\n"
                 . "    }\n"
                 . "  }\n"
                 . "}\n";
        return (bool)file_put_contents(self::SPAMHAUS_RBL_FILE, $content);
    }

    static function doSaveSpamhaus()
    {
        runtime_csfr::Protect();
        global $controller;
        $enabled  = $controller->GetControllerRequest('FORM', 'inSpamhausEnabled') === '1' ? 1 : 0;
        $keyInput = trim($controller->GetControllerRequest('FORM', 'inSpamhausKey') ?? '');

        // Si el campo viene vacío, conservar el token almacenado en Redis
        if ($keyInput === '') {
            try {
                $keyInput = self::redis()->hGet('bulwark:antispam:spamhaus', 'key') ?? '';
            } catch (Exception $e) {
                $keyInput = '';
            }
        }
        $key = $keyInput;

        if ($enabled) {
            if (!preg_match('/^[a-z0-9]{10,50}$/', $key)) {
                self::$err_msg = 'Introduce un token DQS válido (letras minúsculas y números, 10-50 caracteres).';
                return;
            }
            $testHost = '2.0.0.127.' . $key . '.zen.dq.spamhaus.net';
            $records  = @dns_get_record($testHost, DNS_A);
            if (empty($records)) {
                self::$err_msg = 'Token no válido o sin acceso a Spamhaus DQS. Comprueba el token e inténtalo de nuevo.';
                return;
            }
            if (!self::writeSpamhausRbl($key)) {
                self::$err_msg = 'No se pudo escribir la configuración RBL. Comprueba permisos de /var/bulwark/rspamd/.';
                return;
            }
        } else {
            $key = '';
            @unlink(self::SPAMHAUS_RBL_FILE);
        }

        try {
            self::redis()->hMSet('bulwark:antispam:spamhaus', ['enabled' => $enabled, 'key' => $key]);
        } catch (Exception $e) {
            self::$err_msg = 'Error Redis: ' . $e->getMessage();
            return;
        }

        if (!class_exists('privilege')) {
            require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
        }
        try {
            privilege::run('rspamd_restart', [], true);
            self::$ok_msg = $enabled
                ? 'Spamhaus DQS activado y configurado correctamente.'
                : 'Spamhaus DQS desactivado.';
        } catch (Exception $e) {
            self::$err_msg = 'Error al reiniciar rspamd: ' . $e->getMessage();
        }
    }

    static function getSpamhausConfig()
    {
        try {
            $r       = self::redis();
            $cfg     = $r->hGetAll('bulwark:antispam:spamhaus');
            $enabled = !empty($cfg['enabled']) && $cfg['enabled'] === '1';
            $key     = $cfg['key'] ?? '';
        } catch (Exception $e) {
            $enabled = false;
            $key     = '';
        }

        $checkedOn  = $enabled ? 'checked' : '';
        $checkedOff = $enabled ? '' : 'checked';
        $maskedKey  = $key ? str_repeat('*', max(0, strlen($key) - 6)) . substr($key, -6) : '';
        $csrf       = self::getCSFR_Tag();

        $fileStatus = file_exists(self::SPAMHAUS_RBL_FILE)
            ? '<span class="badge bg-success">Aplicado</span>'
            : '<span class="badge bg-default">No aplicado</span>';

        $html  = '<form method="post" action="./?module=antispam_admin&action=SaveSpamhaus&tab=spamhaus">';
        $html .= $csrf;
        $html .= '<div class="mb-3">';
        $html .= '<label class="col-sm-3 col-form-label">Estado</label>';
        $html .= '<div class="col-sm-6" style="padding-top:7px;">';
        $html .= '<label class="me-3"><input class="form-check-input me-1" type="radio" name="inSpamhausEnabled" value="1" ' . $checkedOn . '> Activado</label>';
        $html .= '&nbsp;&nbsp;<label class="me-3"><input class="form-check-input me-1" type="radio" name="inSpamhausEnabled" value="0" ' . $checkedOff . '> Desactivado</label>';
        $html .= '</div></div>';

        $html .= '<div class="mb-3">';
        $html .= '<label class="col-sm-3 col-form-label">Token DQS</label>';
        $html .= '<div class="col-sm-5">';
        $html .= '<input type="text" name="inSpamhausKey" class="form-control" maxlength="50" '
               . 'placeholder="' . ($maskedKey ? htmlspecialchars($maskedKey, ENT_QUOTES, 'UTF-8') : 'Introduce tu token DQS de spamhaus.com') . '" '
               . 'value="">';
        if ($maskedKey) {
            $html .= '<span class="help-block">Deja vacío para conservar el token actual. Escribe uno nuevo para reemplazarlo.</span>';
        }
        $html .= '<span class="help-block">Regístrate gratis en <strong>spamhaus.com</strong> → Products → DQS para obtener tu token.</span>';
        $html .= '</div></div>';

        $html .= '<div class="mb-3">';
        $html .= '<label class="col-sm-3 col-form-label">Estado en rspamd</label>';
        $html .= '<div class="col-sm-6" style="padding-top:7px;">' . $fileStatus . '</div>';
        $html .= '</div>';

        $html .= '<div class="mb-3"><div class="offset-sm-3 col-sm-9">';
        $html .= '<button type="submit" class="btn btn-primary">';
        $html .= '<span class="bi bi-floppy"></span> Guardar y aplicar</button>';
        $html .= '</div></div></form>';

        return $html;
    }

    static function getGlobalWhitelist()
    {
        try {
            $r     = self::redis();
            $items = $r->sMembers('bulwark:antispam:global:white');
            if (!$items) return false;
            sort($items);
            $csrf = self::getCSFR_Tag();
            return array_map(function($a) use ($csrf) {
                return [
                    'address'     => htmlspecialchars($a, ENT_QUOTES, 'UTF-8'),
                    'remove_form' => '<form method="post" action="./?module=antispam_admin&action=RemoveGlobalList&tab=whitelist" style="display:inline;">'
                                  . '<input type="hidden" name="inType" value="white">'
                                  . '<input type="hidden" name="inAddress" value="' . htmlspecialchars($a, ENT_QUOTES, 'UTF-8') . '">'
                                  . $csrf
                                  . '<button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash me-1"></i>Remove</button></form>',
                ];
            }, $items);
        } catch (Exception $e) { return false; }
    }

    static function getGlobalBlacklist()
    {
        try {
            $r     = self::redis();
            $items = $r->sMembers('bulwark:antispam:global:black');
            if (!$items) return false;
            sort($items);
            $csrf = self::getCSFR_Tag();
            return array_map(function($a) use ($csrf) {
                return [
                    'address'     => htmlspecialchars($a, ENT_QUOTES, 'UTF-8'),
                    'remove_form' => '<form method="post" action="./?module=antispam_admin&action=RemoveGlobalList&tab=blacklist" style="display:inline;">'
                                  . '<input type="hidden" name="inType" value="black">'
                                  . '<input type="hidden" name="inAddress" value="' . htmlspecialchars($a, ENT_QUOTES, 'UTF-8') . '">'
                                  . $csrf
                                  . '<button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash me-1"></i>Remove</button></form>',
                ];
            }, $items);
        } catch (Exception $e) { return false; }
    }

    const RSPAMD_OPTIONS_FILE = '/var/bulwark/rspamd/options.inc';

    private static function writeRspamdOptions(string $primary, string $secondary): bool
    {
        $ns = '"' . $primary . ':53:1"';
        if ($secondary) $ns .= ', "' . $secondary . ':53:1"';
        $content = "dns {\n"
                 . "    nameserver = [{$ns}];\n"
                 . "    timeout = 2s;\n"
                 . "    retransmits = 5;\n"
                 . "    sockets = 16;\n"
                 . "    connections = 4;\n"
                 . "}\n";
        return (bool)file_put_contents(self::RSPAMD_OPTIONS_FILE, $content);
    }

    static function doSaveDnsConfig()
    {
        runtime_csfr::Protect();
        global $controller;
        $primary   = trim($controller->GetControllerRequest('FORM', 'inDnsPrimary') ?? '');
        $secondary = trim($controller->GetControllerRequest('FORM', 'inDnsSecondary') ?? '');

        $ipRegex = '/^(\d{1,3}\.){3}\d{1,3}$/';
        if (!preg_match($ipRegex, $primary)) {
            self::$err_msg = 'DNS primario no válido. Introduce una IP válida (ej: 8.8.8.8).';
            return;
        }
        if ($secondary && !preg_match($ipRegex, $secondary)) {
            self::$err_msg = 'DNS secundario no válido. Introduce una IP válida o déjalo vacío.';
            return;
        }

        if (!self::writeRspamdOptions($primary, $secondary)) {
            self::$err_msg = 'No se pudo escribir la configuración DNS. Comprueba permisos de /var/bulwark/rspamd/.';
            return;
        }

        try {
            self::redis()->hMSet('bulwark:antispam:dns', ['primary' => $primary, 'secondary' => $secondary]);
        } catch (Exception $e) {
            self::$err_msg = 'Error Redis: ' . $e->getMessage();
            return;
        }

        if (!class_exists('privilege')) {
            require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
        }
        try {
            privilege::run('rspamd_restart', [], true);
            self::$ok_msg = 'Configuración DNS guardada. rspamd reiniciado.';
        } catch (Exception $e) {
            self::$err_msg = 'Configuración guardada pero error al reiniciar rspamd: ' . $e->getMessage();
        }
    }

    static function getDnsConfig()
    {
        try {
            $cfg  = self::redis()->hGetAll('bulwark:antispam:dns');
            $pri  = $cfg['primary']   ?? '8.8.8.8';
            $sec  = $cfg['secondary'] ?? '8.8.4.4';
        } catch (Exception $e) {
            $pri = '8.8.8.8';
            $sec = '8.8.4.4';
        }
        $csrf = self::getCSFR_Tag();

        $html  = '<form method="post" action="./?module=antispam_admin&action=SaveDnsConfig&tab=settings">';
        $html .= $csrf;
        $html .= '<div class="mb-3">';
        $html .= '<label class="col-sm-3 col-form-label">DNS primario</label>';
        $html .= '<div class="col-sm-3"><input type="text" name="inDnsPrimary" class="form-control" maxlength="15" '
               . 'value="' . htmlspecialchars($pri, ENT_QUOTES, 'UTF-8') . '" placeholder="8.8.8.8"></div>';
        $html .= '</div>';
        $html .= '<div class="mb-3">';
        $html .= '<label class="col-sm-3 col-form-label">DNS secundario</label>';
        $html .= '<div class="col-sm-3"><input type="text" name="inDnsSecondary" class="form-control" maxlength="15" '
               . 'value="' . htmlspecialchars($sec, ENT_QUOTES, 'UTF-8') . '" placeholder="8.8.4.4"></div>';
        $html .= '<div class="col-sm-6"><p class="help-block" style="margin-top:7px;">Resolvers con recursión que rspamd usa para consultas RBL/DQS. '
               . 'El DNS local del servidor no tiene recursión y no puede resolver zonas externas.</p></div>';
        $html .= '</div>';
        $html .= '<div class="mb-3"><div class="offset-sm-3 col-sm-9">';
        $html .= '<button type="submit" class="btn btn-primary"><span class="bi bi-floppy"></span> Guardar y aplicar</button>';
        $html .= '</div></div></form>';

        return $html;
    }
}

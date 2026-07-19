<?php
/**
 * ClamAV Admin module — antivirus para email y buzones
 */
require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';

class module_controller extends ctrl_module
{
    const REDIS_HOST = '127.0.0.1';
    const REDIS_PORT = 6379;
    const CLAMD_HOST = '127.0.0.1';
    const CLAMD_PORT = 3310;

    // Ficheros dinámicos (www:www 640)
    const CLAMAV_DIR          = '/var/bulwark/clamav';
    const ANTIVIRUS_CONF      = '/var/bulwark/clamav/antivirus.conf';
    const SCAN_LOG            = '/var/bulwark/clamav/scan_results.log';
    const FRESHCLAM_CHECKS    = '/var/bulwark/clamav/freshclam_checks.conf';
    const SCAN_SCHEDULE       = '/var/bulwark/clamav/scan_schedule.conf';
    const SCAN_PATHS_CONF     = '/var/bulwark/clamav/scan_paths.conf';
    const QUARANTINE_DIR      = '/var/bulwark/clamav/quarantine';

    // Rutas predefinidas que el admin puede activar/desactivar
    const SCAN_PATH_OPTIONS   = [
        '/var/mail'                  => 'Correo del sistema (<code>/var/mail/</code>) — buzones mbox de cuentas locales',
        '/var/bulwark/hostdata'      => 'Archivos web (<code>/var/bulwark/hostdata/</code>) — <code>public_html</code> de todos los dominios',
        '/var/bulwark/vmail'         => 'Buzones virtuales (<code>/var/bulwark/vmail/</code>) — correo Dovecot en formato Maildir',
        '/var/bulwark/temp'          => 'Temporales del panel (<code>/var/bulwark/temp/</code>) — archivos subidos pendientes',
        '/var/bulwark/backups'       => 'Copias de seguridad (<code>/var/bulwark/backups/</code>)',
    ];

    // Fichero estático rspamd (root)
    const RSPAMD_ANTIVIRUS    = '/usr/local/etc/rspamd/local.d/antivirus.conf';

    static $ok_msg;
    static $err_msg;

    // ----------------------------------------------------------------
    // Redis
    // ----------------------------------------------------------------

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

    // ----------------------------------------------------------------
    // Helpers clamd
    // ----------------------------------------------------------------

    private static function isClamdRunning(): bool
    {
        $sock = @fsockopen(self::CLAMD_HOST, self::CLAMD_PORT, $errno, $errstr, 1.5);
        if (!$sock) return false;
        fclose($sock);
        return true;
    }

    private static function queryClamd(string $cmd): string
    {
        $sock = @fsockopen(self::CLAMD_HOST, self::CLAMD_PORT, $errno, $errstr, 2.0);
        if (!$sock) return '';
        fwrite($sock, "n{$cmd}\n");
        $resp = '';
        $limit = 20;
        while (!feof($sock) && $limit-- > 0) {
            $chunk = fread($sock, 256);
            if ($chunk === false || $chunk === '') break;
            $resp .= $chunk;
            if (strpos($resp, "\n") !== false) break;
        }
        fclose($sock);
        return trim($resp);
    }

    private static function getDbInfo(): array
    {
        $ver = self::queryClamd('VERSION');
        // Formato: "ClamAV 1.4.1/27486/Thu Jun 26 10:21:45 2025"
        if (!$ver) return ['engine' => '?', 'db_version' => '?', 'db_date' => '?'];
        $parts = explode('/', $ver, 3);
        return [
            'engine'     => isset($parts[0]) ? trim(str_replace('ClamAV', '', $parts[0])) : '?',
            'db_version' => $parts[1] ?? '?',
            'db_date'    => isset($parts[2]) ? trim($parts[2]) : '?',
        ];
    }

    // ----------------------------------------------------------------
    // Escritura config rspamd antivirus
    // ----------------------------------------------------------------

    private static function writeAntivirusConf(bool $enabled, string $action = 'reject'): bool
    {
        if (!$enabled) {
            return @file_put_contents(self::ANTIVIRUS_CONF, "# ClamAV email scanning desactivado\n") !== false;
        }
        $conf  = "# Generado por Bulwark clamav_admin — no editar manualmente\n";
        $conf .= "clamav {\n";
        $conf .= "    action        = \"" . ($action === 'reject' ? 'reject' : 'add header') . "\";\n";
        $conf .= "    scan_mime_parts = true;\n";
        $conf .= "    scan_text_mime  = false;\n";
        $conf .= "    scan_image_mime = false;\n";
        $conf .= "    symbol          = \"CLAM_VIRUS\";\n";
        $conf .= "    type            = \"clamav\";\n";
        $conf .= "    servers         = \"127.0.0.1:3310\";\n";
        $conf .= "    timeout         = 15.0;\n";
        $conf .= "    retransmits     = 2;\n";
        $conf .= "    log_clean       = false;\n";
        $conf .= "}\n";
        return @file_put_contents(self::ANTIVIRUS_CONF, $conf) !== false;
    }

    // ----------------------------------------------------------------
    // Acciones — Email
    // ----------------------------------------------------------------

    static function doToggleEmail()
    {
        runtime_csfr::Protect();
        try {
            $r       = self::redis();
            $current = $r->hGet('bulwark:clamav', 'email_enabled') === '1';
            $new     = !$current;
            $action  = $r->hGet('bulwark:clamav', 'email_action') ?: 'reject';

            if ($new && !self::isClamdRunning()) {
                self::$err_msg = 'clamd no está en ejecución — arranca ClamAV antes de activar la protección de email.';
                return;
            }

            if (!self::writeAntivirusConf($new, $action)) {
                self::$err_msg = 'Error al escribir ' . self::ANTIVIRUS_CONF . ' (permisos www:www?)';
                return;
            }

            $r->hSet('bulwark:clamav', 'email_enabled', $new ? '1' : '0');
            privilege::run('rspamd_reload');
            self::prg(
                $new ? 'Protección antivirus de email activada.' : 'Protección antivirus de email desactivada.',
                'ok', 'email'
            );
        } catch (Exception $e) {
            self::$err_msg = 'Error: ' . $e->getMessage();
        }
    }

    static function doSaveEmailAction()
    {
        runtime_csfr::Protect();
        global $controller;
        $action = $controller->GetControllerRequest('FORM', 'inEmailAction');
        if (!in_array($action, ['reject', 'add header'], true)) {
            self::$err_msg = 'Acción no válida.';
            return;
        }
        try {
            $r       = self::redis();
            $enabled = $r->hGet('bulwark:clamav', 'email_enabled') === '1';
            $r->hSet('bulwark:clamav', 'email_action', $action);
            if ($enabled) {
                if (!self::writeAntivirusConf(true, $action)) {
                    self::$err_msg = 'Error al escribir la configuración.';
                    return;
                }
                privilege::run('rspamd_reload');
            }
            self::prg('Acción guardada: ' . $action . '.', 'ok', 'email');
        } catch (Exception $e) {
            self::$err_msg = 'Error: ' . $e->getMessage();
        }
    }

    // ----------------------------------------------------------------
    // Acciones — Clamd (servicio)
    // ----------------------------------------------------------------

    static function doToggleClamd()
    {
        runtime_csfr::Protect();
        $running = self::isClamdRunning();
        try {
            if ($running) {
                self::writeAntivirusConf(false);
                self::redis()->hSet('bulwark:clamav', 'email_enabled', '0');
                privilege::run('rspamd_reload');
                // Guardar flash ANTES de cerrar la sesión
                $_SESSION['clamav_admin_flash'] = ['type' => 'ok', 'msg' => 'ClamAV detenido y protección de email desactivada.'];
                session_write_close();
                // clamd_stop_bg usa daemon(8) — no bloquea si hay un escaneo activo
                privilege::run('clamd_stop_bg', [], false);
            } else {
                $_SESSION['clamav_admin_flash'] = ['type' => 'ok', 'msg' => 'ClamAV iniciado.'];
                session_write_close();
                privilege::run('clamd_start', [], true);
            }
            header('Location: ./?module=clamav_admin&tab=status');
            exit;
        } catch (Exception $e) {
            self::$err_msg = 'Error: ' . $e->getMessage();
        }
    }

    // ----------------------------------------------------------------
    // Acciones — Escaneo de buzones
    // ----------------------------------------------------------------

    static function doScanNow()
    {
        runtime_csfr::Protect();
        if (!self::isClamdRunning()) {
            self::$err_msg = 'clamd no está en ejecución.';
            return;
        }
        $_SESSION['clamav_admin_flash'] = ['type' => 'ok', 'msg' => 'Escaneo iniciado en segundo plano. Los resultados estarán disponibles en unos minutos.'];
        session_write_close();
        privilege::run('clamd_scan_launch', [], false);
        header('Location: ./?module=clamav_admin&tab=scan');
        exit;
    }

    static function doSaveScanSchedule()
    {
        runtime_csfr::Protect();
        global $controller;
        $freq = $controller->GetControllerRequest('FORM', 'inScanFreq');
        $hour = (int)($controller->GetControllerRequest('FORM', 'inScanHour') ?? 3);
        $hour = max(0, min(23, $hour));

        $schedules = [
            'daily'    => "0 {$hour} * * *",
            'weekly'   => "0 {$hour} * * 0",
            'biweekly' => "0 {$hour} 1,15 * *",
            'monthly'  => "0 {$hour} 1 * *",
            'disable'  => 'disable',
        ];

        if (!isset($schedules[$freq])) {
            self::$err_msg = 'Frecuencia no válida.';
            return;
        }

        if (@file_put_contents(self::SCAN_SCHEDULE, $schedules[$freq] . "\n") === false) {
            self::$err_msg = 'Error al escribir la programación.';
            return;
        }

        privilege::run('clamav_cron_update');
        try { self::redis()->hSet('bulwark:clamav', 'scan_freq', $freq); } catch (Exception $e) {}
        try { self::redis()->hSet('bulwark:clamav', 'scan_hour', (string)$hour); } catch (Exception $e) {}

        self::prg(
            $freq === 'disable' ? 'Escaneo programado desactivado.' : "Escaneo programado: {$freq} a las {$hour}:00.",
            'ok', 'scan'
        );
    }

    // ----------------------------------------------------------------
    // Acciones — Actualizaciones de firmas
    // ----------------------------------------------------------------

    static function doUpdateNow()
    {
        runtime_csfr::Protect();
        $_SESSION['clamav_admin_flash'] = ['type' => 'ok', 'msg' => 'Actualización de firmas iniciada en segundo plano.'];
        session_write_close();
        privilege::run('freshclam_launch', [], false);
        header('Location: ./?module=clamav_admin&tab=update');
        exit;
    }

    static function doSaveUpdateSchedule()
    {
        runtime_csfr::Protect();
        global $controller;
        $checks = (int)($controller->GetControllerRequest('FORM', 'inChecksPerDay') ?? 4);
        $checks = max(1, min(24, $checks));

        if (@file_put_contents(self::FRESHCLAM_CHECKS, $checks . "\n") === false) {
            self::$err_msg = 'Error al guardar la configuración de actualizaciones.';
            return;
        }

        privilege::run('clamav_cron_update');
        try { self::redis()->hSet('bulwark:clamav', 'freshclam_checks', (string)$checks); } catch (Exception $e) {}

        $interval = round(24 / $checks, 1);
        self::prg("Actualizaciones programadas: {$checks} veces al día (cada ~{$interval}h).", 'ok', 'update');
    }

    static function doSaveScanPaths()
    {
        runtime_csfr::Protect();
        $allowed = array_keys(self::SCAN_PATH_OPTIONS);
        $selected = [];
        foreach ($allowed as $path) {
            // El checkbox envía el valor si está marcado
            if (isset($_POST['scanpath_' . md5($path)])) {
                $selected[] = $path;
            }
        }
        // Al menos una ruta obligatoria
        if (empty($selected)) {
            self::$err_msg = 'Selecciona al menos una ruta de escaneo.';
            return;
        }
        if (@file_put_contents(self::SCAN_PATHS_CONF, implode("\n", $selected) . "\n") === false) {
            self::$err_msg = 'Error al guardar las rutas de escaneo.';
            return;
        }
        self::prg('Rutas de escaneo actualizadas.', 'ok', 'scan');
    }

    private static function getScanPaths(): array
    {
        $defaults = ['/var/mail'];
        if (!file_exists(self::SCAN_PATHS_CONF)) return $defaults;
        $lines = @file(self::SCAN_PATHS_CONF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return $defaults;
        $allowed = array_keys(self::SCAN_PATH_OPTIONS);
        $paths = array_filter($lines, fn($p) => in_array(trim($p), $allowed, true));
        return $paths ? array_values($paths) : $defaults;
    }

    // ----------------------------------------------------------------
    // Getters de display
    // ----------------------------------------------------------------

    // ----------------------------------------------------------------
    // Quarantine
    // ----------------------------------------------------------------

    private static function parseQuarantineSignatures(): array
    {
        // Returns: [ quarantine_basename => ['sig' => '...', 'src' => '/original/path'] ]
        $map = [];
        if (!file_exists(self::SCAN_LOG)) return $map;
        $lines = @file(self::SCAN_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return $map;

        $pending = null; // ['sig' => ..., 'src' => ...]
        foreach ($lines as $line) {
            if (preg_match('/^(.+): (.+) FOUND$/', $line, $m)) {
                $pending = ['sig' => $m[2], 'src' => $m[1]];
            } elseif ($pending !== null && preg_match("/moved to '(.+)'$/", $line, $m)) {
                $map[basename($m[1])] = $pending;
                $pending = null;
            } else {
                $pending = null;
            }
        }
        return $map;
    }

    static function getQuarantineContent(): string
    {
        $dir = self::QUARANTINE_DIR;
        if (!is_dir($dir)) {
            return '<div class="alert alert-success"><span class="bi bi-check-circle-fill"></span>'
                . ' La cuarentena está vacía — el directorio se creará al detectarse el primer archivo infectado.</div>';
        }

        $files = array_values(array_filter((array)scandir($dir), function($f) use ($dir) {
            return $f !== '.' && $f !== '..' && is_file($dir . '/' . $f);
        }));

        if (empty($files)) {
            return '<div class="alert alert-success">'
                . '<span class="bi bi-check-circle-fill"></span>'
                . ' La cuarentena está vacía. No se han detectado archivos infectados.</div>';
        }

        $sigs = self::parseQuarantineSignatures();

        usort($files, function($a, $b) use ($dir) {
            return filemtime($dir . '/' . $b) - filemtime($dir . '/' . $a);
        });

        $h  = '<div class="alert alert-warning" style="margin-bottom:15px;">';
        $h .= '<strong>Advertencia:</strong> Los archivos de cuarentena contienen malware real. ';
        $h .= 'Descárgalos solo para análisis forense en un entorno seguro. ';
        $h .= 'Restaura únicamente archivos confirmados como falsos positivos.';
        $h .= '</div>';
        $h .= '<div class="table-responsive">';
        $h .= '<table class="table table-striped table-hover table-sm">';
        $h .= '<thead><tr>';
        $h .= '<th>Nombre original</th><th>Ruta de origen</th><th>Firma detectada</th><th>Tamaño</th><th>Fecha cuarentena</th><th>Acciones</th>';
        $h .= '</tr></thead><tbody>';

        foreach ($files as $f) {
            $path     = $dir . '/' . $f;
            $size     = filesize($path);
            $mtime    = date('d/m/Y H:i', filemtime($path));
            $origName = preg_replace('/\.\d{3}$/', '', $f);
            $sigText  = isset($sigs[$f]['sig'])
                ? htmlspecialchars($sigs[$f]['sig'], ENT_QUOTES, 'UTF-8') : '';
            $srcText  = isset($sigs[$f]['src'])
                ? htmlspecialchars($sigs[$f]['src'], ENT_QUOTES, 'UTF-8') : '';
            $sig      = $sigText !== ''
                ? '<span class="badge bg-danger">' . $sigText . '</span>'
                : '<em class="text-muted">—</em>';
            $fenc     = htmlspecialchars($f, ENT_QUOTES, 'UTF-8');
            $origEnc  = htmlspecialchars($origName, ENT_QUOTES, 'UTF-8');
            $origJs   = addslashes($origName);

            $sizeStr = $size >= 1048576
                ? round($size / 1048576, 1) . ' MB'
                : ($size >= 1024 ? round($size / 1024, 1) . ' KB' : $size . ' B');

            $h .= '<tr>';
            $h .= '<td><code>' . $origEnc . '</code>';
            if ($f !== $origName) $h .= '<br><small class="text-muted">' . $fenc . '</small>';
            $h .= '</td>';
            $h .= '<td>' . ($srcText !== '' ? '<code>' . $srcText . '</code>' : '<em class="text-muted">—</em>') . '</td>';
            $h .= '<td>' . $sig . '</td>';
            $h .= '<td>' . $sizeStr . '</td>';
            $h .= '<td>' . $mtime . '</td>';
            $h .= '<td style="white-space:nowrap;">';

            // Descargar
            $h .= '<a href="./?module=clamav_admin&action=DownloadQuarantine&file=' . urlencode($f) . '" '
                . 'class="btn btn-sm btn-secondary" title="Descargar para análisis (se añade extensión .infected)">'
                . '<span class="bi bi-download"></span> Descargar</a> ';

            // Restaurar
            $h .= '<form method="post" action="./?module=clamav_admin&action=RestoreQuarantine&tab=quarantine" style="display:inline;">'
                . runtime_csfr::Token()
                . '<input type="hidden" name="qfile" value="' . $fenc . '">'
                . '<button type="submit" class="btn btn-sm btn-warning" '
                . 'onclick="return confirm(\'¿Restaurar \\\'' . $origJs . '\\\' a /var/mail/?\\nHazlo solo si confirmas que es un falso positivo.\');" '
                . 'title="Restaurar a /var/mail/ (falso positivo)">'
                . '<span class="bi bi-share-alt"></span> Restaurar</button></form> ';

            // Eliminar
            $h .= '<form method="post" action="./?module=clamav_admin&action=DeleteQuarantine&tab=quarantine" style="display:inline;">'
                . runtime_csfr::Token()
                . '<input type="hidden" name="qfile" value="' . $fenc . '">'
                . '<button type="submit" class="btn btn-sm btn-danger" '
                . 'onclick="return confirm(\'¿Eliminar permanentemente \\\'' . $origJs . '\\\'?\\nEsta acción no se puede deshacer.\');" '
                . 'title="Eliminar permanentemente">'
                . '<span class="bi bi-trash"></span> Eliminar</button></form>';

            $h .= '</td></tr>';
        }

        $h .= '</tbody></table></div>';
        $h .= '<p class="text-muted"><small>'
            . count($files) . ' archivo(s) en cuarentena. '
            . 'Los archivos descargados incluyen la extensión <code>.infected</code> para evitar ejecución accidental.</small></p>';
        return $h;
    }

    static function doDownloadQuarantine()
    {
        $f = isset($_GET['file']) ? basename((string)$_GET['file']) : '';
        if ($f === '' || !preg_match('/^[A-Za-z0-9._@+\-]+$/', $f)) {
            http_response_code(400);
            exit('Nombre de archivo no válido.');
        }
        $path = self::QUARANTINE_DIR . '/' . $f;
        if (!is_file($path)) {
            http_response_code(404);
            exit('Archivo no encontrado en cuarentena.');
        }

        while (ob_get_level() > 0) ob_end_clean();

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $f . '.infected"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache');
        header('Pragma: no-cache');
        readfile($path);
        exit();
    }

    static function doRestoreQuarantine()
    {
        runtime_csfr::Protect();
        $f = isset($_POST['qfile']) ? basename((string)$_POST['qfile']) : '';
        if ($f === '' || !preg_match('/^[A-Za-z0-9._@+\-]+$/', $f)) {
            self::$err_msg = 'Nombre de archivo no válido.';
            return;
        }
        if (!is_file(self::QUARANTINE_DIR . '/' . $f)) {
            self::$err_msg = 'El archivo ya no está en cuarentena.';
            return;
        }

        $reqFile = '/var/bulwark/run/clamav_restore_request';
        if (@file_put_contents($reqFile, $f) === false) {
            self::$err_msg = 'No se pudo escribir la solicitud de restauración en ' . $reqFile;
            return;
        }
        @chmod($reqFile, 0640);

        $origName = preg_replace('/\.\d{3}$/', '', $f);
        $_SESSION['clamav_admin_flash'] = ['type' => 'ok', 'msg' => htmlspecialchars($origName, ENT_QUOTES, 'UTF-8') . ' restaurado a /var/mail/.'];
        session_write_close();
        [$exitCode, $stdout, $stderr] = privilege::run('clamd_quarantine_restore', [], false);
        if ($exitCode !== 0) {
            // No podemos mostrar el error via flash (sesión cerrada), usamos header de emergencia
            header('Location: ./?module=clamav_admin&tab=quarantine&_err=' . urlencode('Error al restaurar (' . $exitCode . ')'));
            exit;
        }
        header('Location: ./?module=clamav_admin&tab=quarantine');
        exit;
    }

    static function doDeleteQuarantine()
    {
        runtime_csfr::Protect();
        $f = isset($_POST['qfile']) ? basename((string)$_POST['qfile']) : '';
        if ($f === '' || !preg_match('/^[A-Za-z0-9._@+\-]+$/', $f)) {
            self::$err_msg = 'Nombre de archivo no válido.';
            return;
        }
        $path = self::QUARANTINE_DIR . '/' . $f;
        if (!is_file($path)) {
            self::$err_msg = 'El archivo ya no existe en cuarentena.';
            return;
        }
        if (!@unlink($path)) {
            self::$err_msg = 'No se pudo eliminar el archivo. Comprueba los permisos del directorio de cuarentena.';
            return;
        }
        $origName = preg_replace('/\.\d{3}$/', '', $f);
        self::prg(htmlspecialchars($origName, ENT_QUOTES, 'UTF-8') . ' eliminado permanentemente de la cuarentena.', 'ok', 'quarantine');
    }

    // ----------------------------------------------------------------
    // Solicitudes de restauración de usuarios
    // ----------------------------------------------------------------

    const RESTORE_REQUESTS = '/var/bulwark/run/restore_requests';

    static function doApproveRestoreRequest()
    {
        runtime_csfr::Protect();
        $reqFile = basename((string)($_POST['reqfile'] ?? ''));
        if ($reqFile === '' || !preg_match('/^[a-zA-Z0-9_\-]+\.json$/', $reqFile)) {
            self::$err_msg = 'Solicitud no válida.';
            return;
        }
        $reqPath = self::RESTORE_REQUESTS . '/' . $reqFile;
        if (!file_exists($reqPath)) {
            self::$err_msg = 'Solicitud no encontrada.';
            return;
        }
        $req = @json_decode(file_get_contents($reqPath), true);
        if (!$req || empty($req['qfile']) || empty($req['user'])) {
            self::$err_msg = 'Solicitud corrupta.';
            return;
        }
        $qfile = $req['qfile'];
        $username = basename($req['user']);
        $qDir = '/var/bulwark/hostdata/' . $username . '/quarantine';
        if (!file_exists($qfile)) {
            self::$err_msg = 'El archivo ya no existe en cuarentena.';
            @unlink($reqPath);
            return;
        }

        // Escribir el request y llamar al script privilegiado de restauración de usuario
        $restoreReq = self::RESTORE_REQUESTS . '/admin_approved_' . md5($qfile);
        file_put_contents($restoreReq, $qfile);
        privilege::run('clamav_user_restore');
        @unlink($reqPath);
        self::prg('Restauración aprobada para ' . htmlspecialchars($username, ENT_QUOTES) . '.', 'ok', 'restorereq');
    }

    static function doRejectRestoreRequest()
    {
        runtime_csfr::Protect();
        $reqFile = basename((string)($_POST['reqfile'] ?? ''));
        if ($reqFile === '' || !preg_match('/^[a-zA-Z0-9_\-]+\.json$/', $reqFile)) {
            self::$err_msg = 'Solicitud no válida.';
            return;
        }
        $reqPath = self::RESTORE_REQUESTS . '/' . $reqFile;
        @unlink($reqPath);
        self::prg('Solicitud rechazada.', 'ok', 'restorereq');
    }

    static function getRestoreRequests(): string
    {
        $reqDir = self::RESTORE_REQUESTS;
        if (!is_dir($reqDir)) {
            return '<p class="text-muted"><em>No hay solicitudes de restauración pendientes.</em></p>';
        }
        $requests = [];
        foreach ((array)scandir($reqDir) as $f) {
            if (!str_ends_with($f, '.json') || str_starts_with($f, 'admin_approved_')) continue;
            $path = $reqDir . '/' . $f;
            $data = @json_decode(file_get_contents($path), true);
            if (!$data || empty($data['user'])) continue;
            $requests[] = ['file' => $f, 'data' => $data];
        }

        if (empty($requests)) {
            return '<p class="text-muted"><em>No hay solicitudes de restauración pendientes.</em></p>';
        }

        $rows = '';
        foreach ($requests as $r) {
            $d    = $r['data'];
            $fenc = htmlspecialchars($r['file'], ENT_QUOTES);
            $user = htmlspecialchars($d['user'] ?? '—', ENT_QUOTES);
            $qname = htmlspecialchars($d['qname'] ?? basename($d['qfile'] ?? '—'), ENT_QUOTES);
            $requested = htmlspecialchars($d['requested'] ?? '—', ENT_QUOTES);
            $rows .= '<tr>
                <td>' . $user . '</td>
                <td><code>' . $qname . '</code></td>
                <td><small>' . $requested . '</small></td>
                <td>
                  <form method="post" action="./?module=clamav_admin&action=ApproveRestoreRequest&tab=restorereq" style="display:inline">
                    <input type="hidden" name="reqfile" value="' . $fenc . '">
                    ' . runtime_csfr::Token() . '
                    <button type="submit" class="btn btn-sm btn-success"
                      onclick="return confirm(\'¿Aprobar y restaurar este archivo?\')">
                      <span class="bi bi-check-lg"></span> Aprobar
                    </button>
                  </form>
                  <form method="post" action="./?module=clamav_admin&action=RejectRestoreRequest&tab=restorereq" style="display:inline">
                    <input type="hidden" name="reqfile" value="' . $fenc . '">
                    ' . runtime_csfr::Token() . '
                    <button type="submit" class="btn btn-sm btn-danger"
                      onclick="return confirm(\'¿Rechazar esta solicitud?\')">
                      <span class="bi bi-x-lg"></span> Rechazar
                    </button>
                  </form>
                </td>
              </tr>';
        }

        return '<table class="table table-striped table-sm">
          <thead><tr>
            <th>Usuario</th><th>Archivo</th><th>Solicitado</th><th>Acción</th>
          </tr></thead>
          <tbody>' . $rows . '</tbody>
        </table>';
    }

    // ----------------------------------------------------------------
    // Result
    // ----------------------------------------------------------------

    static function getResult()
    {
        if (self::$err_msg)
            return ui_sysmessage::shout(ui_language::translate(self::$err_msg), 'zannounceerror');
        if (self::$ok_msg)
            return ui_sysmessage::shout(ui_language::translate(self::$ok_msg), 'zannounceok');
        if (!empty($_SESSION['clamav_admin_flash'])) {
            $flash = $_SESSION['clamav_admin_flash'];
            unset($_SESSION['clamav_admin_flash']);
            $cls = ($flash['type'] ?? 'ok') === 'ok' ? 'zannounceok' : 'zannounceerror';
            return ui_sysmessage::shout(ui_language::translate($flash['msg'] ?? ''), $cls);
        }
        return '';
    }

    private static function prg(string $msg, string $type, string $tab): void
    {
        $_SESSION['clamav_admin_flash'] = ['type' => $type, 'msg' => $msg];
        header('Location: ./?module=clamav_admin&tab=' . rawurlencode($tab));
        exit;
    }

    static function getClamStatus()
    {
        $running   = self::isClamdRunning();
        $db        = $running ? self::getDbInfo() : ['engine' => '?', 'db_version' => '?', 'db_date' => '?'];
        $emailOn   = false;
        $scanFreq  = 'disable';
        $lastScan  = '—';
        try {
            $r       = self::redis();
            $emailOn = $r->hGet('bulwark:clamav', 'email_enabled') === '1';
            $scanFreq = $r->hGet('bulwark:clamav', 'scan_freq') ?: 'disable';
        } catch (Exception $e) {}

        // Última línea del log de escaneo
        if (file_exists(self::SCAN_LOG) && is_readable(self::SCAN_LOG)) {
            $lines = @file(self::SCAN_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines) {
                foreach (array_reverse($lines) as $line) {
                    if (strpos($line, '=== Escaneo finalizado') !== false) {
                        $lastScan = htmlspecialchars(trim($line), ENT_QUOTES, 'UTF-8');
                        break;
                    }
                }
            }
        }

        $svcClass = $running ? 'success' : 'danger';
        $svcLabel = $running ? 'Activo' : 'Detenido';
        $emailClass = $emailOn ? 'success' : 'default';
        $emailLabel = $emailOn ? 'Activo' : 'Inactivo';

        $btnLabel = $running ? 'Detener ClamAV' : 'Arrancar ClamAV';
        $btnClass = $running ? 'btn-danger' : 'btn-success';

        $h  = '<div class="row">';

        // Card 1 — Servicio
        $h .= '<div class="col-md-3"><div class="card border-' . $svcClass . '">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Servicio clamd</h4></div>';
        $h .= '<div class="card-body" style="text-align:center;">';
        $h .= '<span class="badge bg-' . $svcClass . '" style="font-size:15px;padding:6px 14px;">' . $svcLabel . '</span>';
        $h .= '<br><br>';
        $h .= '<form method="post" action="./?module=clamav_admin&action=ToggleClamd&tab=status">'
            . runtime_csfr::Token()
            . '<button type="submit" class="btn btn-sm ' . $btnClass . '">' . $btnLabel . '</button>'
            . '</form>';
        $h .= '</div></div></div>';

        // Card 2 — Protección email
        $h .= '<div class="col-md-3"><div class="card border-' . $emailClass . '">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Protección email</h4></div>';
        $h .= '<div class="card-body" style="text-align:center;">';
        $h .= '<span class="badge bg-' . $emailClass . '" style="font-size:15px;padding:6px 14px;">' . $emailLabel . '</span>';
        $h .= '</div></div></div>';

        // Card 3 — Base de firmas
        $h .= '<div class="col-md-3"><div class="card">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Base de firmas</h4></div>';
        $h .= '<div class="card-body">';
        $h .= '<small class="text-muted">Motor</small><br><strong>' . htmlspecialchars($db['engine']) . '</strong><br>';
        $h .= '<small class="text-muted">Versión DB</small><br><strong>' . htmlspecialchars($db['db_version']) . '</strong><br>';
        $h .= '<small class="text-muted">Fecha DB</small><br><span style="font-size:12px;">' . htmlspecialchars($db['db_date']) . '</span>';
        $h .= '</div></div></div>';

        // Card 4 — Último escaneo
        $h .= '<div class="col-md-3"><div class="card">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Último escaneo buzones</h4></div>';
        $h .= '<div class="card-body">';
        $h .= '<span style="font-size:12px;">' . $lastScan . '</span>';
        $h .= '<br><br>';
        $h .= '<small class="text-muted">Programación: <strong>' . htmlspecialchars($scanFreq) . '</strong></small>';
        $h .= '</div></div></div>';

        $h .= '</div>';
        return $h;
    }

    static function getEmailConfig()
    {
        $emailOn = false;
        $action  = 'reject';
        try {
            $r       = self::redis();
            $emailOn = $r->hGet('bulwark:clamav', 'email_enabled') === '1';
            $action  = $r->hGet('bulwark:clamav', 'email_action') ?: 'reject';
        } catch (Exception $e) {}

        $running = self::isClamdRunning();
        $btnLabel = $emailOn ? 'Desactivar protección email' : 'Activar protección email';
        $btnClass = $emailOn ? 'btn-warning' : 'btn-success';

        $h  = '<div class="card">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Estado de protección</h4></div>';
        $h .= '<div class="card-body">';
        $h .= '<p class="text-muted">Cuando está activa, rspamd pasa cada email entrante a clamd para análisis. '
            . 'Si se detecta un virus, rspamd actúa según la acción configurada.</p>';

        if (!$running) {
            $h .= '<div class="alert alert-danger"><strong>clamd no está en ejecución.</strong> '
                . 'Ve a la pestaña <em>Estado</em> y arranca el servicio antes de activar la protección de email.</div>';
        }

        $h .= '<form method="post" action="./?module=clamav_admin&action=ToggleEmail&tab=email">';
        $h .= runtime_csfr::Token();
        $h .= '<button type="submit" class="btn ' . $btnClass . '"' . (!$running && !$emailOn ? ' disabled' : '') . '>'
            . $btnLabel . '</button>';
        $h .= '</form>';
        $h .= '</div></div>';

        // Acción al detectar virus
        $selReject = $action === 'reject'     ? ' selected' : '';
        $selHeader = $action === 'add header' ? ' selected' : '';

        $h .= '<div class="card" style="margin-top:15px;">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Acción al detectar virus</h4></div>';
        $h .= '<div class="card-body">';
        $h .= '<p class="text-muted">Define qué hace rspamd cuando clamd detecta un virus en un email.</p>';
        $h .= '<form method="post" action="./?module=clamav_admin&action=SaveEmailAction&tab=email">';
        $h .= runtime_csfr::Token();
        $h .= '<div class="mb-3"><label class="col-sm-3 col-form-label">Acción</label>';
        $h .= '<div class="col-sm-5"><select name="inEmailAction" class="form-control">';
        $h .= '<option value="reject"' . $selReject . '>Reject — rechazar el email (recomendado)</option>';
        $h .= '<option value="add header"' . $selHeader . '>Add header — entregar con aviso X-Virus</option>';
        $h .= '</select></div></div>';
        $h .= '<div class="mb-3"><div class="col-sm-9 offset-sm-3">';
        $h .= '<button type="submit" class="btn btn-primary">'
            . '<span class="bi bi-floppy"></span>&nbsp; Guardar acción</button>';
        $h .= '</div></div></form>';
        $h .= '</div></div>';

        // Símbolo generado
        $h .= '<div class="card" style="margin-top:15px;">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Símbolo rspamd</h4></div>';
        $h .= '<div class="card-body">';
        $h .= '<table class="table table-sm" style="margin-bottom:0;">';
        $h .= '<tr><th style="width:40%">Símbolo</th><th>Significado</th></tr>';
        $h .= '<tr><td><code>CLAM_VIRUS</code></td><td>Virus detectado por ClamAV — acción configurada arriba</td></tr>';
        $h .= '</table>';
        $h .= '</div></div>';

        return $h;
    }

    private static function isScanRunning(): bool
    {
        $lock = self::CLAMAV_DIR . '/scan.lock';
        if (!file_exists($lock)) return false;
        $pid = (int)@file_get_contents($lock);
        if ($pid <= 0) return false;
        // FreeBSD: /proc/<pid>/status existe si el proceso está vivo
        // Linux: /proc/<pid> también funciona
        return file_exists('/proc/' . $pid . '/status') || file_exists('/proc/' . $pid);
    }

    static function getScanConfig()
    {
        $freq = 'weekly';
        $hour = 3;
        try {
            $r    = self::redis();
            $freq = $r->hGet('bulwark:clamav', 'scan_freq') ?: 'weekly';
            $hour = (int)($r->hGet('bulwark:clamav', 'scan_hour') ?: 3);
        } catch (Exception $e) {}

        $running    = self::isClamdRunning();
        $scanning   = self::isScanRunning();

        $h  = '<div class="card">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Escaneo manual</h4></div>';
        $h .= '<div class="card-body">';

        // Banner de escaneo activo
        if ($scanning) {
            $h .= '<div class="alert alert-info" style="margin-bottom:12px;">'
                . '<span class="bi bi-arrow-clockwise" style="animation:spin 1.2s linear infinite;"></span>'
                . '&nbsp; <strong>Escaneo en curso.</strong> Los resultados aparecerán en la pestaña '
                . '<em>Resultados</em> cuando termine. Puedes recargar la página para actualizar el estado.'
                . '</div>'
                . '<style>@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}'
                . '.bi-arrow-clockwise{display:inline-block;}</style>';
        }

        $activePaths = self::getScanPaths();
        $pathList    = implode(', ', array_map(fn($p) => '<code>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</code>', $activePaths));
        $h .= '<p class="text-muted">Analiza las rutas configuradas en busca de archivos infectados. '
            . 'El escaneo se ejecuta en segundo plano y los resultados aparecen en la pestaña <em>Resultados</em>.</p>';
        $h .= '<p><strong>Rutas activas:</strong> ' . $pathList . '</p>';
        $h .= '<form method="post" action="./?module=clamav_admin&action=ScanNow&tab=scan">';
        $h .= runtime_csfr::Token();
        $scanDisabled = (!$running || $scanning) ? ' disabled' : '';
        $h .= '<button type="submit" class="btn btn-primary"' . $scanDisabled . '>'
            . '<span class="bi bi-search"></span>&nbsp; Escanear ahora</button>';
        if (!$running) {
            $h .= '&nbsp;<span class="text-danger">clamd debe estar en ejecución</span>';
        } elseif ($scanning) {
            $h .= '&nbsp;<span class="text-info">Escaneo activo — espera a que termine</span>';
        }
        $h .= '</form>';
        $h .= '</div></div>';

        // Panel de selección de rutas
        $h .= '<div class="card" style="margin-top:15px;">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Rutas de escaneo</h4></div>';
        $h .= '<div class="card-body">';
        $h .= '<p class="text-muted">Selecciona qué directorios analiza ClamAV. '
            . 'Marca al menos uno. Las rutas se escanean en cada ejecución manual y programada.</p>';
        $h .= '<form method="post" action="./?module=clamav_admin&action=SaveScanPaths&tab=scan">';
        $h .= runtime_csfr::Token();
        foreach (self::SCAN_PATH_OPTIONS as $path => $desc) {
            $key     = 'scanpath_' . md5($path);
            $checked = in_array($path, $activePaths, true) ? ' checked' : '';
            $exists  = is_dir($path);
            $badge   = $exists
                ? '<span class="badge bg-success" style="font-size:11px;">existe</span>'
                : '<span class="badge bg-default" style="font-size:11px;">no encontrado</span>';
            $h .= '<div class="checkbox" style="margin-bottom:6px;">';
            $h .= '<label>';
            $h .= '<input type="checkbox" name="' . $key . '" value="1"' . $checked
                . ($exists ? '' : ' disabled') . '> ';
            $h .= $desc . ' ' . $badge;
            $h .= '</label></div>';
        }
        $h .= '<br><button type="submit" class="btn btn-secondary">'
            . '<span class="bi bi-floppy"></span>&nbsp; Guardar rutas</button>';
        $h .= '</form>';
        $h .= '</div></div>';

        // Programación
        $freqs = [
            'daily'    => 'Diario',
            'weekly'   => 'Semanal (domingo)',
            'biweekly' => 'Quincenal (días 1 y 15)',
            'monthly'  => 'Mensual (día 1)',
            'disable'  => 'Desactivado',
        ];

        $h .= '<div class="card" style="margin-top:15px;">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Programación de escaneo</h4></div>';
        $h .= '<div class="card-body">';
        $h .= '<form method="post" action="./?module=clamav_admin&action=SaveScanSchedule&tab=scan">';
        $h .= runtime_csfr::Token();

        $h .= '<div class="mb-3"><label class="col-sm-3 col-form-label">Frecuencia</label>';
        $h .= '<div class="col-sm-5"><select name="inScanFreq" class="form-control">';
        foreach ($freqs as $val => $label) {
            $sel = $val === $freq ? ' selected' : '';
            $h .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
        }
        $h .= '</select></div></div>';

        $h .= '<div class="mb-3"><label class="col-sm-3 col-form-label">Hora (0-23)</label>';
        $h .= '<div class="col-sm-3"><input type="number" name="inScanHour" min="0" max="23" '
            . 'value="' . $hour . '" class="form-control"></div></div>';

        $h .= '<div class="mb-3"><div class="col-sm-9 offset-sm-3">';
        $h .= '<button type="submit" class="btn btn-primary">'
            . '<span class="bi bi-floppy"></span>&nbsp; Guardar programación</button>';
        $h .= '</div></div></form>';
        $h .= '</div></div>';

        return $h;
    }

    static function getScanResults()
    {
        $logFile = self::SCAN_LOG;
        if (!file_exists($logFile) || !is_readable($logFile)) {
            return '<div class="alert alert-info">No hay resultados de escaneo disponibles todavía.</div>';
        }

        $content = @file_get_contents($logFile);
        if ($content === false || trim($content) === '') {
            return '<div class="alert alert-info">El log de escaneo está vacío.</div>';
        }

        // Mostrar solo los últimos ~150 líneas para no saturar
        $lines = explode("\n", $content);
        $total = count($lines);
        if ($total > 150) {
            $lines = array_slice($lines, -150);
            $truncNote = '<p class="text-muted"><small>Mostrando las últimas 150 líneas de ' . $total . ' totales.</small></p>';
        } else {
            $truncNote = '';
        }

        // Colorear líneas importantes
        $html = '';
        foreach ($lines as $line) {
            $escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
            if (strpos($line, 'FOUND') !== false) {
                $html .= '<span class="text-danger"><strong>' . $escaped . '</strong></span>' . "\n";
            } elseif (strpos($line, '===') !== false) {
                $html .= '<span class="text-primary"><strong>' . $escaped . '</strong></span>' . "\n";
            } elseif (strpos($line, 'Infected files: 0') !== false) {
                $html .= '<span class="text-success"><strong>' . $escaped . '</strong></span>' . "\n";
            } elseif (strpos($line, 'Infected files:') !== false) {
                $html .= '<span class="text-danger"><strong>' . $escaped . '</strong></span>' . "\n";
            } else {
                $html .= $escaped . "\n";
            }
        }

        return $truncNote
            . '<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:4px;'
            . 'font-size:12px;max-height:450px;overflow-y:auto;">' . $html . '</pre>';
    }

    static function getUpdateConfig()
    {
        $checks = 4;
        try {
            $val = self::redis()->hGet('bulwark:clamav', 'freshclam_checks');
            if ($val !== false && $val !== '') $checks = (int)$val;
        } catch (Exception $e) {}

        $running = self::isClamdRunning();
        $interval = $checks > 0 ? round(24 / $checks, 1) : '?';

        $h  = '<div class="card">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Actualizar ahora</h4></div>';
        $h .= '<div class="card-body">';
        $h .= '<p class="text-muted">Ejecuta <code>freshclam</code> manualmente para descargar las últimas firmas. '
            . 'El proceso se lanza en segundo plano. Tras completarse, ClamAV carga las nuevas firmas automáticamente.</p>';
        $h .= '<form method="post" action="./?module=clamav_admin&action=UpdateNow&tab=updates">';
        $h .= runtime_csfr::Token();
        $h .= '<button type="submit" class="btn btn-primary">'
            . '<span class="bi bi-arrow-clockwise"></span>&nbsp; Actualizar firmas ahora</button>';
        $h .= '</form>';
        $h .= '</div></div>';

        // Programación automática
        $h .= '<div class="card" style="margin-top:15px;">';
        $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Frecuencia de actualización automática</h4></div>';
        $h .= '<div class="card-body">';
        $h .= '<p class="text-muted">freshclam se ejecuta como servicio y comprueba nuevas firmas N veces al día. '
            . 'Configuración actual: <strong>' . $checks . ' veces/día</strong> (cada ~' . $interval . 'h).</p>';
        $h .= '<form method="post" action="./?module=clamav_admin&action=SaveUpdateSchedule&tab=updates">';
        $h .= runtime_csfr::Token();

        $options = [1=>'1 vez/día (cada 24h)', 2=>'2 veces/día (cada 12h)',
                    4=>'4 veces/día (cada 6h)', 6=>'6 veces/día (cada 4h)',
                    8=>'8 veces/día (cada 3h)', 12=>'12 veces/día (cada 2h)',
                    24=>'24 veces/día (cada hora)'];

        $h .= '<div class="mb-3"><label class="col-sm-4 col-form-label">Comprobaciones por día</label>';
        $h .= '<div class="col-sm-5"><select name="inChecksPerDay" class="form-control">';
        foreach ($options as $val => $label) {
            $sel = $val === $checks ? ' selected' : '';
            $h .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
        }
        $h .= '</select></div></div>';
        $h .= '<div class="mb-3"><div class="col-sm-9 offset-sm-4">';
        $h .= '<button type="submit" class="btn btn-primary">'
            . '<span class="bi bi-floppy"></span>&nbsp; Guardar frecuencia</button>';
        $h .= '</div></div></form>';
        $h .= '</div></div>';

        // Info firmas actuales
        if ($running) {
            $db = self::getDbInfo();
            $h .= '<div class="card" style="margin-top:15px;">';
            $h .= '<div class="card-header"><h4 class="card-title" style="margin:0;">Estado actual de firmas</h4></div>';
            $h .= '<div class="card-body">';
            $h .= '<table class="table table-sm" style="margin-bottom:0;">';
            $h .= '<tr><th style="width:40%">Campo</th><th>Valor</th></tr>';
            $h .= '<tr><td>Versión motor</td><td>' . htmlspecialchars($db['engine']) . '</td></tr>';
            $h .= '<tr><td>Versión DB</td><td>' . htmlspecialchars($db['db_version']) . '</td></tr>';
            $h .= '<tr><td>Fecha DB</td><td>' . htmlspecialchars($db['db_date']) . '</td></tr>';
            $h .= '</table>';
            $h .= '</div></div>';
        }

        return $h;
    }
}

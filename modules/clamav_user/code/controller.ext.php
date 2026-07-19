<?php
require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';

/**
 * Módulo Antivirus — vista de usuario
 * Permite al usuario ver su cuarentena, descargar archivos infectados,
 * solicitar restauraciones al administrador y lanzar escaneos de su propio
 * directorio (límite: 1 escaneo cada 2 horas por usuario).
 */
class module_controller extends ctrl_module
{
    const QUARANTINE_SUBDIR  = 'quarantine';
    const RESTORE_REQUESTS   = '/var/bulwark/run/restore_requests';
    const SCAN_REQUESTS      = '/var/bulwark/run/scan_requests';
    const SCAN_COOLDOWN      = 7200; // 2 horas en segundos
    const REDIS_HOST         = '127.0.0.1';
    const REDIS_PORT         = 6379;

    private static string $ok_msg  = '';
    private static string $err_msg = '';

    static function getResult(): string
    {
        if (self::$err_msg !== '')
            return ui_sysmessage::shout(htmlspecialchars(self::$err_msg, ENT_QUOTES), 'zannounceerror');
        if (self::$ok_msg !== '')
            return ui_sysmessage::shout(htmlspecialchars(self::$ok_msg, ENT_QUOTES), 'zannounceok');
        return '';
    }

    // -----------------------------------------------------------------------
    // Acciones POST
    // -----------------------------------------------------------------------

    static function doDownloadQuarantine(): bool
    {
        $currentuser = ctrl_users::GetUserDetail();
        $username    = $currentuser['username'];
        $qname       = self::sanitizeQname(self::GetFormValue('qname'));
        if (!$qname) return false;

        $qDir = self::userQuarantineDir($username);
        $file = $qDir . '/' . $qname;
        if (!file_exists($file) || !is_file($file)) return false;

        $meta = self::readMeta($file);
        $orig = $meta ? basename($meta['original_path']) : $qname;
        $dl   = $orig . '.infected';

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addslashes($dl) . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-store');
        readfile($file);
        exit;
    }

    static function doRequestRestore(): bool
    {
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $username    = $currentuser['username'];
        $qname       = self::sanitizeQname(self::GetFormValue('qname'));
        if (!$qname) return false;

        $qDir = self::userQuarantineDir($username);
        $file = $qDir . '/' . $qname;
        if (!file_exists($file)) return false;

        $reqDir = self::RESTORE_REQUESTS;
        if (!is_dir($reqDir)) mkdir($reqDir, 0750, true);

        $reqFile = $reqDir . '/' . $username . '_' . md5($qname) . '.json';
        $data = [
            'user'      => $username,
            'qname'     => $qname,
            'qfile'     => $file,
            'requested' => date('c'),
            'status'    => 'pending',
        ];
        file_put_contents($reqFile, json_encode($data, JSON_PRETTY_PRINT));
        chmod($reqFile, 0640);
        self::$ok_msg = 'Solicitud de restauración enviada al administrador.';
        return true;
    }

    static function doDeleteQuarantine(): bool
    {
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $username    = $currentuser['username'];

        $qDir    = self::userQuarantineDir($username);
        $realDir = realpath($qDir);
        if ($realDir === false) return false;

        // basename evita path traversal; realpath confirma que queda dentro del dir
        $qname = basename((string) self::GetFormValue('qname'));
        if ($qname === '' || $qname === '.' || $qname === '..') return false;
        $real = realpath($realDir . '/' . $qname);
        if ($real === false || strpos($real, $realDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
            return false;
        }

        @unlink($real);
        @unlink($real . '.json'); // metadato sidecar
        $reqFile = self::RESTORE_REQUESTS . '/' . $username . '_' . md5($qname) . '.json';
        if (is_file($reqFile)) @unlink($reqFile); // solicitud de restauración pendiente

        self::refreshQuarantineCount($username);
        self::$ok_msg = 'Archivo eliminado de la cuarentena.';
        return true;
    }

    static function doDeleteAllQuarantine(): bool
    {
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $username    = $currentuser['username'];

        $qDir    = self::userQuarantineDir($username);
        $realDir = realpath($qDir);
        if ($realDir === false) {
            self::$err_msg = 'No hay archivos en cuarentena.';
            return false;
        }

        $n = 0;
        foreach (self::listQuarantineFiles($qDir) as $qname) {
            $real = realpath($realDir . '/' . $qname);
            if ($real === false || strpos($real, $realDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
                continue;
            }
            @unlink($real);
            @unlink($real . '.json');
            $reqFile = self::RESTORE_REQUESTS . '/' . $username . '_' . md5($qname) . '.json';
            if (is_file($reqFile)) @unlink($reqFile);
            $n++;
        }

        self::refreshQuarantineCount($username);
        self::$ok_msg = "Se eliminaron $n archivo(s) de la cuarentena.";
        return true;
    }

    static function doRequestScan(): bool
    {
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $username    = $currentuser['username'];

        // Comprobar cooldown
        $remaining = self::scanCooldownRemaining($username);
        if ($remaining > 0) {
            $min = ceil($remaining / 60);
            self::$err_msg = "Debes esperar $min minuto(s) antes de lanzar otro escaneo.";
            return false;
        }

        // Validar que el directorio del usuario existe
        $userDir = '/var/bulwark/hostdata/' . $username;
        if (!is_dir($userDir)) {
            self::$err_msg = 'No se encontró tu directorio de alojamiento.';
            return false;
        }

        // Escribir fichero de solicitud
        $reqDir = self::SCAN_REQUESTS;
        if (!is_dir($reqDir)) mkdir($reqDir, 0750, true);
        $reqFile = $reqDir . '/' . $username . '.req';
        file_put_contents($reqFile, $username);
        chmod($reqFile, 0640);

        // Llamar al script privilegiado
        privilege::run('clamav_user_scan');

        // Registrar timestamp del escaneo
        self::setScanTimestamp($username);

        self::$ok_msg = 'Escaneo iniciado. Los resultados aparecerán en la cuarentena si se detecta algún archivo infectado. Puedes cerrar esta página.';
        return true;
    }

    // -----------------------------------------------------------------------
    // Vistas
    // -----------------------------------------------------------------------

    static function getQuarantineAlert(): string
    {
        $currentuser = ctrl_users::GetUserDetail();
        $count       = self::countQuarantine($currentuser['username']);
        if ($count === 0) return '';

        $s = $count === 1 ? 'archivo infectado' : 'archivos infectados';
        return ui_sysmessage::shout(
            "Tienes <strong>$count $s</strong> en cuarentena. Revísalos en la tabla inferior.",
            'zannounceimportant'
        );
    }

    static function getScanPanel(): string
    {
        $currentuser = ctrl_users::GetUserDetail();
        $username    = $currentuser['username'];
        $remaining   = self::scanCooldownRemaining($username);

        $lastScan = self::lastScanTime($username);
        $lastStr  = $lastScan ? date('d/m/Y H:i', $lastScan) : 'Nunca';

        if ($remaining > 0) {
            $min  = ceil($remaining / 60);
            $pct  = round((1 - $remaining / self::SCAN_COOLDOWN) * 100);
            $next = date('H:i', time() + $remaining);
            $btn  = '<button class="btn btn-secondary" disabled>'
                  . '<span class="bi bi-hourglass"></span>'
                  . " Disponible a las $next (en $min min)"
                  . '</button>';
            $bar  = '<div class="progress" style="margin-top:8px;margin-bottom:0;height:6px;">'
                  . '<div class="progress-bar" style="width:' . $pct . '%"></div>'
                  . '</div>';
        } else {
            $btn = '<button type="submit" name="action" value="RequestScan" class="btn btn-primary">'
                 . '<span class="bi bi-search"></span> Escanear mis archivos'
                 . '</button>';
            $bar = '';
        }

        return '<div class="card" style="margin-bottom:20px;">'
             . '<div class="card-header"><strong><span class="bi bi-search"></span> Escaneo bajo demanda</strong></div>'
             . '<div class="card-body">'
             . '<p>Analiza los archivos de tu alojamiento en busca de malware. '
             . 'Los archivos infectados se moverán a cuarentena y recibirás un email de notificación.</p>'
             . '<p class="text-muted" style="margin-bottom:10px;"><small>Último escaneo: <strong>' . htmlspecialchars($lastStr, ENT_QUOTES) . '</strong>'
             . ' &nbsp;·&nbsp; Límite: 1 escaneo cada 2 horas</small></p>'
             . '<form method="post" action="./?module=clamav_user&action=RequestScan">'
             . runtime_csfr::Token()
             . $btn
             . '</form>'
             . $bar
             . '</div>'
             . '</div>';
    }

    static function getQuarantineContent(): string
    {
        $currentuser = ctrl_users::GetUserDetail();
        $username    = $currentuser['username'];
        $qDir        = self::userQuarantineDir($username);
        $files       = self::listQuarantineFiles($qDir);

        if (empty($files)) {
            return '<p class="text-muted"><em>No hay archivos en cuarentena.</em></p>';
        }

        $rows = '';
        foreach ($files as $qname) {
            $file = $qDir . '/' . $qname;
            $meta = self::readMeta($file);
            $orig = $meta ? htmlspecialchars(basename($meta['original_path']), ENT_QUOTES) : htmlspecialchars($qname, ENT_QUOTES);
            $sig  = $meta ? htmlspecialchars($meta['signature'],         ENT_QUOTES) : '—';
            $dom  = $meta ? htmlspecialchars($meta['domain_name'] ?? '—', ENT_QUOTES) : '—';
            $at   = $meta ? htmlspecialchars($meta['quarantined_at'] ?? '—', ENT_QUOTES) : '—';
            $sz   = self::humanSize(filesize($file));
            $qenc = htmlspecialchars($qname, ENT_QUOTES);

            $restoreBtn = self::restoreRequestExists($username, $qname)
                ? '<span class="badge bg-warning">Restauración solicitada</span>'
                : '<form method="post" action="./?module=clamav_user&action=RequestRestore" style="display:inline">'
                  . runtime_csfr::Token()
                  . '<input type="hidden" name="qname" value="' . $qenc . '">'
                  . '<button type="submit" class="btn btn-sm btn-warning"'
                  . ' onclick="return confirm(\'¿Solicitar restauración al administrador?\')">'
                  . '<span class="bi bi-share"></span> Restaurar'
                  . '</button></form>';

            $rows .= '<tr>'
                . '<td>' . $orig . '</td>'
                . '<td>' . $dom . '</td>'
                . '<td><small>' . $sig . '</small></td>'
                . '<td>' . $sz . '</td>'
                . '<td><small>' . $at . '</small></td>'
                . '<td>'
                . '<form method="post" action="./?module=clamav_user&action=DownloadQuarantine" style="display:inline">'
                . runtime_csfr::Token()
                . '<input type="hidden" name="qname" value="' . $qenc . '">'
                . '<button type="submit" class="btn btn-sm btn-secondary">'
                . '<span class="bi bi-download"></span> Descargar'
                . '</button></form> '
                . $restoreBtn
                . ' <form method="post" action="./?module=clamav_user&action=DeleteQuarantine" style="display:inline">'
                . runtime_csfr::Token()
                . '<input type="hidden" name="qname" value="' . $qenc . '">'
                . '<button type="submit" class="btn btn-sm btn-danger"'
                . ' onclick="return confirm(\'¿Eliminar definitivamente este archivo en cuarentena? Esta acción no se puede deshacer.\')">'
                . '<span class="bi bi-trash"></span> Borrar'
                . '</button></form>'
                . '</td></tr>';
        }

        $deleteAll = '<div class="d-flex justify-content-end" style="margin-bottom:10px;">'
            . '<form method="post" action="./?module=clamav_user&action=DeleteAllQuarantine" style="display:inline">'
            . runtime_csfr::Token()
            . '<button type="submit" class="btn btn-sm btn-danger"'
            . ' onclick="return confirm(\'¿Eliminar TODOS los archivos en cuarentena? Esta acción no se puede deshacer.\')">'
            . '<span class="bi bi-trash"></span> Borrar todos los detectados'
            . '</button></form></div>';

        return $deleteAll
            . '<div class="table-responsive"><table class="table table-striped table-sm">'
            . '<thead><tr>'
            . '<th>Nombre original</th><th>Dominio</th><th>Firma detectada</th>'
            . '<th>Tamaño</th><th>Fecha cuarentena</th><th>Acciones</th>'
            . '</tr></thead>'
            . '<tbody id="clamav-qtbody">' . $rows . '</tbody>'
            . '</table>
</div>'
            . '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2" style="margin-top:10px;">'
            . '<div id="clamav-qpager" class="d-flex align-items-center flex-wrap gap-1"></div>'
            . '<div class="d-flex align-items-center gap-2">'
            . '<label for="clamav-qperpage" class="text-muted" style="font-size:12px;margin:0;">Por página:</label>'
            . '<select id="clamav-qperpage" class="form-select form-select-sm" style="width:auto;">'
            . '<option value="10">10</option><option value="25" selected>25</option>'
            . '<option value="50">50</option><option value="100">100</option>'
            . '<option value="99999">Todos</option>'
            . '</select></div></div>';
    }

    static function getQuarantineCount(): int
    {
        $currentuser = ctrl_users::GetUserDetail();
        return self::countQuarantine($currentuser['username']);
    }

    // -----------------------------------------------------------------------
    // Helpers — cooldown
    // -----------------------------------------------------------------------

    private static function scanCooldownRemaining(string $username): int
    {
        $last = self::lastScanTime($username);
        if ($last === null) return 0;
        $elapsed = time() - $last;
        return max(0, self::SCAN_COOLDOWN - $elapsed);
    }

    private static function lastScanTime(string $username): ?int
    {
        // Intentar Redis primero
        $r = self::redisClient();
        if ($r !== null) {
            $val = $r->get("bulwark:scan:$username:last_scan");
            if ($val !== false) return (int)$val;
        }
        // Fallback: fichero de timestamp
        $tsFile = self::SCAN_REQUESTS . '/' . $username . '.ts';
        if (file_exists($tsFile)) return (int)file_get_contents($tsFile);
        return null;
    }

    private static function setScanTimestamp(string $username): void
    {
        $now = time();
        $r   = self::redisClient();
        if ($r !== null) {
            $r->setEx("bulwark:scan:$username:last_scan", self::SCAN_COOLDOWN, (string)$now);
        }
        // Fichero de fallback
        $tsFile = self::SCAN_REQUESTS . '/' . $username . '.ts';
        file_put_contents($tsFile, (string)$now);
        chmod($tsFile, 0640);
    }

    // -----------------------------------------------------------------------
    // Helpers — generales
    // -----------------------------------------------------------------------

    private static function userQuarantineDir(string $username): string
    {
        return '/var/bulwark/hostdata/' . $username . '/' . self::QUARANTINE_SUBDIR;
    }

    private static function listQuarantineFiles(string $qDir): array
    {
        if (!is_dir($qDir)) return [];
        $files = [];
        foreach ((array)scandir($qDir) as $f) {
            if ($f === '.' || $f === '..') continue;
            if (str_ends_with($f, '.json')) continue;
            if (!is_file($qDir . '/' . $f)) continue;
            $files[] = $f;
        }
        usort($files, fn($a, $b) => strcmp($b, $a));
        return $files;
    }

    private static function countQuarantine(string $username): int
    {
        $r = self::redisClient();
        if ($r !== null) {
            $val = $r->get("bulwark:quarantine:$username:count");
            if ($val !== false) return (int)$val;
        }
        return count(self::listQuarantineFiles(self::userQuarantineDir($username)));
    }

    private static function refreshQuarantineCount(string $username): void
    {
        $remaining = count(self::listQuarantineFiles(self::userQuarantineDir($username)));
        $r = self::redisClient();
        if ($r !== null) {
            $r->set("bulwark:quarantine:$username:count", $remaining);
        }
    }

    private static function redisClient(): ?object
    {
        static $r = null;
        if ($r === null) {
            if (!class_exists('Redis')) return null;
            $r = new Redis();
            if (!@$r->connect(self::REDIS_HOST, self::REDIS_PORT, 2)) {
                $r = null;
            } else {
                $rp = @file_get_contents('/usr/local/bulwark/cnf/redis.pass');
                if ($rp !== false && trim($rp) !== '') { try { $r->auth(['panel', trim($rp)]); } catch (Exception $e) {} }
            }
        }
        return $r;
    }

    private static function readMeta(string $file): ?array
    {
        $metaFile = $file . '.json';
        if (!file_exists($metaFile)) return null;
        $data = @json_decode(file_get_contents($metaFile), true);
        return is_array($data) ? $data : null;
    }

    private static function sanitizeQname(string $raw): string
    {
        $clean = basename(trim($raw));
        if (!preg_match('/^[a-zA-Z0-9._\-]+$/', $clean)) return '';
        return $clean;
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    private static function restoreRequestExists(string $username, string $qname): bool
    {
        $reqFile = self::RESTORE_REQUESTS . '/' . $username . '_' . md5($qname) . '.json';
        return file_exists($reqFile);
    }
}

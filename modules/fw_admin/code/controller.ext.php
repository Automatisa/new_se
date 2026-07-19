<?php
/**
 * fw_admin — Gestión de cortafuegos pf y SSHGuard en FreeBSD.
 *
 * Pestañas: Estado | IPs bloqueadas | Lista blanca | SSHGuard | Configuración
 *
 * Todas las operaciones privilegiadas van por privilege::run() (sin exec()).
 * Los argumentos dinámicos (IPs) se pasan via archivo temporal root:bulwark 660.
 */
class module_controller extends ctrl_module
{
    static $ok     = false;
    static $error  = false;
    static $errMsg = '';
    static $okMsg  = '';

    // ------------------------------------------------------------------ guard

    private static function requireAdmin(): void
    {
        $u = ctrl_users::GetUserDetail();
        if ((int)($u['usergroupid'] ?? 3) !== 1) {
            header('Location: ./?module=dashboard');
            exit;
        }
    }

    // ---------------------------------------------------- validación de IPs

    /**
     * Acepta IPv4, IPv6 y ambos en notación CIDR.
     */
    static function validateIPOrCIDR(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        if (strpos($ip, '/') !== false) {
            [$addr, $prefix] = explode('/', $ip, 2);
            if (!ctype_digit($prefix)) {
                return false;
            }
            $p = (int)$prefix;
            if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $p >= 0 && $p <= 32;
            }
            if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return $p >= 0 && $p <= 128;
            }
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Protege contra bloqueo accidental de la IP del servidor,
     * la IP del cliente actual o direcciones de loopback.
     */
    private static function isSafeToBlock(string $ip): bool
    {
        $serverIP = (string)(ctrl_options::GetSystemOption('server_ip') ?: '');
        $clientIP = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        if ($ip === '127.0.0.1' || $ip === '::1') {
            return false;
        }
        if ($serverIP !== '' && $ip === $serverIP) {
            return false;
        }
        if ($clientIP !== '' && $ip === $clientIP) {
            return false;
        }
        return true;
    }

    private static function isInWhitelist(string $ip): bool
    {
        global $zdbh;
        $q = $zdbh->prepare(
            "SELECT COUNT(*) FROM x_fw_whitelist WHERE fw_ip_vc=:ip AND fw_deleted_ts IS NULL"
        );
        $q->bindValue(':ip', $ip);
        $q->execute();
        return (int)$q->fetchColumn() > 0;
    }

    // ---------------------------------------------------------- estado JSON

    /**
     * Lee /var/bulwark/logs/fw_status.json (escrito por fw_status_dump.sh como root,
     * propietario www:www 640 → el proceso PHP puede leerlo sin privilegios).
     * Si no existe, intenta generarlo primero.
     */
    private static function readStatusJson(): array
    {
        $path = ctrl_options::GetSystemOption('fw_status_json_path')
                    ?: '/var/bulwark/logs/fw_status.json';

        if (!file_exists($path)) {
            try {
                privilege::run('fw_status_dump');
            } catch (\Exception $e) {
                // Si falla (doas no configurado aún), continuar sin JSON
            }
        }

        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode(@file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    // ------------------------------------------------------ init / dispatch

    // Wrappers do*: el framework los despacha en runtime_controller::Init() ANTES de renderizar la
    // página, cuando aún NO se han enviado cabeceras. Así el PRG redirect de getInit() SÍ se ejecuta.
    // Sin esto, getInit corría solo durante el render (vía <@ Init @>), con cabeceras ya enviadas ->
    // el header('Location') se omitía -> la página quedaba como resultado del POST -> el navegador
    // pedía "reenviar formulario" y al aceptar se RE-EJECUTABA la acción (p.ej. reiniciar de nuevo).
    // Todos delegan en getInit(), que lee la acción de la URL y hace el dispatch + redirect.
    static function doBlockIP(): void            { self::getInit(); }
    static function doUnblockIP(): void          { self::getInit(); }
    static function doAddWhitelist(): void       { self::getInit(); }
    static function doRemoveWhitelist(): void    { self::getInit(); }
    static function doUnbanSshguard(): void      { self::getInit(); }
    static function doSaveConfig(): void         { self::getInit(); }
    static function doRestartService(): void     { self::getInit(); }
    static function doRefreshStatus(): void      { self::getInit(); }
    static function doAddRule(): void            { self::getInit(); }
    static function doUpdateRule(): void         { self::getInit(); }
    static function doDeleteRule(): void         { self::getInit(); }
    static function doToggleRule(): void         { self::getInit(); }
    static function doReloadRules(): void        { self::getInit(); }
    static function doClearLoginAttempts(): void { self::getInit(); }

    static function getInit(): void
    {
        self::requireAdmin();
        global $controller;

        $action = $controller->GetControllerRequest('URL', 'action');
        if (!$action) {
            return;
        }

        switch ($action) {
            case 'BlockIP':          self::ExecuteBlockIP();          break;
            case 'UnblockIP':        self::ExecuteUnblockIP();        break;
            case 'AddWhitelist':     self::ExecuteAddWhitelist();     break;
            case 'RemoveWhitelist':  self::ExecuteRemoveWhitelist();  break;
            case 'UnbanSshguard':    self::ExecuteUnbanSshguard();    break;
            case 'SaveConfig':       self::ExecuteSaveConfig();       break;
            case 'RestartService':   self::ExecuteRestartService();   break;
            case 'RefreshStatus':    self::ExecuteRefreshStatus();    break;
            case 'AddRule':          self::ExecuteAddRule();          break;
            case 'UpdateRule':       self::ExecuteUpdateRule();       break;
            case 'DeleteRule':       self::ExecuteDeleteRule();       break;
            case 'ToggleRule':       self::ExecuteToggleRule();       break;
            case 'ReloadRules':        self::ExecuteReloadRules();        break;
            case 'ClearLoginAttempts': self::ExecuteClearLoginAttempts(); break;
        }

        // PRG: guardar el mensaje flash en sesión y redirigir a la URL sin 'action'.
        // Evita el reenvío del formulario y que el framework muestre el mensaje
        // "No doSaveConfig class exists" (fw_admin despacha por getInit, no por do*()).
        if (self::$ok) {
            $_SESSION['fw_admin_flash'] = ['ok', self::$okMsg];
        } elseif (self::$error) {
            $_SESSION['fw_admin_flash'] = ['err', self::$errMsg];
        }
        if (!headers_sent()) {
            $qs = $_GET;
            unset($qs['action']);
            header('Location: ./?' . http_build_query($qs));
            exit;
        }
    }

    // ------------------------------------------------------------- acciones

    private static function ExecuteBlockIP(): void
    {
        global $zdbh;
        $ip     = trim((string)($_POST['inIP']     ?? ''));
        $reason = substr(trim((string)($_POST['inReason'] ?? '')), 0, 255);

        if (!self::validateIPOrCIDR($ip)) {
            self::$error  = true;
            self::$errMsg = "IP/CIDR inválido: " . htmlspecialchars($ip, ENT_QUOTES);
            return;
        }
        if (!self::isSafeToBlock($ip)) {
            self::$error  = true;
            self::$errMsg = "No se puede bloquear esa IP (IP del servidor, del cliente actual o loopback).";
            return;
        }
        if (self::isInWhitelist($ip)) {
            self::$error  = true;
            self::$errMsg = "Esa IP está en la lista blanca. Elimínala primero.";
            return;
        }

        $u   = ctrl_users::GetUserDetail();
        $uid = (int)($u['userid'] ?? 0);
        $now = time();

        // Upsert: reactivar si ya existe con soft-delete o inactiva
        $exists = $zdbh->prepare(
            "SELECT fb_id_pk FROM x_fw_blocked WHERE fb_ip_vc=:ip AND fb_deleted_ts IS NULL LIMIT 1"
        );
        $exists->bindValue(':ip', $ip);
        $exists->execute();

        if ($exists->fetchColumn()) {
            $upd = $zdbh->prepare(
                "UPDATE x_fw_blocked SET fb_active_in=1, fb_reason_vc=:r, fb_added_ts=:ts
                 WHERE fb_ip_vc=:ip AND fb_deleted_ts IS NULL"
            );
            $upd->bindValue(':r',  $reason);
            $upd->bindValue(':ts', $now);
            $upd->bindValue(':ip', $ip);
            $upd->execute();
        } else {
            $ins = $zdbh->prepare(
                "INSERT INTO x_fw_blocked (fb_ip_vc, fb_reason_vc, fb_added_by, fb_added_ts, fb_active_in)
                 VALUES (:ip, :r, :uid, :ts, 1)"
            );
            $ins->bindValue(':ip',  $ip);
            $ins->bindValue(':r',   $reason);
            $ins->bindValue(':uid', $uid);
            $ins->bindValue(':ts',  $now);
            $ins->execute();
        }

        try {
            [$code,, $err] = privilege::run('fw_block_apply');
            if ($code !== 0) {
                throw new \RuntimeException($err ?: "Código de salida $code");
            }
            self::$ok    = true;
            self::$okMsg = "IP " . htmlspecialchars($ip, ENT_QUOTES) . " bloqueada y aplicada en pf.";
        } catch (\Exception $e) {
            self::$error  = true;
            self::$errMsg = "IP guardada en BD pero error al aplicar en pf: "
                          . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    private static function ExecuteUnblockIP(): void
    {
        global $zdbh;
        $id = (int)($_POST['inID'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $upd = $zdbh->prepare(
            "UPDATE x_fw_blocked SET fb_deleted_ts=:ts, fb_active_in=0 WHERE fb_id_pk=:id"
        );
        $upd->bindValue(':ts', time());
        $upd->bindValue(':id', $id);
        $upd->execute();

        try {
            [$code,, $err] = privilege::run('fw_block_apply');
            if ($code !== 0) {
                throw new \RuntimeException($err ?: "Código de salida $code");
            }
            self::$ok    = true;
            self::$okMsg = "Bloqueo eliminado y tabla pf actualizada.";
        } catch (\Exception $e) {
            self::$error  = true;
            self::$errMsg = "Eliminado de BD pero error en pf: "
                          . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    private static function ExecuteAddWhitelist(): void
    {
        global $zdbh;
        $ip     = trim((string)($_POST['inIP']     ?? ''));
        $reason = substr(trim((string)($_POST['inReason'] ?? '')), 0, 255);

        if (!self::validateIPOrCIDR($ip)) {
            self::$error  = true;
            self::$errMsg = "IP/CIDR inválido: " . htmlspecialchars($ip, ENT_QUOTES);
            return;
        }

        $exists = $zdbh->prepare(
            "SELECT fw_id_pk FROM x_fw_whitelist WHERE fw_ip_vc=:ip AND fw_deleted_ts IS NULL LIMIT 1"
        );
        $exists->bindValue(':ip', $ip);
        $exists->execute();
        if ($exists->fetchColumn()) {
            self::$error  = true;
            self::$errMsg = "Esa IP ya está en la lista blanca.";
            return;
        }

        $u   = ctrl_users::GetUserDetail();
        $uid = (int)($u['userid'] ?? 0);

        $ins = $zdbh->prepare(
            "INSERT INTO x_fw_whitelist (fw_ip_vc, fw_reason_vc, fw_added_by, fw_added_ts)
             VALUES (:ip, :r, :uid, :ts)"
        );
        $ins->bindValue(':ip',  $ip);
        $ins->bindValue(':r',   $reason);
        $ins->bindValue(':uid', $uid);
        $ins->bindValue(':ts',  time());
        $ins->execute();

        try {
            [$code,, $err] = privilege::run('fw_whitelist_apply');
            if ($code !== 0) {
                throw new \RuntimeException($err ?: "Código de salida $code");
            }
            self::$ok    = true;
            self::$okMsg = htmlspecialchars($ip, ENT_QUOTES) . " añadida a la lista blanca.";
        } catch (\Exception $e) {
            self::$error  = true;
            self::$errMsg = "Guardada en BD pero error en pf: "
                          . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    private static function ExecuteRemoveWhitelist(): void
    {
        global $zdbh;
        $id = (int)($_POST['inID'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $upd = $zdbh->prepare(
            "UPDATE x_fw_whitelist SET fw_deleted_ts=:ts WHERE fw_id_pk=:id"
        );
        $upd->bindValue(':ts', time());
        $upd->bindValue(':id', $id);
        $upd->execute();

        try {
            [$code,, $err] = privilege::run('fw_whitelist_apply');
            if ($code !== 0) {
                throw new \RuntimeException($err ?: "Código de salida $code");
            }
            self::$ok    = true;
            self::$okMsg = "IP eliminada de la lista blanca.";
        } catch (\Exception $e) {
            self::$error  = true;
            self::$errMsg = "Eliminada de BD pero error en pf: "
                          . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    private static function ExecuteUnbanSshguard(): void
    {
        $ip = trim((string)($_POST['inIP'] ?? ''));

        if (!self::validateIPOrCIDR($ip)) {
            self::$error  = true;
            self::$errMsg = "IP inválida.";
            return;
        }

        // Escribir IP en archivo de solicitud (root:bulwark 660)
        // El script wrapper lee y borra este archivo, valida formato y llama a pfctl
        $reqFile = '/var/bulwark/run/fw_unban_request';

        if (@file_put_contents($reqFile, $ip) === false) {
            self::$error  = true;
            self::$errMsg = "No se pudo escribir la solicitud de desbaneo en $reqFile.";
            return;
        }
        @chmod($reqFile, 0660);

        try {
            [$code,, $err] = privilege::run('fw_sshguard_unban');
            if ($code !== 0) {
                throw new \RuntimeException($err ?: "Código de salida $code");
            }
            // Marcar como inactiva en BD (la sincronización del daemon la limpiará también)
            global $zdbh;
            $upd = $zdbh->prepare(
                "UPDATE x_fw_auto_banned SET fa_active_in=0 WHERE fa_ip_vc=:ip"
            );
            $upd->bindValue(':ip', $ip);
            $upd->execute();

            self::$ok    = true;
            self::$okMsg = htmlspecialchars($ip, ENT_QUOTES) . " desbaneada de SSHGuard/pf.";
        } catch (\Exception $e) {
            @unlink($reqFile);
            self::$error  = true;
            self::$errMsg = "Error al desbanear: " . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    private static function ExecuteRefreshStatus(): void
    {
        try {
            [$code,, $err] = privilege::run('fw_status_dump');
            if ($code !== 0) {
                throw new \RuntimeException($err ?: "Código de salida $code");
            }
            self::$ok    = true;
            self::$okMsg = "Estado del cortafuegos actualizado.";
        } catch (\Exception $e) {
            self::$error  = true;
            self::$errMsg = "Error al actualizar estado: "
                          . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    private static function ExecuteSaveConfig(): void
    {
        $numeric = ['fw_ban_time', 'fw_max_retry', 'fw_find_time', 'fw_login_max', 'fw_login_window'];
        $boolean = ['fw_pf_enabled', 'fw_sshguard_enabled'];

        foreach ($boolean as $key) {
            $old = (string)ctrl_options::GetSystemOption($key);
            $val = isset($_POST[$key]) ? (string)$_POST[$key] : '0';
            $val = in_array($val, ['0','1'], true) ? $val : '0';
            ctrl_options::SetSystemOption($key, $val);
            // BUG FIX: antes solo se GUARDABA el ajuste y pf/sshguard nunca se paraban.
            // Ahora, si cambia, se aplica de verdad (service on/off + sysrc) vía el script.
            if ($val !== $old) {
                $svc = ($key === 'fw_pf_enabled') ? 'pf' : 'sshguard';
                self::applyServiceToggle($svc, $val === '1' ? 'on' : 'off');
            }
        }
        foreach ($numeric as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $val = trim((string)$_POST[$key]);
            if (ctype_digit($val) && (int)$val >= 1) {
                ctrl_options::SetSystemOption($key, $val);
            }
        }
        self::$ok    = true;
        self::$okMsg = "Configuración guardada.";
    }

    /**
     * Reinicia el servicio pf o SSHGuard (sin cambiar si arranca en boot). Pensado para
     * recargar reglas/config tras editarlas. Invocado por el botón "Reiniciar" de Estado.
     */
    private static function ExecuteRestartService(): void
    {
        $svc = isset($_POST['fw_svc']) ? (string)$_POST['fw_svc'] : '';
        if (!in_array($svc, ['pf', 'sshguard'], true)) {
            self::$error  = true;
            self::$errMsg = "Servicio no válido.";
            return;
        }
        self::applyServiceToggle($svc, 'restart');
        if (!self::$error) {
            self::$ok    = true;
            self::$okMsg = "Servicio " . htmlspecialchars($svc, ENT_QUOTES) . " reiniciado.";
        }
    }

    /**
     * Aplica de verdad el arranque/parada/reinicio de pf o SSHGuard: escribe la orden en el
     * fichero de petición y llama al script privilegiado (service on/off/restart + sysrc en
     * on/off para persistir). $svc = 'pf'|'sshguard'; $act = 'on'|'off'|'restart'.
     */
    private static function applyServiceToggle(string $svc, string $act): void
    {
        $reqFile = '/var/bulwark/run/fw_service_toggle_req';
        try {
            if (@file_put_contents($reqFile, $svc . ' ' . $act . "\n") === false) {
                throw new \RuntimeException("no se pudo escribir la orden en $reqFile");
            }
            @chmod($reqFile, 0660);
            [$code,, $err] = privilege::run('fw_service_toggle');
            if ($code !== 0) {
                throw new \RuntimeException($err ?: "código de salida $code");
            }
            // Refrescar el JSON de estado para que la pestaña Estado muestre lo aplicado.
            try { privilege::run('fw_status_dump'); } catch (\Exception $e) { /* no crítico */ }
        } catch (\Exception $e) {
            $verbo = $act === 'on' ? 'activar' : ($act === 'off' ? 'desactivar' : 'reiniciar');
            self::$error  = true;
            self::$errMsg = "No se pudo " . $verbo
                          . " " . htmlspecialchars($svc, ENT_QUOTES) . ": "
                          . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    // ------------------------------------------------- getters de template

    static function getResult(): string
    {
        // Mensaje flash restaurado tras el PRG redirect (ver getInit).
        if (!empty($_SESSION['fw_admin_flash'])) {
            [$type, $msg] = $_SESSION['fw_admin_flash'];
            unset($_SESSION['fw_admin_flash']);
            $cls  = $type === 'ok' ? 'alert-success' : 'alert-danger';
            $icon = $type === 'ok' ? 'bi-check-circle' : 'bi-exclamation-triangle';
            return '<div class="alert ' . $cls . '"><span class="bi ' . $icon . '"></span> '
                 . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        if (self::$ok) {
            return '<div class="alert alert-success"><span class="bi bi-check-circle"></span> '
                 . self::$okMsg . '</div>';
        }
        if (self::$error) {
            return '<div class="alert alert-danger"><span class="bi bi-exclamation-triangle"></span> '
                 . self::$errMsg . '</div>';
        }
        return '';
    }

    static function getStatusPanel(): string
    {
        $status = self::readStatusJson();

        $pfOn = !empty($status['pf_enabled'])
            ? '<span class="badge bg-success">ACTIVO</span>'
            : '<span class="badge bg-danger">INACTIVO / sin datos</span>';

        $sgOn = !empty($status['sshguard_enabled'])
            ? '<span class="badge bg-success">ACTIVO</span>'
            : '<span class="badge bg-warning">INACTIVO / sin datos</span>';

        $ts = !empty($status['generated_ts'])
            ? date('d/m/Y H:i:s', (int)$status['generated_ts'])
            : 'Sin datos aún';

        $mnCount = (int)($status['manual_blocked_count']   ?? 0);
        $sgCount = (int)($status['sshguard_blocked_count'] ?? 0);

        $csrf = self::getCSFR_Tag();
        // Botón "Reiniciar" reutilizable para cada servicio (recarga reglas/config).
        $restartBtn = function (string $svc) use ($csrf): string {
            return '<form method="post" style="display:inline;margin-left:10px;"'
                 . ' action="./?module=fw_admin&action=RestartService&tab=status">'
                 . $csrf
                 . '<input type="hidden" name="fw_svc" value="' . $svc . '">'
                 . '<button type="submit" class="btn btn-sm btn-secondary"'
                 . ' title="Reiniciar el servicio ' . $svc . ' (recarga reglas/config)">'
                 . '<span class="bi bi-arrow-clockwise"></span> Reiniciar</button></form>';
        };

        $html  = '<div class="table-responsive"><table class="table table-sm" style="max-width:560px;">';
        $html .= '<tr><th style="width:220px;">Packet Filter (pf)</th><td>' . $pfOn . $restartBtn('pf') . '</td></tr>';
        $html .= '<tr><th>SSHGuard</th><td>' . $sgOn . $restartBtn('sshguard') . '</td></tr>';
        $html .= '<tr><th>IPs bloqueadas manualmente</th><td><strong>' . $mnCount . '</strong></td></tr>';
        $html .= '<tr><th>IPs baneadas por SSHGuard</th><td><strong>' . $sgCount . '</strong></td></tr>';
        $html .= '<tr><th>Último refresco de estado</th><td>' . htmlspecialchars($ts) . '</td></tr>';
        $html .= '</table>
</div>';

        $html .= '<div class="alert alert-info" style="max-width:600px;font-size:12px;margin-top:10px;">'
               . '<strong>Fragmento requerido en <code>/etc/pf.conf</code></strong> (ejecutar <code>service pf reload</code> tras añadirlo):<br>'
               . '<pre style="margin:6px 0;font-size:11px;">'
               . "table &lt;bulwark_whitelist&gt; persist file \"/var/bulwark/run/pf_whitelist.txt\"\n"
               . "table &lt;bulwark_blocked&gt;   persist file \"/var/bulwark/run/pf_blocked.txt\"\n"
               . "table &lt;sshguard&gt;           persist\n\n"
               . "pass  quick from &lt;bulwark_whitelist&gt;\n"
               . "block drop quick from &lt;bulwark_blocked&gt;\n"
               . "block drop quick from &lt;sshguard&gt;"
               . '</pre>'
               . '<strong>SSHGuard (<code>/usr/local/etc/sshguard.conf</code>) debe incluir:</strong><br>'
               . '<pre style="margin:6px 0;font-size:11px;">'
               . "BACKEND=\"/usr/local/libexec/sshg-fw-pf\"\n"
               . "LOGREADER=\"SYSLOG\"\n"
               . "THRESHOLD=10\n"
               . "BLOCK_TIME=3600\n"
               . "DETECTION_TIME=600\n"
               . "WHITELIST_FILE=/usr/local/etc/sshguard.whitelist"
               . '</pre>'
               . '</div>';

        return $html;
    }

    static function getBlockedTable(): string
    {
        global $zdbh;

        $q = $zdbh->prepare(
            "SELECT fb_id_pk, fb_ip_vc, fb_reason_vc, fb_added_ts, fb_active_in
             FROM x_fw_blocked WHERE fb_deleted_ts IS NULL ORDER BY fb_added_ts DESC"
        );
        $q->execute();
        $rows = $q->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return '<p class="text-muted">No hay IPs bloqueadas manualmente.</p>';
        }

        $html  = '<div class="table-responsive"><table class="table table-striped table-sm">';
        $html .= '<thead><tr>'
               . '<th>IP / Red CIDR</th>'
               . '<th>Motivo</th>'
               . '<th>Añadida</th>'
               . '<th>Estado pf</th>'
               . '<th></th>'
               . '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $estado = $r['fb_active_in']
                ? '<span class="badge bg-danger">BLOQUEADA</span>'
                : '<span class="badge bg-default">inactiva</span>';
            $added = $r['fb_added_ts'] ? date('d/m/Y H:i', (int)$r['fb_added_ts']) : '-';
            $ipEsc = htmlspecialchars($r['fb_ip_vc'], ENT_QUOTES);

            $html .= '<tr>'
                   . '<td><code>' . $ipEsc . '</code></td>'
                   . '<td>' . htmlspecialchars($r['fb_reason_vc']) . '</td>'
                   . '<td>' . $added . '</td>'
                   . '<td>' . $estado . '</td>'
                   . '<td>'
                   . '<button type="button" class="btn btn-sm btn-warning"'
                   .   ' onclick="fwDeleteBlock(' . (int)$r['fb_id_pk'] . ',\'' . $ipEsc . '\')">'
                   . '<span class="bi bi-trash"></span> Eliminar'
                   . '</button>'
                   . '</td></tr>';
        }

        $html .= '</tbody></table>
</div>';
        return $html;
    }

    static function getWhitelistTable(): string
    {
        global $zdbh;

        $q = $zdbh->prepare(
            "SELECT fw_id_pk, fw_ip_vc, fw_reason_vc, fw_added_ts
             FROM x_fw_whitelist WHERE fw_deleted_ts IS NULL ORDER BY fw_added_ts DESC"
        );
        $q->execute();
        $rows = $q->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return '<p class="text-muted">La lista blanca está vacía. Añade aquí tu IP de administración para que nunca quede bloqueada.</p>';
        }

        $html  = '<div class="table-responsive"><table class="table table-striped table-sm">';
        $html .= '<thead><tr>'
               . '<th>IP / Red CIDR</th>'
               . '<th>Motivo</th>'
               . '<th>Añadida</th>'
               . '<th></th>'
               . '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $added = $r['fw_added_ts'] ? date('d/m/Y H:i', (int)$r['fw_added_ts']) : '-';
            $ipEsc = htmlspecialchars($r['fw_ip_vc'], ENT_QUOTES);

            $html .= '<tr>'
                   . '<td><code>' . $ipEsc . '</code></td>'
                   . '<td>' . htmlspecialchars($r['fw_reason_vc']) . '</td>'
                   . '<td>' . $added . '</td>'
                   . '<td>'
                   . '<button type="button" class="btn btn-sm btn-danger"'
                   .   ' onclick="fwDeleteWhite(' . (int)$r['fw_id_pk'] . ',\'' . $ipEsc . '\')">'
                   . '<span class="bi bi-trash"></span> Eliminar'
                   . '</button>'
                   . '</td></tr>';
        }

        $html .= '</tbody></table>
</div>';
        return $html;
    }

    static function getAutoBlockedTable(): string
    {
        global $zdbh;

        // Detectar si las columnas fa_service_vc/fa_port_in ya existen (v101+)
        $hasServiceCol = false;
        try {
            $zdbh->query("SELECT fa_service_vc FROM x_fw_auto_banned LIMIT 0");
            $hasServiceCol = true;
        } catch (\Exception $e) {}

        $cols = $hasServiceCol
            ? "fa_ip_vc, fa_jail_vc, fa_service_vc, fa_port_in, fa_since_ts"
            : "fa_ip_vc, fa_jail_vc, '' AS fa_service_vc, 0 AS fa_port_in, fa_since_ts";

        $q = $zdbh->prepare(
            "SELECT $cols FROM x_fw_auto_banned WHERE fa_active_in=1 ORDER BY fa_since_ts DESC"
        );
        $q->execute();
        $rows = $q->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return '<p class="text-muted">SSHGuard no tiene ninguna IP baneada en este momento '
                 . '(o el daemon aún no ha sincronizado — ciclo cada 5 min).</p>';
        }

        $html  = '<div class="table-responsive"><table class="table table-striped table-sm">';
        $html .= '<thead><tr>'
               . '<th>IP / Red</th>'
               . '<th>Servicio atacado</th>'
               . '<th>Puerto</th>'
               . '<th>Baneada desde</th>'
               . '<th></th>'
               . '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $since   = $r['fa_since_ts'] ? date('d/m/Y H:i', (int)$r['fa_since_ts']) : '-';
            $ipEsc   = htmlspecialchars($r['fa_ip_vc'], ENT_QUOTES);
            $svc     = $r['fa_service_vc'] ?: '—';
            $port    = (int)$r['fa_port_in'];
            $portStr = $port > 0 ? (string)$port : '—';

            // Badge de color según servicio
            $badgeClass = match(true) {
                in_array($svc, ['SSH','SSHD'])               => 'danger',
                in_array($svc, ['ProFTPD','FTP','vsftpd'])   => 'warning',
                in_array($svc, ['Exim','Postfix','SMTP'])    => 'info',
                default                                       => 'default',
            };

            $html .= '<tr>'
                   . '<td><code>' . $ipEsc . '</code></td>'
                   . '<td><span class="badge bg-' . $badgeClass . '">'
                   .     htmlspecialchars($svc) . '</span></td>'
                   . '<td>' . $portStr . '</td>'
                   . '<td>' . $since . '</td>'
                   . '<td>'
                   . '<button type="button" class="btn btn-sm btn-warning"'
                   .   ' onclick="fwUnban(\'' . $ipEsc . '\')">'
                   . '<span class="bi bi-check-circle-fill"></span> Desbanear'
                   . '</button>'
                   . '</td></tr>';
        }

        $html .= '</tbody></table>
</div>';
        return $html;
    }

    // ------------------------------------------------- reglas personalizadas pf

    private static function validateRuleParams(
        string &$action, string &$direction, string &$proto,
        string &$src, int &$port, int &$portMax
    ): bool {
        if (!in_array($action,    ['block','pass'],           true)) { return false; }
        if (!in_array($direction, ['in','out','any'],         true)) { return false; }
        if (!in_array($proto,     ['tcp','udp','icmp','any'], true)) { return false; }
        if ($src !== 'any' && !self::validateIPOrCIDR($src))        { return false; }
        if ($port < 0 || $port > 65535)                             { return false; }
        if ($portMax < 0 || $portMax > 65535)                       { return false; }
        if ($portMax > 0 && $portMax <= $port)                      { return false; }
        return true;
    }

    private static function hasPortMaxCol(): bool
    {
        global $zdbh;
        try {
            $zdbh->query("SELECT fr_port_max_in FROM x_fw_rules LIMIT 0");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function ExecuteAddRule(): void
    {
        global $zdbh;
        $action    = trim((string)($_POST['inAction']    ?? 'block'));
        $direction = trim((string)($_POST['inDirection'] ?? 'in'));
        $proto     = trim((string)($_POST['inProto']     ?? 'tcp'));
        $src       = trim((string)($_POST['inSrc']       ?? '')) ?: 'any';
        $port      = (int)($_POST['inPort']    ?? 0);
        $portMax   = (int)($_POST['inPortMax'] ?? 0);
        $desc      = substr(trim((string)($_POST['inDesc']  ?? '')), 0, 255);
        $order     = max(1, min(999, (int)($_POST['inOrder'] ?? 100)));

        if (!self::validateRuleParams($action, $direction, $proto, $src, $port, $portMax)) {
            self::$error  = true;
            self::$errMsg = "Parámetros de regla inválidos.";
            return;
        }

        $hasMax = self::hasPortMaxCol();
        $sql = $hasMax
            ? "INSERT INTO x_fw_rules
                (fr_action_en, fr_proto_vc, fr_direction_en, fr_src_vc,
                 fr_port_in, fr_port_max_in, fr_desc_vc, fr_order_in, fr_enabled_in, fr_added_ts)
               VALUES (:act, :proto, :dir, :src, :port, :portmax, :desc, :ord, 1, :ts)"
            : "INSERT INTO x_fw_rules
                (fr_action_en, fr_proto_vc, fr_direction_en, fr_src_vc,
                 fr_port_in, fr_desc_vc, fr_order_in, fr_enabled_in, fr_added_ts)
               VALUES (:act, :proto, :dir, :src, :port, :desc, :ord, 1, :ts)";

        $ins = $zdbh->prepare($sql);
        $ins->bindValue(':act',   $action);
        $ins->bindValue(':proto', $proto);
        $ins->bindValue(':dir',   $direction);
        $ins->bindValue(':src',   $src);
        $ins->bindValue(':port',  $port);
        if ($hasMax) { $ins->bindValue(':portmax', $portMax); }
        $ins->bindValue(':desc',  $desc);
        $ins->bindValue(':ord',   $order);
        $ins->bindValue(':ts',    time());
        $ins->execute();

        try {
            privilege::run('fw_rules_apply');
            self::$ok    = true;
            self::$okMsg = "Regla añadida y aplicada en pf.";
        } catch (\Exception $e) {
            self::$error  = true;
            self::$errMsg = "Regla guardada en BD pero error al aplicar: "
                          . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    private static function ExecuteUpdateRule(): void
    {
        global $zdbh;
        $id        = (int)($_POST['inID']        ?? 0);
        $action    = trim((string)($_POST['inAction']    ?? 'block'));
        $direction = trim((string)($_POST['inDirection'] ?? 'in'));
        $proto     = trim((string)($_POST['inProto']     ?? 'tcp'));
        $src       = trim((string)($_POST['inSrc']       ?? '')) ?: 'any';
        $port      = (int)($_POST['inPort']    ?? 0);
        $portMax   = (int)($_POST['inPortMax'] ?? 0);
        $desc      = substr(trim((string)($_POST['inDesc']  ?? '')), 0, 255);
        $order     = max(1, min(999, (int)($_POST['inOrder'] ?? 100)));

        if ($id <= 0) { return; }
        if (!self::validateRuleParams($action, $direction, $proto, $src, $port, $portMax)) {
            self::$error  = true;
            self::$errMsg = "Parámetros de regla inválidos.";
            return;
        }

        $hasMax = self::hasPortMaxCol();
        $sql = $hasMax
            ? "UPDATE x_fw_rules SET fr_action_en=:act, fr_proto_vc=:proto,
               fr_direction_en=:dir, fr_src_vc=:src, fr_port_in=:port,
               fr_port_max_in=:portmax, fr_desc_vc=:desc, fr_order_in=:ord
               WHERE fr_id_pk=:id"
            : "UPDATE x_fw_rules SET fr_action_en=:act, fr_proto_vc=:proto,
               fr_direction_en=:dir, fr_src_vc=:src, fr_port_in=:port,
               fr_desc_vc=:desc, fr_order_in=:ord
               WHERE fr_id_pk=:id";

        $upd = $zdbh->prepare($sql);
        $upd->bindValue(':act',   $action);
        $upd->bindValue(':proto', $proto);
        $upd->bindValue(':dir',   $direction);
        $upd->bindValue(':src',   $src);
        $upd->bindValue(':port',  $port);
        if ($hasMax) { $upd->bindValue(':portmax', $portMax); }
        $upd->bindValue(':desc',  $desc);
        $upd->bindValue(':ord',   $order);
        $upd->bindValue(':id',    $id);
        $upd->execute();

        try {
            privilege::run('fw_rules_apply');
            self::$ok    = true;
            self::$okMsg = "Regla actualizada y aplicada en pf.";
        } catch (\Exception $e) {
            self::$error  = true;
            self::$errMsg = "Regla guardada en BD pero error al aplicar: "
                          . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    private static function ExecuteDeleteRule(): void
    {
        global $zdbh;
        $id = (int)($_POST['inID'] ?? 0);
        if ($id <= 0) { return; }

        $zdbh->prepare("DELETE FROM x_fw_rules WHERE fr_id_pk=:id")
             ->execute([':id' => $id]);

        try {
            privilege::run('fw_rules_apply');
            self::$ok    = true;
            self::$okMsg = "Regla eliminada y tabla pf actualizada.";
        } catch (\Exception $e) {
            self::$error  = true;
            self::$errMsg = "Eliminada de BD pero error en pf: "
                          . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    private static function ExecuteToggleRule(): void
    {
        global $zdbh;
        $id = (int)($_POST['inID'] ?? 0);
        if ($id <= 0) { return; }

        $zdbh->prepare(
            "UPDATE x_fw_rules SET fr_enabled_in = 1 - fr_enabled_in WHERE fr_id_pk=:id"
        )->execute([':id' => $id]);

        try {
            privilege::run('fw_rules_apply');
            self::$ok    = true;
            self::$okMsg = "Estado de la regla cambiado y pf actualizado.";
        } catch (\Exception $e) {
            self::$error  = true;
            self::$errMsg = "BD actualizada pero error en pf: "
                          . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    private static function ExecuteReloadRules(): void
    {
        try {
            privilege::run('fw_rules_apply');
            self::$ok    = true;
            self::$okMsg = "Reglas personalizadas reaplicadas en pf.";
        } catch (\Exception $e) {
            self::$error  = true;
            self::$errMsg = "Error al reaplicar reglas: "
                          . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    static function getRulesTable(): string
    {
        global $zdbh;

        try {
            $zdbh->query("SELECT 1 FROM x_fw_rules LIMIT 0");
        } catch (\Exception $e) {
            return '<div class="alert alert-warning">La tabla <code>x_fw_rules</code> no existe. '
                 . 'Ejecuta <code>migrate_v102.sql</code>.</div>';
        }

        $hasMax = self::hasPortMaxCol();
        $colSel = $hasMax
            ? "fr_id_pk,fr_action_en,fr_direction_en,fr_proto_vc,fr_src_vc,
               fr_port_in,fr_port_max_in,fr_desc_vc,fr_order_in,fr_enabled_in"
            : "fr_id_pk,fr_action_en,fr_direction_en,fr_proto_vc,fr_src_vc,
               fr_port_in,0 AS fr_port_max_in,fr_desc_vc,fr_order_in,fr_enabled_in";

        $q    = $zdbh->query("SELECT $colSel FROM x_fw_rules ORDER BY fr_order_in ASC, fr_id_pk ASC");
        $rows = $q->fetchAll(\PDO::FETCH_ASSOC);
        $csrf = self::getCSFR_Tag();

        // ---- Formulario de añadir (tabla para alineación correcta) ----
        $th = 'style="font-size:11px;font-weight:600;color:#555;padding:0 4px 4px;white-space:nowrap;"';
        $td = 'style="padding:0 4px;"';

        $html  = '<form method="post" action="./?module=fw_admin&action=AddRule&tab=rules"'
               . ' style="margin-bottom:14px;">' . $csrf
               . '<table style="width:100%;border-collapse:separate;border-spacing:0;">'

               // Fila de encabezados
               . '<thead><tr>'
               . '<th ' . $th . '>Acción</th>'
               . '<th ' . $th . '>Dirección</th>'
               . '<th ' . $th . '>Protocolo</th>'
               . '<th ' . $th . '>IP / Red origen</th>'
               . '<th ' . $th . '>Puerto</th>'
               . '<th ' . $th . '>Hasta&nbsp;<small style="font-weight:normal;">(rango)</small></th>'
               . '<th ' . $th . '>Descripción</th>'
               . '<th ' . $th . '>Orden</th>'
               . '<th></th>'
               . '</tr></thead>'

               // Fila de controles
               . '<tbody><tr>'

               . '<td ' . $td . '><select name="inAction" class="form-control form-control-sm" style="min-width:130px;">'
               . '<option value="pass">pass — permitir</option>'
               . '<option value="block">block — bloquear</option>'
               . '</select></td>'

               . '<td ' . $td . '><select name="inDirection" class="form-control form-control-sm" style="min-width:110px;">'
               . '<option value="in">in — entrada</option>'
               . '<option value="out">out — salida</option>'
               . '<option value="any">any — ambas</option>'
               . '</select></td>'

               . '<td ' . $td . '><select name="inProto" class="form-control form-control-sm" style="min-width:80px;">'
               . '<option value="tcp">TCP</option>'
               . '<option value="udp">UDP</option>'
               . '<option value="icmp">ICMP</option>'
               . '<option value="any">any</option>'
               . '</select></td>'

               . '<td ' . $td . '><input type="text" name="inSrc"'
               . ' class="form-control form-control-sm" style="min-width:150px;"'
               . ' placeholder="vacío = cualquier origen"></td>'

               . '<td ' . $td . '><input type="number" name="inPort"'
               . ' class="form-control form-control-sm" style="width:75px;"'
               . ' min="0" max="65535" value="0" placeholder="ej: 443"></td>'

               . '<td ' . $td . '><input type="number" name="inPortMax"'
               . ' class="form-control form-control-sm" style="width:75px;"'
               . ' min="0" max="65535" value="0" placeholder="0 = no"'
               . ' title="Puerto final del rango. Dejar en 0 para puerto único."></td>'

               . '<td ' . $td . '><input type="text" name="inDesc"'
               . ' class="form-control form-control-sm" style="min-width:160px;"'
               . ' placeholder="ej: Acceso HTTPS" maxlength="255"></td>'

               . '<td ' . $td . '><input type="number" name="inOrder"'
               . ' class="form-control form-control-sm" style="width:60px;"'
               . ' value="100" min="1" max="999"'
               . ' title="Orden de evaluación: número menor se aplica antes."></td>'

               . '<td style="padding:0 0 0 6px;white-space:nowrap;">'
               . '<button type="submit" class="btn btn-primary btn-sm">'
               . '<span class="bi bi-plus-lg"></span> Añadir'
               . '</button></td>'

               . '</tr></tbody></table>'

               . '<p style="font-size:11px;color:#888;margin:4px 0 0;">'
               . '<strong>Puerto / Hasta:</strong> puerto único → solo "Puerto" (ej: <code>443</code>). '
               . 'Rango → ambos campos (ej: <code>50000</code> hasta <code>50100</code>). '
               . '<strong>ICMP</strong> no usa puertos → dejar en <code>0</code>.'
               . '</p>'
               . '</form>';

        if (empty($rows)) {
            $html .= '<div class="alert alert-warning"><strong>Sin reglas.</strong> '
                  .  'Con <code>block in all</code> en pf.conf y sin reglas en el anchor, '
                  .  '<strong>todo el tráfico entrante quedará bloqueado</strong>. '
                  .  'Ejecuta <code>migrate_v102.sql</code> para insertar las reglas por defecto.</div>';
            return $html;
        }

        $html .= '<div class="table-responsive"><table class="table table-striped table-sm" style="font-size:12px;">';
        $html .= '<thead><tr><th>#</th><th>Acción</th><th>Dir</th><th>Proto</th>'
               . '<th>Origen</th><th>Puerto</th><th>Descripción / Regla pf generada</th>'
               . '<th>Estado</th><th></th></tr></thead><tbody>';

        foreach ($rows as $r) {
            $id      = (int)$r['fr_id_pk'];
            $enabled = (int)$r['fr_enabled_in'];
            $port    = (int)$r['fr_port_in'];
            $portMax = (int)$r['fr_port_max_in'];

            $actBadge  = $r['fr_action_en'] === 'block'
                ? '<span class="badge bg-danger">block</span>'
                : '<span class="badge bg-success">pass</span>';
            $statBadge = $enabled
                ? '<span class="badge bg-success">activa</span>'
                : '<span class="badge bg-default">inactiva</span>';

            // Puerto para mostrar
            $isIcmp  = ($r['fr_proto_vc'] === 'icmp');
            $portStr = '—';
            $pfPort  = '';
            if (!$isIcmp && $port > 0) {
                if ($portMax > $port) {
                    $portStr = $port . ':' . $portMax;
                    $pfPort  = ' port ' . $port . ':' . $portMax;
                } else {
                    $portStr = (string)$port;
                    $pfPort  = ' port ' . $port;
                }
            }

            $pf = $r['fr_action_en'] . ' ' . $r['fr_direction_en'] . ' quick'
                . ($r['fr_proto_vc'] !== 'any' ? ' proto ' . $r['fr_proto_vc'] : '')
                . ' from ' . $r['fr_src_vc'] . ' to any' . $pfPort
                . ($r['fr_action_en'] === 'pass' ? ' keep state' : '');

            $html .= '<tr' . (!$enabled ? ' class="text-muted"' : '') . '>'
                   . '<td>' . $r['fr_order_in'] . '</td>'
                   . '<td>' . $actBadge . '</td>'
                   . '<td>' . htmlspecialchars($r['fr_direction_en']) . '</td>'
                   . '<td>' . htmlspecialchars($r['fr_proto_vc']) . '</td>'
                   . '<td><code>' . htmlspecialchars($r['fr_src_vc']) . '</code></td>'
                   . '<td><code>' . $portStr . '</code></td>'
                   . '<td><small>' . htmlspecialchars($r['fr_desc_vc']) . '</small>'
                   .     '<br><code style="font-size:10px;color:#888;">'
                   .     htmlspecialchars($pf) . '</code></td>'
                   . '<td>' . $statBadge . '</td>'
                   . '<td style="white-space:nowrap;">'
                   // Editar — abre modal con valores actuales
                   . '<button class="btn btn-sm btn-info" type="button"'
                   .   ' onclick="fwEditRule('
                   .       $id . ','
                   .       json_encode($r['fr_action_en'])    . ','
                   .       json_encode($r['fr_direction_en']) . ','
                   .       json_encode($r['fr_proto_vc'])     . ','
                   .       json_encode($r['fr_src_vc'])       . ','
                   .       $port    . ','
                   .       $portMax . ','
                   .       json_encode($r['fr_desc_vc'])      . ','
                   .       $r['fr_order_in']
                   .   ')" title="Editar regla">'
                   . '<span class="bi bi-pencil"></span>'
                   . '</button> '
                   // Activar/Desactivar
                   . '<button class="btn btn-sm btn-secondary" type="button"'
                   .   ' onclick="fwToggleRule(' . $id . ')"'
                   .   ' title="' . ($enabled ? 'Desactivar' : 'Activar') . '">'
                   . '<i class="bi bi-' . ($enabled ? 'pause' : 'play') . '"></i>'
                   . '</button> '
                   // Eliminar
                   . '<button class="btn btn-sm btn-danger" type="button"'
                   .   ' onclick="fwDeleteRule(' . $id . ')">'
                   . '<span class="bi bi-trash"></span>'
                   . '</button></td></tr>';
        }

        $html .= '</tbody></table>
</div>';
        return $html;
    }

    static function getPfRulesPanel(): string
    {
        $status = self::readStatusJson();
        $rules  = $status['pf_rules'] ?? [];
        $anchor = $status['pf_anchor_rules'] ?? [];

        if (empty($rules)) {
            return '<p class="text-muted">Sin datos de pf. '
                 . 'Pulsa "Actualizar estado" en la pestaña Estado para refrescar.</p>';
        }

        $ts  = !empty($status['generated_ts'])
             ? date('d/m/Y H:i:s', (int)$status['generated_ts'])
             : '?';

        $html  = '<p class="text-muted" style="font-size:12px;">Última actualización: ' . $ts . '</p>';
        $html .= '<h4>Reglas activas (<code>pfctl -sr</code>)</h4>';
        $html .= '<pre style="font-size:11px;background:#1e1e1e;color:#d4d4d4;'
               . 'padding:10px;border-radius:4px;max-height:250px;overflow-y:auto;">';
        foreach ($rules as $line) {
            $html .= htmlspecialchars($line) . "\n";
        }
        $html .= '</pre>';

        $html .= '<h4>Reglas del anchor <code>bulwark_rules</code></h4>';
        if (empty($anchor)) {
            $html .= '<p class="text-muted" style="font-size:12px;">'
                   . 'El anchor está vacío (sin reglas personalizadas activas) '
                   . 'o no está declarado en <code>/etc/pf.conf</code>. '
                   . 'Añade <code>anchor "bulwark_rules"</code> antes de <code>pass all</code>.</p>';
        } else {
            $html .= '<pre style="font-size:11px;background:#1e1e1e;color:#90ee90;'
                   . 'padding:10px;border-radius:4px;">';
            foreach ($anchor as $line) {
                $html .= htmlspecialchars($line) . "\n";
            }
            $html .= '</pre>';
        }

        return $html;
    }

    static function getConfigPanel(): string
    {
        $banTime    = ctrl_options::GetSystemOption('fw_ban_time')         ?: '3600';
        $maxRetry   = ctrl_options::GetSystemOption('fw_max_retry')        ?: '5';
        $findTime   = ctrl_options::GetSystemOption('fw_find_time')        ?: '600';
        // OJO: no usar ?: '1' porque '0' es falsy en PHP y "Deshabilitado" (0) se
        // mostraría siempre como "Sí". Preservar explícitamente el valor '0'/'1'.
        $pfVal = ctrl_options::GetSystemOption('fw_pf_enabled');
        $sgVal = ctrl_options::GetSystemOption('fw_sshguard_enabled');
        $pfOn  = ($pfVal === '0' || $pfVal === '1') ? $pfVal : '1';
        $sgOn  = ($sgVal === '0' || $sgVal === '1') ? $sgVal : '1';
        $loginMax   = ctrl_options::GetSystemOption('fw_login_max')        ?: '5';
        $loginWin   = ctrl_options::GetSystemOption('fw_login_window')     ?: '600';

        $chk1 = $pfOn === '1' ? ' checked' : '';
        $chk0 = $pfOn !== '1' ? ' checked' : '';
        $sg1  = $sgOn === '1' ? ' checked' : '';
        $sg0  = $sgOn !== '1' ? ' checked' : '';

        $html  = '<div class="table-responsive"><table class="table table-striped" style="max-width:600px;">';

        $html .= '<tr><th>Habilitar pf</th><td>'
               . '<label class="me-3"><input class="form-check-input me-1" type="radio" name="fw_pf_enabled" value="1"' . $chk1 . '> Sí</label>'
               . '<label class="me-3"><input class="form-check-input me-1" type="radio" name="fw_pf_enabled" value="0"' . $chk0 . '> No</label>'
               . '</td></tr>';

        $html .= '<tr><th>Habilitar SSHGuard</th><td>'
               . '<label class="me-3"><input class="form-check-input me-1" type="radio" name="fw_sshguard_enabled" value="1"' . $sg1 . '> Sí</label>'
               . '<label class="me-3"><input class="form-check-input me-1" type="radio" name="fw_sshguard_enabled" value="0"' . $sg0 . '> No</label>'
               . '</td></tr>';

        $html .= '<tr><th>Tiempo de ban (segundos)</th><td>'
               . '<input type="number" name="fw_ban_time" class="form-control" value="'
               . htmlspecialchars($banTime) . '" min="60" max="86400" style="width:120px;">'
               . '<small class="text-muted"> (BLOCK_TIME en sshguard.conf — referencia)</small>'
               . '</td></tr>';

        $html .= '<tr><th>Intentos antes del ban</th><td>'
               . '<input type="number" name="fw_max_retry" class="form-control" value="'
               . htmlspecialchars($maxRetry) . '" min="1" max="100" style="width:100px;">'
               . '<small class="text-muted"> (THRESHOLD en sshguard.conf — referencia)</small>'
               . '</td></tr>';

        $html .= '<tr><th>Ventana de detección (segundos)</th><td>'
               . '<input type="number" name="fw_find_time" class="form-control" value="'
               . htmlspecialchars($findTime) . '" min="60" max="7200" style="width:120px;">'
               . '<small class="text-muted"> (DETECTION_TIME en sshguard.conf — referencia)</small>'
               . '</td></tr>';

        $html .= '<tr><th colspan="2" style="background:#f5f5f5;padding-top:10px;">'
               . '<strong>Protección panel web (brute force)</strong></th></tr>';

        $html .= '<tr><th>Intentos antes del bloqueo</th><td>'
               . '<input type="number" name="fw_login_max" class="form-control" value="'
               . htmlspecialchars($loginMax) . '" min="1" max="50" style="width:100px;">'
               . '<small class="text-muted"> fallos seguidos → bloqueo automático en pf</small>'
               . '</td></tr>';

        $html .= '<tr><th>Ventana de conteo (segundos)</th><td>'
               . '<input type="number" name="fw_login_window" class="form-control" value="'
               . htmlspecialchars($loginWin) . '" min="60" max="86400" style="width:120px;">'
               . '<small class="text-muted"> ventana para contar los ' . htmlspecialchars($loginMax) . ' intentos</small>'
               . '</td></tr>';

        $html .= '</table>
</div>';
        return $html;
    }

    // -------------------------------------------------- login brute force

    private static function ExecuteClearLoginAttempts(): void
    {
        global $zdbh;
        $ip = trim((string)($_POST['inIP'] ?? ''));
        if ($ip !== '' && self::validateIPOrCIDR($ip)) {
            $zdbh->prepare("DELETE FROM x_fw_login_attempts WHERE la_ip_vc=:ip")
                 ->execute([':ip' => $ip]);
            self::$ok    = true;
            self::$okMsg = "Intentos de " . htmlspecialchars($ip, ENT_QUOTES) . " eliminados.";
        } else {
            // Sin IP = limpiar todo
            $zdbh->exec("DELETE FROM x_fw_login_attempts");
            self::$ok    = true;
            self::$okMsg = "Historial de intentos de login eliminado.";
        }
    }

    static function getLoginAttemptsTable(): string
    {
        global $zdbh;

        $maxAttempts = max(1, (int)(ctrl_options::GetSystemOption('fw_login_max')    ?: 5));
        $window      = max(60, (int)(ctrl_options::GetSystemOption('fw_login_window') ?: 600));
        $csrf        = self::getCSFR_Tag();

        // Resumen por IP: intentos totales, último intento, ¿bloqueada ya?
        try {
            $rows = $zdbh->query(
                "SELECT la_ip_vc,
                        COUNT(*)          AS total,
                        MAX(la_ts_in)     AS last_ts,
                        GROUP_CONCAT(DISTINCT la_user_vc ORDER BY la_ts_in DESC SEPARATOR ', ')
                                          AS users
                 FROM x_fw_login_attempts
                 GROUP BY la_ip_vc
                 ORDER BY last_ts DESC
                 LIMIT 100"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return '<div class="alert alert-warning">Tabla <code>x_fw_login_attempts</code> '
                 . 'no encontrada. Ejecuta <code>migrate_v103.sql</code>.</div>';
        }

        $html  = '<p class="text-muted" style="font-size:12px;">'
               . 'Umbral actual: <strong>' . $maxAttempts . ' intentos</strong> '
               . 'en <strong>' . ($window / 60) . ' minutos</strong> → auto-bloqueo.'
               . ' IPs en lista blanca nunca se bloquean.'
               . '</p>';

        // Botón limpiar todo
        $html .= '<form method="post" action="./?module=fw_admin&action=ClearLoginAttempts&tab=loginattempts"'
               . ' style="margin-bottom:10px;" onsubmit="return confirm(\'¿Limpiar todo el historial de intentos?\');">'
               . $csrf
               . '<input type="hidden" name="inIP" value="">'
               . '<button type="submit" class="btn btn-secondary btn-sm">'
               . '<span class="bi bi-trash"></span> Limpiar historial completo'
               . '</button></form>';

        if (empty($rows)) {
            return $html . '<div class="alert alert-success">'
                 . '<span class="bi bi-check-lg"></span> '
                 . 'Sin intentos fallidos registrados.</div>';
        }

        // Obtener IPs actualmente bloqueadas para marcarlas
        $blocked = [];
        try {
            $bRows = $zdbh->query(
                "SELECT fb_ip_vc FROM x_fw_blocked WHERE fb_active_in=1 AND fb_deleted_ts IS NULL"
            )->fetchAll(\PDO::FETCH_COLUMN);
            $blocked = array_flip($bRows);
        } catch (\Throwable $ignored) {}

        $html .= '<div class="table-responsive"><table class="table table-striped table-sm" style="font-size:12px;">';
        $html .= '<thead><tr>'
               . '<th>IP</th>'
               . '<th>Intentos</th>'
               . '<th>Usuarios probados</th>'
               . '<th>Último intento</th>'
               . '<th>Estado</th>'
               . '<th></th>'
               . '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $ip      = $r['la_ip_vc'];
            $total   = (int)$r['total'];
            $lastTs  = (int)$r['last_ts'];
            $users   = htmlspecialchars((string)$r['users'], ENT_QUOTES);
            $isBlocked = isset($blocked[$ip]);

            $statusBadge = $isBlocked
                ? '<span class="badge bg-danger">BLOQUEADA</span>'
                : ($total >= $maxAttempts
                    ? '<span class="badge bg-warning">umbral superado</span>'
                    : '<span class="badge bg-default">activa</span>');

            $trClass = $isBlocked ? ' danger' : ($total >= $maxAttempts ? ' warning' : '');

            $html .= '<tr class="' . $trClass . '">'
                   . '<td><code>' . htmlspecialchars($ip, ENT_QUOTES) . '</code></td>'
                   . '<td><strong>' . $total . '</strong></td>'
                   . '<td><small>' . $users . '</small></td>'
                   . '<td><small>' . date('d/m/Y H:i:s', $lastTs) . '</small></td>'
                   . '<td>' . $statusBadge . '</td>'
                   . '<td style="white-space:nowrap;">'
                   // Limpiar intentos de esta IP
                   . '<form method="post" style="display:inline;"'
                   . ' action="./?module=fw_admin&action=ClearLoginAttempts&tab=loginattempts">'
                   . $csrf
                   . '<input type="hidden" name="inIP" value="' . htmlspecialchars($ip, ENT_QUOTES) . '">'
                   . '<button type="submit" class="btn btn-sm btn-secondary"'
                   . ' title="Borrar intentos de esta IP">'
                   . '<span class="bi bi-eraser"></span>'
                   . '</button></form>';

            // Si no está bloqueada, botón para bloquear manualmente
            if (!$isBlocked) {
                $html .= ' <form method="post" style="display:inline;"'
                       . ' action="./?module=fw_admin&action=BlockIP&tab=loginattempts">'
                       . $csrf
                       . '<input type="hidden" name="inIP" value="' . htmlspecialchars($ip, ENT_QUOTES) . '">'
                       . '<input type="hidden" name="inReason" value="Brute force panel (manual)">'
                       . '<button type="submit" class="btn btn-sm btn-danger"'
                       . ' title="Bloquear esta IP en pf">'
                       . '<span class="bi bi-slash-circle"></span>'
                       . '</button></form>';
            }

            $html .= '</td></tr>';
        }

        $html .= '</tbody></table>
</div>';
        return $html;
    }

    // Retorna la IP del cliente actual para mostrársela al admin (ayuda a no bloquearse)
    static function getClientIP(): string
    {
        return htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '', ENT_QUOTES);
    }
}

<?php

/**
 * Módulo admin: migración de correo IMAP con imapsync. SIEMPRE externo -> panel (import); el panel
 * nunca es origen (sin exfiltración). Solo grupo admin (x_permissions + requireAdmin). El worker
 * (hook del daemon) procesa la cola respetando los límites configurables. Las contraseñas NO se
 * guardan en la BD: van a un passfile protegido por trabajo, borrado al terminar.
 */
class module_controller extends ctrl_module
{
    private static $flash = null;
    const RUNDIR = '/var/sentora/run/imapsync/';

    // El MÓDULO es de usuario (cada uno migra su propio correo). Solo los AJUSTES son de admin.
    private static function isAdmin(): bool
    {
        $u = ctrl_users::GetUserDetail();
        return (int)($u['usergroupid'] ?? 3) === 1;
    }
    private static function requireAdmin(): void
    {
        if (!self::isAdmin()) { header('Location: ./?module=dashboard'); exit; }
    }

    static function getDescription() { return ui_module::GetModuleDescription(); }
    static function getModuleName()  { return ui_module::GetModuleName(); }

    // Conmutador de vista: log (show=log) vs principal.
    static function getIsViewLog()  { return isset($_GET['show']) && $_GET['show'] === 'log'; }
    static function getIsSettings() { return isset($_GET['show']) && $_GET['show'] === 'settings'; }
    static function getIsMain()     { return !self::getIsViewLog() && !self::getIsSettings(); }
    static function getAdmin()      { return self::isAdmin(); } // para <% if Admin %> en la plantilla

    static function getFlash()
    {
        if (empty($_SESSION['imapsync_flash'])) return '';
        list($t, $m) = $_SESSION['imapsync_flash']; unset($_SESSION['imapsync_flash']);
        return ui_sysmessage::shout($m, $t === 'ok' ? 'zannounceok' : 'zannounce');
    }

    // ---- helpers ----
    private static function opt($k, $def) { $v = ctrl_options::GetSystemOption($k); return ($v === false || $v === null || $v === '') ? $def : $v; }
    private static function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

    // ---- Formulario de migración ----
    static function getMigrateFormHTML()
    {
        global $zdbh;
        $cu = ctrl_users::GetUserDetail();
        // Desplegable de buzones (destino, siempre local). El usuario ve SOLO los suyos; el admin, todos.
        if (self::isAdmin()) {
            $st = $zdbh->query("SELECT mb_address_vc FROM x_mailboxes WHERE mb_deleted_ts IS NULL ORDER BY mb_address_vc");
        } else {
            $st = $zdbh->prepare("SELECT mb_address_vc FROM x_mailboxes WHERE mb_acc_fk=:u AND mb_deleted_ts IS NULL ORDER BY mb_address_vc");
            $st->execute(array(':u' => (int)$cu['userid']));
        }
        $opts = '';
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $addr) {
            $opts .= '<option value="' . self::esc($addr) . '">' . self::esc($addr) . '</option>';
        }
        if ($opts === '') { return '<p>No hay buzones locales. Crea alguno en el módulo de correo primero.</p>'; }
        $csrf = runtime_csfr::Token();
        $h  = '<form action="./?module=imapsync&action=Launch" method="post">' . $csrf;
        $h .= '<table class="zform table"><tr><td style="width:280px;"><b>Buzón destino (en el panel)</b></td><td><select name="inDest" required class="form-select form-select-sm" style="max-width:360px;">' . $opts . '</select></td></tr>';
        $h .= '<tr><td>Contraseña del buzón destino</td><td><input type="password" name="inDestPass" required class="form-control form-control-sm" style="max-width:360px;"></td></tr>';
        $h .= '<tr><td colspan="2"><hr><b>Cuenta externa de ORIGEN</b></td></tr>';
        $h .= '<tr><td>Servidor origen (IP o dominio)</td><td><input type="text" name="inSrcHost" required placeholder="mail.proveedor-viejo.com" class="form-control form-control-sm" style="max-width:360px;"></td></tr>';
        $h .= '<tr><td>Puerto / seguridad</td><td><input type="number" name="inSrcPort" value="993" class="form-control form-control-sm" style="width:110px;display:inline-block;"> '
            . '<select name="inSrcSsl" class="form-select form-select-sm" style="width:150px;display:inline-block;"><option value="ssl">SSL (993)</option><option value="tls">STARTTLS (143)</option><option value="none">Sin cifrado</option></select></td></tr>';
        $h .= '<tr><td>Usuario origen</td><td><input type="text" name="inSrcUser" required placeholder="usuario@proveedor-viejo.com" class="form-control form-control-sm" style="max-width:360px;"></td></tr>';
        $h .= '<tr><td>Contraseña origen</td><td><input type="password" name="inSrcPass" required class="form-control form-control-sm" style="max-width:360px;"></td></tr>';
        $h .= '<tr><td>Carpetas a migrar</td><td>'
            . '<label style="margin-right:18px;"><input type="checkbox" name="inIncSpam" value="1"> Incluir <b>Spam/Junk</b></label>'
            . '<label><input type="checkbox" name="inIncTrash" value="1"> Incluir <b>Papelera/Trash</b></label>'
            . '<br><small class="text-muted">Por defecto se copian todas MENOS Spam y Papelera. Se preservan la fecha y el estado leído/no leído de cada correo, y se mapean Enviados/Borradores.</small></td></tr>';
        $h .= '<tr><td></td><td><button class="btn btn-primary button-loader" type="submit"><i class="bi bi-play-fill me-1"></i>Encolar migración</button> '
            . '<small class="text-muted">Se ejecuta en segundo plano, por tandas, respetando los límites.</small></td></tr></table></form>';
        return $h;
    }

    // ---- Lista de trabajos ----
    static function getJobsHTML()
    {
        global $zdbh;
        $cu = ctrl_users::GetUserDetail();
        if (self::isAdmin()) {
            $rows = $zdbh->query("SELECT * FROM x_imapsync_jobs WHERE ij_deleted_ts IS NULL ORDER BY ij_id_pk DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $st = $zdbh->prepare("SELECT * FROM x_imapsync_jobs WHERE ij_acc_fk=:u AND ij_deleted_ts IS NULL ORDER BY ij_id_pk DESC LIMIT 100");
            $st->execute(array(':u' => (int)$cu['userid']));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        if (!$rows) return '<p>No hay trabajos todavía.</p>';
        $col = array('queued' => '#616161', 'running' => '#1565c0', 'partial' => '#e65100', 'done' => '#2e7d32', 'error' => '#c62828', 'canceled' => '#616161');
        $h = '<table class="table"><tr><th>#</th><th>Destino</th><th>Origen</th><th>Estado</th><th>Progreso</th><th>Última</th><th>Acciones</th></tr>';
        foreach ($rows as $r) {
            $st = $r['ij_status_vc'];
            $c  = $col[$st] ?? '#616161';
            $prog = ((int)$r['ij_msgs_in']) . ($r['ij_total_msgs_in'] ? ' / ' . (int)$r['ij_total_msgs_in'] : '') . ' msgs';
            if ((int)$r['ij_bytes_bi'] > 0) { $prog .= ' · ' . round($r['ij_bytes_bi'] / 1048576, 1) . ' MB'; }
            $last = $r['ij_lastrun_ts'] ? gmdate('Y-m-d H:i', $r['ij_lastrun_ts']) : '—';
            $csrf = runtime_csfr::Token();
            $act  = '';
            if (in_array($st, array('queued', 'running', 'partial'), true)) {
                $act .= '<form action="./?module=imapsync&action=CancelJob" method="post" style="display:inline">' . $csrf
                     . '<input type="hidden" name="inId" value="' . (int)$r['ij_id_pk'] . '"><button class="btn btn-sm btn-warning" type="submit">Cancelar</button></form> ';
            }
            $act .= '<a class="btn btn-sm btn-secondary" href="./?module=imapsync&show=log&id=' . (int)$r['ij_id_pk'] . '">Log</a>';
            $h .= '<tr><td>' . (int)$r['ij_id_pk'] . '</td><td>' . self::esc($r['ij_dest_user_vc']) . '</td>'
                . '<td>' . self::esc($r['ij_src_user_vc']) . '<br><small class="text-muted">' . self::esc($r['ij_src_host_vc']) . '</small></td>'
                . '<td><b style="color:' . $c . '">' . self::esc($st) . '</b>';
            if ($st === 'error' && !empty($r['ij_error_tx'])) { $h .= '<br><small style="color:#c62828">' . self::esc(substr($r['ij_error_tx'], 0, 120)) . '</small>'; }
            $h .= '</td><td>' . self::esc($prog) . '</td><td>' . self::esc($last) . '</td><td>' . $act . '</td></tr>';
        }
        return $h . '</table>';
    }

    // ---- Ajustes ----
    static function getSettingsHTML()
    {
        self::requireAdmin(); // vista dedicada de administrador
        // Etiqueta => [texto, valor por defecto, ámbito]. Los límites de recursos son POR PROCESO
        // (cada migración se limita por estos criterios, NO es la suma de todas); la concurrencia es
        // el tope GLOBAL de procesos a la vez, y las ejecuciones/día son por cuenta.
        $f = array(
            'imapsync_max_concurrent'   => array('Máx. procesos simultáneos', 2,   'global (todo el servidor)'),
            'imapsync_max_per_acct_day' => array('Máx. ejecuciones por cuenta y día', 5, 'por cuenta'),
            'imapsync_job_timeout'      => array('Timeout por tanda (segundos)', 900, 'por proceso'),
            'imapsync_nice'             => array('Prioridad CPU (nice 0-19)', 19,   'por proceso'),
            'imapsync_max_bytes_sec'    => array('Límite bytes/seg (0 = sin límite)', 0, 'por proceso'),
            'imapsync_max_msgs_sec'     => array('Límite mensajes/seg (0 = sin límite)', 0, 'por proceso'),
        );
        $csrf = runtime_csfr::Token();
        $h  = '<a class="btn btn-sm btn-secondary" href="./?module=imapsync">&larr; Volver a migraciones</a>';
        $h .= '<p class="text-muted">Estos límites los fija el administrador y se aplican a CADA proceso de migración por igual (no a la suma).</p>';
        $h .= '<form action="./?module=imapsync&action=SaveSettings" method="post">' . $csrf . '<table class="zform table">';
        foreach ($f as $k => $d) {
            $h .= '<tr><td style="width:320px;">' . self::esc($d[0]) . ' <small class="text-muted">(' . self::esc($d[2]) . ')</small></td>'
                . '<td><input type="number" name="' . $k . '" value="' . self::esc(self::opt($k, $d[1])) . '" class="form-control form-control-sm" style="width:140px;"></td></tr>';
        }
        $h .= '<tr><td></td><td><button class="btn btn-primary" type="submit"><i class="bi bi-floppy me-1"></i>Guardar ajustes</button></td></tr></table></form>';
        return $h;
    }

    static function doSaveSettings()
    {
        global $controller;
        runtime_csfr::Protect();
        self::requireAdmin();
        foreach (array('imapsync_max_concurrent', 'imapsync_max_per_acct_day', 'imapsync_job_timeout', 'imapsync_nice', 'imapsync_max_bytes_sec', 'imapsync_max_msgs_sec') as $k) {
            $v = (int)$controller->GetControllerRequest('FORM', $k);
            if ($v < 0) $v = 0;
            if ($k === 'imapsync_nice' && $v > 19) $v = 19;
            ctrl_options::SetSystemOption($k, (string)$v);
        }
        $_SESSION['imapsync_flash'] = array('ok', 'Ajustes guardados.');
        if (!headers_sent()) { header('location: ./?module=imapsync'); exit; }
    }

    // ---- Lanzar (encolar) ----
    static function doLaunch()
    {
        global $zdbh, $controller;
        runtime_csfr::Protect();
        $cu   = ctrl_users::GetUserDetail();
        $dest = trim((string)$controller->GetControllerRequest('FORM', 'inDest'));
        $dpw  = (string)$controller->GetControllerRequest('FORM', 'inDestPass');
        $host = trim((string)$controller->GetControllerRequest('FORM', 'inSrcHost'));
        $port = (int)$controller->GetControllerRequest('FORM', 'inSrcPort');
        $ssl  = (string)$controller->GetControllerRequest('FORM', 'inSrcSsl');
        $suser= trim((string)$controller->GetControllerRequest('FORM', 'inSrcUser'));
        $spw  = (string)$controller->GetControllerRequest('FORM', 'inSrcPass');
        $incSpam  = $controller->GetControllerRequest('FORM', 'inIncSpam')  ? 1 : 0;
        $incTrash = $controller->GetControllerRequest('FORM', 'inIncTrash') ? 1 : 0;

        // Validaciones (dirección BLOQUEADA: destino = buzón local existente). Anti-IDOR: el buzón
        // destino debe ser del PROPIO usuario (el admin puede migrar a cualquiera).
        if (self::isAdmin()) {
            $ok = $zdbh->prepare("SELECT COUNT(*) FROM x_mailboxes WHERE mb_address_vc=:a AND mb_deleted_ts IS NULL");
            $ok->execute(array(':a' => $dest));
        } else {
            $ok = $zdbh->prepare("SELECT COUNT(*) FROM x_mailboxes WHERE mb_address_vc=:a AND mb_acc_fk=:u AND mb_deleted_ts IS NULL");
            $ok->execute(array(':a' => $dest, ':u' => (int)$cu['userid']));
        }
        if (!(int)$ok->fetchColumn())                                   { self::fail('El buzón destino no existe o no es tuyo.'); }
        if (!preg_match('/^[a-zA-Z0-9.-]{1,255}$/', $host))             { self::fail('Servidor de origen no válido.'); }
        if (!preg_match('/^[^\s@]{1,120}@?[a-zA-Z0-9.-]{0,255}$/', $suser)) { self::fail('Usuario de origen no válido.'); }
        if ($port < 1 || $port > 65535) $port = 993;
        if (!in_array($ssl, array('ssl', 'tls', 'none'), true)) $ssl = 'ssl';
        if ($dpw === '' || $spw === '')                                { self::fail('Faltan contraseñas.'); }

        // Límite por cuenta y día.
        $max = (int)self::opt('imapsync_max_per_acct_day', 5);
        $cnt = $zdbh->prepare("SELECT COUNT(*) FROM x_imapsync_jobs WHERE ij_acc_fk=:u AND ij_created_ts > (UNIX_TIMESTAMP()-86400)");
        $cnt->execute(array(':u' => (int)$cu['userid']));
        if ((int)$cnt->fetchColumn() >= $max)                          { self::fail('Alcanzado el límite de ' . $max . ' migraciones por cuenta y día.'); }

        // Insertar el trabajo (encolado) y escribir el passfile protegido (origen y destino).
        $zdbh->prepare("INSERT INTO x_imapsync_jobs (ij_acc_fk,ij_dest_user_vc,ij_src_host_vc,ij_src_port_in,ij_src_ssl_vc,ij_src_user_vc,ij_inc_spam_in,ij_inc_trash_in,ij_status_vc,ij_created_ts,ij_updated_ts)
                        VALUES (:u,:d,:h,:p,:s,:su,:isp,:itr,'queued',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())")
             ->execute(array(':u' => (int)$cu['userid'], ':d' => $dest, ':h' => $host, ':p' => $port, ':s' => $ssl, ':su' => $suser, ':isp' => $incSpam, ':itr' => $incTrash));
        $jid = (int)$zdbh->lastInsertId();
        @mkdir(self::RUNDIR, 0770, true);
        $pf = self::RUNDIR . $jid . '.pass';
        // línea 1 = pass origen, línea 2 = pass destino (el runner las separa en passfiles de imapsync)
        if (@file_put_contents($pf, $spw . "\n" . $dpw . "\n") !== false) { @chmod($pf, 0600); }
        $log = self::RUNDIR . $jid . '.log';
        $zdbh->prepare("UPDATE x_imapsync_jobs SET ij_passfile_vc=:pf, ij_log_vc=:lg WHERE ij_id_pk=:id")
             ->execute(array(':pf' => $pf, ':lg' => $log, ':id' => $jid));

        $_SESSION['imapsync_flash'] = array('ok', 'Migración #' . $jid . ' encolada. Se ejecutará en segundo plano por tandas.');
        if (!headers_sent()) { header('location: ./?module=imapsync'); exit; }
    }

    private static function fail($msg)
    {
        $_SESSION['imapsync_flash'] = array('err', $msg);
        if (!headers_sent()) { header('location: ./?module=imapsync'); exit; }
        exit;
    }

    static function doCancelJob()
    {
        global $zdbh, $controller;
        runtime_csfr::Protect();
        $cu = ctrl_users::GetUserDetail();
        $id = (int)$controller->GetControllerRequest('FORM', 'inId');
        // Anti-IDOR: solo el dueño del trabajo (o el admin) puede cancelarlo.
        $own = self::isAdmin() ? '' : ' AND ij_acc_fk=' . (int)$cu['userid'];
        $j = $zdbh->prepare("SELECT ij_passfile_vc FROM x_imapsync_jobs WHERE ij_id_pk=:id" . $own);
        $j->execute(array(':id' => $id));
        $pf = $j->fetchColumn();
        if ($pf === false) { $_SESSION['imapsync_flash'] = array('err', 'Trabajo no encontrado.'); if (!headers_sent()) { header('location: ./?module=imapsync'); exit; } }
        if ($pf && is_file($pf)) { @unlink($pf); }
        $zdbh->prepare("UPDATE x_imapsync_jobs SET ij_status_vc='canceled', ij_updated_ts=UNIX_TIMESTAMP() WHERE ij_id_pk=:id AND ij_status_vc IN ('queued','running','partial')" . $own)
             ->execute(array(':id' => $id));
        $_SESSION['imapsync_flash'] = array('ok', 'Trabajo #' . $id . ' cancelado.');
        if (!headers_sent()) { header('location: ./?module=imapsync'); exit; }
    }

    // Muestra el log del trabajo (solo lectura, admin).
    static function getViewLogHTML()
    {
        global $zdbh;
        $cu = ctrl_users::GetUserDetail();
        if (!isset($_GET['id'])) return '';
        // Anti-IDOR: solo el log de un trabajo propio (o cualquiera si admin).
        $own = self::isAdmin() ? '' : ' AND ij_acc_fk=' . (int)$cu['userid'];
        $j = $zdbh->prepare("SELECT ij_log_vc FROM x_imapsync_jobs WHERE ij_id_pk=:id AND ij_deleted_ts IS NULL" . $own);
        $j->execute(array(':id' => (int)$_GET['id']));
        $log = $j->fetchColumn();
        $lines = ($log && is_file($log)) ? @file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
        $tail = $lines ? implode("\n", array_slice($lines, -200)) : 'Sin log todavía.';
        return '<a class="btn btn-sm btn-secondary" href="./?module=imapsync">&larr; Volver</a>'
            . '<pre style="max-height:520px;overflow:auto;background:#111;color:#ddd;padding:8px;border-radius:4px;font-size:12px;">' . self::esc($tail) . '</pre>';
    }
}

<?php
/**
 * Updates — estado de actualizaciones del sistema (paquetes pkg + parches base FreeBSD) y del panel.
 * El chequeo pesado lo hace el daemon diario / un script en 2º plano y se cachea en status.json;
 * la página solo LEE esa caché (carga instantánea). Acciones (aplicar) solo admin, vía doas.
 */
require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';

class module_controller extends ctrl_module
{
    const STATUS_FILE = '/var/bulwark/updates/status.json';
    const PINS_FILE   = '/var/bulwark/updates/pkgpins.json';
    const RUN_FILE    = '/var/bulwark/updates/running';
    const RESULT_FILE = '/var/bulwark/updates/last_result';
    const LOG_FILE    = '/var/bulwark/updates/last_action.log';

    // Paquetes gestionados con pin de mayor (espejo de la whitelist de pkg_pin.sh, para validar
    // la acción de verificación). La fuente única real es MANAGED en pkg_pin.sh.
    const MANAGED = array('dovecot-mysql', 'proftpd', 'redis', 'postfix-mysql', 'rspamd', 'opendkim');

    static $ok_msg;
    static $err_msg;

    // ---- helpers -------------------------------------------------------------------------------
    private static function isAdmin()
    {
        $u = ctrl_users::GetUserDetail();
        return (int)($u['usergroupid'] ?? 0) === ctrl_groups::GROUP_ADMIN;
    }

    private static function status()
    {
        if (!is_readable(self::STATUS_FILE)) return null;
        $j = json_decode((string)@file_get_contents(self::STATUS_FILE), true);
        return is_array($j) ? $j : null;
    }

    private static function pins()
    {
        if (!is_readable(self::PINS_FILE)) return null;
        $j = json_decode((string)@file_get_contents(self::PINS_FILE), true);
        return (is_array($j) && isset($j['packages']) && is_array($j['packages'])) ? $j : null;
    }

    /** Tarea en curso ('check'|'pkg'|'base'|'pin') o '' si no hay ninguna. */
    private static function running()
    {
        return is_readable(self::RUN_FILE) ? trim((string)@file_get_contents(self::RUN_FILE)) : '';
    }

    private static function runPriv($cmd, array $args = array())
    {
        if (!class_exists('privilege')) require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
        privilege::run($cmd, $args, true);
    }

    // ---- acciones (solo admin) -----------------------------------------------------------------
    static function doCheckNow()
    {
        runtime_csfr::Protect();
        if (!self::isAdmin()) { self::$err_msg = 'Solo el administrador puede comprobar actualizaciones.'; return; }
        try { self::runPriv('sys_update_check'); self::$ok_msg = 'Comprobación lanzada. Se actualizará en unos segundos.'; }
        catch (Exception $e) { self::$err_msg = 'No se pudo lanzar la comprobación: ' . $e->getMessage(); }
    }

    static function doUpgradePackages()
    {
        runtime_csfr::Protect();
        if (!self::isAdmin()) { self::$err_msg = 'Solo el administrador puede actualizar paquetes.'; return; }
        try { self::runPriv('pkg_upgrade'); self::$ok_msg = 'Actualización de paquetes iniciada en segundo plano.'; }
        catch (Exception $e) { self::$err_msg = 'No se pudo iniciar: ' . $e->getMessage(); }
    }

    static function doApplyBasePatches()
    {
        runtime_csfr::Protect();
        if (!self::isAdmin()) { self::$err_msg = 'Solo el administrador puede aplicar parches del sistema base.'; return; }
        try { self::runPriv('freebsd_update_apply'); self::$ok_msg = 'Aplicación de parches base iniciada en segundo plano.'; }
        catch (Exception $e) { self::$err_msg = 'No se pudo iniciar: ' . $e->getMessage(); }
    }

    static function doUpdatePanel()
    {
        runtime_csfr::Protect();
        if (!self::isAdmin()) { self::$err_msg = 'Solo el administrador puede actualizar el panel.'; return; }
        try { self::runPriv('panel_update'); self::$ok_msg = 'Actualización del panel iniciada en segundo plano.'; }
        catch (Exception $e) { self::$err_msg = 'No se pudo iniciar: ' . $e->getMessage(); }
    }

    static function doVerifyMajor()
    {
        runtime_csfr::Protect();
        if (!self::isAdmin()) { self::$err_msg = 'Solo el administrador puede actualizar paquetes.'; return; }
        $pkg = (string)$GLOBALS['controller']->GetControllerRequest('FORM', 'inPkg');
        if (!in_array($pkg, self::MANAGED, true)) { self::$err_msg = 'Paquete no gestionado.'; return; }
        try {
            self::runPriv('pkg_pin_verify', array($pkg));
            self::$ok_msg = 'Salto de versión mayor de "' . htmlspecialchars($pkg, ENT_QUOTES) . '" iniciado en segundo plano.';
        } catch (Exception $e) {
            self::$err_msg = 'No se pudo iniciar: ' . $e->getMessage();
        }
    }

    static function getPanelStatusHTML()
    {
        $admin   = self::isAdmin();
        $running = self::running();
        $st      = self::status();
        $csrf    = self::getCSFR_Tag();
        $ver     = htmlspecialchars((string)ctrl_options::GetSystemOption('dbversion'), ENT_QUOTES, 'UTF-8');

        $behind = $st ? (int)($st['panel_behind'] ?? 0) : 0;
        $local  = $st ? htmlspecialchars(trim((string)($st['panel_local'] ?? '')), ENT_QUOTES) : '';
        $log    = $st ? trim((string)($st['panel_log'] ?? '')) : '';

        $h  = '<p style="margin-bottom:6px;">Versión del panel: <strong>' . $ver . '</strong>'
            . ($local ? ' <small class="text-muted">(' . $local . ')</small>' : '') . '</p>';
        if ($st === null) {
            $h .= '<p class="text-muted">Sin datos aún. Pulsa "Comprobar ahora".</p>';
        } elseif ($behind > 0) {
            $h .= '<p><span class="badge bg-warning">' . $behind . '</span> actualización(es) del panel disponibles.</p>';
            if ($log !== '') {
                $h .= '<details style="margin-bottom:8px;"><summary style="cursor:pointer;">Ver cambios</summary>'
                    . '<pre style="font-size:12px;max-height:200px;overflow:auto;border:1px solid #e5e7eb;border-radius:6px;padding:8px;white-space:pre-wrap;">'
                    . htmlspecialchars($log, ENT_QUOTES) . '</pre></details>';
            }
        } else {
            $h .= '<p><span class="badge bg-success">Al día</span></p>';
        }

        if ($admin && $running === '' && $behind > 0) {
            $h .= '<form method="post" action="./?module=updates&action=UpdatePanel" style="display:inline;">' . $csrf
                . '<button type="submit" class="btn btn-primary" onclick="return confirm(\'Actualizar el panel ahora (git pull)? Las migraciones de BD no son automáticas.\')">'
                . '<i class="bi bi-cloud-arrow-down me-1"></i>Actualizar panel</button></form>';
        } elseif ($running === 'panel') {
            $h .= '<div class="alert alert-info" style="margin:0;"><i class="bi bi-hourglass-split me-1"></i>Actualizando el panel… <small>(la página se refresca sola)</small></div>';
        }
        return $h;
    }

    // ---- getters de vista ----------------------------------------------------------------------
    static function getResult()
    {
        if (self::$err_msg) return ui_sysmessage::shout(ui_language::translate(self::$err_msg), 'zannounceerror');
        if (self::$ok_msg)  return ui_sysmessage::shout(ui_language::translate(self::$ok_msg), 'zannounceok');
        return '';
    }

    public static function getBulwarkUpdates()
    {
        $installed = htmlspecialchars((string)ctrl_options::GetSystemOption('dbversion'), ENT_QUOTES, 'UTF-8');
        return 'Versión del panel: <strong>' . $installed . '</strong>';
    }

    /** Autorrefresco de la página mientras hay una tarea en curso. */
    static function getAutoRefresh()
    {
        return self::running() !== '' ? '<meta http-equiv="refresh" content="8">' : '';
    }

    /** Mensaje de resultado de la ÚLTIMA acción aplicada (éxito/fallo) + detalle del log.
     *  Solo cuando NO hay tarea en curso (para no mostrar un resultado viejo a medias). */
    static function getActionResult()
    {
        if (self::running() !== '') return '';
        if (!is_readable(self::RESULT_FILE)) return '';
        $p      = explode('|', trim((string)@file_get_contents(self::RESULT_FILE)));
        $action = $p[0] ?? '';
        $rc     = (int)($p[1] ?? 1);
        $ts     = (int)($p[2] ?? 0);
        $n      = $p[3] ?? '';
        $label  = ($action === 'pkg') ? 'Actualización de paquetes'
                : (($action === 'base') ? 'Parches del sistema base'
                : (($action === 'pin') ? 'Actualización de paquete gestionado' : 'Acción'));
        $when   = $ts ? ' (' . date('d/m/Y H:i', $ts) . ')' : '';

        if ($rc === 0) {
            $extra = '';
            if ($action === 'pkg' && $n !== '' && (int)$n > 0) { $extra = ' — ' . (int)$n . ' paquete(s) afectados'; }
            elseif ($action === 'pin' && $n !== '') { $extra = ' — ' . htmlspecialchars((string)$n, ENT_QUOTES); }
            $msg = ui_sysmessage::shout($label . ' completada correctamente' . $extra . '.' . $when, 'zannounceok');
        } else {
            $msg = ui_sysmessage::shout($label . ' terminó con ERRORES (código ' . $rc . ').' . $when, 'zannounceerror');
        }
        // Detalle (log de la última acción), plegable.
        if (is_readable(self::LOG_FILE)) {
            $log = htmlspecialchars((string)@file_get_contents(self::LOG_FILE), ENT_QUOTES, 'UTF-8');
            if ($log !== '') {
                $msg .= '<details style="margin:6px 0 10px;"><summary style="cursor:pointer;">Ver detalle</summary>'
                      . '<pre style="font-size:12px;max-height:260px;overflow:auto;border:1px solid #e5e7eb;border-radius:6px;padding:8px;white-space:pre-wrap;">'
                      . $log . '</pre></details>';
            }
        }
        return $msg;
    }

    static function getManagedPackagesHTML()
    {
        $admin   = self::isAdmin();
        $running = self::running();
        $pins    = self::pins();
        $csrf    = self::getCSFR_Tag();

        $intro = '<p class="text-muted" style="font-size:12px;margin-bottom:8px;">'
               . 'Paquetes críticos con la <strong>mayor bloqueada</strong>: las subversiones (parches de '
               . 'seguridad) se aplican solas; un salto de <strong>mayor</strong> se retiene hasta que lo '
               . 'verificas aquí.</p>';

        if ($pins === null) {
            return $intro . '<p class="text-muted">Sin datos aún. Pulsa "Comprobar ahora".</p>';
        }
        if (empty($pins['packages'])) {
            return $intro . '<p class="text-muted">No hay paquetes gestionados instalados en este servidor.</p>';
        }

        $stateBadge = function ($state) {
            switch ($state) {
                case 'subversion': return '<span class="badge bg-warning">Subversión disponible</span>';
                case 'major':      return '<span class="badge bg-danger">Nueva mayor</span>';
                default:           return '<span class="badge bg-success">Al día</span>';
            }
        };

        $h  = $intro;
        $h .= '<table class="table table-sm align-middle" style="max-width:600px;margin-bottom:6px;">';
        $h .= '<thead><tr><th>Paquete</th><th>Instalada</th><th>Disponible</th><th>Estado</th>'
            . '<th style="text-align:right;">Acción</th></tr></thead><tbody>';
        foreach ($pins['packages'] as $p) {
            $name  = htmlspecialchars((string)($p['pkg'] ?? ''), ENT_QUOTES);
            $inst  = htmlspecialchars((string)($p['installed'] ?? ''), ENT_QUOTES);
            $cand  = htmlspecialchars((string)($p['candidate'] ?? ''), ENT_QUOTES);
            $state = (string)($p['state'] ?? 'uptodate');
            $lock  = !empty($p['locked']) ? ' <i class="bi bi-lock-fill text-muted" title="Bloqueado (pin)"></i>' : '';

            $action = '<span class="text-muted">—</span>';
            if ($admin && $running === '' && $state === 'major' && in_array($p['pkg'] ?? '', self::MANAGED, true)) {
                $confirm = 'Actualizar ' . addslashes((string)$p['pkg']) . ' a la nueva versión MAYOR ('
                         . addslashes((string)($p['candidate'] ?? '')) . ')? Puede requerir migración de config y reiniciar el servicio.';
                $action = '<form method="post" action="./?module=updates&action=VerifyMajor" style="display:inline;">' . $csrf
                        . '<input type="hidden" name="inPkg" value="' . $name . '">'
                        . '<button type="submit" class="btn btn-sm btn-danger" onclick="return confirm(\'' . htmlspecialchars($confirm, ENT_QUOTES) . '\')">'
                        . '<i class="bi bi-arrow-up-circle me-1"></i>Verificar y actualizar</button></form>';
            } elseif ($state === 'subversion') {
                $action = '<span class="text-muted" style="font-size:12px;">automática</span>';
            }

            $h .= '<tr><td><strong>' . $name . '</strong>' . $lock . '</td>'
                . '<td>' . $inst . '</td><td>' . $cand . '</td>'
                . '<td>' . $stateBadge($state) . '</td>'
                . '<td style="text-align:right;">' . $action . '</td></tr>';
        }
        $h .= '</tbody></table>';

        if ($running === 'pin') {
            $h .= '<div class="alert alert-info" style="margin:6px 0 0;"><i class="bi bi-hourglass-split me-1"></i>'
                . 'Aplicando actualización de paquete… <small>(la página se refresca sola)</small></div>';
        }
        return $h;
    }

    static function getSystemStatusHTML()
    {
        $admin   = self::isAdmin();
        $running = self::running();
        $st      = self::status();
        $csrf    = self::getCSFR_Tag();

        if ($st === null) {
            $body = '<p class="text-muted">Aún no se ha comprobado. Pulsa "Comprobar ahora" o espera al chequeo diario.</p>';
        } else {
            $pkg   = (int)($st['pkg_updatable'] ?? 0);
            $audit = (int)($st['pkg_audit'] ?? 0);
            $base  = (int)($st['base_patches'] ?? 0);
            $when  = !empty($st['checked_ts']) ? date('d/m/Y H:i', (int)$st['checked_ts']) : '—';

            $badge = function ($n, $okTxt, $cls = 'warning') {
                return $n > 0
                    ? '<span class="badge bg-' . $cls . '">' . $n . '</span>'
                    : '<span class="badge bg-success">' . $okTxt . '</span>';
            };

            $body  = '<table class="table table-sm align-middle" style="max-width:560px;margin-bottom:10px;">';
            $body .= '<tr><td><i class="bi bi-shield-exclamation me-1"></i>Avisos de seguridad (paquetes vulnerables)</td>'
                   . '<td style="text-align:right;">' . $badge($audit, 'Ninguno', 'danger') . '</td></tr>';
            $body .= '<tr><td><i class="bi bi-box-seam me-1"></i>Paquetes actualizables</td>'
                   . '<td style="text-align:right;">' . $badge($pkg, 'Al día') . '</td></tr>';
            $body .= '<tr><td><i class="bi bi-hdd-stack me-1"></i>Parches del sistema base</td>'
                   . '<td style="text-align:right;">' . $badge($base, 'Al día') . '</td></tr>';
            $body .= '</table>';
            $body .= '<p class="text-muted" style="font-size:12px;">Último chequeo: ' . htmlspecialchars($when, ENT_QUOTES)
                   . '. <em>Los paquetes críticos con pin (dovecot, postfix, redis…) no se cuentan aquí; se gestionan en la tarjeta inferior.</em></p>';

            if ($audit > 0 && !empty($st['audit_list'])) {
                $body .= '<div class="alert alert-danger" style="font-size:12px;white-space:pre-wrap;max-height:140px;overflow:auto;">'
                       . htmlspecialchars((string)$st['audit_list'], ENT_QUOTES) . '</div>';
            }
            if ($pkg > 0 && !empty($st['pkg_list'])) {
                $body .= '<details style="margin-bottom:10px;"><summary style="cursor:pointer;">Ver paquetes (' . $pkg . ')</summary>'
                       . '<div style="font-size:12px;white-space:pre-wrap;max-height:200px;overflow:auto;border:1px solid #e5e7eb;border-radius:6px;padding:8px;margin-top:6px;">'
                       . htmlspecialchars((string)$st['pkg_list'], ENT_QUOTES) . '</div></details>';
            }
        }

        // Botones (solo admin). Deshabilitados mientras hay una tarea en curso.
        $buttons = '';
        if ($admin) {
            if ($running !== '') {
                $map = array('check' => 'Comprobando…', 'pkg' => 'Actualizando paquetes…', 'base' => 'Aplicando parches base…');
                $buttons = '<div class="alert alert-info" style="margin:0;"><i class="bi bi-hourglass-split me-1"></i>'
                         . htmlspecialchars($map[$running] ?? 'Tarea en curso…', ENT_QUOTES)
                         . ' <small>(la página se refresca sola)</small></div>';
            } else {
                $mk = function ($action, $label, $cls, $confirm) use ($csrf) {
                    $onclick = $confirm ? ' onclick="return confirm(\'' . $confirm . '\')"' : '';
                    return '<form method="post" action="./?module=updates&action=' . $action . '" style="display:inline;">'
                         . $csrf
                         . '<button type="submit" class="btn ' . $cls . '"' . $onclick . '>' . $label . '</button></form> ';
                };
                $buttons  = $mk('CheckNow', '<i class="bi bi-arrow-repeat me-1"></i>Comprobar ahora', 'btn-secondary', '');
                $buttons .= $mk('UpgradePackages', '<i class="bi bi-box-arrow-down me-1"></i>Actualizar paquetes', 'btn-primary',
                                'Actualizar TODOS los paquetes ahora? Puede reiniciar servicios.');
                $buttons .= $mk('ApplyBasePatches', '<i class="bi bi-shield-check me-1"></i>Aplicar parches base', 'btn-warning',
                                'Aplicar los parches de seguridad del sistema base?');
            }
        } else {
            $buttons = '<p class="text-muted">Solo el administrador puede aplicar actualizaciones.</p>';
        }

        return $body . '<div style="margin-top:8px;">' . $buttons . '</div>';
    }
}

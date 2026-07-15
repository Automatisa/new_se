<?php
/**
 * Updates — estado de actualizaciones del sistema (paquetes pkg + parches base FreeBSD) y del panel.
 * El chequeo pesado lo hace el daemon diario / un script en 2º plano y se cachea en status.json;
 * la página solo LEE esa caché (carga instantánea). Acciones (aplicar) solo admin, vía doas.
 */
require_once '/usr/local/sentora/dryden/sys/privilege.class.php';

class module_controller extends ctrl_module
{
    const STATUS_FILE = '/var/sentora/updates/status.json';
    const RUN_FILE    = '/var/sentora/updates/running';

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

    /** Tarea en curso ('check'|'pkg'|'base') o '' si no hay ninguna. */
    private static function running()
    {
        return is_readable(self::RUN_FILE) ? trim((string)@file_get_contents(self::RUN_FILE)) : '';
    }

    private static function runPriv($cmd)
    {
        if (!class_exists('privilege')) require_once '/usr/local/sentora/dryden/sys/privilege.class.php';
        privilege::run($cmd, array(), true);
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

    // ---- getters de vista ----------------------------------------------------------------------
    static function getResult()
    {
        if (self::$err_msg) return ui_sysmessage::shout(ui_language::translate(self::$err_msg), 'zannounceerror');
        if (self::$ok_msg)  return ui_sysmessage::shout(ui_language::translate(self::$ok_msg), 'zannounceok');
        return '';
    }

    public static function getSentoraUpdates()
    {
        $installed = htmlspecialchars((string)ctrl_options::GetSystemOption('dbversion'), ENT_QUOTES, 'UTF-8');
        return 'Versión del panel: <strong>' . $installed . '</strong>';
    }

    /** Autorrefresco de la página mientras hay una tarea en curso. */
    static function getAutoRefresh()
    {
        return self::running() !== '' ? '<meta http-equiv="refresh" content="8">' : '';
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
            $body .= '<p class="text-muted" style="font-size:12px;">Último chequeo: ' . htmlspecialchars($when, ENT_QUOTES) . '</p>';

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

<?php

/**
 * Módulo de administración: Estado de Let's Encrypt (solo grupo admin).
 *
 * Seguridad: el acceso al módulo ya está restringido por x_permissions (no se concede a los grupos
 * de usuario/reseller), y además requireAdmin() redirige si no es admin (defensa en profundidad).
 * Solo lectura: muestra el estado cacheado en x_le_status (que rellena el daemon del módulo sencrypt),
 * el estado global (ajustes le_*), la cola de pendientes/reemisión y las últimas líneas de sencrypt.log.
 */
class module_controller extends ctrl_module
{
    // Redirige a cualquiera que no sea administrador (grupo 1).
    private static function requireAdmin(): void
    {
        $u = ctrl_users::GetUserDetail();
        if ((int)($u['usergroupid'] ?? 3) !== 1) {
            header('Location: ./?module=dashboard');
            exit;
        }
    }

    static function getDescription() { return ui_module::GetModuleDescription(); }
    static function getModuleName()  { return ui_module::GetModuleName(); }

    static function getLeStatus()
    {
        global $zdbh;
        self::requireAdmin();
        $esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

        // --- 1. Estado global ---
        $staging = ctrl_options::GetSystemOption('le_staging') === 'true';
        $ari     = ctrl_options::GetSystemOption('le_ari_enabled') === 'true';
        $cap     = (int)ctrl_options::GetSystemOption('le_max_per_run'); if ($cap <= 0) $cap = 100;
        $backoff = (int)ctrl_options::GetSystemOption('le_backoff_until');
        $boActive = time() < $backoff;
        $h  = '<div class="zgrid_wrapper"><h3>Estado global</h3><p style="line-height:2;">';
        $h .= 'Entorno: <b style="color:' . ($staging ? '#e65100' : '#2e7d32') . ';">' . ($staging ? 'STAGING (pruebas)' : 'Producción') . '</b> &nbsp;·&nbsp; ';
        $h .= 'ARI: <b>' . ($ari ? 'activo' : 'inactivo') . '</b> &nbsp;·&nbsp; ';
        $h .= 'Máx. emisiones por pasada: <b>' . $cap . '</b> &nbsp;·&nbsp; ';
        $h .= 'Backoff (rate-limit): <b style="color:' . ($boActive ? '#c62828' : '#2e7d32') . ';">' . ($boActive ? ('activo hasta ' . gmdate('Y-m-d H:i', $backoff) . ' UTC') : 'no') . '</b>';
        $h .= '</p></div>';

        // --- 2. Tabla de certificados (todos los usuarios) ---
        $h .= '<div class="zgrid_wrapper"><h3>Certificados (todos los usuarios)</h3><table class="table">';
        $h .= '<tr><th>Dominio</th><th>Propietario</th><th>Entorno</th><th>Estado</th><th>Caduca</th><th>Renovación (ARI)</th><th>Emitido</th><th>Actualizado</th></tr>';
        $rows = $zdbh->query("SELECT * FROM x_le_status ORDER BY (ls_expires_ts IS NULL) DESC, ls_expires_ts ASC")->fetchAll(PDO::FETCH_ASSOC);
        $labels = array('valid' => array('Válido', '#2e7d32'), 'expiring' => array('Por caducar', '#e65100'),
                        'expired' => array('Caducado', '#c62828'), 'missing' => array('Sin emitir', '#616161'),
                        'error' => array('Error', '#c62828'), 'unknown' => array('—', '#616161'));
        if (!$rows) {
            $h .= '<tr><td colspan="7">Sin datos todavía (se rellena en la próxima pasada del daemon).</td></tr>';
        } else {
            foreach ($rows as $r) {
                $st = $r['ls_state_vc'];
                list($lab, $col) = isset($labels[$st]) ? $labels[$st] : array($st, '#616161');
                $exp = $r['ls_expires_ts'] ? (floor(($r['ls_expires_ts'] - time()) / 86400) . ' días (' . gmdate('Y-m-d', $r['ls_expires_ts']) . ')') : '—';
                $iss = $r['ls_issued_ts'] ? gmdate('Y-m-d', $r['ls_issued_ts']) : '—';
                $upd = $r['ls_updated_ts'] ? gmdate('Y-m-d H:i', $r['ls_updated_ts']) : '—';
                // Renovación según ARI (ventana sugerida por LE + instante elegido); '—' si aún sin ARI.
                if (!empty($r['ls_renew_at_ts'])) {
                    $ren = gmdate('Y-m-d H:i', $r['ls_renew_at_ts']);
                    if (!empty($r['ls_ari_start_ts']) && !empty($r['ls_ari_end_ts'])) {
                        $ren .= '<br><small style="color:#888;">' . gmdate('m-d', $r['ls_ari_start_ts']) . '..' . gmdate('m-d', $r['ls_ari_end_ts']) . '</small>';
                    }
                } else {
                    $ren = '<small style="color:#888;">estática (30d)</small>';
                }
                $h .= '<tr><td>' . $esc($r['ls_domain_vc']) . '</td><td>' . $esc($r['ls_owner_vc']) . '</td><td>' . $esc($r['ls_env_vc']) . '</td>';
                $h .= '<td><span style="color:' . $col . ';font-weight:bold;">' . $lab . '</span>';
                if ($st === 'error' && !empty($r['ls_last_error_tx'])) { $h .= '<br><small style="color:#c62828;">' . $esc(substr($r['ls_last_error_tx'], 0, 140)) . '</small>'; }
                $h .= '</td><td>' . $esc($exp) . '</td><td>' . $ren . '</td><td>' . $esc($iss) . '</td><td>' . $esc($upd) . '</td></tr>';
            }
        }
        $h .= '</table></div>';

        // --- 3. Pendientes / cola ---
        $h .= '<div class="zgrid_wrapper"><h3>Pendientes</h3>';
        $reiss = $zdbh->query("SELECT vh_name_vc FROM x_vhosts WHERE vh_le_reissue_ts IS NOT NULL AND vh_le_reissue_ts>0 AND vh_deleted_ts IS NULL ORDER BY vh_le_reissue_ts DESC")->fetchAll(PDO::FETCH_ASSOC);
        $h .= '<p><b>Reemisión solicitada:</b> ' . ($reiss ? implode(', ', array_map(function ($r) use ($esc) { return $esc($r['vh_name_vc']); }, $reiss)) : 'ninguna') . '</p>';
        $att = $zdbh->query("SELECT ls_domain_vc, ls_state_vc FROM x_le_status WHERE ls_state_vc IN ('expiring','expired','missing','error') ORDER BY ls_expires_ts ASC")->fetchAll(PDO::FETCH_ASSOC);
        $h .= '<p><b>Requieren atención (' . count($att) . '):</b> ' . ($att ? implode(', ', array_map(function ($r) use ($esc) { return $esc($r['ls_domain_vc']) . ' (' . $esc($r['ls_state_vc']) . ')'; }, array_slice($att, 0, 30))) : 'ninguno');
        if ($boActive) { $h .= ' <span style="color:#e65100;">[emisiones en pausa por backoff]</span>'; }
        $h .= '</p></div>';

        // --- 4. Últimos mensajes del log ---
        $h .= '<div class="zgrid_wrapper"><h3>Últimos mensajes (sencrypt.log)</h3>';
        $logf  = ctrl_options::GetSystemOption('bulwark_root') . 'modules/sencrypt/sencrypt.log';
        $lines = @file($logf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            $h .= '<pre style="max-height:260px;overflow:auto;background:#111;color:#ddd;padding:8px;border-radius:4px;font-size:12px;">' . $esc(implode("\n", array_slice($lines, -25))) . '</pre>';
        } else {
            $h .= '<p>Sin mensajes.</p>';
        }
        $h .= '</div>';
        return $h;
    }
}

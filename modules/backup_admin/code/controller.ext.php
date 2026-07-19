<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 *
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
class module_controller extends ctrl_module
{

    static $ok;

    static function getConfig()
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail();
        $moduleName = ui_module::GetModuleName();

        $sql = "SELECT * FROM x_settings WHERE so_module_vc=:module AND so_usereditable_en = 'true' ORDER BY so_cleanname_vc";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':module', $moduleName);
        $numrows->execute();

        //$numrows = $zdbh->query($sql);
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $res = array();
            $sql->bindParam(':module', $moduleName);
            $sql->execute();
            while ($rowmailsettings = $sql->fetch()) {
                if (ctrl_options::CheckForPredefinedOptions($rowmailsettings['so_defvalues_tx'])) {
                    $fieldhtml = ctrl_options::OuputSettingMenuField($rowmailsettings['so_name_vc'], $rowmailsettings['so_defvalues_tx'], $rowmailsettings['so_value_tx']);
                } else {
                    $fieldhtml = ctrl_options::OutputSettingTextArea($rowmailsettings['so_name_vc'], $rowmailsettings['so_value_tx']);
                }
                array_push($res, array('cleanname' => ui_language::translate($rowmailsettings['so_cleanname_vc']),
                    'name' => $rowmailsettings['so_name_vc'],
                    'description' => ui_language::translate($rowmailsettings['so_desc_tx']),
                    'value' => $rowmailsettings['so_value_tx'],
                    'fieldhtml' => $fieldhtml));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function doUpdateConfig()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $moduleName = ui_module::GetModuleName();
        $sql = "SELECT * FROM x_settings WHERE so_module_vc=:module AND so_usereditable_en = 'true'";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':module', $moduleName);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':module', $moduleName);
            $sql->execute();
            while ($row = $sql->fetch()) {
                if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', $row['so_name_vc']))) {
                    $value = $controller->GetControllerRequest('FORM', $row['so_name_vc']);
                    $name = $row['so_name_vc'];
                    $updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
                    $updatesql->bindParam(':value', $value);
                    $updatesql->bindParam(':name', $name);
                    $updatesql->execute();
                }
            }
        }
        self::$ok = true;
    }

    /** Vista de admin: todas las programaciones + la cola de copias. Placeholder <@ SchedulesOverview @>. */
    static function getSchedulesOverview()
    {
        global $zdbh;
        $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };
        $csrf = self::getCSFR_Tag();
        $master = strtolower((string)ctrl_options::GetSystemOption('schedule_bu')) === 'true';
        $batch  = (int)ctrl_options::GetSystemOption('backup_batch_size'); if ($batch <= 0) $batch = 2;

        $out  = '<div class="zgrid_wrapper"><h2>Copias automáticas — programaciones y cola</h2>';
        $out .= '<p><small>Interruptor maestro (Daily Backups): <b>' . ($master ? 'ACTIVADO' : 'desactivado') . '</b>. '
              . 'Bloque por ejecución del daemon: <b>' . $batch . '</b> (ajuste backup_batch_size). '
              . 'Cada cliente configura su horario en su módulo Backup &rarr; Automáticas.</small></p>';

        // Acciones
        $out .= '<form method="post" action="./?module=backup_admin&action=ProcessQueueNow" style="display:inline">' . $csrf
              . '<button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-play-circle me-1"></i>Procesar cola ahora</button></form> ';
        $out .= '<form method="post" action="./?module=backup_admin&action=ClearQueue" style="display:inline" onsubmit="return confirm(\'¿Vaciar las entradas terminadas (done/error) de la cola?\');">' . $csrf
              . '<button class="btn btn-secondary btn-sm" type="submit"><i class="bi bi-trash me-1"></i>Vaciar terminadas</button></form>';

        // Programaciones
        $sched = $zdbh->query("SELECT s.*, a.ac_user_vc FROM x_backup_schedule s JOIN x_accounts a ON a.ac_id_pk=s.bs_acc_fk AND a.ac_deleted_ts IS NULL ORDER BY s.bs_enabled_in DESC, a.ac_user_vc")->fetchAll(PDO::FETCH_ASSOC);
        $out .= '<h3 style="margin-top:18px">Programaciones (' . count($sched) . ')</h3>';
        if ($sched) {
            $dias = array('Dom','Lun','Mar','Mié','Jue','Vie','Sáb');
            $out .= '<div class="table-responsive"><table class="table table-sm table-striped"><tr><th>Cuenta</th><th>Activa</th><th>Frecuencia</th><th>Cuándo</th><th>Destino</th><th>Próxima</th><th>Última</th></tr>';
            foreach ($sched as $s) {
                $freq = $s['bs_freq_vc'];
                $cuando = ($freq === 'weekly') ? ($dias[(int)$s['bs_dow_in']] . ' ' . sprintf('%02d:00', $s['bs_hour_in']))
                        : (($freq === 'monthly') ? ('día ' . (int)$s['bs_dom_in'] . ' ' . sprintf('%02d:00', $s['bs_hour_in']))
                        : sprintf('%02d:00', $s['bs_hour_in']));
                $out .= '<tr><td>' . $h($s['ac_user_vc']) . '</td>'
                      . '<td>' . ((int)$s['bs_enabled_in'] ? '<span class="badge bg-success">sí</span>' : '<span class="badge bg-secondary">no</span>') . '</td>'
                      . '<td>' . $h(array('daily'=>'Diaria','weekly'=>'Semanal','monthly'=>'Mensual')[$freq] ?? $freq) . '</td>'
                      . '<td>' . $h($cuando) . '</td><td>' . $h($s['bs_dest_vc']) . '</td>'
                      . '<td>' . (!empty($s['bs_next_run_ts']) ? date('d/m H:i', (int)$s['bs_next_run_ts']) : '—') . '</td>'
                      . '<td>' . (!empty($s['bs_last_run_ts']) ? date('d/m H:i', (int)$s['bs_last_run_ts']) : '—') . '</td></tr>';
            }
            $out .= '</table></div>';
        } else { $out .= '<p><small>Ningún cliente tiene copia automática configurada.</small></p>'; }

        // Cola
        $queue = $zdbh->query("SELECT q.*, a.ac_user_vc FROM x_backup_queue q LEFT JOIN x_accounts a ON a.ac_id_pk=q.bq_acc_fk ORDER BY q.bq_id_pk DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
        $out .= '<h3 style="margin-top:18px">Cola (últimas 30)</h3>';
        if ($queue) {
            $badge = array('pending'=>'bg-warning text-dark','running'=>'bg-info','done'=>'bg-success','error'=>'bg-danger');
            $out .= '<div class="table-responsive"><table class="table table-sm table-striped"><tr><th>Cuenta</th><th>Modo</th><th>Estado</th><th>Encolada</th><th>Fin</th><th>Int.</th><th>Detalle</th></tr>';
            foreach ($queue as $q) {
                $cls = $badge[$q['bq_status_vc']] ?? 'bg-secondary';
                $out .= '<tr><td>' . $h($q['ac_user_vc']) . '</td><td>' . $h($q['bq_mode_vc']) . '</td>'
                      . '<td><span class="badge ' . $cls . '">' . $h($q['bq_status_vc']) . '</span></td>'
                      . '<td>' . date('d/m H:i', (int)$q['bq_enqueued_ts']) . '</td>'
                      . '<td>' . (!empty($q['bq_finished_ts']) ? date('d/m H:i', (int)$q['bq_finished_ts']) : '—') . '</td>'
                      . '<td>' . (int)$q['bq_attempts_in'] . '</td>'
                      . '<td><small>' . $h($q['bq_message_tx']) . '</small></td></tr>';
            }
            $out .= '</table></div>';
        } else { $out .= '<p><small>La cola está vacía.</small></p>'; }

        $out .= '</div>';
        return $out;
    }

    /** Acción admin: forzar un ciclo del programador (encolar vencidas + procesar un bloque). */
    static function doProcessQueueNow()
    {
        runtime_csfr::Protect();
        $enq = $proc = 0;
        if (class_exists('sys_backup_scheduler')) {
            list($enq, $proc) = sys_backup_scheduler::tick();
        }
        $_SESSION['ba_flash'] = "Programador ejecutado: $enq encoladas, $proc procesadas.";
        if (!headers_sent()) { header('Location: ./?module=backup_admin'); exit(); }
    }

    /** Acción admin: vaciar de la cola las entradas terminadas (done/error). */
    static function doClearQueue()
    {
        global $zdbh;
        runtime_csfr::Protect();
        $n = $zdbh->exec("DELETE FROM x_backup_queue WHERE bq_status_vc IN ('done','error')");
        $_SESSION['ba_flash'] = 'Cola limpiada (' . (int)$n . ' entradas terminadas borradas).';
        if (!headers_sent()) { header('Location: ./?module=backup_admin'); exit(); }
    }

    static function getResult()
    {
        if (!empty($_SESSION['ba_flash'])) {
            $m = $_SESSION['ba_flash']; unset($_SESSION['ba_flash']);
            return ui_sysmessage::shout(htmlspecialchars($m, ENT_QUOTES), 'zannounceok');
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your settings have been saved successfully!"));
        }
        return;
    }

}

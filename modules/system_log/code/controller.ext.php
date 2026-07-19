<?php
/**
 * @package system_log
 * @version 100
 * @author new_bulwark
 *
 * Admin-only module for managing internal Bulwark system logs (x_logs).
 * Access restricted to group 1 (Administrators) via x_permissions.
 */

class module_controller extends ctrl_module
{
    static $retentionSaved;
    static $purgeDone;

    // ----------------------------------------------------------------
    // Data getters
    // ----------------------------------------------------------------

    static function getLogCount() {
        global $zdbh;
        $row = $zdbh->query("SELECT COUNT(*) FROM x_logs")->fetch(PDO::FETCH_NUM);
        return number_format($row[0]);
    }

    static function getLogRetentionDays() {
        $v = (int) ctrl_options::GetSystemOption('log_retention_days');
        return ($v > 0) ? $v : 30;
    }

    static function getSystemLogs() {
        global $zdbh;
        $stmt = $zdbh->prepare(
            "SELECT lg_id_pk, lg_code_vc, lg_module_vc, lg_detail_tx, lg_when_ts
             FROM x_logs ORDER BY lg_id_pk DESC LIMIT 200"
        );
        $stmt->execute();
        $res = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $res[] = [
                'id'     => (int)$r['lg_id_pk'],
                'code'   => $r['lg_code_vc'],
                'module' => $r['lg_module_vc'],
                'detail' => $r['lg_detail_tx'],
                'when'   => $r['lg_when_ts'],
            ];
        }
        return $res ?: false;
    }

    static function isRetentionSaved() {
        return !fs_director::CheckForEmptyValue(self::$retentionSaved);
    }

    static function isPurgeDone() {
        return !fs_director::CheckForEmptyValue(self::$purgeDone);
    }

    // ----------------------------------------------------------------
    // Actions
    // ----------------------------------------------------------------

    static function doSaveRetention() {
        runtime_csfr::Protect();
        global $controller, $zdbh;
        $days = (int) $controller->GetControllerRequest('FORM', 'inRetentionDays');
        if ($days < 1) $days = 1;
        $stmt = $zdbh->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='log_retention_days'");
        $stmt->bindValue(':v', (string)$days);
        $stmt->execute();
        self::$retentionSaved = true;
    }

    static function doPurgeAllLogs() {
        runtime_csfr::Protect();
        global $zdbh;
        $zdbh->exec("TRUNCATE TABLE x_logs");
        self::$purgeDone = true;
    }

    static function doExportLogs() {
        runtime_csfr::Protect();
        global $zdbh;
        $stmt = $zdbh->prepare(
            "SELECT lg_id_pk, lg_code_vc, lg_module_vc, lg_detail_tx, lg_stack_tx, lg_when_ts
             FROM x_logs ORDER BY lg_id_pk DESC"
        );
        $stmt->execute();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="bulwark_logs_' . date('Ymd_His') . '.csv"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['ID', 'Código', 'Módulo', 'Detalle', 'Stack', 'Fecha']);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $r['lg_id_pk'],
                $r['lg_code_vc'],
                $r['lg_module_vc'],
                $r['lg_detail_tx'],
                $r['lg_stack_tx'],
                $r['lg_when_ts'],
            ]);
        }
        fclose($out);
        die();
    }

    // ----------------------------------------------------------------
    // Result messages
    // ----------------------------------------------------------------

    static function getResult() {
        if (!fs_director::CheckForEmptyValue(self::$retentionSaved)) {
            return ui_sysmessage::shout(ui_language::translate("Log retention period updated successfully."), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$purgeDone)) {
            return ui_sysmessage::shout(ui_language::translate("All system logs have been purged."), "zannounceok");
        }
        return;
    }
}

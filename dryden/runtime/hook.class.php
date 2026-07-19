<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Integration hooks class.
 * @package zpanelx
 * @subpackage dryden -> runtime
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
class runtime_hook {

    /**
     * Executes a hook file at the called position.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $name The name of the hook of which to execute.
     */
    static function Execute($name) {
        $hook_log = new debug_logger();
        $mod_folder = "modules/*/hooks/{" . $name . ".hook.php}";
        $hook_log->method = ctrl_options::GetSystemOption('logmode');
        $hook_log->logcode = "861";
        foreach (glob($mod_folder, GLOB_BRACE) as $hook_file) {
            if (file_exists($hook_file)) {
                // Extraer el módulo de la ruta (modules/<modulo>/hooks/...)
                $hook_log->module = (preg_match('#modules/([^/]+)/#', $hook_file, $mm)) ? $mm[1] : 'NA';
                $hook_log->detail = "Execute hook file (" . $hook_file . ")";
                $failed = false;
                try {
                  include $hook_file;
                } catch (\Throwable $e) {
                  $hook_log->detail .= ' -> Exception(' . $e->getMessage() . ') :(';
                  $failed = true;
                }
                // Solo se registra si el hook falla: evita inundar x_logs con la
                // actividad rutinaria del daemon (antes se anotaba cada ejecución).
                if ($failed) {
                    $hook_log->writeLog();
                }
            }
        }
    }

}

?>

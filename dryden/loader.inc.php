<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Module loader script for detecting and displaying the correct module using the Dryden framework, this handles the autolaoding of classes.
 * @package zpanelx
 * @subpackage dryden -> core
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
global $starttime;
$mtime = explode(' ', microtime());
$mtime = $mtime[1] + $mtime[0];
$starttime = $mtime;
$class_name = null;

function x__autoload($class_name)
{
    $path = 'dryden/' . str_replace('_', '/', $class_name) . '.class.php';
    if (file_exists($path)) {
        require_once $path;
    }
}

spl_autoload_register('x__autoload');

// La clase 'privilege' (dryden/sys/privilege.class.php) no encaja con la convención
// del autoloader (buscaría dryden/privilege.class.php), por eso muchos módulos la
// requerían a mano y otros lo olvidaban -> "Class privilege not found" -> 500.
// Al ser una clase core de sistema, se carga aquí una sola vez para todos.
require_once __DIR__ . '/sys/privilege.class.php';

if (isset($_GET['module'])) {
    $CleanModuleName = fs_protector::SanitiseFolderName($_GET['module']);

    $ControlerPath = 'modules/' . $CleanModuleName . '/code/controller.ext.php';
    if (file_exists($ControlerPath)) {
        require_once $ControlerPath;
    }

    $ModulePath = 'modules/' . $CleanModuleName . '/code/' . $class_name . '.class.php';
    if (file_exists($ModulePath)) {
        require_once $ModulePath;
    }
}

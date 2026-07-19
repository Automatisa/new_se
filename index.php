<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * The ZPanelX loader and default handler file.
 * @package zpanelx
 * @subpackage core
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// FORZAR HTTPS: el panel maneja credenciales y NO debe servirse por http. Además, una cookie de
// sesión puesta por https es `Secure` y el navegador NO la reenvía por http (ni deja que http la
// sobrescriba) -> el login por http se queda en bucle sin error. Redirigimos a https ANTES de
// tocar la sesión. Se respeta un proxy que termine TLS (X-Forwarded-Proto).
$__fwdProto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) : '';
$__isHttps  = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || $__fwdProto === 'https';
if (!$__isHttps && !empty($_SERVER['HTTP_HOST']) && !headers_sent()) {
    // Sanitizar el Host (evita inyección de cabecera / CRLF): solo caracteres válidos de host.
    $__host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)$_SERVER['HTTP_HOST']);
    $__uri  = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
    if ($__host !== '') {
        header('Location: https://' . $__host . $__uri, true, 301);
        exit;
    }
}

// Endurecer la cookie de sesión ANTES de session_start(): HttpOnly (que un XSS no
// pueda leerla), SameSite=Strict (bloquea envío en peticiones cross-site) y Secure
// solo si la petición actual es HTTPS (para no romper accesos HTTP previos al redirect).
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
]);
session_start();

// Cabeceras de seguridad HTTP globales del panel. Se emiten antes de cualquier
// salida. La CSP es deliberadamente permisiva con inline scripts/styles porque el
// tema legacy (dryden) los usa masivamente; lo que sí bloqueamos es el framing
// (anti-clickjacking), plugins/objetos, y el secuestro de base/formularios.
if (!headers_sent()) {
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 0');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; "
        . "font-src 'self' data:; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'none'"
    );
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
debug_phperrors::SetMode('dev');
require_once 'inc/dbc.inc.php';
debug_phperrors::SetMode(ctrl_options::GetSystemOption('debug_mode'));
require_once 'inc/init.inc.php';
//This is where we check the session for hi-jacking
if(!runtime_sessionsecurity::antiSessionHijacking()){
    exit(header("location: ./?sessionIssue"));
}
?>
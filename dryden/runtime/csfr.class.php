<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Cross Site Forgery Request protection class.
 * @package zpanelx
 * @subpackage dryden -> runtime
 * @version 1.0.2
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
class runtime_csfr {

    /**
     * Builds a 'hidden' form type which is populated with the generated token.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return string The HTML form tag.
     */
    static function Token() {
        if (!isset($_SESSION['zpcsfr'])) {
            self::Tokeniser();
        }
        $token = $_SESSION['zpcsfr'];
        return "<input type=\"hidden\" name=\"csfr_token\" value=\"" . $token . "\">";
    }

    /**
     * Generates a new CSFR token.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return bool
     */
    static function Tokeniser() {
        $_SESSION['zpcsfr'] = runtime_randomstring::randomHash();
        return true;
    }

    /**
     * Verfies that the submitted form has a valid CSFR token.
     * Token is PER-SESSION (no rotation per-request): rotar en cada POST rompe
     * flujos multi-form y el botón "atrás" del navegador en SSR sin fetch.
     * El token se regenera solo en login/logout (ctrl_auth).
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return bool
     */
    static function Protect() {
        if (isset($_POST['csfr_token']) && hash_equals((string)($_SESSION['zpcsfr'] ?? ''), (string)$_POST['csfr_token'])) {
            return true;
        }
        // Token inválido/caducado (típico: pestaña vieja, o sesión renovada tras re-login).
        // En vez de una pantalla muerta [0204], avisamos y volvemos al módulo con una recarga
        // limpia por GET (nunca reenvía el POST). Destino seguro: solo el módulo actual.
        $module = isset($_GET['module']) ? preg_replace('/[^a-z0-9_]/i', '', (string)$_GET['module']) : '';
        $target = $module !== '' ? './?module=' . $module : './';

        // Si aún no se envió salida, redirigir directamente (302) es lo más limpio.
        if (!headers_sent()) {
            header('Location: ' . $target, true, 302);
        }
        $t = htmlspecialchars($target, ENT_QUOTES);
        die("<!doctype html><html lang=\"es\"><head><meta charset=\"utf-8\">
            <meta http-equiv=\"refresh\" content=\"3;url=$t\">
            <title>Sesión expirada</title>
            <style>
              body{font-family:Verdana,Geneva,sans-serif;background:#f5f5f5;margin:0;padding:40px;color:#333}
              .box{max-width:520px;margin:8vh auto;background:#fff;border:1px solid #e0c000;
                   border-left:5px solid #e0a000;border-radius:6px;padding:28px 32px;
                   box-shadow:0 2px 8px rgba(0,0,0,.08)}
              h1{font-size:18px;margin:0 0 12px;color:#a06a00}
              p{font-size:14px;line-height:1.5;color:#555}
              a.btn{display:inline-block;margin-top:14px;padding:9px 16px;background:#3a7bd5;color:#fff;
                    text-decoration:none;border-radius:4px;font-size:14px}
              small{color:#999}
            </style></head><body>
            <div class=\"box\">
              <h1>Tu sesión o el formulario ha caducado</h1>
              <p>Por seguridad, el formulario que enviaste tenía un token que ya no es válido
                 (suele pasar con una pestaña abierta desde hace rato o tras volver a iniciar
                 sesión). No se ha perdido nada.</p>
              <p>Te llevamos de vuelta en unos segundos. Si no, pulsa aquí:</p>
              <a class=\"btn\" href=\"$t\">Volver e intentarlo de nuevo</a>
              <p><small>Ref. [0204]</small></p>
            </div></body></html>");
    }

}

?>
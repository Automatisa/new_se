<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Authentication class handles ZPanel authentication and handles user sessions.
 * @package zpanelx
 * @subpackage dryden -> controller
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
class ctrl_auth
{

    /**
     * Checks that the server has a valid session for the user if not it will redirect to the login screen.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * return bool
     */
    static function RequireUser()
    {
        global $zdbh;
        if (!isset($_SESSION['zpuid'])) {
            if (isset($_COOKIE['zUser'])) {
                if (isset($_COOKIE['zSec'])) {
                    if ($_COOKIE['zSec'] == false) {
                        $secure = false;
                    } else {
                        $secure = true;
                    }
                } else {
                    $secure = true;
                }
                self::Authenticate($_COOKIE['zUser'], $_COOKIE['zPass'], false, true, $secure);
            }
            runtime_hook::Execute('OnRequireUserLogin');
            $sqlQuery = "SELECT ac_usertheme_vc, ac_usercss_vc FROM
                         x_accounts WHERE
                         ac_user_vc = :zadmin";
            $bindArray = array(':zadmin' => 'zadmin');
            $zdbh->bindQuery($sqlQuery, $bindArray);
            $themeRow = $zdbh->returnRow();
            // Validate theme name against filesystem to prevent path traversal via DB
            $rawTheme = $themeRow['ac_usertheme_vc'] ?? 'Bulwark_Default';
            $themeName = preg_match('/^[A-Za-z0-9_\-]+$/', $rawTheme) ? $rawTheme : 'Bulwark_Default';
            include 'etc/styles/' . $themeName . '/login.ztml';
            exit;
        }
        return true;
    }

    /**
     * Sets a user session ID.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param int $zpuid The Bulwark user account ID to set the session as.
     * @return bool
     */
    static function SetUserSession($zpuid = 0, $sessionSecuirty = true)
    {
        $sessionSecuirty = runtime_sessionsecurity::getSessionSecurityEnabled();
        if (isset($zpuid)) {
            $_SESSION['zpuid'] = $zpuid;
            if ($sessionSecuirty) {
                //Implamentation of session security
                runtime_sessionsecurity::setCookie();
                runtime_sessionsecurity::setUserIP();
                runtime_sessionsecurity::setUserAgent();
                runtime_sessionsecurity::setSessionSecurityEnabled(true);
            } else {
                //Implamentation of session security but set it as off
                runtime_sessionsecurity::setCookie();
                runtime_sessionsecurity::setUserIP();
                runtime_sessionsecurity::setUserAgent();
                runtime_sessionsecurity::setSessionSecurityEnabled(false);
            }

            return true;
        } else {
            return false;
        }
    }

    /**
     * Sets the value of a given named session variable, if does not exist will create the session variable too.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $name The name of the session variable to set.
     * @param string $value The value of the session variable to set.
     * @return boolean
     */
    static function SetSession($name, $value = "")
    {
        if (isset($name)) {
            $_SESSION['' . $name . ''] = $value;
            return true;
        } else {
            return false;
        }
    }

     /**
     * The main authentication mechanism, checks username and password against the database and logs the user in on a successful authenitcation request.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global db_driver $zdbh The ZPX database handle.
     * @param string $username The username to use to authenticate with.
     * @param string $password The password to use to authenticate with.
     * @param bool $rememberMe Remember the password for 30 days? (true/false)
     * @param bool $checkingcookie The authentication request has come from a set cookie.
     * @return mixed Returns 'false' if the authentication fails otherwise will return the user ID.
     */
    static function Authenticate($username, $password, $rememberMe = false, $isCookie = false, $sessionSecurity = false)
    {
        global $zdbh;
        // Autenticación desde cookie: valida token aleatorio en ac_resethash_tx
        if ($isCookie) {
            $sqlString = "SELECT * FROM x_accounts
                           WHERE ac_user_vc = :username
                             AND ac_resethash_tx = :rmtok
                             AND ac_enabled_in = 1
                             AND ac_deleted_ts IS NULL";
            $bindArray = [':username' => $username, ':rmtok' => 'RM:' . $password];
            $zdbh->bindQuery($sqlString, $bindArray);
            $row = $zdbh->returnRow();
        } else {
            $sqlString = "SELECT * FROM x_accounts
                           WHERE ac_user_vc = :username
                             AND ac_pass_vc = :password
                             AND ac_enabled_in = 1
                             AND ac_deleted_ts IS NULL";
            $bindArray = [':username' => $username, ':password' => $password];
            $zdbh->bindQuery($sqlString, $bindArray);
            $row = $zdbh->returnRow();
        }

        if ($row) {
            // Anti session-fixation: al elevar la sesión a autenticada se genera un ID
            // nuevo, invalidando cualquier ID que un atacante hubiera podido fijar en el
            // navegador de la víctima antes del login.
            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                session_regenerate_id(true);
            }
            ctrl_auth::SetUserSession($row['ac_id_pk'], $sessionSecurity);
            // Emitir un token CSRF NUEVO al autenticar: invalida cualquier token que
            // pudiera quedar de una sesión anterior (evita [0204] con formularios viejos).
            if (class_exists('runtime_csfr')) {
                runtime_csfr::Tokeniser();
            }
            // Fix SQL injection: prepared statement en lugar de concatenación
            $log_logon = $zdbh->prepare("UPDATE x_accounts SET ac_lastlogon_ts = :ts WHERE ac_id_pk = :id");
            $log_logon->execute([':ts' => time(), ':id' => (int)$row['ac_id_pk']]);
            // Fix: NO almacenar hash de contraseña en cookies (credential theft).
            // El "remember me" se sustituye por un token aleatorio en ac_resethash_tx.
            if ($rememberMe && !$isCookie) {
                $rememberToken = bin2hex(random_bytes(32));
                $rt = $zdbh->prepare("UPDATE x_accounts SET ac_resethash_tx = :tok WHERE ac_id_pk = :id");
                $rt->execute([':tok' => 'RM:' . $rememberToken, ':id' => (int)$row['ac_id_pk']]);
                $cookieOpts = ['expires' => time() + 60 * 60 * 24 * 7, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict'];
                setcookie("zUser", $username,       $cookieOpts);
                setcookie("zPass", $rememberToken,  $cookieOpts);
            }

            runtime_hook::Execute('OnGoodUserLogin');
            return $row['ac_id_pk'];
        } else {
            runtime_hook::Execute('OnBadUserLogin');
            return false;
        }
    }

    /**
     * Destroys a session and ends a user's Bulwark session.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return bool
     */
    static function KillSession()
    {
        runtime_hook::Execute('OnUserLogout');
        // Destrucción COMPLETA de la sesión en el logout. Antes solo se ponía zpuid=null,
        // dejando vivos la cookie de sesión, el fichero de sesión y el token CSRF (zpcsfr):
        // eso dejaba una sesión "medio muerta" que, reutilizada por el navegador, provocaba
        // formularios con token caducado (error [0204]). Ahora se limpia todo.
        $_SESSION = array();
        // Borrar la cookie de sesión en el navegador (si no, queda una cookie que reengancha
        // una sesión vieja). Se respetan los parámetros con los que se creó.
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', array(
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => isset($p['samesite']) ? $p['samesite'] : 'Strict',
            ));
        }
        unset($_COOKIE['zUserSaltCookie']);
        // Destruir la sesión en el servidor (el logout hace redirect+exit a continuación).
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
        return true;
    }

    /**
     * Deletes the authentication 'rememberme' cookies.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return bool
     */
    static function KillCookies()
    {
        global $zdbh;
        // Invalidar el token remember-me en la BD si existe
        if (!empty($_COOKIE['zUser']) && !empty($_COOKIE['zPass'])) {
            $q = $zdbh->prepare("UPDATE x_accounts SET ac_resethash_tx = NULL WHERE ac_user_vc = :u AND ac_resethash_tx = :tok AND ac_deleted_ts IS NULL");
            $q->execute([':u' => $_COOKIE['zUser'], ':tok' => 'RM:' . $_COOKIE['zPass']]);
        }
        $expired = ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict'];
        setcookie("zUser", '', $expired);
        setcookie("zPass", '', $expired);
        unset($_COOKIE['zUser'], $_COOKIE['zPass'], $_COOKIE['zSec']);
        return true;
    }

    /**
     * Returns the UID (User ID) of the current logged in user.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @global obj $controller The Bulwark controller object.
     * @return int The current user's session ID.
     */
    static function CurrentUserID()
    {
        global $controller;
        $result = $controller->GetControllerRequest('USER', 'zpuid');
        return $result;
    }

}

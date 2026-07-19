<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * The web gui initiation script.
 * @package zpanelx
 * @subpackage core
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
global $controller, $zdbh, $zlo;
$controller = new runtime_controller();

runtime_hook::Execute('OnBoot');

$zlo->method = ctrl_options::GetSystemOption('logmode');
if ($zlo->hasInfo()) {
    $zlo->writeLog();
    $zlo->reset();
}

if (isset($_GET['logout'])) {
    runtime_hook::Execute('OnLogout');
    ctrl_auth::KillSession();
    ctrl_auth::KillCookies();
    header("location: ./?loggedout");
    exit;
}

if (isset($_GET['returnsession'])) {
    if (!empty($_SESSION['ruid_stack'])) {
        $returnuid = array_pop($_SESSION['ruid_stack']);
        ctrl_auth::SetUserSession($returnuid, runtime_sessionsecurity::getSessionSecurityEnabled());
    }
    header("location: ./");
    exit;
}

if (isset($_POST['inForgotPassword'])) {
    runtime_csfr::Protect();
    // Token criptográficamente seguro (reemplaza mt_rand)
    $randomkey = bin2hex(random_bytes(32));
    $forgotPass = runtime_xss::xssClean($_POST['inForgotPassword']);
    $sth = $zdbh->prepare("SELECT ac_id_pk, ac_user_vc, ac_email_vc FROM x_accounts WHERE ac_email_vc = :forgotPass AND ac_deleted_ts IS NULL");
    $sth->bindParam(':forgotPass', $forgotPass);
    $sth->execute();
    $rows = $sth->fetchAll();
    if ($rows) {
        $result = $rows['0'];
        // Fix SQL injection: prepared statement en lugar de concatenación.
        // Se almacena "timestamp:token" para poder caducar el enlace (1h). El correo
        // solo lleva el token; la marca de tiempo se valida en el consumo.
        $upd = $zdbh->prepare("UPDATE x_accounts SET ac_resethash_tx = :hash WHERE ac_id_pk = :id");
        $upd->execute([':hash' => time() . ':' . $randomkey, ':id' => (int)$result['ac_id_pk']]);
        if (isset($_SERVER['HTTPS'])) {
            $protocol = 'https://';
        } else {
            $protocol = 'http://';
        }
        $port = ctrl_options::GetSystemOption('bulwark_port');
        $domain = ctrl_options::GetSystemOption('bulwark_domain');
        # If using non-standard port
        if ($port !== "80" && $port !== "443" && !empty($port)) {
            # Append port to domain
            $domain .= ":" . $port;
        }
        $phpmailer = new sys_email();
        $phpmailer->Subject = "Hosting Panel Password Reset";
        $phpmailer->Body = "Hi " . $result['ac_user_vc'] . ",
            
You, or somebody pretending to be you, has requested a password reset link to be sent for your web hosting control panel login.
        
If you wish to proceed with the password reset on your account, please use the link below to be taken to the password reset page.
            
" . $protocol . $domain . "/?resetkey=" . $randomkey . "


                ";
        $phpmailer->addAddress($result['ac_email_vc']);
        $phpmailer->SendEmail();
        runtime_hook::Execute('OnRequestForgotPassword');
    }
}

if (isset($_POST['inConfEmail'])) {
    runtime_csfr::Protect();
    // Se busca solo por email; el token se compara en PHP con hash_equals (tiempo
    // constante) y se verifica su caducidad (1h). Así el enlace de reset expira y no
    // queda válido para siempre si nunca se usa.
    $suppliedKey = isset($_GET['resetkey']) ? (string)$_GET['resetkey'] : '';
    $sql = $zdbh->prepare("SELECT ac_id_pk, ac_resethash_tx FROM x_accounts WHERE ac_email_vc = :email AND ac_resethash_tx IS NOT NULL AND ac_resethash_tx <> '' AND ac_deleted_ts IS NULL");
    $sql->bindParam(':email', $_POST['inConfEmail']);
    $sql->execute();
    $row = $sql->fetch();
    $result = false;
    if ($row && $suppliedKey !== '') {
        $stored = (string)$row['ac_resethash_tx'];
        $sep = strpos($stored, ':');
        // Compatibilidad con tokens antiguos sin timestamp: si no hay ':', se trata
        // como caducado (fuerza a solicitar uno nuevo con el formato actual).
        if ($sep !== false) {
            $issuedTs = (int)substr($stored, 0, $sep);
            $storedTok = substr($stored, $sep + 1);
            if ($issuedTs > 0 && (time() - $issuedTs) <= 3600 && hash_equals($storedTok, $suppliedKey)) {
                $result = ['ac_id_pk' => $row['ac_id_pk']];
            }
        }
    }

    $crypto = new runtime_hash;
    $crypto->SetPassword($_POST['inNewPass']);
    $randomsalt = $crypto->RandomSalt();
    $crypto->SetSalt($randomsalt);
    $secure_password = $crypto->CryptParts($crypto->Crypt())->Hash;

    if ($result) {
        $sql = $zdbh->prepare("UPDATE x_accounts SET ac_resethash_tx = NULL, ac_pass_vc = :password, ac_passsalt_vc = :salt WHERE ac_id_pk = :uid");
        $sql->bindParam(':password', $secure_password);
        $sql->bindParam(':salt', $randomsalt);
        $sql->bindParam(':uid', $result['ac_id_pk']);
        $sql->execute();
        runtime_hook::Execute('OnSuccessfulPasswordReset');
    } else {
        runtime_hook::Execute('OnFailedPasswordReset');
    }
    header("location: ./?passwordreset");
    exit();
}

if (isset($_POST['inUsername'])) {
    if (ctrl_options::GetSystemOption('login_csfr') == 'false')
        runtime_csfr::Protect();

    $rememberdetails = isset($_POST['inRemember']);
    $inSessionSecuirty = isset($_POST['inSessionSecurity']);

    $sql = $zdbh->prepare("SELECT ac_passsalt_vc FROM x_accounts WHERE ac_user_vc = :username AND ac_deleted_ts IS NULL");
    $sql->bindParam(':username', $_POST['inUsername']);
    $sql->execute();
    $result = $sql->fetch();
    $crypto = new runtime_hash;
    $crypto->SetPassword($_POST['inPassword']);
    $crypto->SetSalt($result['ac_passsalt_vc']);
    $secure_password = $crypto->CryptParts($crypto->Crypt())->Hash;

    if (!ctrl_auth::Authenticate($_POST['inUsername'], $secure_password, $rememberdetails, false, $inSessionSecuirty)) {
        // ── Brute-force tracking ────────────────────────────────────────────
        $bfIP = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP)
                ? $_SERVER['REMOTE_ADDR'] : '';
        if ($bfIP !== '') {
            try {
                // No bloquear IPs en lista blanca
                $bfWl = $zdbh->prepare(
                    "SELECT fw_id_pk FROM x_fw_whitelist
                     WHERE fw_ip_vc=:ip AND fw_deleted_ts IS NULL LIMIT 1"
                );
                $bfWl->bindValue(':ip', $bfIP);
                $bfWl->execute();

                if (!$bfWl->fetchColumn()) {
                    // Registrar intento
                    $zdbh->prepare(
                        "INSERT INTO x_fw_login_attempts (la_ip_vc, la_user_vc, la_ts_in)
                         VALUES (:ip, :usr, :ts)"
                    )->execute([
                        ':ip'  => $bfIP,
                        ':usr' => substr((string)($_POST['inUsername'] ?? ''), 0, 64),
                        ':ts'  => time(),
                    ]);

                    // Comprobar umbral
                    $bfMax    = max(1, (int)(ctrl_options::GetSystemOption('fw_login_max')    ?: 5));
                    $bfWindow = max(60, (int)(ctrl_options::GetSystemOption('fw_login_window') ?: 600));

                    $bfCnt = $zdbh->prepare(
                        "SELECT COUNT(*) FROM x_fw_login_attempts
                         WHERE la_ip_vc=:ip AND la_ts_in >= :since"
                    );
                    $bfCnt->execute([':ip' => $bfIP, ':since' => time() - $bfWindow]);

                    if ((int)$bfCnt->fetchColumn() >= $bfMax) {
                        // Auto-bloquear: INSERT IGNORE para no duplicar
                        $zdbh->prepare(
                            "INSERT IGNORE INTO x_fw_blocked
                                (fb_ip_vc, fb_reason_vc, fb_added_by, fb_added_ts, fb_active_in)
                             VALUES (:ip, 'Brute force panel (auto)', 0, :ts, 1)"
                        )->execute([':ip' => $bfIP, ':ts' => time()]);

                        // Aplicar a pf inmediatamente sin esperar al daemon
                        if (class_exists('privilege')) {
                            try { privilege::run('fw_block_apply'); } catch (\Throwable $ignored) {}
                        }
                    }
                }
            } catch (\Throwable $bfEx) {
                // No interrumpir el flujo de login si la tabla no existe aún
                error_log('fw_admin brute-force tracking: ' . $bfEx->getMessage());
            }
        }
        // ── Fin brute-force tracking ────────────────────────────────────────
        header("location: ./?invalidlogin");
        exit();
    }
}

if (isset($_COOKIE['zUser'])) {

    if (isset($_COOKIE['zSec'])) {
        if ($_COOKIE['zSec'] === '0' || $_COOKIE['zSec'] === false) {
            $secure = false;
        } else {
            $secure = true;
        }
    } else {
        $secure = true;
    }

    ctrl_auth::Authenticate($_COOKIE['zUser'], $_COOKIE['zPass'], false, true, $secure);
}

if (!isset($_SESSION['zpuid'])) {
    ctrl_auth::RequireUser();
}


runtime_hook::Execute('OnBeforeControllerInit');
$controller->Init();
ui_templateparser::Generate("etc/styles/" . ui_template::GetUserTemplate());
?>

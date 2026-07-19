<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Email class used for sending out emails from ZPanel. This class extends on the PHPMailer library included in etc/lib/PHPMailer!
 * @package zpanelx
 * @subpackage dryden -> sys
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
require './etc/lib/PHPMailer/src/Exception.php';
require './etc/lib/PHPMailer/src/PHPMailer.php';
require './etc/lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

class sys_email extends PHPMailer {

    /**
     * Sends the email with the contents of the object (Body etc. set using the parant calls in phpMailer!)
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return boolean 
     */
    private static function isValidSMTPHost($host) {
        if (empty($host)) return false;
        // If it looks like an IP, reject private/reserved ranges
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            return filter_var($host, FILTER_VALIDATE_IP, $flags) !== false;
        }
        // For hostnames, reject localhost and cloud metadata hostnames
        $lower = strtolower($host);
        $blocked = ['localhost', 'metadata.internal', 'metadata.google.internal',
                    '169.254.169.254', 'fd00::ec2', 'instance-data'];
        foreach ($blocked as $b) {
            if ($lower === $b || str_ends_with($lower, '.' . $b)) return false;
        }
        // Must be a valid hostname
        return (bool) filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    public function SendEmail() {
        $this->Mailer = ctrl_options::GetSystemOption('mailer_type');
        $this->From = ctrl_options::GetSystemOption('email_from_address');
        $this->FromName = ctrl_options::GetSystemOption('email_from_name');
        if (ctrl_options::GetSystemOption('email_smtp') <> 'false') {
            $this->isSMTP();
            if (ctrl_options::GetSystemOption('smtp_auth') <> 'false') {
                $this->SMTPAuth = true;
                $this->Username = ctrl_options::GetSystemOption('smtp_username');
                $this->Password = ctrl_options::GetSystemOption('smtp_password');
            }
            if (ctrl_options::GetSystemOption('smtp_secure') <> 'false') {
                $this->SMTPSecure = ctrl_options::GetSystemOption('smtp_secure');
            }
            $smtp_host = ctrl_options::GetSystemOption('smtp_server');
            $smtp_port = intval(ctrl_options::GetSystemOption('smtp_port'));
            if (!self::isValidSMTPHost($smtp_host) || $smtp_port < 1 || $smtp_port > 65535) {
                return false;
            }
            $this->Host = $smtp_host;
            $this->Port = $smtp_port;
        }

        $this->CharSet = 'utf-8';
        ob_start();
        $send_resault = $this->send();
        $error = ob_get_contents();
        ob_clean();
        if ($send_resault) {
            runtime_hook::Execute('OnSuccessfulSendEmail');
            return true;
        } else {
            $logger = new debug_logger();
            $logger->method = ctrl_options::GetSystemOption('logmode');
            $logger->logcode = "061";
            $logger->detail = 'Error sending email (using sys_email): ' . $error . '';
            $logger->writeLog();
            runtime_hook::Execute('OnFailedSendEmail');
            return false;
        }
    }

}

?>

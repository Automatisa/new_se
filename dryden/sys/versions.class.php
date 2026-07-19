<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * This reports on core zpanel software versions.
 * @package zpanelx
 * @subpackage dryden -> sys
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
class sys_versions {

    /**
     * Returns the Apache HTTPd Server Version Number
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return string Apache Server version number.
     */
    static function ShowApacheVersion() {
        // Detección sin comandos externos (respeta la norma de no usar exec).

        // 1) PHP como módulo de Apache (mod_php)
        if (function_exists('apache_get_version')
            && preg_match('|Apache/(\d+\.\d+\.\d+)|i', (string) apache_get_version(), $m)) {
            return $m[1];
        }

        // 2) SERVER_SOFTWARE (solo si ServerTokens expone la versión)
        if (!empty($_SERVER['SERVER_SOFTWARE'])
            && preg_match('|Apache/(\d+\.\d+\.\d+)|i', $_SERVER['SERVER_SOFTWARE'], $m)) {
            return $m[1];
        }

        // 3) Leer la cadena de versión embebida en el binario httpd (solo lectura)
        $candidates = [];
        $sn = (string) ctrl_options::GetSystemOption('apache_sn');
        if ($sn !== '') {
            if ($sn[0] === '/') {
                $candidates[] = $sn;
            } else {
                foreach (['/usr/local/sbin/', '/usr/sbin/', '/usr/local/bin/', '/usr/bin/'] as $d) {
                    $candidates[] = $d . $sn;
                }
            }
        }
        $candidates = array_merge($candidates, [
            '/usr/local/sbin/httpd', '/usr/sbin/httpd',
            '/usr/sbin/apache2', '/usr/local/sbin/apache2',
        ]);
        foreach ($candidates as $bin) {
            if (@is_readable($bin)) {
                $data = @file_get_contents($bin);
                if ($data !== false && preg_match('|Apache/(\d+\.\d+\.\d+)|', $data, $m)) {
                    return $m[1];
                }
            }
        }

        return "Not found";
    }

    /**
     * Returns the PHP version number.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return string PHP version number
     */
    static function ShowPHPVersion() {
        return phpversion();
    }

    /**
     * Returns the MySQL server version number.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return string MySQL version number 
     */
    static function ShowMySQLVersion() {
        global $zdbh;
        $retval = $zdbh->query("SHOW VARIABLES LIKE \"version\"")->Fetch();
        return $retval['Value'];
    }

    /**
     * Returns a human readable copy of the Kernal version number running on the server.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param string $platform The OS Platform (eg. Linux or Windows)
     * @return string *NIX kernal version. - Will return 'N/A' for Microsoft Windows.
     */
    static function ShowOSKernalVersion($platform) {
        if ($platform == 'Linux') {
            $retval = exec('uname -r');
        } else {
            $retval = "N/A";
        }
        return $retval;
    }

    /**
     * Returns in human readable form the operating system platform (eg. Windows, Linux, FreeBSD, Other)
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return string Human readable OS Platform name.
     */
    static function ShowOSPlatformVersion() {
        $os_abbr = strtoupper(substr(PHP_OS, 0, 3));
        if ($os_abbr == "WIN") {
            $retval = "Windows";
        } elseif ($os_abbr == "LIN") {
            $retval = "Linux";
        } elseif ($os_abbr == "FRE") {
            $retval = "FreeBSD";
        } elseif ($os_abbr == "DAR") {
            $retval = "MacOSX";
        } else {
            $retval = "Other";
        }
        return $retval;
    }

    /**
     * Returns the Linux operating system (distrubution) name.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return string The OS/Distrib name.
     */
    static function ShowOSName() {
        // Detección sin comandos externos (php_uname no usa exec).
        // El valor devuelto debe coincidir con un icono de img/os_icons/<name>.png
        $sys = (string) php_uname('s'); // "FreeBSD", "Linux", "Darwin", "NetBSD"...

        $direct = ['FreeBSD', 'NetBSD', 'OpenBSD', 'DragonFly', 'Darwin', 'SunOS'];
        foreach ($direct as $name) {
            if (stripos($sys, $name) !== false) {
                return $name;
            }
        }
        if (stripos($sys, 'Windows') !== false) {
            return 'Windows';
        }

        if (stripos($sys, 'Linux') !== false) {
            // Nombre de distribución vía /etc/os-release (estándar, sin ejecutar nada)
            $id = '';
            if (is_readable('/etc/os-release')) {
                foreach (file('/etc/os-release', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
                    if (strpos($ln, 'ID=') === 0) {
                        $id = strtolower(trim(substr($ln, 3), " \"'"));
                        break;
                    }
                }
            }
            $distros = [
                'ubuntu' => 'Ubuntu', 'debian' => 'Debian', 'centos' => 'CentOS',
                'fedora' => 'Fedora', 'rhel' => 'Redhat', 'redhat' => 'Redhat',
                'arch' => 'Arch', 'gentoo' => 'Gentoo', 'slackware' => 'Slackware',
                'opensuse' => 'Suse', 'suse' => 'Suse', 'mandrake' => 'Mandrake',
            ];
            foreach ($distros as $k => $name) {
                if ($id !== '' && strpos($id, $k) !== false) {
                    return $name;
                }
            }
            return 'Unix'; // Linux genérico (icono existente)
        }

        return 'Unix';
    }

    /**
     * Returns in human readable form the version of perl installed.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return string Human readable Perl version number.
     */
    static function ShowPerlVersion() {
        ob_start();
        passthru("perl -v", $result);
        $content_grabbed = ob_get_contents();
        ob_end_clean();
        if (self::ShowOSPlatformVersion() == "Windows") {
            preg_match_all("#(?<=\()(.*?)(?=\))#", $content_grabbed, $perlversion);
        } else {
            preg_match_all("#(\d+).(\d+).(\d+)#", $content_grabbed, $perlversion);
        }
        if (!empty($perlversion[0]) && !empty($perlversion[0][0])) {
            $retval = str_replace("v", "", $perlversion[0][0]);
        } else {
            $retval = "Perl not available";
        }
        return $retval;
    }

    /**
     * Returns the Bulwark version (based on the DB version number.)
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @return string Bulwark DB Version
     */
    static function ShowBulwarkVersion() {
        return ctrl_options::GetSystemOption('dbversion');
    }
}

?>

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
$ftp_db = ctrl_options::GetSystemOption('ftp_db');
include('cnf/db.php');
$z_db_user = $user;
$z_db_pass = $pass;
try {
    $ftp_db = new db_driver("mysql:host=" . $host . ";dbname=$ftp_db", $z_db_user, $z_db_pass);
} catch (PDOException $e) {
    
}

// Hash password with SHA512-CRYPT ($6$) — ProFTPD verifies via SQLAuthTypes Crypt
function proftpd_mysql_hash_password($plain) {
    $salt = '$6$' . substr(base64_encode(random_bytes(12)), 0, 16) . '$';
    return crypt($plain, $salt);
}

function proftpd_mysql_get_hosting_uid(string $panelUsername): int {
    $sysuser = 'h_' . $panelUsername;
    $fh = @fopen('/etc/passwd', 'r');
    if ($fh) {
        while (($line = fgets($fh)) !== false) {
            $parts = explode(':', trim($line));
            if (($parts[0] ?? '') === $sysuser && isset($parts[2])) {
                fclose($fh);
                return (int)$parts[2];
            }
        }
        fclose($fh);
    }
    return 80;
}

// Included after acount has been created
if (!fs_director::CheckForEmptyValue(self::$create)) {
    $homedir = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . $homedirectory_to_use . "";
    $sql = $ftp_db->prepare("INSERT INTO ftpquotalimits (name, quota_type, per_session, limit_type, bytes_in_avail, bytes_out_avail, bytes_xfer_avail, files_in_avail, files_out_avail, files_xfer_avail) VALUES (:username, 'user', 'true', 'hard', 0, 0, 0, 0, 0, 0);");
    $sql->bindParam(':username', $username);
    $sql->execute();
    $daemonHash = proftpd_mysql_hash_password($password);
    $ftpUid = proftpd_mysql_get_hosting_uid($currentuser['username']);
    $ftpGid = 80; // grupo www — Apache y PHP-FPM leen por grupo
    $sql = $ftp_db->prepare("INSERT INTO ftpuser (userid, passwd, uid, gid, homedir, shell, count, accessed, modified) VALUES (:username, :password, :uid, :gid, :homedir, '/sbin/nologin', 0, now(), now());");
    $sql->bindParam(':username', $username);
    $sql->bindParam(':password', $daemonHash);
    $sql->bindParam(':uid', $ftpUid);
    $sql->bindParam(':gid', $ftpGid);
    $sql->bindParam(':homedir', $homedir);
    $sql->execute();
}


// Included after account is created
if (!fs_director::CheckForEmptyValue(self::$delete)) {
    $sql = $ftp_db->prepare("DELETE FROM ftpuser  WHERE userid=:userid");
    $sql->bindParam(':userid', $rowftp['ft_user_vc']);
    $sql->execute();
    $sql = $ftp_db->prepare("DELETE FROM ftpquotalimits WHERE name=:username");
    $sql->bindParam(':username', $rowftp['ft_user_vc']);
    $sql->execute();
}
// Included after account password has been reset
if (!fs_director::CheckForEmptyValue(self::$reset)) {
    $daemonHash = proftpd_mysql_hash_password($password);
    $sql = $ftp_db->prepare("UPDATE ftpuser SET passwd=:password WHERE userid=:username");
    $sql->bindParam(':username', $rowftp['ft_user_vc']);
    $sql->bindParam(':password', $daemonHash);
    $sql->execute();
}

// Regenerate per-user access limits config when accounts are added or removed
if (!fs_director::CheckForEmptyValue(self::$create) || !fs_director::CheckForEmptyValue(self::$delete)) {
    include_once(__DIR__ . '/ftp_conf_gen.php');
    generateFTPAccessConf($host, $z_db_user, $z_db_pass);
}
?>
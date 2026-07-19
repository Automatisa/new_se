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
$mailserver_db = ctrl_options::GetSystemOption('mailserver_db');
include('cnf/db.php');
$z_db_user = $user;
$z_db_pass = $pass;
try {
    $mail_db = new db_driver("mysql:host=" . $host . ";dbname=" . $mailserver_db . "", $z_db_user, $z_db_pass);
} catch (PDOException $e) {
    // Do not expose connection details to the user
}

// Adding PostFix Mailboxes
if (!fs_director::CheckForEmptyValue(self::$create)) {
    //$result = $mail_db->query("SELECT domain FROM domain WHERE domain='" . $domain . "'")->Fetch();
    $numrows = $mail_db->prepare("SELECT domain FROM domain WHERE domain=:domain");
    $numrows->bindParam(':domain', $domain);
    $numrows->execute();
    $result = $numrows->fetch();
    if (!$result) {
        $sql = $mail_db->prepare("INSERT INTO domain (  domain,
                                                        description,
                                                        aliases,
                                                        mailboxes,
                                                        maxquota,
                                                        quota,
                                                        transport,
                                                        backupmx,
                                                        created,
                                                        modified,
                                                        active) VALUES (
                                                        :domain,
                                                        '',
                                                        0,
                                                        0,
                                                        0,
                                                        0,
                                                        '',
                                                        0,
                                                        NOW(),
                                                        NOW(),
                                                        '1')");
        $sql->bindParam(':domain', $domain);
        $sql->execute();
    }
    //$result = $mail_db->query("SELECT username FROM mailbox WHERE username='" . $fulladdress . "'")->Fetch();
    $numrows = $mail_db->prepare("SELECT username FROM mailbox WHERE username=:fulladdress");
    $numrows->bindParam(':fulladdress', $fulladdress);
    $numrows->execute();
    $result = $numrows->fetch();
    if (!$result) {
        $sql = $mail_db->prepare("INSERT INTO mailbox (username,
								 							password,
														 	name,
															maildir,
														 	local_part,
														 	quota,
														 	domain,
														 	created,
														 	modified,
														 	active) VALUES (
														 	:fulladdress,
														 	:password,
														 	:address,
														 	:location,
														 	:address2,
														 	:maxMail,
														 	:domain,
														 	NOW(),
														 	NOW(),
														 	'1')");
        $salt = '$6$' . substr(base64_encode(random_bytes(12)), 0, 16) . '$';
        $password = '{SHA512-CRYPT}' . crypt($password, $salt);
        $location = $currentuser['username'] . "/mail/" . $domain . "/" . $address . "/";
        $maxMail = ctrl_options::GetSystemOption('max_mail_size');

        $sql->bindParam(':password', $password);
        $sql->bindParam(':address', $address);
        $sql->bindParam(':fulladdress', $fulladdress);
        $sql->bindParam(':location', $location);
        $sql->bindParam(':address2', $address);
        $sql->bindParam(':maxMail', $maxMail);
        $sql->bindParam(':domain', $domain);
        $sql->execute();
        $sql = $mail_db->prepare("INSERT INTO alias  (address,
														 	goto,
														 	domain,
															created,
														 	modified,
														 	active) VALUES (
														 	:fulladdress,
														 	:fulladdress2,
														 	:domain,
														 	NOW(),
														 	NOW(),
														 	'1')");
        $sql->bindParam(':domain', $domain);
        $sql->bindParam(':fulladdress', $fulladdress);
        $sql->bindParam(':fulladdress2', $fulladdress);
        $sql->execute();

        // Create Maildir++ directory structure on disk inside hostdata.
        // El directorio raíz del usuario (hostdata/{user}/) ya existe y pertenece a www;
        // solo creamos mail/ y subdirectorios con permisos vmail (SGID 02770).
        $mail_dir   = rtrim(ctrl_options::GetSystemOption('hosted_dir'), '/') . '/' . $currentuser['username'] . '/mail';
        $domain_dir = $mail_dir . '/' . $domain;
        $mailbase   = $domain_dir . '/' . $address;
        foreach ([$mail_dir, $domain_dir] as $dir) {
            @mkdir($dir, 02770);
            @chmod($dir, 02770);
            @chgrp($dir, 'vmail');
        }
        foreach (['', '/cur', '/new', '/tmp',
                  '/.Drafts', '/.Drafts/cur', '/.Drafts/new', '/.Drafts/tmp',
                  '/.Sent',   '/.Sent/cur',   '/.Sent/new',   '/.Sent/tmp',
                  '/.Trash',  '/.Trash/cur',  '/.Trash/new',  '/.Trash/tmp',
                  '/.Junk',   '/.Junk/cur',   '/.Junk/new',   '/.Junk/tmp'] as $sub) {
            @mkdir($mailbase . $sub, 02770);
            @chmod($mailbase . $sub, 02770);
            @chgrp($mailbase . $sub, 'vmail');
        }
        file_put_contents($mailbase . '/subscriptions', "Drafts\nSent\nTrash\nJunk\n");
        @chgrp($mailbase . '/subscriptions', 'vmail');
    }
}

// Deleting PostFix Mailboxes
if (!fs_director::CheckForEmptyValue(self::$delete)) {
    $sql = $mail_db->prepare("DELETE FROM mailbox WHERE username=:mb_address_vc");
    $sql->bindParam(':mb_address_vc', $rowmailbox['mb_address_vc']);
    $sql->execute();
    $sql = $mail_db->prepare("DELETE FROM alias WHERE address=:mb_address_vc");
    $sql->bindParam(':mb_address_vc', $rowmailbox['mb_address_vc']);
    $sql->execute();
}

//Saving PostFix Mailboxes
if (!fs_director::CheckForEmptyValue(self::$update)) {
    if (!fs_director::CheckForEmptyValue($password)) {
        $sql = $mail_db->prepare("UPDATE mailbox SET password=:password, modified=NOW() WHERE username=:mb_address_vc");
        $salt = '$6$' . substr(base64_encode(random_bytes(12)), 0, 16) . '$';
        $password = '{SHA512-CRYPT}' . crypt($password, $salt);
        $sql->bindParam(':password', $password);
        $sql->bindParam(':mb_address_vc', $rowmailbox['mb_address_vc']);
        $sql->execute();
    }
    $sql = $mail_db->prepare("UPDATE mailbox SET active=:enabled, modified=NOW() WHERE username=:mb_address_vc");
    $sql->bindParam(':enabled', $enabled);
    $sql->bindParam(':mb_address_vc', $rowmailbox['mb_address_vc']);
    $sql->execute();
}
?>
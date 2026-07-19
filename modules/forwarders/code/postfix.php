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
    
}



// Deleting Postfix Forwarder
if (!fs_director::CheckForEmptyValue(self::$delete)) {
    $numrows = $mail_db->prepare("SELECT address FROM alias WHERE address=:fw_address_vc");
    $numrows->bindParam(':fw_address_vc', $rowforwarder['fw_address_vc']);
    $numrows->execute();
    $result = $numrows->fetch();
    if ($result) {
        // If a mailbox exists, reset alias to local delivery; otherwise delete it
        $mbcheck = $mail_db->prepare("SELECT username FROM mailbox WHERE username=:addr");
        $mbcheck->bindParam(':addr', $rowforwarder['fw_address_vc']);
        $mbcheck->execute();
        if ($mbcheck->fetch()) {
            $sql = $mail_db->prepare("UPDATE alias SET goto=:addr, modified=NOW() WHERE address=:addr2");
            $sql->bindParam(':addr', $rowforwarder['fw_address_vc']);
            $sql->bindParam(':addr2', $rowforwarder['fw_address_vc']);
            $sql->execute();
        } else {
            $sql = $mail_db->prepare("DELETE FROM alias WHERE address=:addr");
            $sql->bindParam(':addr', $rowforwarder['fw_address_vc']);
            $sql->execute();
        }
    }

    // Clean up domain if no mailboxes or aliases remain
    $domaincheck = explode("@", $rowforwarder['fw_address_vc']);
    $sql = $mail_db->prepare("SELECT * FROM mailbox WHERE domain=:domain");
    $sql->bindParam(':domain', $domaincheck[1]);
    $sql->execute();
    $mailboxresult = $sql->fetch();
    $sql = $mail_db->prepare("SELECT * FROM alias WHERE domain=:domain");
    $sql->bindParam(':domain', $domaincheck[1]);
    $sql->execute();
    $aliasresult = $sql->fetch();
    if (!$mailboxresult && !$aliasresult) {
        $sql = $mail_db->prepare("DELETE FROM domain WHERE domain=:domain");
        $sql->bindParam(':domain', $domaincheck[1]);
        $sql->execute();
    }
}

// Adding Postfix Forwarder
if (!fs_director::CheckForEmptyValue(self::$create)) {
    $domainparts = explode("@", $address);
    $forwarder_domain = $domainparts[1];

    // Ensure domain exists in postfix
    $numrows = $mail_db->prepare("SELECT domain FROM domain WHERE domain=:domain");
    $numrows->bindParam(':domain', $forwarder_domain);
    $numrows->execute();
    if (!$numrows->fetch()) {
        $sql = $mail_db->prepare("INSERT INTO domain (domain, description, aliases, mailboxes, maxquota, quota, transport, backupmx, created, modified, active) VALUES (:domain, '', 0, 0, 0, 0, '', 0, NOW(), NOW(), '1')");
        $sql->bindParam(':domain', $forwarder_domain);
        $sql->execute();
    }

    $numrows = $mail_db->prepare("SELECT address, goto FROM alias WHERE address=:address");
    $numrows->bindParam(':address', $address);
    $numrows->execute();
    $result = $numrows->fetch();

    if ($result) {
        // Alias exists (e.g. a mailbox): append destination
        $goTo = ($keepmessage == 1) ? $result['goto'] . "," . $destination : $destination;
        $sql = $mail_db->prepare("UPDATE alias SET goto=:goTo, modified=NOW() WHERE address=:address");
        $sql->bindParam(':goTo', $goTo);
        $sql->bindParam(':address', $address);
        $sql->execute();
    } else {
        // No existing alias: create one
        $goTo = ($keepmessage == 1) ? $address . "," . $destination : $destination;
        $sql = $mail_db->prepare("INSERT INTO alias (address, goto, domain, created, modified, active) VALUES (:address, :goto, :domain, NOW(), NOW(), '1')");
        $sql->bindParam(':address', $address);
        $sql->bindParam(':goto', $goTo);
        $sql->bindParam(':domain', $forwarder_domain);
        $sql->execute();
    }
}
?>
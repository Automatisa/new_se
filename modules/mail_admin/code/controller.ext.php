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
class module_controller extends ctrl_module
{

    static $ok;

    static function getConfig()
    {
        global $zdbh;
        $sql = "SELECT * FROM x_settings WHERE so_module_vc=:name AND so_usereditable_en = 'true' ORDER BY so_cleanname_vc";
        //$numrows = $zdbh->query($sql);
        $name = ui_module::GetModuleName();
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':name', $name);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':name', $name);
            $res = array();
            $sql->execute();
            while ($rowmailsettings = $sql->fetch()) {
                if (ctrl_options::CheckForPredefinedOptions($rowmailsettings['so_defvalues_tx'])) {
                    $fieldhtml = ctrl_options::OuputSettingMenuField($rowmailsettings['so_name_vc'], $rowmailsettings['so_defvalues_tx'], $rowmailsettings['so_value_tx']);
                } else {
                    $fieldhtml = ctrl_options::OutputSettingTextArea($rowmailsettings['so_name_vc'], $rowmailsettings['so_value_tx']);
                }
                array_push($res, array('cleanname' => ui_language::translate($rowmailsettings['so_cleanname_vc']),
                    'name' => $rowmailsettings['so_name_vc'],
                    'description' => ui_language::translate($rowmailsettings['so_desc_tx']),
                    'value' => $rowmailsettings['so_value_tx'],
                    'fieldhtml' => $fieldhtml));
            }
            return $res;
        } else {
            return false;
        }
    }

    static function doUpdateConfig()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();

        // Per-setting validators: prevent invalid or dangerous values being saved.
        // mailserver_php is used in include() — only known filenames allowed.
        // mailserver_db is used as a PDO dbname — only safe identifiers allowed.
        $validators = array(
            'mailserver_php' => function($v) {
                return $v === 'postfix.php';
            },
            'mailserver_db' => function($v) {
                return (bool) preg_match('/^[a-zA-Z0-9_]{1,64}$/', $v);
            },
            'max_mail_size' => function($v) {
                return ctype_digit($v) && (int)$v > 0;
            },
            'remove_orphan' => function($v) {
                return in_array($v, array('true', 'false'), true);
            },
        );

        $sql = "SELECT * FROM x_settings WHERE so_module_vc=:name AND so_usereditable_en = 'true'";
        $name = ui_module::GetModuleName();
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':name', $name);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':name', $name);
            $sql->execute();
            while ($row = $sql->fetch()) {
                $fieldName = $row['so_name_vc'];
                $value = $controller->GetControllerRequest('FORM', $fieldName);
                if (fs_director::CheckForEmptyValue($value)) {
                    continue;
                }
                // Apply per-field validator if one exists
                if (isset($validators[$fieldName]) && !$validators[$fieldName]($value)) {
                    continue;
                }
                $updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :fieldname");
                $updatesql->bindParam(':value', $value);
                $updatesql->bindParam(':fieldname', $fieldName);
                $updatesql->execute();
            }
        }
        self::$ok = true;
    }

    static function getResult()
    {
        if (self::$sysmail_msg) {
            return ui_sysmessage::shout(ui_language::translate(self::$sysmail_msg), self::$sysmail_err ? 'zannounceerror' : 'zannounceok');
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your settings have been saved successfully!"));
        }
        return;
    }

    // ---- Destino del correo del SISTEMA (root/postmaster) ---------------------------------------
    // Rebotes MAILER-DAEMON, salida de cron, avisos de seguridad... Se puede reenviar a un buzón
    // (del propio servidor o externo) editando el ajuste system_mail_to y aplicándolo a
    // /etc/mail/aliases con un script privilegiado (doas).
    static $sysmail_msg = null;
    static $sysmail_err = false;

    static function getSysMailTo()
    {
        $v = ctrl_options::GetSystemOption('system_mail_to');
        return htmlspecialchars($v === false ? '' : (string)$v, ENT_QUOTES, 'UTF-8');
    }

    static function doSaveSysMail()
    {
        runtime_csfr::Protect();
        global $controller;
        $dest = trim((string)$controller->GetControllerRequest('FORM', 'inSysMailTo'));

        // Vacío = entrega local (/var/mail/root). Si no, debe ser un email válido.
        if ($dest !== '' && !filter_var($dest, FILTER_VALIDATE_EMAIL)) {
            self::$sysmail_err = true;
            self::$sysmail_msg = 'Introduce un email válido o déjalo vacío para entrega local.';
            return;
        }

        if (ctrl_options::GetSystemOption('system_mail_to') !== false) {
            ctrl_options::SetSystemOption('system_mail_to', $dest);
        } else {
            ctrl_options::SetSystemOption('system_mail_to', $dest, true);
        }

        if (!class_exists('privilege')) {
            require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
        }
        try {
            privilege::run('sysmail_alias_apply', array(), true);
            self::$sysmail_msg = $dest === ''
                ? 'Guardado. El correo del sistema se entregará en el buzón local del servidor (/var/mail/root).'
                : 'Guardado. El correo del sistema se reenviará a ' . htmlspecialchars($dest, ENT_QUOTES, 'UTF-8') . '.';
        } catch (Exception $e) {
            self::$sysmail_err = true;
            self::$sysmail_msg = 'Guardado, pero no se pudo aplicar el alias: ' . $e->getMessage();
        }
    }

}

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

    static $error;
    static $delete;
    static $ok;

    public static function getFAQS()
    {
        global $zdbh;
        $sql = "SELECT * FROM x_faqs WHERE fq_question_tx IS NOT NULL AND fq_deleted_ts IS NULL";
        $numrows = $zdbh->query($sql);
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $res = array();
            $sql->execute();
            while ($rowfaqs = $sql->fetch()) {
                array_push($res, array(
                    'question' => $rowfaqs['fq_question_tx'],
                    'answer' => $rowfaqs['fq_answer_tx'],
                    'reseller' => $rowfaqs['fq_acc_fk'],
                    'global' => $rowfaqs['fq_global_in'],
                    'id' => $rowfaqs['fq_id_pk']));
            }
            return $res;
        } else {
            return false;
        }
    }

    public function ListCurrentFAQ($fid)
    {
        global $zdbh;
        $sql = "SELECT * FROM x_faqs WHERE fq_id_pk=:fid IS NOT NULL AND fq_deleted_ts IS NULL";
        //$numrows = $zdbh->query($sql);
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':fid', $fid);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':fid', $fid);
            $res = array();
            $sql->execute();
            while ($rowfaqs = $sql->fetch()) {
                array_push($res, array(
                    'question' => $rowfaqs['fq_question_tx'],
                    'answer' => $rowfaqs['fq_answer_tx'],
                    'reseller' => $rowfaqs['fq_acc_fk'],
                    'global' => $rowfaqs['fq_global_in'],
                    'id' => $rowfaqs['fq_id_pk']));
            }
            return $res;
        } else {
            return false;
        }
    }

    public static function getUserFAQS()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $faqs = self::getFAQS();
        if ($faqs) {
            $res = array();
            foreach ($faqs as $faq) {
                $createdby = NULL;
                if ($faq['reseller'] == $currentuser['resellerid'] || $faq['reseller'] == $currentuser['userid'] || (int)$currentuser['usergroupid'] === ctrl_groups::GROUP_ADMIN || $faq['global'] <> 0) {
                    if ($faq['reseller'] == $currentuser['userid'] || (int)$currentuser['usergroupid'] === ctrl_groups::GROUP_ADMIN) {
                        $allowdelete = "<input type=\"image\" src=\"" . self::getModulePath() . "assets/delete_small.png\" name=\"inDelete_" . $faq['id'] . "\" id=\"inDelete_" . $faq['id'] . "\" value=\"" . $faq['id'] . "\" title=\"DELETE FAQ\">";
                        if ((int)$currentuser['usergroupid'] === ctrl_groups::GROUP_ADMIN) {
                            $createdbyid = ctrl_users::GetUserDetail($faq['reseller']);
                            $createdby = " (" . $createdbyid['username'] . ")";
                        }
                    } else {
                        $allowdelete = NULL;
                    }
                    array_push($res, array(
                        'question' => $faq['question'] . $createdby,
                        'answer' => $faq['answer'],
                        'reseller' => $faq['reseller'],
                        'global' => $faq['global'],
                        'allowdelete' => $allowdelete,
                        'id' => $faq['id']));
                }
            }
            return $res;
        } else {
            return false;
        }
    }

    public static function getAddFAQS()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        if ((int)$currentuser['usergroupid'] === ctrl_groups::GROUP_ADMIN || (int)$currentuser['usergroupid'] === ctrl_groups::GROUP_RESELLER) {
            return true;
        } else {
            return false;
        }
    }

    static function doDeleteFaq()
    {
        global $controller;
        runtime_csfr::Protect();
        $faqs = self::getFAQS();
        //print_r($_POST);
        foreach ($faqs as $faq) {
            if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inDelete_' . $faq['id'] . '_x'))) {
                header("location: ./?module=" . $controller->GetCurrentModule() . "&show=Delete&other=" . $faq['id'] . "");
                exit;
                //self::ExecuteDeleteFaq($faq['id']);
            }
        }
    }

    static function doConfirmDeleteFAQ()
    {
        global $controller, $zdbh;
        runtime_csfr::Protect();
        $formvars = $controller->GetAllControllerRequests('FORM');
        // AUTZ (IDOR fix): el admin puede borrar cualquier FAQ; un reseller SOLO las que creó
        // (fq_acc_fk). Antes se borraba por inDelete sin comprobar dueño.
        $cu  = ctrl_users::GetUserDetail();
        $fid = (int) $formvars['inDelete'];
        if ((int)$cu['usergroupid'] !== ctrl_groups::GROUP_ADMIN) {
            $chk = $zdbh->prepare("SELECT COUNT(*) FROM x_faqs WHERE fq_id_pk=:fid AND fq_acc_fk=:uid AND fq_deleted_ts IS NULL");
            $chk->execute(array(':fid' => $fid, ':uid' => (int) $cu['userid']));
            if ((int) $chk->fetchColumn() === 0) {
                return false;
            }
        }
        if (self::ExecuteDeleteFaq($fid))
            return true;
        return false;
    }

    static function doAddFaq()
    {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inAdd'))) {
            $question = $controller->GetControllerRequest('FORM', 'question');
            $answer = $controller->GetControllerRequest('FORM', 'answer');
            $userid = $currentuser['userid'];
            if ((int)$currentuser['usergroupid'] === ctrl_groups::GROUP_ADMIN) {
                $global = 1;
            } else {
                $global = 0;
            }
            self::ExecuteAddFaq($question, $answer, $userid, $global);
        }
    }

    static function getEditCurrentFAQID()
    {
        global $controller;
        // Se refleja en value="..." de la plantilla; escapar para evitar XSS reflejado.
        if ($controller->GetControllerRequest('URL', 'other')) {
            return htmlspecialchars((string)$controller->GetControllerRequest('URL', 'other'), ENT_QUOTES, 'UTF-8');
        } else {
            return "";
        }
    }

    static function ExecuteDeleteFaq($fq_id_pk)
    {
        global $zdbh;
        $sql = "UPDATE x_faqs SET fq_deleted_ts=:time WHERE fq_id_pk=:fq_id_pk";
        $sql = $zdbh->prepare($sql);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':fq_id_pk', $fq_id_pk);
        $sql->execute();
        self::$delete = true;
        return true;
    }

    static function ExecuteAddFaq($question, $answer, $userid, $global)
    {
        global $zdbh;
        if ($question != "" && $answer != "") {
            $sql = "INSERT INTO x_faqs (fq_acc_fk, fq_question_tx, fq_answer_tx, fq_global_in, fq_created_ts) VALUES (:userid, :question, :answer, :global, :time)";
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $userid);
            $sql->bindParam(':question', $question);
            $sql->bindParam(':answer', $answer);
            $sql->bindParam(':global', $global);
            $time = time();
            $sql->bindParam(':time', $time);
            $sql->execute();
            self::$ok = true;
            return true;
        } else {
            self::$error = true;
            return false;
        }
    }

    static function getisDeleteFAQ()
    {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        if ((isset($urlvars['show'])) && ($urlvars['show'] == "Delete"))
            return true;
        return false;
    }

    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$error)) {
            return ui_sysmessage::shout(ui_language::translate("You need to enter a question and an answer to add a FAQ item!"), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$delete)) {
            return ui_sysmessage::shout(ui_language::translate("FAQ item was deleted successfully!"), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("FAQ item was added successfully!"), "zannounceok");
        }
        return;
    }

}

<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Generic template place holder class.
 * @package zpanelx
 * @subpackage dryden -> ui -> tpl
 * @version 1.1.0
 * @author Jason Davis (jason.davis.fl@gmail.com)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
class ui_tpl_modulelistzsidebar {

    public static function Template() {
        $line = '';
        $modcats = ui_moduleloader::GetModuleCats();

        foreach ($modcats as $modcat) {
            $mods = ui_moduleloader::GetModuleList($modcat['mc_id_pk'], 'modadmin');

            if ($mods) {
                $line .= '<li>';
                $line .= '<div class="heading">' .$modcat['mc_name_vc']. ' <span class="open">+</span></div>';
                $line .= '<ul>';
                
                foreach ($mods as $mod) {
                    $line .= '<li>';
                    $line .= '<a href="?module=' . $mod['mo_folder_vc'] . '">'
                           . '<img src="/modules/' . $mod['mo_folder_vc'] . '/assets/icon.png" width="16" height="16" alt="" style="opacity:.75;filter:grayscale(30%)">'
                           . ' <: ' . $mod['mo_name_vc'] . ' :>'
                           . '</a>';
                    $line .= '</li>';
                }
                $line .= '</ul></li>';
            }
        }

        return $line;
    }

}

?>

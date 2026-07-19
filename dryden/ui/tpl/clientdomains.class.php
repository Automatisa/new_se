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
class ui_tpl_clientdomains {

    public static function Template() {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail();
        $user = $currentuser;
        $domain_limit = 4;

        /* Domains */
        $line = self::getDomains($currentuser, $zdbh, 'domain', $domain_limit);

        /* Sub Domains */
        $line .= self::getDomains($currentuser, $zdbh, 'subdomain', $domain_limit);

        /* Parked Domains */
        $line .= self::getDomains($currentuser, $zdbh, 'parkeddomain', $domain_limit);

        return $line;
    }


    /**
     * getDomains Retuns Domains a user owns
     * @param  object  $user         User Object
     * @param  object  $db           Database Object
     * @param  string  $type         domains, subdomain, or parkeddomain
     * @param  integer $domain_limit Number of Domains to list.  Default is 4
     * @return string                Returns all domains as a string
     */
    public static function getDomains($user, $db, $type = 'domain', $domain_limit = 4){

        $zdbh = $db;

        switch ($type) {
            case 'domain':
                $domain_type = 1;
                $name = 'Domain';
                $domain_url = 'domains';
                break;
            case 'subdomain':
                $domain_type = 2;
                $name = 'Sub-Domain';
                $domain_url = 'sub_domains';
                break;
            case 'parkeddomain':
                $domain_type = 3;
                $name = 'Parked Domain';
                $domain_url = 'parked_domains';
                break;
        }

        /* Domains */
        $sql = 'SELECT * FROM x_vhosts WHERE vh_acc_fk= :userid AND vh_type_in=' .$domain_type. ' AND vh_deleted_ts IS NULL ORDER BY vh_id_pk LIMIT '.$domain_limit;

        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $user['userid']);
        $numrows->execute();
        $line = '';
        
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $user['userid']);
            $sql->execute();
            $limit = 0;

            if($domain_type == 1){
                $line .= '<div class="stats-row s-top">';
            }else{
                $line .= '<div class="stats-row">';
            }
            $line .= '    <div class="stats-column"><strong>'.$name.'s</strong></div>';
            $line .= '    <div class="stats-column"><strong>Status</strong></div>';
            $line .= '    <div class="stats-column"><a href="?module='.$domain_url.'">(Add New '.$name.')</a></div>';
            $line .= '</div><!--end stats-row-->';
            while ($rowdomains = $sql->fetch()) {
                if ($rowdomains['vh_type_in'] == $domain_type) {

                    $line .= '<div class="stats-row">';
                    $line .= '  <div class="stats-column"><a href="http://'.$rowdomains['vh_name_vc'] . '" target="_blank">' . $rowdomains['vh_name_vc'] . '</a></div>';


                    if ($rowdomains['vh_active_in'] == 1 && $rowdomains['vh_enabled_in'] == 1) {
                        $line .= '<div class="stats-column"><span class="badge bg-success">Active</span></div>';
                    } elseif ($rowdomains['vh_active_in'] == 0 && $rowdomains['vh_enabled_in'] == 1) {
                        $line .= '<div class="stats-column"><span class="badge bg-warning">Pending</span></div>';
                    } else {
                        $line .= '';
                    }
                    if ($rowdomains['vh_enabled_in'] == 0) {
                        $line .= '<div class="stats-column"><span class="badge bg-danger">Disabled</span></div>';
                    }

                    $line .= '</div><!--end stats-row-->';
                }
                $limit++;
            }
            if ($limit >= $domain_limit) {

                $line .= '<div class="stats-row">';
                $line .= '    <div class="stats-column"><a href="?module='.$domain_url.'">(View all '.$name.'s)</a></div>';
                $line .= '</div><!--end stats-row-->';

            }
        } else {

            if($domain_type == 1){
                $line .= '<div class="stats-row s-top">';
            }else{
                $line .= '<div class="stats-row">';
            }
            $line .= '    <div class="stats-column"><strong>'.$name.'s</strong></div>';
            $line .= '</div><!--end stats-row-->';
            $line .= '<div class="stats-row">';
            $line .= '    <div class="stats-column"><span class="Side_Info_None">No '.$name.'s Found</span></div>';
            $line .= '    <div class="stats-column"><a href="?module='.$domain_url.'">Add New '.$name.'</a></div>';
            $line .= '</div><!--end stats-row-->';
        }

        return $line;
    }
}

?>
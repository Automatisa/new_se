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
class ui_tpl_modulelistznavbar
{

    public static function Template()
    {

        $activeClass = isset($_REQUEST['module']) ? '' : 'active';
        $line = '<li class="nav-item"><a class="nav-link' . ($activeClass ? ' ' . $activeClass : '') . '" href="."><: Home :></a></li>';

        $modcats = ui_moduleloader::GetModuleCats();
        rsort($modcats);

        foreach ($modcats as $modcat) {
            $shortName = $modcat['mc_name_vc'];

            switch ($shortName) {
                case 'Account Information':
                    $shortName = 'Account';
                    break;
                case 'Server Admin':
                    $shortName = 'Admin';
                    break;
                case 'Database Management':
                    $shortName = 'Database';
                    break;
                case 'Domain Management':
                    $shortName = 'Domain';
                    break;
                case 'File Management':
                    $shortName = 'File';
                    break;
            }

            $shortName = '<: ' . $shortName . ' :>';
            $mods = ui_moduleloader::GetModuleList($modcat['mc_id_pk']);

            if ($mods) {
                $line .= '<li class="nav-item dropdown">';
                if ($shortName == '<: Account :>') {
                    $currentuser = ctrl_users::GetUserDetail();
                    $image = self::get_gravatar($currentuser['email'], 22, 'mm', 'g', true);
                    $line .= '<a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">' . $image . ' ' . $shortName . '</a>';
                } else {
                    $line .= '<a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">' . $shortName . '</a>';
                }

                $line .= '<ul class="dropdown-menu dropdown-menu-dark">';
                foreach ($mods as $mod) {

                    $class_name = str_replace(array(' ', '_'), '-', strtolower($mod['mo_folder_vc']));
                    $activeItem = (isset($_GET['module']) && $_GET['module'] == $mod['mo_folder_vc']) ? ' active' : '';

                    if ($mod['mo_installed_ts'] != 0) {
                        if (file_exists('etc/styles/' . ui_template::GetUserTemplate() . '/img/modules/' . $mod['mo_folder_vc'] . '/assets/icon.png')) {
                            $line .= '<li><a class="dropdown-item' . $activeItem . '" href="?module=' . $mod['mo_folder_vc'] . '"><i class="icon-' . $class_name . ' greyscale"><img src="etc/styles/' . ui_template::GetUserTemplate() . '/img/modules/' . $mod['mo_folder_vc'] . '/assets/icon.png" height="16px" width="16px"></i> <: ' . $mod['mo_name_vc'] . ' :></a></li>';
                        } else {
                            $line .= '<li><a class="dropdown-item' . $activeItem . '" href="?module=' . $mod['mo_folder_vc'] . '"><i class="icon-' . $class_name . ' greyscale"><img src="/modules/' . $mod['mo_folder_vc'] . '/assets/icon.png" height="16px" width="16px"></i> <: ' . $mod['mo_name_vc'] . ' :></a></li>';
                        }
                    } else {
                        $line .= '<li><a class="dropdown-item' . $activeItem . '" href="?module=' . $mod['mo_folder_vc'] . '"><i class="icon-' . $class_name . '"></i> <: ' . $mod['mo_name_vc'] . ' :></a></li>';
                    }
                }
                if ($shortName == '<: Account :>') {
                    $line .= '<li><hr class="dropdown-divider"></li>';
                    $line .= '<li><a class="dropdown-item" href="?logout"><i class="bi bi-box-arrow-right"></i> Logout</a></li>';
                }

                $line .= '</ul></li>';
            }
        }

        return $line;
    }

    /**
     * Get either a Gravatar URL or complete image tag for a specified email address.
     *
     * @param string $email The email address
     * @param string $s Size in pixels, defaults to 80px [ 1 - 2048 ]
     * @param string $d Default imageset to use [ 404 | mm | identicon | monsterid | wavatar ]
     * @param string $r Maximum rating (inclusive) [ g | pg | r | x ]
     * @param boole $img True to return a complete IMG tag False for just the URL
     * @param array $atts Optional, additional key/value attributes to include in the IMG tag
     * @return String containing either just a URL or a complete image tag
     * @source http://gravatar.com/site/implement/images/php/
     */
    public static function get_gravatar($email, $s = 80, $d = 'mm', $r = 'g', $img = false, $atts = array())
    {
        // Avatar LOCAL: SVG genérico embebido como data: URI. NO se hace ninguna petición
        // externa (antes se pedía a gravatar.com el md5 del email -> fuga de privacidad y una
        // petición fallida por la CSP). Funciona con la CSP (img-src 'self' data:).
        $svg  = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='#aaaaaa'>"
              . "<circle cx='12' cy='8' r='4'/><path d='M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7'/></svg>";
        $data = 'data:image/svg+xml;base64,' . base64_encode($svg);
        if (!$img) {
            return $data;
        }
        $tag = '<img src="' . $data . '" width="' . (int)$s . '" height="' . (int)$s . '" alt="avatar"';
        foreach ($atts as $key => $val) {
            $tag .= ' ' . $key . '="' . $val . '"';
        }
        $tag .= ' />';
        return $tag;
    }

    /**
     * Detects the correct protocol to use when building the Gravatar image URL, this prevents SSL errors if the panel is being hosted over SSL.
     * @return string The protocol prefix.
     */
    private static function getCurrentProtocol()
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) {
            return 'https://';
        } else {
            return 'http://';
        }
    }

}

?>

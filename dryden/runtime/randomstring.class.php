<?php
/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/)
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * Class provides functionallity to generate secure random strings
 * @package zpanelx
 * @subpackage dryden -> runtime
 * @version 1.0.2
 * @author Sam Mottley (smottley@zpanelcp.com)
 * @copyright ZPanel Project (http://www.zpanelcp.com/)
 * @link http://www.zpanelcp.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */

class runtime_randomstring{
    /**
     * Generate a random string.
     *
     * Reescrito para usar un CSPRNG (random_int) en vez de mt_rand/uniqid/microtime/rand,
     * que eran predecibles. Esta función genera el token CSRF ($_SESSION['zpcsfr']), así que
     * su calidad es relevante para la seguridad.
     *
     * @param int    $size       Longitud de la cadena (por defecto 50).
     * @param string $characters Alfabeto permitido.
     * @param bool|string $hash  false = devolver la cadena; true = sha256; o el nombre de un
     *                           algoritmo para hash($algo, cadena).
     * @return string
     */
    static public function randomHash($size = 50, $characters = '1234567890qwertyuiopasdfghjklzxcvbnm', $hash = false) {
        $size = (int)$size;
        if ($size < 1) { $size = 50; }
        $len = strlen($characters);
        $out = '';
        if ($len > 0) {
            for ($i = 0; $i < $size; $i++) {
                $out .= $characters[random_int(0, $len - 1)];
            }
        }
        if ($hash === false) {
            return $out;
        }
        $algo = ($hash === true) ? 'sha256' : $hash;
        return hash($algo, $out);
    }
}

?>

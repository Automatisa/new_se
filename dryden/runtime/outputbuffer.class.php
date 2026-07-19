<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 *
 * Output buffering support class.
 * @package bulwark
 * @subpackage dryden -> runtime
 * @version 1.0.0
 * @author Bobby Allen (ballen@bobbyallen.me)
 * @copyright 2014-2019 Sentora Project (http://www.sentora.org/) 
 * @link http://www.sentora.org
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */
class runtime_outputbuffer
{

    /**
     * Captures the content from the output buffer.
     * @author Bobby Allen (ballen@bobbyallen.me)
     * @param callable command The code of which to return the output buffer for.
     * @return string The output buffer contents.
     */
    public static function Capture(Closure $command)
    {
        ob_start();
        call_user_func($command);
        $result = ob_get_clean();
        if (ob_get_length() > 0) {
            ob_end_clean();
        }    
        return $result;
    }

}

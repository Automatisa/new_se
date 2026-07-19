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
 * Change P.Peyremorte:
 * - cleaned WriteCronFile() (removed duplicate parts).
 * - reformated header inserted in crontab file (heading spaces and wrong EOL encoding)
 * - removed daemon task that is handled by independant crontab /etc/cron.d/bulwark-daemon (linux)
 */
    if (!class_exists('privilege')) {
        require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
    }
class module_controller extends ctrl_module
{

    static $error;
    static $noexists;
    static $cronnoexists;
    static $cronnowrite;
    static $alreadyexists;
    static $blank;
    static $invalidtiming;
    static $ok;

    /**
     * Whitelist validator for cron timing expressions.
     *
     * Background (security fix, June 2026): prior to this fix, the value of the
     * `inTiming` form field was persisted verbatim into x_cronjobs.ct_timing_vc
     * and later concatenated into the generated crontab file (see WriteCronFile,
     * line ~286). Because no character whitelist was applied, an authenticated
     * user could submit a value such as "* * * * *\nroot /path/to/whatever" or
     * embed shell metacharacters, yielding RCE on the host under the user that
     * runs cron (FreeBSD: `cron`/`root` reload path). Severity: critical.
     *
     * This method enforces a strict whitelist: only the 5 cron fields, each
     * containing digits, `*`, `/`, `,` and `-`, separated by single spaces.
     * Any other character (in particular backticks, `$`, `(`, `)`, newlines,
     * semicolons, `&`, `|`, `>`, `<`, quotes, backslashes, etc.) is rejected.
     *
     * @param mixed $timing Value submitted as cron timing.
     * @return bool true iff $timing is a syntactically valid 5-field cron expr.
     */
    public static function IsValidCronTiming($timing)
    {
        if (!is_string($timing) || $timing === '') {
            return false;
        }
        // Hard whitelist of allowed characters — anything else is rejected.
        if (!preg_match('/^[\s\d\*\/,\-]+$/', $timing)) {
            return false;
        }
        // Collapse runs of whitespace and split into the 5 cron fields.
        $fields = preg_split('/\s+/', trim($timing));
        if (!is_array($fields) || count($fields) !== 5) {
            return false;
        }
        // Per-field validator: *, */N, N, N,N-N/N, etc. No commas inside ranges.
        $fieldRe = '/^(\*(\/[0-9]+)?|([0-9]+(-[0-9]+)?(\/[0-9]+)?)(,([0-9]+(-[0-9]+)?(\/[0-9]+)?))*)$/';
        foreach ($fields as $f) {
            if (!preg_match($fieldRe, $f)) {
                return false;
            }
        }
        return true;
    }

    static function getCrons()
    {
        global $zdbh;
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $line = "<h2>" . ui_language::translate("Current Cron Tasks") . "</h2>";
        $sql = "SELECT COUNT(*) FROM x_cronjobs WHERE ct_acc_fk=:userid AND ct_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);

        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {

                $sql = $zdbh->prepare("SELECT * FROM x_cronjobs WHERE ct_acc_fk=:userid AND ct_deleted_ts IS NULL");
                $sql->bindParam(':userid', $currentuser['userid']);
                $sql->execute();
                $line .= "<form action=\"./?module=cron&action=DeleteCron\" method=\"post\">";
                $line .= "<table class=\"table table-striped\">";
                $line .= "<tr>";
                $line .= "<th>" . ui_language::translate("Script") . "</th>";
                $line .= "<th>" . ui_language::translate("Timing") . "</th>";
                $line .= "<th>" . ui_language::translate("Description") . "</th>";
                $line .= "<th></th>";
                $line .= "</tr>";
                while ($rowcrons = $sql->fetch()) {
                    $line .= "<tr>";
                    $line .= "<td>" . htmlspecialchars($rowcrons['ct_script_vc'], ENT_QUOTES, 'UTF-8') . "</td>";
                    $line .= "<td>" . ui_language::translate(self::TranslateTiming($rowcrons['ct_timing_vc'])) . "</td>";
                    $line .= "<td>" . htmlspecialchars($rowcrons['ct_description_tx'], ENT_QUOTES, 'UTF-8') . "</td>";
                    $line .= "<td><button class=\"button-loader delete btn btn-danger\" type=\"submit\" name=\"inDelete_" . $rowcrons['ct_id_pk'] . "\" id=\"button\" value=\"inDelete_" . $rowcrons['ct_id_pk'] . "\"><i class=\"bi bi-trash me-1\"></i>" . ui_language::translate("Delete") . "</button></td>";
                    $line .= "</tr>";
                }
                $line .= "</table>";
                $line .= runtime_csfr::Token();
                $line .= "</form>";
            } else {
                $line .= ui_language::translate("You currently do not have any tasks setup.");
            }
            return $line;
        }
    }

    /** Dominios del usuario (dir bajo web/ + nombre) para el desplegable. */
    static function getUserDomains()
    {
        global $zdbh;
        $cu = ctrl_users::GetUserDetail();
        $st = $zdbh->prepare("SELECT vh_name_vc, vh_directory_vc FROM x_vhosts
                               WHERE vh_acc_fk = :u AND vh_deleted_ts IS NULL AND vh_directory_vc <> ''
                               ORDER BY vh_name_vc");
        $st->execute(array(':u' => $cu['userid']));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Ruta relativa efectiva del script, montada desde dominio + ruta (o inScript legacy). */
    static function effScript()
    {
        global $controller;
        $domain = (string)$controller->GetControllerRequest('FORM', 'inDomain');
        $path   = (string)$controller->GetControllerRequest('FORM', 'inPath');
        $legacy = (string)$controller->GetControllerRequest('FORM', 'inScript');
        if ($path !== '') {
            $path = ltrim(str_replace('\\', '/', $path), '/');
            return ($domain !== '') ? 'web/' . $domain . '/' . $path : $path;
        }
        return $legacy;
    }

    /** Expresión cron efectiva, montada desde el modo de programación (o inTiming legacy). */
    static function effTiming()
    {
        global $controller;
        $mode = (string)$controller->GetControllerRequest('FORM', 'inSchedMode');
        $c = function ($name, $min, $max) use ($controller) {
            $v = (int)$controller->GetControllerRequest('FORM', $name);
            return max($min, min($max, $v));
        };
        switch ($mode) {
            case 'simple':   return (string)$controller->GetControllerRequest('FORM', 'inTimingPreset');
            case 'daily':    return $c('inMinute',0,59) . ' ' . $c('inHour',0,23) . ' * * *';
            case 'weekly':   return $c('inMinute',0,59) . ' ' . $c('inHour',0,23) . ' * * ' . $c('inWeekday',0,6);
            case 'monthly':  return $c('inMinute',0,59) . ' ' . $c('inHour',0,23) . ' ' . $c('inMonthday',1,31) . ' * *';
            case 'advanced': return trim((string)$controller->GetControllerRequest('FORM', 'inCronExpr'));
            default:         return (string)$controller->GetControllerRequest('FORM', 'inTiming');
        }
    }

    static function getCreateCron()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $base = htmlspecialchars(ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . '/', ENT_QUOTES);

        // Opciones de dominio
        $domOpts = '<option value="">' . ui_language::translate('(home root)') . '</option>';
        foreach (self::getUserDomains() as $d) {
            $dir = htmlspecialchars($d['vh_directory_vc'], ENT_QUOTES);
            $nm  = htmlspecialchars($d['vh_name_vc'], ENT_QUOTES);
            $domOpts .= '<option value="' . $dir . '">' . $nm . '</option>';
        }

        $presets =
             '<option value="* * * * *">' . ui_language::translate('Every 1 minute') . '</option>'
            . '<option value="0,5,10,15,20,25,30,35,40,45,50,55 * * * *">' . ui_language::translate('Every 5 minutes') . '</option>'
            . '<option value="0,10,20,30,40,50 * * * *">' . ui_language::translate('Every 10 minutes') . '</option>'
            . '<option value="0,30 * * * *">' . ui_language::translate('Every 30 minutes') . '</option>'
            . '<option value="0 * * * *">' . ui_language::translate('Every 1 hour') . '</option>'
            . '<option value="0 0,2,4,6,8,10,12,14,16,18,20,22 * * *">' . ui_language::translate('Every 2 hours') . '</option>'
            . '<option value="0 0,8,16 * * *">' . ui_language::translate('Every 8 hours') . '</option>'
            . '<option value="0 0,12 * * *">' . ui_language::translate('Every 12 hours') . '</option>'
            . '<option value="0 0 * * *">' . ui_language::translate('Every 1 day') . '</option>';

        $hourOpts = ''; for ($i=0;$i<24;$i++){ $hh=str_pad($i,2,'0',STR_PAD_LEFT); $hourOpts.='<option value="'.$i.'">'.$hh.'</option>'; }
        $minOpts  = ''; for ($i=0;$i<60;$i+=5){ $mm=str_pad($i,2,'0',STR_PAD_LEFT); $minOpts.='<option value="'.$i.'">'.$mm.'</option>'; }
        $dowNames = array('Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado');
        $dowOpts  = ''; foreach($dowNames as $i=>$n){ $dowOpts.='<option value="'.$i.'">'.$n.'</option>'; }
        $mdayOpts = ''; for ($i=1;$i<=31;$i++){ $mdayOpts.='<option value="'.$i.'">'.$i.'</option>'; }

        $csrf = runtime_csfr::Token();
        $t = function($k){ return ui_language::translate($k); };

        $line  = '<h2>' . $t('Create a new task') . '</h2>';
        $line .= '<form id="cronCreateForm" action="./?module=cron&action=CreateCron" method="post">';
        $line .= '<table class="table table-striped">';

        // --- Script: dominio + ruta dentro ---
        $line .= '<tr valign="top"><th>' . $t('Script') . ':</th><td>';
        $line .= '<div style="margin-bottom:6px;">' . $t('Domain') . ': '
               . '<select name="inDomain" id="inDomain" onchange="cronPreview()">' . $domOpts . '</select></div>';
        $line .= '<div>' . $t('File') . ': '
               . '<input name="inPath" type="text" id="inPath" size="40" placeholder="public_html/task.php" oninput="cronPreview()"/></div>';
        $line .= '<div style="margin-top:8px;font-size:12px;color:#555;">' . $t('Full path') . ':<br>'
               . '<code id="cronFull">' . $base . '</code></div>';
        $line .= '<div style="margin-top:6px;font-size:12px;color:#777;">'
               . $t('Only PHP scripts run (the home is noexec). File access inside your script must use absolute paths.') . '</div>';
        $line .= '</td></tr>';

        // --- Comentario ---
        $line .= '<tr><th>' . $t('Comment') . ':</th><td><input name="inDescription" type="text" id="inDescription" size="50" maxlength="50" /></td></tr>';

        // --- Programación ---
        $line .= '<tr valign="top"><th>' . $t('Executed') . ':</th><td>';
        $line .= '<select name="inSchedMode" id="inSchedMode" onchange="cronMode()">'
               . '<option value="simple">' . $t('Simple interval') . '</option>'
               . '<option value="daily">' . $t('Daily at time') . '</option>'
               . '<option value="weekly">' . $t('Weekly') . '</option>'
               . '<option value="monthly">' . $t('Monthly') . '</option>'
               . '<option value="advanced">' . $t('Advanced (cron expression)') . '</option>'
               . '</select>';
        $line .= '<div id="sched_simple" class="schedbox" style="margin-top:8px;"><select name="inTimingPreset" onchange="cronPreview()">' . $presets . '</select></div>';
        // Bloque hora:minuto compartido por diario/semanal/mensual
        $line .= '<div id="sched_time" class="schedbox" style="display:none;margin-top:8px;">' . $t('At') . ' '
               . '<select name="inHour" onchange="cronPreview()">' . $hourOpts . '</select> : '
               . '<select name="inMinute" onchange="cronPreview()">' . $minOpts . '</select></div>';
        $line .= '<div id="sched_weekday" class="schedbox" style="display:none;margin-top:8px;">' . $t('Day of week') . ' '
               . '<select name="inWeekday" onchange="cronPreview()">' . $dowOpts . '</select></div>';
        $line .= '<div id="sched_monthday" class="schedbox" style="display:none;margin-top:8px;">' . $t('Day of month') . ' '
               . '<select name="inMonthday" onchange="cronPreview()">' . $mdayOpts . '</select></div>';
        $line .= '<div id="sched_advanced" class="schedbox" style="display:none;margin-top:8px;">'
               . '<input name="inCronExpr" type="text" size="30" placeholder="*/15 * * * *" oninput="cronPreview()"/> '
               . '<small>' . $t('5 fields: minute hour day month weekday') . '</small></div>';
        $line .= '<div style="margin-top:8px;font-size:12px;color:#555;">' . $t('Schedule') . ': <b><span id="cronHuman">—</span></b> '
               . '<code id="cronExpr" style="margin-left:6px;"></code></div>';
        $line .= '</td></tr>';

        $line .= '<tr><th colspan="2" align="right">' . $csrf
               . '<button class="button-loader btn btn-primary" type="submit" id="button"><i class="bi bi-plus-circle me-1"></i>' . $t('Create') . '</button></th></tr>';
        $line .= '</table></form>';

        // JS: cambio de modo + vista previa de ruta y horario (CSP permite inline).
        $baseJs = json_encode(ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . '/');
        $dows = json_encode($dowNames);
        $line .= <<<JS
<script>
(function(){
 var base=$baseJs, dowNames=$dows;
 window.cronMode=function(){
   var m=document.getElementById('inSchedMode').value;
   function show(id,on){ document.getElementById(id).style.display=on?'block':'none'; }
   show('sched_simple', m==='simple');
   show('sched_time', m==='daily'||m==='weekly'||m==='monthly');
   show('sched_weekday', m==='weekly');
   show('sched_monthday', m==='monthly');
   show('sched_advanced', m==='advanced');
   cronPreview();
 };
 function two(n){return (n<10?'0':'')+n;}
 window.cronPreview=function(){
   var f=document.getElementById('cronCreateForm'); if(!f) return;
   // ruta
   var dom=f.inDomain.value, p=(f.inPath.value||'').replace(/\\\\/g,'/').replace(/^\\/+/,'');
   var rel = p ? (dom? 'web/'+dom+'/'+p : p) : '';
   document.getElementById('cronFull').textContent = base + rel;
   // horario
   var m=f.inSchedMode.value, expr='', human='';
   function gv(n){return f[n]?parseInt(f[n].value,10):0;}
   if(m==='simple'){ expr=f.inTimingPreset.value; human=f.inTimingPreset.options[f.inTimingPreset.selectedIndex].text; }
   else if(m==='daily'){ var h=gv('inHour'),mi=gv('inMinute'); expr=mi+' '+h+' * * *'; human='Cada día a las '+two(h)+':'+two(mi); }
   else if(m==='weekly'){ var h=gv('inHour'),mi=gv('inMinute'),w=gv('inWeekday'); expr=mi+' '+h+' * * '+w; human='Cada '+dowNames[w]+' a las '+two(h)+':'+two(mi); }
   else if(m==='monthly'){ var h=gv('inHour'),mi=gv('inMinute'),d=gv('inMonthday'); expr=mi+' '+h+' '+d+' * *'; human='El día '+d+' de cada mes a las '+two(h)+':'+two(mi); }
   else if(m==='advanced'){ expr=(f.inCronExpr.value||'').trim(); human='Expresión personalizada'; }
   document.getElementById('cronExpr').textContent=expr;
   document.getElementById('cronHuman').textContent=human;
 };
 // Los modos daily/weekly/monthly comparten Hora:Minuto -> los ponemos también en weekly/monthly clonando.
 document.addEventListener('DOMContentLoaded',function(){ if(document.getElementById('inSchedMode')){ cronMode(); } });
 if(document.getElementById('inSchedMode')){ cronMode(); }
})();
</script>
JS;
        return $line;
    }

    static function doCreateCron()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        if (fs_director::CheckForEmptyValue(self::CheckCronForErrors())) {
            // If the user submitted a 'new' request then we will simply add the cron task to the database...
            $sql = $zdbh->prepare("INSERT INTO x_cronjobs (ct_acc_fk, ct_script_vc, ct_description_tx, ct_timing_vc, ct_fullpath_vc, ct_created_ts) VALUES (:userid, :script, :desc, :timing, :fullpath, " . time() . ")");
            // Siempre usar el usuario de sesión autenticado — nunca el valor inUserID del formulario.
            $effScript = self::effScript();
            $effTiming = self::effTiming();
            $sql->bindParam(':userid', $currentuser['userid']);
            $sql->bindParam(':script', $effScript);
            $sql->bindParam(':desc', $controller->GetControllerRequest('FORM', 'inDescription'));
            $sql->bindParam(':timing', $effTiming);
            $full_path = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/" . $effScript;
            $sql->bindParam(':fullpath', $full_path);
            $sql->execute();
            self::WriteCronFile();
            self::$ok = TRUE;
            return;
        }
        self::$error = TRUE;
        return;
    }

    static function doDeleteCron()
    {
        global $zdbh;
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $sql = "SELECT COUNT(*) FROM x_cronjobs WHERE ct_acc_fk=:userid AND ct_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_cronjobs WHERE ct_acc_fk=:userid AND ct_deleted_ts IS NULL");
                $sql->bindParam(':userid', $currentuser['userid']);
                $sql->execute();
                while ($rowcrons = $sql->fetch()) {
                    if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inDelete_' . $rowcrons['ct_id_pk'] . ''))) {
                        $sql2 = $zdbh->prepare("UPDATE x_cronjobs SET ct_deleted_ts=:time WHERE ct_id_pk=:cronid");
                        $sql2->bindParam(':cronid', $rowcrons['ct_id_pk']);
                        $sql2->bindParam(':time', time());
                        $sql2->execute();
                        self::WriteCronFile();
                        self::$ok = TRUE;
                        return;
                    }
                }
            }
        }
        self::$error = TRUE;
        return;
    }

    static function CheckCronForErrors()
    {
        global $zdbh;
        global $controller;
        $retval = FALSE;
        //Try to create the cron file if it doesnt exist...
        if (!file_exists(ctrl_options::GetSystemOption('cron_file'))) {
            fs_filehandler::UpdateFile(ctrl_options::GetSystemOption('cron_file'), 0644, "");
        }
        $currentuser = ctrl_users::GetUserDetail();
        // Check to make sure the cron timing is a syntactically valid 5-field
        // cron expression before anything else — without this guard, an
        // authenticated user can persist arbitrary text into ct_timing_vc,
        // which is later concatenated verbatim into the crontab file (see
        // WriteCronFile). Whitelist validator: see IsValidCronTiming().
        if (!self::IsValidCronTiming(self::effTiming())) {
            self::$invalidtiming = TRUE;
            $retval = TRUE;
        }
        $script = self::effScript();
        // Check to make sure the cron is not blank before we go any further...
        if ($script == '') {
            self::$blank = TRUE;
            $retval = TRUE;
        }
        // SEC: rechazar traversal ('..') y rutas absolutas; validar el dominio elegido contra
        // los dominios reales del usuario; y confinar con realpath al home (encima del
        // open_basedir que aplica WriteCronFile en ejecución).
        $homeDir = ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . '/';
        $selDomain = (string)$controller->GetControllerRequest('FORM', 'inDomain');
        $domainOk = ($selDomain === '');
        if (!$domainOk) {
            foreach (self::getUserDomains() as $d) { if ($d['vh_directory_vc'] === $selDomain) { $domainOk = true; break; } }
        }
        $full = fs_director::RemoveDoubleSlash(fs_director::ConvertSlashes($homeDir . $script));
        $real = realpath($full);
        $realHome = realpath($homeDir);
        if ($script != '' && (strpos($script, '..') !== false || substr($script, 0, 1) === '/' || !$domainOk
            || $real === false || $realHome === false || strpos($real, $realHome) !== 0)) {
            self::$noexists = TRUE;
            $retval = TRUE;
        }
        // Check to make sure the cron script exists before we go any further...
        elseif (!is_file($full)) {
            self::$noexists = TRUE;
            $retval = TRUE;
        }
        // Check to see if creating system cron file was successful...
        if (!is_file(ctrl_options::GetSystemOption('cron_file'))) {
            self::$cronnoexists = TRUE;
            $retval = TRUE;
        }
        // Check to makesystem cron file is writable...
        if (!is_writable(ctrl_options::GetSystemOption('cron_file'))) {
            self::$cronnowrite = TRUE;
            $retval = TRUE;
        }
        // Check to make sure the cron is not a duplicate...
        $sql = "SELECT COUNT(*) FROM x_cronjobs WHERE ct_acc_fk=:userid AND ct_script_vc=:inScript AND ct_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $effScriptDup = self::effScript();
        $numrows->bindParam(':inScript', $effScriptDup);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                self::$alreadyexists = TRUE;
                $retval = TRUE;
            }
        }
        // Comprobar cuota de cron jobs del paquete (-1 = ilimitado, 0 = ninguno permitido)
        $quota = (int)$currentuser['cronjobquota'];
        if ($quota !== -1) {
            $used = (int)ctrl_users::GetQuotaUsages('cronjobs', $currentuser['userid']);
            if ($used >= $quota) {
                self::$error = TRUE;
                $retval = TRUE;
            }
        }
        return $retval;
    }

    static function WriteCronFile()
    {
        global $zdbh;
        $currentuser = ctrl_users::GetUserDetail();
        $line = "";
        $sql = "SELECT * FROM x_cronjobs WHERE ct_deleted_ts IS NULL";
        $numrows = $zdbh->query($sql);

        //common header
        $line .= 'SHELL=/bin/bash' . fs_filehandler::NewLine();
        $line .= 'PATH=/sbin:/bin:/usr/sbin:/usr/bin' . fs_filehandler::NewLine();
        $line .= 'HOME=/' . fs_filehandler::NewLine();
        $line .= fs_filehandler::NewLine();
		
		$restrictinfos = ctrl_options::GetSystemOption('php_exer') . ' -d open_basedir="' . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . '/:/tmp/" ';


        $line .= "#################################################################################" . fs_filehandler::NewLine();
        $line .= "# CRONTAB FOR BULWARK CRON MANAGER MODULE                                        " . fs_filehandler::NewLine();
        $line .= "# Module Developed by Bobby Allen, 17/12/2009                                    " . fs_filehandler::NewLine();
        $line .= "# File automatically generated by Bulwark " . sys_versions::ShowBulwarkVersion() . fs_filehandler::NewLine();
        $line .= "#################################################################################" . fs_filehandler::NewLine();
        $line .= "# NEVER MANUALLY REMOVE OR EDIT ANY OF THE CRON ENTRIES FROM THIS FILE,          " . fs_filehandler::NewLine();
        $line .= "#  -> USE BULWARK INSTEAD! (Menu -> Advanced -> Cron Manager)                    " . fs_filehandler::NewLine();
        $line .= "#################################################################################" . fs_filehandler::NewLine();

        //Write command lines in crontab, if any
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->execute();
            while ($rowcron = $sql->fetch()) {
                $fetchRows = $zdbh->prepare("SELECT * FROM x_accounts WHERE ac_id_pk=:userid AND ac_deleted_ts IS NULL");
                $fetchRows->bindParam(':userid', $rowcron['ct_acc_fk']);
                $fetchRows->execute();
                $rowclient = $fetchRows->fetch();
                if ($rowclient && $rowclient['ac_enabled_in'] <> 0) {
                    // Defense in depth: re-validate ct_timing_vc read from DB.
                    // Rows inserted before the inTiming whitelist was added
                    // could contain arbitrary text; if so, skip them rather
                    // than write a poisoned crontab line. Logged to the PHP
                    // error log for the admin to clean up.
                    if (!self::IsValidCronTiming($rowcron['ct_timing_vc'])) {
                        error_log('[bulwark:cron] Skipping cron id=' . (int)$rowcron['ct_id_pk']
                                  . ' due to invalid ct_timing_vc (security guard).');
                        continue;
                    }
                    $line .= "# CRON ID: " . $rowcron['ct_id_pk'] . fs_filehandler::NewLine();
                    $line .= $rowcron['ct_timing_vc'] . " " . $restrictinfos . $rowcron['ct_fullpath_vc'] . fs_filehandler::NewLine();
                    $line .= "# END CRON ID: " . $rowcron['ct_id_pk'] . fs_filehandler::NewLine();
                }
            }
        }
        if (fs_filehandler::UpdateFile(ctrl_options::GetSystemOption('cron_file'), 0644, $line)) {
            privilege::run('cron_install');
            return true;
        } else {
            return false;
        }
    }

    static function TranslateTiming($timing)
    {
        $timing = trim($timing);
        $retval = NULL;
        if ($timing == "* * * * *") {
            $retval = "Every 1 minute";
        }
        if ($timing == "0,5,10,15,20,25,30,35,40,45,50,55 * * * *") {
            $retval = "Every 5 minutes";
        }
        if ($timing == "0,10,20,30,40,50 * * * *") {
            $retval = "Every 10 minutes";
        }
        if ($timing == "0,30 * * * *") {
            $retval = "Every 30 minutes";
        }
        if ($timing == "0 * * * *") {
            $retval = "Every 1 hour";
        }
        if ($timing == "0 0,2,4,6,8,10,12,14,16,18,20,22 * * *") {
            $retval = "Every 2 hours";
        }
        if ($timing == "0 0,8,16 * * *") {
            $retval = "Every 8 hours";
        }
        if ($timing == "0 0,12 * * *") {
            $retval = "Every 12 hours";
        }
        if ($timing == "0 0 * * *") {
            $retval = "Every day";
        }
        if ($timing == "0 0 * * 0") {
            $retval = "Every week";
        }
        if ($timing == "0 0 1 * *") {
            $retval = "Every month";
        }
        return $retval;
    }

    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$invalidtiming)) {
            return ui_sysmessage::shout(ui_language::translate("<strong>Error:</strong> The schedule (timing) you submitted is not a valid 5-field cron expression. Only digits, '*', '/', ',' and '-' are allowed."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$blank)) {
            return ui_sysmessage::shout(ui_language::translate("<strong>Error:</strong> You need to specify a valid location for your script."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$noexists)) {
            return ui_sysmessage::shout(ui_language::translate("<strong>Error:</strong> Your script does not appear to exist at that location."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$cronnoexists)) {
            return ui_sysmessage::shout(ui_language::translate("<strong>Error:</strong> System Cron file could not be created."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$cronnowrite)) {
            return ui_sysmessage::shout(ui_language::translate("<strong>Error:</strong> Could not write to the System Cron file."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$alreadyexists)) {
            return ui_sysmessage::shout(ui_language::translate("<strong>Error:</strong> You can not add the same cron task more than once."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$error)) {
            return ui_sysmessage::shout(ui_language::translate("<strong>Error:</strong> There was an error updating the cron job."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("<strong>Success:</strong> Cron updated successfully."), "zannounceok");
        }
        return;
    }

}

<?php
session_start();
include('../../../cnf/db.php');
include('../../../dryden/db/driver.class.php');
include('../../../dryden/debug/logger.class.php');
include('../../../dryden/runtime/dataobject.class.php');
include('../../../dryden/runtime/randomstring.class.php');
include('../../../dryden/runtime/csfr.class.php');
include('../../../dryden/sys/versions.class.php');
include('../../../dryden/ctrl/options.class.php');
include('../../../dryden/ctrl/auth.class.php');
include('../../../dryden/ctrl/users.class.php');
include('../../../dryden/fs/director.class.php');
include('../../../inc/dbc.inc.php');

runtime_csfr::Protect();

try {
    $zdbh = new db_driver("mysql:host=" . $host . ";dbname=" . $dbname . "", $user, $pass);
} catch (PDOException $e) {
    exit();
}

$userid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$mode   = (isset($_GET['mode']) && in_array($_GET['mode'], array('local', 'remote', 'both'), true)) ? $_GET['mode'] : 'local';

if ($userid > 0 && (int)$_SESSION['zpuid'] === $userid) {
    $currentuser = ctrl_users::GetUserDetail($userid);
    $rawTheme = $currentuser['usertheme'] ?? 'Bulwark_Default';
    $themeName = preg_match('/^[A-Za-z0-9_\-]+$/', $rawTheme) ? $rawTheme : 'Bulwark_Default';
    $rawCSS = $currentuser['usercss'] ?? 'style';
    $cssName = preg_match('/^[A-Za-z0-9_\-]+$/', $rawCSS) ? $rawCSS : 'style';
    ?>
    <!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
    <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1">
            <title>Bulwark &gt; Back-Ups</title>
            <link href="../../../etc/styles/<?php echo htmlspecialchars($themeName, ENT_QUOTES, 'UTF-8'); ?>/css/<?php echo htmlspecialchars($cssName, ENT_QUOTES, 'UTF-8'); ?>.css" rel="stylesheet" type="text/css">
            <script src="../assets/ajaxsbmt.js" type="text/javascript"></script>
            <script>
             function showHide() {
               var div = document.getElementById("BackupResult");
               if (div.style.display == 'none') {
                 div.style.display = 'block';
               }
               else {
                 div.style.display = 'none';
               }
             }
            </script>
        </head>
        <body style="background: #F3F3F3;">
            <div style="margin-left:20px;margin-right:20px;">
                <div class="zform_wrapper">
                    <h2>Backup your hosting account files</h2>
                    <p>Your data is ready to be backed up. This proccess can take a lot of time, depending on your directory size. When finished you will be prompted to download your archive.</p>
                    <p>Current public directory size: <b><?php echo fs_director::ShowHumanFileSize(dirSize(ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/")); ?></b></p>
                    <div id="BackupSubmit" style="height:100%;margin:auto;">
                        <form name="doBackup" action="response_normal.php" method="post" onsubmit="showHide(); xmlhttpPost('dobackup.php?id=<?php echo $userid; ?>&amp;mode=<?php echo htmlspecialchars($mode, ENT_QUOTES); ?>', 'doBackup', 'BackupResult', 'Compressing your data, please wait...<br><img src=\'../assets/bar.gif\'>'); return false;">
                            <?php echo runtime_csfr::Token(); ?>
                            <table class="zform">
                                <tr valign="top">
                                    <th nowrap="nowrap"><button class="fg-button ui-state-default ui-corner-all" id="SubmitBackup" type="submit" name="inBackUp" value="">Backup Now</button></th>
                                    <td><input type="hidden" name="inDownLoad" id="inDownLoad" value="1" /></td>
                                </tr>
                            </table>
                        </form>
                    </div>
                    <div id="BackupResult" style="display:none;height:100%;margin:auto;">
                        Compressing your data, please wait...<br><img src='../assets/bar.gif'>
                    </div>
                </div>
            </div>
        </body>
    </html>
<?php } else { ?>
    <body style="background: #F3F3F3;">
        <h2>Unauthorized Access!</h2>
        You have no permission to view this module.
    </body>
<?php } ?>
<?php

function dirSize($directory) {
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
        $size+=$file->getSize();
    }
    return $size;
}
?>

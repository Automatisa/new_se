<?php

include($_SERVER["DOCUMENT_ROOT"] . 'cnf/db.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/db/driver.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/debug/logger.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/runtime/dataobject.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/sys/versions.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/ctrl/options.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/ctrl/auth.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/ctrl/users.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'dryden/fs/director.class.php');
include($_SERVER["DOCUMENT_ROOT"] . 'inc/dbc.inc.php');

try {
    $zdbh = new db_driver("mysql:host=" . $host . ";dbname=" . $dbname . "", $user, $pass);
} catch (PDOException $e) {
    exit();
}

session_start();

// CRIT-1 FIX: cast to int to prevent type-juggling on session comparison
$userid = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($userid === 0 || (int)$_SESSION['zpuid'] !== $userid) {
    echo '<h2>Unauthorized Access!</h2>You have no permission to view this module.';
    exit;
}

if (!isset($_GET['file']) || $_GET['file'] === '') {
    echo '<h2>ERROR:</h2>Missing file parameter.';
    exit;
}

$currentuser = ctrl_users::GetUserDetail($userid);
$username    = $currentuser['username'];

// CRIT-1 FIX: strip any directory components BEFORE ownership check and BEFORE urldecode
// This prevents path traversal via encoded sequences like %2e%2e%2f or double-encoding
$backupname = basename(urldecode($_GET['file']));

// Ownership: filename must start with the authenticated username followed by '_'
$parts = explode('_', $backupname);
if (!isset($parts[0]) || $parts[0] !== $username) {
    echo '<h2>Unauthorized Access!</h2>You have no permission to download this file.';
    exit;
}

$temp_dir = realpath($_SERVER['DOCUMENT_ROOT'] . 'etc/tmp');
if ($temp_dir === false) {
    echo '<h2>ERROR:</h2>Backup directory not found.';
    exit;
}
$temp_dir .= DIRECTORY_SEPARATOR;

// Resolve final path and verify it stays inside temp_dir (blocks symlink attacks)
$filepath = $temp_dir . $backupname;
$resolved = realpath($filepath);

if ($resolved === false || strpos($resolved, $temp_dir) !== 0) {
    echo '<h2>Unauthorized Access!</h2>Invalid file path.';
    exit;
}

if (!file_exists($resolved)) {
    echo '<h2>ERROR:</h2>File does not exist. Check with your administrator.';
    exit;
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($resolved) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($resolved));
flush();
readfile($resolved);
exit;

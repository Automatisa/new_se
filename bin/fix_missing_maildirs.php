<?php
/**
 * fix_missing_maildirs.php
 * Crea la estructura Maildir++ en disco para cualquier buzón activo en bulwark_postfix
 * que no tenga directorio. Útil tras migraciones o cuando el panel falla al crear dirs.
 * Uso: php /usr/local/bulwark/bin/fix_missing_maildirs.php
 */
$cnf = __DIR__ . '/../cnf/db.php';
if (!file_exists($cnf)) { die("No se encuentra cnf/db.php\n"); }
include $cnf;

$core = new PDO("mysql:host=$host;dbname=bulwark_core", $user, $pass);
$core->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$mailserver_db = $core->query("SELECT so_value_tx FROM x_settings WHERE so_name_vc='mailserver_db'")->fetchColumn();

$db = new PDO("mysql:host=$host;dbname=$mailserver_db", $user, $pass);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$VMAIL_BASE = '/var/bulwark/vmail';

$stmt = $db->query('SELECT username, maildir FROM mailbox WHERE active = 1');
$fixed = 0; $ok = 0; $errors = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $mailbase = $VMAIL_BASE . '/' . rtrim($row['maildir'], '/');

    if (is_dir($mailbase.'/cur') && is_dir($mailbase.'/new') && is_dir($mailbase.'/tmp')) {
        $ok++;
        echo "[OK]    " . $row['username'] . " -> " . $row['maildir'] . "\n";
        continue;
    }

    echo "[CREAR] " . $row['username'] . " -> " . $mailbase . "\n";

    $cumul = '';
    foreach (explode('/', $VMAIL_BASE . '/' . rtrim($row['maildir'], '/')) as $p) {
        if ($p === '') continue;
        $cumul .= '/' . $p;
        if (!is_dir($cumul)) {
            if (!mkdir($cumul, 02770)) {
                $e = error_get_last();
                $errors[] = "mkdir $cumul: " . ($e['message'] ?? 'error');
                error_clear_last();
                continue 2;
            }
            chmod($cumul, 02770);
            chgrp($cumul, 'vmail');
            chown($cumul, 'vmail');
        }
    }

    $subs = ['','/cur','/new','/tmp',
             '/.Drafts','/.Drafts/cur','/.Drafts/new','/.Drafts/tmp',
             '/.Sent','/.Sent/cur','/.Sent/new','/.Sent/tmp',
             '/.Trash','/.Trash/cur','/.Trash/new','/.Trash/tmp',
             '/.Junk','/.Junk/cur','/.Junk/new','/.Junk/tmp'];
    foreach ($subs as $sub) {
        $path = $mailbase . $sub;
        if (!is_dir($path)) mkdir($path, 02770);
        chmod($path, 02770);
        chgrp($path, 'vmail');
        chown($path, 'vmail');
    }

    $subfile = $mailbase . '/subscriptions';
    if (!file_exists($subfile)) {
        file_put_contents($subfile, "Drafts\nSent\nTrash\nJunk\n");
        chgrp($subfile, 'vmail');
        chown($subfile, 'vmail');
    }

    echo "        -> OK\n";
    $fixed++;
}

echo "\n--- Resultado: {$ok} ya existian, {$fixed} creados";
if ($errors) {
    echo ", " . count($errors) . " errores:\n";
    foreach ($errors as $e) echo "  $e\n";
} else {
    echo " ---\n";
}

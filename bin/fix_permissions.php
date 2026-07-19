<?php
/**
 * fix_permissions.php
 * Verifica y corrige permisos, propietarios y estructura de directorios de Bulwark.
 * Uso:
 *   php /usr/local/bulwark/bin/fix_permissions.php          # verifica y corrige
 *   php /usr/local/bulwark/bin/fix_permissions.php --check  # solo informa, no cambia nada
 */

$DRY_RUN = in_array('--check', $argv ?? []);

$PANEL_PATH = '/usr/local/bulwark';
$PANEL_DATA = '/var/bulwark';
$PANEL_CONF = '/usr/local/etc/bulwark';

// ─── helpers ─────────────────────────────────────────────────────────────────

$fixed  = 0;
$issues = 0;
$errors = [];

function uid_of(string $name): int {
    $r = posix_getpwnam($name);
    return $r ? (int)$r['uid'] : -1;
}
function gid_of(string $name): int {
    $r = posix_getgrnam($name);
    return $r ? (int)$r['gid'] : -1;
}
function octal(int $mode): string {
    return substr(sprintf('%04o', $mode & 07777), -4);
}

function check_path(string $path, int $want_mode, string $want_user, string $want_group,
                    bool $recursive = false): void
{
    global $DRY_RUN, $fixed, $issues, $errors;

    if (!file_exists($path)) {
        echo "  [FALTA]  $path\n";
        $issues++;
        return;
    }

    $stat  = stat($path);
    $cur_mode  = $stat['mode'] & 07777;
    $cur_uid   = $stat['uid'];
    $cur_gid   = $stat['gid'];
    $want_uid  = uid_of($want_user);
    $want_gid  = gid_of($want_group);
    $ok        = true;

    if ($cur_mode !== $want_mode) {
        echo "  [MODO]   $path  es:" . octal($cur_mode) . "  quiere:" . octal($want_mode) . "\n";
        if (!$DRY_RUN) { chmod($path, $want_mode); $fixed++; }
        $ok = false; $issues++;
    }
    if ($want_uid >= 0 && $cur_uid !== $want_uid) {
        echo "  [OWNER]  $path  es:" . posix_getpwuid($cur_uid)['name']
             . "  quiere:$want_user\n";
        if (!$DRY_RUN) { chown($path, $want_user); $fixed++; }
        $ok = false; $issues++;
    }
    if ($want_gid >= 0 && $cur_gid !== $want_gid) {
        echo "  [GROUP]  $path  es:" . posix_getgrgid($cur_gid)['name']
             . "  quiere:$want_group\n";
        if (!$DRY_RUN) { chgrp($path, $want_group); $fixed++; }
        $ok = false; $issues++;
    }

    if ($ok) echo "  [OK]     $path\n";

    if ($recursive && is_dir($path)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            check_path((string)$item, $want_mode, $want_user, $want_group, false);
        }
    }
}

function check_glob(string $pattern, int $mode, string $user, string $group): void {
    $files = glob($pattern);
    if (empty($files)) {
        echo "  [VACIO]  $pattern  (sin coincidencias)\n";
        return;
    }
    foreach ($files as $f) check_path($f, $mode, $user, $group);
}

function section(string $title): void {
    echo "\n" . str_repeat('─', 60) . "\n";
    echo "  $title\n";
    echo str_repeat('─', 60) . "\n";
}

// ─── main ────────────────────────────────────────────────────────────────────

if (posix_geteuid() !== 0) {
    die("Este script debe ejecutarse como root.\n");
}

echo $DRY_RUN
    ? "\n=== fix_permissions.php [MODO REVISIÓN — sin cambios] ===\n"
    : "\n=== fix_permissions.php [MODO CORRECCIÓN] ===\n";

// ── 1. Raíz del mailstore ────────────────────────────────────────────────────
section("1. Mailstore /var/bulwark/vmail/");

check_path("$PANEL_DATA/vmail", 02770, 'vmail', 'vmail');

// Directorios de usuario: {vmail_root}/{paneluser}/ y {paneluser}/mail/
foreach (glob("$PANEL_DATA/vmail/*/") ?: [] as $userdir) {
    check_path(rtrim($userdir, '/'), 02770, 'vmail', 'vmail');
    $maildir = rtrim($userdir, '/') . '/mail';
    if (is_dir($maildir)) {
        check_path($maildir, 02770, 'vmail', 'vmail');
        // Directorios de dominio
        foreach (glob("$maildir/*/") ?: [] as $domdir) {
            check_path(rtrim($domdir, '/'), 02770, 'vmail', 'vmail');
            // Directorios de buzón
            foreach (glob(rtrim($domdir, '/') . "/*/") ?: [] as $mbdir) {
                $mb = rtrim($mbdir, '/');
                // El buzón en sí: 2770 para compatibilidad www:vmail
                check_path($mb, 02770, 'vmail', 'vmail');
                foreach (['cur','new','tmp',
                          '.Drafts','.Drafts/cur','.Drafts/new','.Drafts/tmp',
                          '.Sent','.Sent/cur','.Sent/new','.Sent/tmp',
                          '.Trash','.Trash/cur','.Trash/new','.Trash/tmp',
                          '.Junk','.Junk/cur','.Junk/new','.Junk/tmp'] as $sub) {
                    if (file_exists("$mb/$sub"))
                        check_path("$mb/$sub", 02770, 'vmail', 'vmail');
                }
                if (file_exists("$mb/subscriptions"))
                    check_path("$mb/subscriptions", 0640, 'vmail', 'vmail');
            }
        }
    }
}

// ── 2. Logs ──────────────────────────────────────────────────────────────────
section("2. Logs /var/bulwark/logs/");

check_path("$PANEL_DATA/logs",                0755, 'root',  'wheel');
check_path("$PANEL_DATA/logs/dovecot",        0750, 'vmail', 'vmail');
check_glob("$PANEL_DATA/logs/dovecot/*.log",  0640, 'vmail', 'vmail');
check_path("$PANEL_DATA/logs/bind",           0755, 'bind',  'bind');
check_glob("$PANEL_DATA/logs/bind/*.log",     0640, 'bind',  'bind');
check_path("$PANEL_DATA/logs/roundcube",      0755, 'www',   'www');
check_glob("$PANEL_DATA/logs/roundcube/*",    0640, 'www',   'www');
foreach (['bulwark.log','bulwark-access.log','bulwark-error.log',
          'bulwark-bandwidth.log','php_errors.log','daemon-last-run.log'] as $f) {
    if (file_exists("$PANEL_DATA/logs/$f"))
        check_path("$PANEL_DATA/logs/$f", 0640, 'www', 'www');
}

// ── 3. Datos variables ───────────────────────────────────────────────────────
section("3. Datos variables /var/bulwark/");

check_path("$PANEL_DATA/sessions", 01733, 'www',  'www');
check_path("$PANEL_DATA/temp",     01777, 'root', 'wheel');
check_path("$PANEL_DATA/hostdata", 0755,  'www',  'www');
check_path("$PANEL_DATA/backups",  0700,  'root', 'wheel');
check_path("$PANEL_DATA/sieve",    0750,  'vmail','mail');
check_path("$PANEL_DATA/named",    0755,  'bind', 'bind');
check_path("$PANEL_DATA/named/data", 0755, 'bind','bind');

// ── 4. Panel ─────────────────────────────────────────────────────────────────
section("4. Panel $PANEL_PATH/");

check_path("$PANEL_PATH",          0755, 'root', 'wheel');
check_path("$PANEL_PATH/cnf/db.php", 0640, 'root', 'www');
check_path("$PANEL_PATH/etc/tmp",  0755, 'www',  'www');
check_path("$PANEL_PATH/bin",      0755, 'root', 'wheel');
check_glob("$PANEL_PATH/bin/*.php",  0750, 'root', 'wheel');
check_glob("$PANEL_PATH/bin/set*",   0750, 'root', 'wheel');
check_glob("$PANEL_PATH/bin/update*",0750, 'root', 'wheel');

// ── 5. Configuración sensible ────────────────────────────────────────────────
section("5. Configuración $PANEL_CONF/");

// Dovecot
check_path("$PANEL_CONF/dovecot2/dovecot.conf",          0644, 'root', 'wheel');
check_path("$PANEL_CONF/dovecot2/dovecot-mysql.conf",    0640, 'root', 'dovecot');
check_path("$PANEL_CONF/dovecot2/dovecot-dict-quota.conf",0640,'root', 'dovecot');
check_path("$PANEL_CONF/dovecot2/dovecot-trash.conf",    0644, 'root', 'wheel');

// Postfix MySQL maps
check_glob("$PANEL_CONF/postfix/mysql-*.cf", 0640, 'root', 'postfix');

// BIND
check_path("$PANEL_CONF/bind",               0755,  'bind', 'wheel');
check_path("$PANEL_CONF/bind/named.conf",    0640,  'bind', 'bind');
check_path("$PANEL_CONF/bind/rndc.key",      0640,  'bind', 'bind');
check_path("$PANEL_CONF/bind/rndc.conf",     0640,  'bind', 'bind');
check_path("$PANEL_CONF/bind/etc",           0775,  'bind', 'www');
check_path("$PANEL_CONF/bind/etc/named.conf",0664,  'bind', 'www');
check_path("$PANEL_CONF/bind/zones",         0775,  'bind', 'www');
check_glob("$PANEL_CONF/bind/zones/*.txt",   0664,  'bind', 'www');

// Apache
check_path("$PANEL_CONF/apache/httpd.conf",        0644, 'root', 'wheel');
check_path("$PANEL_CONF/apache/httpd-vhosts.conf", 0644, 'www',  'www');

// ── 6. Symlinks ──────────────────────────────────────────────────────────────
section("6. Symlinks");

$symlinks = [
    '/usr/local/etc/dovecot/dovecot.conf'    => "$PANEL_CONF/dovecot2/dovecot.conf",
    "$PANEL_PATH/etc/apps/webmail"           => '/usr/local/www/roundcube/public_html',
    "$PANEL_PATH/etc/apps/phpmyadmin"        => '/usr/local/www/phpMyAdmin',
];
foreach ($symlinks as $link => $target) {
    if (!is_link($link)) {
        echo "  [FALTA]  symlink $link -> $target\n";
        $issues++;
        if (!$DRY_RUN) { symlink($target, $link); $fixed++; }
    } elseif (readlink($link) !== $target) {
        echo "  [WRONG]  symlink $link apunta a " . readlink($link) . " (quiere $target)\n";
        $issues++;
        if (!$DRY_RUN) { unlink($link); symlink($target, $link); $fixed++; }
    } else {
        echo "  [OK]     $link -> $target\n";
    }
}

// ── resumen ──────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('═', 60) . "\n";
if ($DRY_RUN) {
    echo "  Revisión completada: $issues problema(s) encontrado(s).\n";
    echo "  Ejecuta sin --check para corregirlos.\n";
} else {
    echo "  Completado: $issues problema(s) detectado(s), $fixed correccion(es) aplicada(s).\n";
}
echo str_repeat('═', 60) . "\n\n";

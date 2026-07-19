<?php
/**
 * imapsync_run.php <jobid> — Ejecuta UNA tanda de migración imapsync para el trabajo indicado.
 * Lo lanza en segundo plano el hook OnDaemonRun de imapsync (como root). SIEMPRE externo -> panel.
 *  - Lee el trabajo y los ajustes de la BD (PDO ligero, sin bootstrap del panel).
 *  - Reconstruye los passfiles de imapsync desde el passfile del trabajo (línea1=origen, línea2=destino).
 *  - Corre `timeout T nice -n N imapsync ...` por proc_open (array argv -> SIN shell), con throttles.
 *  - Como imapsync es INCREMENTAL: si expira el timeout (rc 124) -> 'partial' y se reencola (queued)
 *    para continuar en la siguiente pasada; rc 0 -> 'done'; otro -> 'error'.
 *  - Acumula mensajes/bytes transferidos. Borra los passfiles temporales y el pidfile al terminar.
 */
$jid = (int)($argv[1] ?? 0);
if ($jid <= 0) { exit(1); }

require '/usr/local/bulwark/cnf/db.php'; // $user,$pass,$host,$dbname
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT));
} catch (Exception $e) { exit(1); }

$j = $pdo->query("SELECT * FROM x_imapsync_jobs WHERE ij_id_pk=" . $jid)->fetch(PDO::FETCH_ASSOC);
if (!$j || !in_array($j['ij_status_vc'], array('queued', 'running', 'partial'), true)) { exit(0); }

function opt($pdo, $k, $d) {
    $st = $pdo->prepare("SELECT so_value_tx FROM x_settings WHERE so_name_vc=?");
    $st->execute(array($k));
    $v = $st->fetchColumn();
    return ($v === false || $v === '' || $v === null) ? $d : $v;
}
$timeout = max(30, (int)opt($pdo, 'imapsync_job_timeout', 900));
$nice    = min(19, max(0, (int)opt($pdo, 'imapsync_nice', 19)));
$bps     = (int)opt($pdo, 'imapsync_max_bytes_sec', 0);
$mps     = (int)opt($pdo, 'imapsync_max_msgs_sec', 0);

$rundir = '/var/bulwark/run/imapsync/';
@mkdir($rundir, 0770, true);
$log = $j['ij_log_vc'] ?: ($rundir . $jid . '.log');
$pidf = $rundir . $jid . '.pid';
@file_put_contents($pidf, (string)getmypid());

$pdo->exec("UPDATE x_imapsync_jobs SET ij_status_vc='running', ij_lastrun_ts=" . time() . ", ij_runs_in=ij_runs_in+1, ij_updated_ts=" . time() . " WHERE ij_id_pk=" . $jid);

// passfiles de imapsync desde el passfile del trabajo (línea1 origen, línea2 destino)
$plines = @file($j['ij_passfile_vc'], FILE_IGNORE_NEW_LINES);
if (!is_array($plines) || count($plines) < 2) {
    $pdo->exec("UPDATE x_imapsync_jobs SET ij_status_vc='error', ij_error_tx='passfile ausente o incompleto', ij_updated_ts=" . time() . " WHERE ij_id_pk=" . $jid);
    @unlink($pidf); exit(1);
}
$f1 = $rundir . $jid . '.p1'; $f2 = $rundir . $jid . '.p2';
@file_put_contents($f1, $plines[0]); @chmod($f1, 0600);
@file_put_contents($f2, $plines[1]); @chmod($f2, 0600);

// argv de imapsync (sin shell). Destino SIEMPRE localhost (el panel es el destino).
$args = array(
    '/usr/local/bin/imapsync',
    '--host1', $j['ij_src_host_vc'], '--port1', (string)(int)$j['ij_src_port_in'], '--user1', $j['ij_src_user_vc'], '--passfile1', $f1,
    '--host2', 'localhost', '--port2', '143', '--user2', $j['ij_dest_user_vc'], '--passfile2', $f2,
    '--tls2', '--sslargs2', 'SSL_verify_mode=0',    // dovecot local con STARTTLS, sin verificar cert de loopback
    '--timeout1', '120', '--timeout2', '120',
    '--nofoldersizes', '--noreleasecheck', '--nomodulesversion',
);
if ($j['ij_src_ssl_vc'] === 'ssl')      { $args[] = '--ssl1'; }
elseif ($j['ij_src_ssl_vc'] === 'tls')  { $args[] = '--tls1'; }
$args[] = '--sslargs1'; $args[] = 'SSL_verify_mode=0'; // origen: no fallar por cert autofirmado (migración)
// --automap: mapea carpetas conocidas (Enviados/Borradores/Papelera/Spam) aunque se llamen distinto.
$args[] = '--automap';
// Elección de carpetas: por defecto se EXCLUYEN Spam y Papelera; el usuario puede incluirlas.
$excl = array();
if (!(int)($j['ij_inc_spam_in']  ?? 0)) { $excl[] = 'junk'; $excl[] = 'spam'; $excl[] = 'bulk'; $excl[] = 'correo no deseado'; }
if (!(int)($j['ij_inc_trash_in'] ?? 0)) { $excl[] = 'trash'; $excl[] = 'deleted items'; $excl[] = 'deleted messages'; $excl[] = 'papelera'; }
if ($excl) { $args[] = '--exclude'; $args[] = '(?i)(' . implode('|', $excl) . ')'; }
if ($bps > 0) { $args[] = '--maxbytespersecond'; $args[] = (string)$bps; }
if ($mps > 0) { $args[] = '--maxmessagespersecond'; $args[] = (string)$mps; }

$full = array_merge(array('timeout', (string)$timeout, 'nice', '-n', (string)$nice), $args);
@file_put_contents($log, "\n==== " . gmdate('Y-m-d H:i:s') . " UTC — tanda (timeout {$timeout}s, nice {$nice}) ====\n", FILE_APPEND);
$desc = array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $log, 'a'), 2 => array('file', $log, 'a'));
$proc = @proc_open($full, $desc, $pipes);
$rc = is_resource($proc) ? proc_close($proc) : 1;

// parsear resultados de esta tanda del log
$txt = @file_get_contents($log);
$msgs = 0; $bytes = 0;
if ($txt !== false) {
    if (preg_match_all('/messages transferred\s*:\s*(\d+)/i', $txt, $m)) { $msgs = (int)end($m[1]); }
    if (preg_match_all('/[Tt]otal bytes transferred\s*:\s*(\d+)/', $txt, $b)) { $bytes = (int)end($b[1]); }
}

if ($rc === 124) {
    // timeout de la tanda. Si NO transfirió nada y ya van >=2 tandas, es un fallo persistente
    // (origen inalcanzable/credenciales) -> abortar; si transfirió, es incremental -> reencolar.
    if ($msgs === 0 && (int)$j['ij_runs_in'] >= 2) {
        $pdo->exec("UPDATE x_imapsync_jobs SET ij_status_vc='error', ij_error_tx='sin progreso tras varias tandas (revisa origen/credenciales/red)', ij_updated_ts=" . time() . " WHERE ij_id_pk=" . $jid . " AND ij_status_vc='running'");
        @unlink($j['ij_passfile_vc']);
    } else {
        $pdo->exec("UPDATE x_imapsync_jobs SET ij_status_vc='queued', ij_msgs_in=ij_msgs_in+" . $msgs . ", ij_bytes_bi=ij_bytes_bi+" . $bytes . ", ij_updated_ts=" . time() . " WHERE ij_id_pk=" . $jid . " AND ij_status_vc='running'");
    }
} elseif ($rc === 0) {
    $pdo->exec("UPDATE x_imapsync_jobs SET ij_status_vc='done', ij_msgs_in=ij_msgs_in+" . $msgs . ", ij_bytes_bi=ij_bytes_bi+" . $bytes . ", ij_updated_ts=" . time() . " WHERE ij_id_pk=" . $jid . " AND ij_status_vc='running'");
    @unlink($j['ij_passfile_vc']); // ya no hace falta guardar las contraseñas
} else {
    $tail = $txt !== false ? substr($txt, -400) : '';
    $st = $pdo->prepare("UPDATE x_imapsync_jobs SET ij_status_vc='error', ij_error_tx=?, ij_updated_ts=" . time() . " WHERE ij_id_pk=" . $jid . " AND ij_status_vc='running'");
    $st->execute(array("rc=$rc " . $tail));
    @unlink($j['ij_passfile_vc']);
}
@unlink($f1); @unlink($f2); @unlink($pidf);
exit(0);

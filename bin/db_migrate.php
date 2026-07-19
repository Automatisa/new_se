<?php
/**
 * db_migrate.php — Corredor de migraciones incrementales del panel.
 *
 * Aplica en orden las migraciones de preconf/migrations/ que aún no consten en x_migrations:
 *   - NNNN_desc.sql : cambios de ESQUEMA/datos (se ejecutan sentencia a sentencia).
 *   - NNNN_desc.sh  : cambios de CONFIGURACIÓN/servicios (proftpd, named, postfix…), útil cuando
 *                     una versión cambia la estructura de un config del sistema. Corre como root.
 * Cada migración se aplica UNA sola vez y se registra en x_migrations. Idempotente: seguro re-ejecutar.
 *
 * Uso:
 *   php db_migrate.php              -> aplica las pendientes (lo llama panel_update.sh tras git pull).
 *   php db_migrate.php --baseline   -> marca TODAS como aplicadas SIN ejecutarlas (instalación nueva:
 *                                      bulwark_core.sql ya trae el esquema al día).
 *
 * Debe ejecutarse como root (las migraciones .sh tocan configs del sistema). Sin exec(): las .sh se
 * lanzan con proc_open (array + bypass de shell).
 */

$PANEL = '/usr/local/bulwark';
chdir($PANEL);
$baseline = in_array('--baseline', $argv, true);

require $PANEL . '/cnf/db.php';   // define $host, $user, $pass, $dbname
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    fwrite(STDERR, "db_migrate: no se pudo conectar a la BD: " . $e->getMessage() . "\n");
    exit(1);
}

// Tabla de control (por si es una instalación previa al framework).
$pdo->exec("CREATE TABLE IF NOT EXISTS x_migrations (
    mg_id_pk INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mg_name_vc VARCHAR(191) NOT NULL,
    mg_applied_ts INT NOT NULL,
    PRIMARY KEY (mg_id_pk), UNIQUE KEY uq_mg_name (mg_name_vc)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3");

$applied = array();
foreach ($pdo->query("SELECT mg_name_vc FROM x_migrations") as $r) {
    $applied[$r['mg_name_vc']] = true;
}

$dir   = $PANEL . '/preconf/migrations';
$files = is_dir($dir) ? array_merge((array)glob("$dir/*.sql"), (array)glob("$dir/*.sh")) : array();
usort($files, function ($a, $b) { return strcmp(basename($a), basename($b)); });

$record = $pdo->prepare("INSERT IGNORE INTO x_migrations (mg_name_vc, mg_applied_ts) VALUES (:n, :t)");
$count  = 0;

foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) continue;

    if (!$baseline) {
        try {
            if (substr($name, -4) === '.sql') {
                applySql($pdo, file_get_contents($file));
            } else {
                list($rc, $out) = runSh($file);
                if ($rc !== 0) {
                    throw new Exception("script devolvió rc=$rc: " . $out);
                }
            }
        } catch (Exception $e) {
            // No registrar la fallida y PARAR (no seguir con las posteriores, que podrían depender).
            fwrite(STDERR, "MIGRACIÓN FALLIDA: $name -> " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    $record->execute(array(':n' => $name, ':t' => time()));
    $count++;
    echo ($baseline ? "baselined" : "aplicada") . ": $name\n";
}

echo "Migraciones " . ($baseline ? "marcadas (baseline)" : "aplicadas") . ": $count\n";
exit(0);

/** Ejecuta un fichero SQL sentencia a sentencia (MyISAM no tiene transacciones DDL). */
function applySql(PDO $pdo, $sql)
{
    // Quitar comentarios de línea (-- ...) y líneas vacías.
    $lines = array();
    foreach (preg_split('/\r?\n/', (string)$sql) as $ln) {
        $t = trim($ln);
        if ($t === '' || substr($t, 0, 2) === '--') continue;
        $lines[] = $ln;
    }
    $body = implode("\n", $lines);
    // Dividir SOLO en ';' que termina una línea (fin de sentencia), no en ';' dentro de una cadena.
    // Convención de las migraciones: cada sentencia acaba con ';' al final de su última línea.
    foreach (preg_split('/;[ \t]*(?:\r?\n|$)/', $body) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
}

/** Ejecuta una migración .sh con proc_open (sin exec()); devuelve [rc, salida]. */
function runSh($file)
{
    $desc = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $p = proc_open(array('/bin/sh', $file), $desc, $pipes);
    if (!is_resource($p)) return array(1, 'proc_open falló');
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $rc  = proc_close($p);
    return array($rc, trim($out . "\n" . $err));
}

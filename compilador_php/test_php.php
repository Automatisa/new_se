<?php
/**
 * test_php.php — Prueba de funcionamiento de una versión de PHP (CLI o FPM).
 * Comprueba versión/SAPI, que las extensiones de hosting estén cargadas y que FUNCIONEN de verdad
 * (no solo presentes). Salida en texto plano, línea "RESULTADO=OK" / "RESULTADO=FAIL" al final.
 * Uso CLI:  /usr/local/phpNN/bin/php test_php.php
 * Uso FPM:  como SCRIPT_FILENAME por el socket del dominio (ver fcgi_probe.php).
 */
header('Content-Type: text/plain');   // no-op en CLI
$fail = 0;
function chk($name, $ok, $detail = '') {
    global $fail;
    $s = $ok ? 'OK' : 'FAIL';
    if (!$ok) $fail++;
    printf("  %-26s %s%s\n", $name, $s, $detail !== '' ? "  ($detail)" : '');
}

echo "==== PHP FUNCTIONAL TEST ====\n";
echo "PHP_VERSION = " . PHP_VERSION . "\n";
echo "SAPI        = " . php_sapi_name() . "\n";
echo "ini: date.timezone=" . (ini_get('date.timezone') ?: '(unset)')
   . " memory_limit=" . ini_get('memory_limit')
   . " max_input_vars=" . ini_get('max_input_vars')
   . " opcache.enable=" . ini_get('opcache.enable') . "\n";
echo "-- extensiones cargadas --\n";
foreach (['curl','gd','mysqli','pdo_mysql','mbstring','json','openssl','zip','opcache',
          'xml','simplexml','dom','bcmath','sockets','soap','exif','fileinfo','ctype',
          'iconv','phar','posix','session','sqlite3','tokenizer','xmlreader','xmlwriter'] as $e) {
    chk("ext:$e", extension_loaded($e));
}
echo "-- pruebas funcionales --\n";
chk('json round-trip',  json_decode(json_encode(['a'=>1]))->a === 1);
chk('mbstring utf8',    function_exists('mb_strlen') && mb_strlen('áéíóú') === 5);
chk('bcmath',           function_exists('bcadd') && bcadd('2','3') === '5');
chk('hash sha256',      hash('sha256','x') === '2d711642b726b04401627ca9fbac32f5c8530fb1903cc4db02258717921a4881');
chk('openssl rand',     function_exists('openssl_random_pseudo_bytes') && strlen(openssl_random_pseudo_bytes(16)) === 16);
chk('curl present',     function_exists('curl_version'), function_exists('curl_version') ? ('libcurl '.curl_version()['version']) : '');
chk('gd create',        function_exists('imagecreatetruecolor') && is_object(@imagecreatetruecolor(8,8)) || (function_exists('imagecreatetruecolor') && @imagecreatetruecolor(8,8) !== false));
chk('pdo_mysql driver', in_array('mysql', PDO::getAvailableDrivers(), true));
chk('sqlite3 mem',      class_exists('SQLite3') && (new SQLite3(':memory:'))->querySingle('SELECT 1') == 1);
chk('zip class',        class_exists('ZipArchive'));
chk('simplexml parse',  (bool) simplexml_load_string('<r><a>1</a></r>'));
chk('opcache active',   function_exists('opcache_get_status'));

echo (($fail === 0) ? "RESULTADO=OK" : "RESULTADO=FAIL($fail)") . "\n";

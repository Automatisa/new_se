<?php
session_start();
if (empty($_SESSION['zpuid']) || (int)$_SESSION['zpuid'] <= 0) {
    header('Location: /');
    exit;
}

$extensions = get_loaded_extensions();
sort($extensions, SORT_STRING | SORT_FLAG_CASE);

$ini_keys = [
    'memory_limit', 'max_execution_time', 'upload_max_filesize',
    'post_max_size', 'max_input_vars', 'default_charset',
    'display_errors', 'log_errors', 'opcache.enable',
    'date.timezone',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>PHP Info — Bulwark</title>
<style>
  body { font-family: sans-serif; margin: 2em; background: #f5f5f5; color: #333; }
  h1   { font-size: 1.4em; border-bottom: 2px solid #337ab7; padding-bottom:.4em; color:#337ab7; }
  h2   { font-size: 1.1em; margin-top: 1.8em; color: #555; }
  table { border-collapse: collapse; width: 100%; max-width: 760px; background:#fff;
          box-shadow: 0 1px 3px rgba(0,0,0,.1); }
  th, td { padding: .45em .8em; text-align: left; border-bottom: 1px solid #e0e0e0; font-size:.9em; }
  th { background: #337ab7; color: #fff; font-weight: normal; }
  .grid { display: flex; flex-wrap: wrap; gap: .3em; max-width: 760px; margin-top:.5em; }
  .ext { background:#fff; border:1px solid #ccc; border-radius:3px;
         padding:.25em .6em; font-size:.82em; font-family:monospace; }
  .ok  { color: #3a3; }
  .off { color: #a33; }
</style>
</head>
<body>
<h1>PHP <?= htmlspecialchars(PHP_VERSION) ?></h1>

<h2>Configuración principal</h2>
<table>
  <tr><th>Directiva</th><th>Valor</th></tr>
<?php foreach ($ini_keys as $k): ?>
  <?php $v = ini_get($k); ?>
  <tr>
    <td><?= htmlspecialchars($k) ?></td>
    <td class="<?= in_array(strtolower((string)$v), ['1','on','true']) ? 'ok' : (in_array(strtolower((string)$v), ['','0','off','false']) && strpos($k,'enable') !== false ? 'off' : '') ?>">
      <?= htmlspecialchars((string)$v !== '' ? $v : '(no definido)') ?>
    </td>
  </tr>
<?php endforeach; ?>
</table>

<h2>Extensiones cargadas (<?= count($extensions) ?>)</h2>
<div class="grid">
<?php foreach ($extensions as $ext): ?>
  <span class="ext"><?= htmlspecialchars($ext) ?></span>
<?php endforeach; ?>
</div>

</body>
</html>

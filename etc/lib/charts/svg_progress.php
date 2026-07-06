<?php
declare(strict_types=1);

$percent = isset($_GET['percent']) ? max(0, min(100, (int)$_GET['percent'])) : 0;

$w = 115;
$h = 17;
if (isset($_GET['size'])) {
    $parts = explode('::', $_GET['size']);
    if (count($parts) === 2) {
        $w = max(10, min(500, (int)$parts[0]));
        $h = max(5,  min(100, (int)$parts[1]));
    }
}

$filled = (int)round($w * $percent / 100);
if ($percent >= 90) {
    $barColor = '#e74c3c';
} elseif ($percent >= 70) {
    $barColor = '#f39c12';
} else {
    $barColor = '#79b52b';
}

$textY  = (int)round($h * 0.75);
$textX  = (int)round($w / 2);

header('Content-Type: image/svg+xml');
header('Cache-Control: no-store');
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<svg xmlns="http://www.w3.org/2000/svg" width="<?= $w ?>" height="<?= $h ?>">
  <rect x="0" y="0" width="<?= $w ?>" height="<?= $h ?>" fill="#cccccc" rx="2" ry="2"/>
  <rect x="0" y="0" width="<?= $filled ?>" height="<?= $h ?>" fill="<?= $barColor ?>" rx="2" ry="2"/>
  <text x="<?= $textX ?>" y="<?= $textY ?>"
        text-anchor="middle"
        font-size="<?= (int)round($h * 0.7) ?>"
        font-family="sans-serif"
        fill="white"
        font-weight="bold"><?= $percent ?>%</text>
</svg>

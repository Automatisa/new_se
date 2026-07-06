<?php
declare(strict_types=1);

// ── Input sanitization ────────────────────────────────────────────────────────

$rawScores = isset($_GET['score']) ? explode('::', $_GET['score']) : ['0', '100'];
$rawLabels = isset($_GET['labels']) ? explode('::', $_GET['labels']) : [];

$values = [];
$labels = [];
foreach ($rawScores as $i => $v) {
    $values[] = max(0.0, (float)$v);
    $raw      = isset($rawLabels[$i]) ? $rawLabels[$i] : '';
    $labels[] = htmlspecialchars(str_replace('_', ' ', $raw), ENT_QUOTES, 'UTF-8');
}

// Image dimensions
$imgW = 240;
$imgH = 190;
if (isset($_GET['imagesize'])) {
    $p = explode('::', $_GET['imagesize']);
    if (count($p) === 2) {
        $imgW = max(80,  min(800, (int)$p[0]));
        $imgH = max(60,  min(600, (int)$p[1]));
    }
}

// ── Auto-layout: pie left 56%, legend right 44% ───────────────────────────────
// legendsize/chartsize are pChart positional params — ignored for layout,
// radius is accepted but capped so the pie never overflows into the legend area.

$legendX  = (int)round($imgW * 0.56);
$pieAreaW = $legendX - 4;

$cx = (int)round($pieAreaW / 2);
$cy = (int)round($imgH / 2);

// Max radius that keeps the full circle inside the pie area
$maxRadius = min($cx - 2, $pieAreaW - $cx - 2, $cy - 2, $imgH - $cy - 2);
$maxRadius = max(8, $maxRadius);

if (isset($_GET['radius'])) {
    $radius = min(max(5, (int)$_GET['radius']), $maxRadius);
} else {
    $radius = $maxRadius;
}

// ── Palette ───────────────────────────────────────────────────────────────────

$palette = [
    '#1a4e84', '#7f8c8d', '#1f242a', '#374149',
    '#27ae60', '#e67e22', '#c0392b', '#8e44ad',
];

// ── SVG arc helpers ───────────────────────────────────────────────────────────

function polarXY(float $cx, float $cy, float $r, float $deg): array {
    $rad = deg2rad($deg);
    return [round($cx + $r * cos($rad), 3), round($cy + $r * sin($rad), 3)];
}

function arcPath(float $cx, float $cy, float $r, float $startDeg, float $sweep, string $fill): string {
    if ($sweep >= 359.99) {
        return '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="'.$fill.'"/>';
    }
    [$x1, $y1] = polarXY($cx, $cy, $r, $startDeg);
    [$x2, $y2] = polarXY($cx, $cy, $r, $startDeg + $sweep);
    $large = $sweep > 180 ? 1 : 0;
    return '<path d="M'.$cx.','.$cy
           .' L'.$x1.','.$y1
           .' A'.$r.','.$r.' 0 '.$large.',1 '.$x2.','.$y2
           .' Z" fill="'.$fill.'"/>';
}

// ── Build segments and legend ─────────────────────────────────────────────────

$total  = array_sum($values);
$paths  = '';
$legend = '';

if ($total <= 0) {
    $paths  = '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$radius.'" fill="#dddddd"/>';
    $paths .= '<text x="'.$cx.'" y="'.($cy + 4).'" text-anchor="middle"'
            . ' font-size="10" fill="#999" font-family="sans-serif">No data</text>';
} else {
    $angle = -90.0;
    foreach ($values as $i => $val) {
        $color = $palette[$i % count($palette)];
        $sweep = ($val / $total) * 360.0;

        if ($sweep >= 0.3) {
            $paths .= arcPath((float)$cx, (float)$cy, (float)$radius, $angle, $sweep, $color);
        }
        $angle += $sweep;

        // Legend row — always show even if slice is tiny
        $ly    = 16 + $i * 17;
        $lx    = $legendX + 4;
        $label = $labels[$i] ?? '';
        $legend .= '<rect x="'.$lx.'" y="'.($ly - 11).'" width="11" height="11" fill="'.$color.'"/>';
        $legend .= '<text x="'.($lx + 15).'" y="'.$ly.'"'
                 . ' font-size="9" fill="#333" font-family="verdana,sans-serif">'.$label.'</text>';
    }
}

// Thin outer ring for definition
$ring = '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$radius.'"'
      . ' fill="none" stroke="#cccccc" stroke-width="0.8"/>';

// ── Output ────────────────────────────────────────────────────────────────────

header('Content-Type: image/svg+xml');
header('Cache-Control: no-store');
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<svg xmlns="http://www.w3.org/2000/svg" width="<?= $imgW ?>" height="<?= $imgH ?>">
  <?= $paths ?>
  <?= $ring ?>
  <?= $legend ?>
</svg>

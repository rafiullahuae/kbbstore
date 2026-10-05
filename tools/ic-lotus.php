<?php
// Lane IC: draws the test icons tools/ic-shots.cjs uploads.  php tools/ic-lotus.php storage/ic-logs
// A lotus-like test icon like the owner's: pink petals on white, artwork in the middle ~75%, 1050 x 1050.
function lotus(int $S, string $out, float $scale = 0.75): void {
    $k = 3; $W = $S * $k;
    $im = imagecreatetruecolor($W, $W);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    $cx = $W / 2; $R = $W * $scale / 2; $cy = $W * 0.5 + $R * 0.68;
    $petal = function ($ang, $len, $wid, $col) use ($im, $cx, $cy) {
        $pts = [];
        for ($i = 0; $i <= 60; $i++) {
            $t = $i / 60 * M_PI; // half-ellipse outline of a petal pointing up, then rotate
            $x = sin($t) * $wid; $y = -$len * (1 - cos($t)) / 2;
            $pts[] = [$x, $y];
        }
        for ($i = 60; $i >= 0; $i--) { $t = $i / 60 * M_PI; $pts[] = [-sin($t) * $wid, -$len * (1 - cos($t)) / 2]; }
        $flat = [];
        $a = deg2rad($ang);
        foreach ($pts as [$x, $y]) { $flat[] = (int) ($cx + $x * cos($a) - $y * sin($a)); $flat[] = (int) ($cy + $x * sin($a) + $y * cos($a)); }
        imagefilledpolygon($im, $flat, $col);
    };
    $c1 = imagecolorallocate($im, 248, 187, 208); $c2 = imagecolorallocate($im, 240, 128, 170); $c3 = imagecolorallocate($im, 226, 72, 132);
    foreach ([-75, 75] as $a) $petal($a, $R * 1.05, $R * 0.26, $c1);
    foreach ([-45, 45] as $a) $petal($a, $R * 1.2, $R * 0.28, $c2);
    foreach ([-20, 20] as $a) $petal($a, $R * 1.3, $R * 0.27, $c2);
    $petal(0, $R * 1.4, $R * 0.3, $c3);
    imagefilledellipse($im, (int) $cx, (int) ($cy + $R * 0.06), (int) ($R * 1.5), (int) ($R * 0.22), imagecolorallocate($im, 214, 60, 120));
    $dst = imagecreatetruecolor($S, $S);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $S, $S, $W, $W);
    imagepng($dst, $out);
    echo "$out ", filesize($out), " bytes ", $S, "x", $S, "\n";
}
lotus(1050, $argv[1].'/ic-lotus-1050.png');
lotus(512, $argv[1].'/ic-lotus-favicon-512.png', 0.95);

<?php
require __DIR__.'/../../vendor/autoload.php';

// Pure-static use of the service: moments() and floorColour() touch no container.
use App\Services\PageWash;

function lum(array $c): float {
    $f = function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; };
    return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
}
function ratio(array $a, array $b): float {
    $l1 = lum($a); $l2 = lum($b);
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}
function hex2rgb(string $h): array { $h = ltrim($h, '#'); return [hexdec(substr($h,0,2)), hexdec(substr($h,2,2)), hexdec(substr($h,4,2))]; }

$text = [
    'body  --ink    #2A2228' => hex2rgb('2A2228'),
    'sec   --ink-2  #5E545A' => hex2rgb('5E545A'),
    'muted --muted  #8C828A' => hex2rgb('8C828A'),
    'pink  --pink   #E0567B' => hex2rgb('E0567B'),
    'pinkd --pink-deep #C13E63' => hex2rgb('C13E63'),
];

echo "BASELINE — the shop today: body background-color #FDEFF3 (kbb.css:1599)\n";
foreach ($text as $n => $c) printf("  %-28s %5.2f\n", $n, ratio($c, hex2rgb('FDEFF3')));
echo "  (pure white #FFFFFF, for reference)\n";
foreach ($text as $n => $c) printf("  %-28s %5.2f\n", $n, ratio($c, hex2rgb('FFFFFF')));

echo "\n";
foreach (PageWash::TREATMENTS as $key => $t) {
    $values = $t + ['c1'=>'#FFF4E8','c2'=>'#FBDFE8','c3'=>'#E9DFF6'];
    $moments = PageWash::moments($values);
    $worst = [];
    $darkest = null; $darkestLum = 2.0;
    foreach ($moments as $mi => $m) {
        foreach ($m as $c) {
            if (lum($c) < $darkestLum) { $darkestLum = lum($c); $darkest = $c; }
        }
    }
    printf("TREATMENT %s — %s  (palette %s, intensity %d, drift %d, %ds, %s)\n",
        strtoupper($key), $t['name'], $t['palette'], $t['intensity'], $t['drift'], $t['cycle'], $t['spread']);
    printf("  floor %s   darkest stop anywhere in the cycle rgb(%d,%d,%d) = #%02X%02X%02X\n",
        PageWash::floorColour($values), $darkest[0],$darkest[1],$darkest[2],$darkest[0],$darkest[1],$darkest[2]);
    foreach ($text as $n => $c) {
        $r = ratio($c, $darkest);
        printf("  %-28s %5.2f   %s\n", $n, $r, $r >= 4.5 ? 'AA' : ($r >= 3.0 ? 'AA-large only' : 'FAIL'));
    }
    // every stop, to show the spread
    $all = [];
    foreach ($moments as $m) foreach ($m as $c) $all[] = sprintf('#%02X%02X%02X', $c[0],$c[1],$c[2]);
    echo "  nine stops: ".implode(' ', $all)."\n\n";
}

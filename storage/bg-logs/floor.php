<?php
function lum(array $c): float {
    $f = function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; };
    return 0.2126*$f($c[0]) + 0.7152*$f($c[1]) + 0.0722*$f($c[2]);
}
function ratio(array $a, array $b): float { $l1=lum($a);$l2=lum($b); return (max($l1,$l2)+0.05)/(min($l1,$l2)+0.05); }
function h(string $x): array { $x=ltrim($x,'#'); return [hexdec(substr($x,0,2)),hexdec(substr($x,2,2)),hexdec(substr($x,4,2))]; }
$tokens = ['ink'=>'2A2228','ink2'=>'5E545A','muted'=>'8C828A','pink'=>'E0567B','pinkdeep'=>'C13E63'];
foreach (['FDEFF3'=>'body background-color (kbb.css:1599)','FCE7EE'=>'darkest stop of the 180deg gradient over it','FBEAF0'=>'bottom stop','FFF7F4'=>'lightest stop'] as $bg=>$what) {
  printf("%s  lum %.4f   %s\n", $bg, lum(h($bg)), $what);
  foreach ($tokens as $n=>$t) printf("    %-9s %5.2f\n", $n, ratio(h($t), h($bg)));
}
// per-channel minimum of each candidate palette, which is the darkest stop any
// slider position can produce.
$palettes = [
 'cream_blush_lilac' => ['FFF4E8','FBDFE8','E9DFF6'],
 'mint_cream_blush'  => ['E2F1E8','FFF6E9','FBDCE6'],
 'lilac_sky_pearl'   => ['E7E2F7','DEECF7','F4F0FA'],
 'peach_rose_pearl'  => ['FFEDE0','FBDEE4','F5F1F6'],
];
echo "\nper-channel minimum (= darkest stop reachable at intensity 100, any drift)\n";
foreach ($palettes as $n=>$p) {
  $m=[255,255,255];
  foreach ($p as $c){ $c=h($c); for($i=0;$i<3;$i++) $m[$i]=min($m[$i],$c[$i]); }
  printf("  %-20s #%02X%02X%02X lum %.4f  ink %5.2f muted %5.2f pink %5.2f\n",
    $n,$m[0],$m[1],$m[2],lum($m),ratio(h('2A2228'),$m),ratio(h('8C828A'),$m),ratio(h('E0567B'),$m));
}

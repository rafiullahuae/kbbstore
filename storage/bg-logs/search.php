<?php
require __DIR__.'/../../vendor/autoload.php';
use App\Services\PageWash;
function lum(array $c): float { $f=fn($v)=>($v/=255)<=0.03928?$v/12.92:(($v+0.055)/1.055)**2.4;
  return 0.2126*$f($c[0])+0.7152*$f($c[1])+0.0722*$f($c[2]); }
function ratio(array $a,array $b): float { $l1=lum($a);$l2=lum($b); return (max($l1,$l2)+0.05)/(min($l1,$l2)+0.05); }
function h(string $x): array { $x=ltrim($x,'#'); return [hexdec(substr($x,0,2)),hexdec(substr($x,2,2)),hexdec(substr($x,4,2))]; }

// exact reachable set: every (drift, intensity) a slider can be on
function darkestReachable(array $palette): array {
    $worst=null; $worstL=2.0;
    for ($d=0;$d<=100;$d+=5) for ($i=0;$i<=100;$i+=5) {
        $v = ['palette'=>'custom','c1'=>$palette[0],'c2'=>$palette[1],'c3'=>$palette[2],'drift'=>$d,'intensity'=>$i];
        foreach (PageWash::moments($v) as $m) foreach ($m as $c) {
            if (lum($c) < $worstL) { $worstL=lum($c); $worst=$c; }
        }
    }
    return [$worst, $worstL];
}
$target = lum(h('FCE7EE'));  // the darkest background the shop renders today
printf("bar: lum >= %.4f  (#FCE7EE, kbb.css:1599's gradient)\n\n", $target);

$cands = [
 'cream_blush_lilac'=>['#FFF7EE','#FDECF1','#F1EAFA'],
 'mint_cream_blush' =>['#E7F4EC','#FFF7EC','#FDEAF0'],
 'mint_b'           =>['#E9F5ED','#FFF7EC','#FDEAF0'],
 'lilac_sky_pearl'  =>['#EFECFB','#EAF3FC','#F7F4FC'],
 'lilac_b'          =>['#EEEBFB','#E9F2FC','#F7F4FC'],
 'peach_rose_pearl' =>['#FFF1E7','#FDE9ED','#F8F5F9'],
];
foreach ($cands as $n=>$p) {
    [$c,$l] = darkestReachable($p);
    printf("%-21s darkest #%02X%02X%02X lum %.4f %s  ink %5.2f ink2 %5.2f muted %5.2f pink %5.2f pinkd %5.2f\n",
      $n,$c[0],$c[1],$c[2],$l, $l>=$target?'PASS':'fail',
      ratio(h('2A2228'),$c),ratio(h('5E545A'),$c),ratio(h('8C828A'),$c),ratio(h('E0567B'),$c),ratio(h('C13E63'),$c));
}

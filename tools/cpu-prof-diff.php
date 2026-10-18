<?php
// Lane CPU -- median exclusive view ms and translator calls, URI A vs URI B, from
// a KBB_CPU_PROFILE=1 requests.jsonl (tools/cpu-front.php). First hit of each dropped.
//   php tools/cpu-prof-diff.php <jsonl> <uriA> <uriB> [out.json]
[$_, $log, $a, $b] = $argv;
$runs = [$a => [], $b => []];
foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $r = json_decode($l, true);
    if (isset($runs[$r['uri']], $r['prof'])) { $runs[$r['uri']][] = $r; }
}
$med = static function (array $x) { sort($x); return $x === [] ? 0 : $x[intdiv(count($x), 2)]; };
$sum = [];
foreach ($runs as $u => $rs) {
    array_shift($rs);
    $views = [];
    foreach ($rs as $r) { foreach ($r['prof']['views'] as $v => $ms) { $views[$v][] = $ms; } }
    $sum[$u] = ['ms' => $med(array_column($rs, 'ms')), 'tr_n' => $med(array_map(fn ($r) => $r['prof']['tr_n'], $rs)),
        'tr_ms' => $med(array_map(fn ($r) => $r['prof']['tr_ms'], $rs)), 'views_ms' => $med(array_map(fn ($r) => array_sum($r['prof']['views']), $rs)),
        'tr_keys' => end($rs)['prof']['tr_keys'], 'views' => array_map($med, $views)];
}
printf("%-10s %8s %8s %8s %8s\n", '', 'ms', 'views', 'tr_n', 'tr_ms');
foreach ($sum as $u => $s) { printf("%-10s %8.1f %8.1f %8d %8.1f\n", $u, $s['ms'], $s['views_ms'], $s['tr_n'], $s['tr_ms']); }
$all = array_unique(array_merge(array_keys($sum[$a]['views']), array_keys($sum[$b]['views'])));
$d = [];
foreach ($all as $v) { $d[$v] = ($sum[$b]['views'][$v] ?? 0) - ($sum[$a]['views'][$v] ?? 0); }
arsort($d);
echo "\nviews costing more on $b (exclusive ms, median):\n";
foreach (array_slice($d, 0, 15, true) as $v => $x) { printf("  %+7.2f  %-60s (%s %.2f / %s %.2f)\n", $x, $v, $a, $sum[$a]['views'][$v] ?? 0, $b, $sum[$b]['views'][$v] ?? 0); }
echo "\ntranslator calls by key prefix on $b:\n";
arsort($sum[$b]['tr_keys']);
foreach (array_slice($sum[$b]['tr_keys'], 0, 12, true) as $k => $n) { printf("  %5d  %s (on %s: %d)\n", $n, $k, $a, $sum[$a]['tr_keys'][$k] ?? 0); }
if (isset($argv[4])) { file_put_contents($argv[4], json_encode($sum, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }

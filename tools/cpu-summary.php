<?php
// Lane CPU -- summarise storage/cpu-logs/requests.jsonl (tools/cpu-front.php) per URI.
// php tools/cpu-summary.php <jsonl> <out.json>
$rows = [];
foreach (file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $r = json_decode($l, true);
    $rows[$r['uri']][] = $r;
}
$med = static function (array $a) { sort($a); return $a[intdiv(count($a), 2)] ?? null; };
$out = [];
foreach ($rows as $uri => $rs) {
    $cold = array_shift($rs);
    $warm = $rs ?: [$cold];
    $last = end($warm);
    $out[$uri] = [
        'status' => $last['status'], 'n_warm' => count($warm),
        'ms_med' => $med(array_column($warm, 'ms')), 'cpu_ms_med' => $med(array_column($warm, 'cpu_ms')),
        'ms_cold' => $cold['ms'], 'queries' => $last['q'], 'q_settings' => $last['q_settings'], 'q_translations' => $last['q_translations'],
        'cache_reads' => array_sum($last['hit']) + array_sum($last['miss']), 'cache_writes' => array_sum($last['write']),
        'mem_peak_mb' => $last['mem_peak_mb'], 'hit' => $last['hit'], 'miss' => $last['miss'], 'cold_writes' => $cold['write'],
    ];
}
file_put_contents($argv[2], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
printf("%-42s %4s %7s %7s %7s %4s %4s %5s %5s\n", 'uri', 'st', 'ms', 'cpu_ms', 'cold', 'q', 'q_tr', 'cache', 'MB');
foreach ($out as $u => $r) {
    printf("%-42s %4d %7.1f %7.1f %7.1f %4d %4d %5d %5.1f\n", substr($u, 0, 42), $r['status'], $r['ms_med'], $r['cpu_ms_med'], $r['ms_cold'], $r['queries'], $r['q_translations'], $r['cache_reads'], $r['mem_peak_mb']);
}

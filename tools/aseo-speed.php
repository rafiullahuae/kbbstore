<?php
// Lane ASEO: server ms (median of 7 warm runs), queries, settings-table queries
// and settings-map cache reads (kbb.settings) per page, through the HTTP
// kernel against the aseo-preview database. PREVIEW ONLY.
//   tools/aseo-speed.sh <label> url...   -> storage/aseo-logs/speed-<label>.json
$base = $_SERVER['argv'][1];
$label = $_SERVER['argv'][2];
$urls = array_slice($_SERVER['argv'], 3);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$out = [];
$qc = 0; $sc = 0; $mc = 0; $listening = false;
foreach ($urls as $u) {
    $ms = [];
    for ($i = 0; $i < 8; $i++) {
        $qc = 0; $sc = 0; $mc = 0;
        $req = Illuminate\Http\Request::create($u, 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/141.0', 'HTTP_HOST' => '127.0.0.1']);
        $t = hrtime(true);
        $res = $kernel->handle($req);
        $dt = (hrtime(true) - $t) / 1e6;
        $kernel->terminate($req, $res);
        if (! $listening) {
            Illuminate\Support\Facades\DB::listen(function ($e) use (&$qc, &$sc) { $qc++; if (preg_match('/from\s+"?settings"?/i', $e->sql)) { $sc++; } });
            Illuminate\Support\Facades\Event::listen([Illuminate\Cache\Events\CacheHit::class, Illuminate\Cache\Events\CacheMissed::class], function ($e) use (&$mc) { if ($e->key === 'kbb.settings') { $mc++; } });
            $listening = true;
        }
        if ($i > 0) { $ms[] = $dt; }
        $q = $qc; $s = $sc; $m = $mc;
        $bytes = strlen((string) $res->getContent());
        $status = $res->getStatusCode();
        $marks = substr_count((string) $res->getContent(), '⟪');
    }
    sort($ms);
    $out[$u] = ['status' => $status, 'ms' => round($ms[intdiv(count($ms), 2)], 1), 'queries' => $q, 'settings_queries' => $s, 'settings_map_reads' => $m, 'html_bytes' => $bytes, 'markers' => $marks];
}
@mkdir($base.'/storage/aseo-logs', 0777, true);
file_put_contents($base.'/storage/aseo-logs/speed-'.$label.'.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
foreach ($out as $u => $r) {
    printf("%-60s %d %6.1f ms  q=%d  set_q=%d  map=%d  %d B  ⟪=%d\n", $u, $r['status'], $r['ms'], $r['queries'], $r['settings_queries'], $r['settings_map_reads'], $r['html_bytes'], $r['markers']);
}

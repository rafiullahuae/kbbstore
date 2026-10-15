<?php
// Lane ARC: server ms (median of 7 warm runs), queries and settings-table reads
// per page, through the HTTP kernel against the preview database.
//   sh speed.sh <label> url...
$base = $_SERVER['argv'][1];
$urls = array_slice($_SERVER['argv'], 3);
$label = $_SERVER['argv'][2];
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
if (in_array($label, ['down', 'up'], true)) {
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach (['2027_11_03_100200_arabic_content_short_descriptions', '2027_11_03_100100_arabic_content_homepage_wording', '2027_11_03_100000_arabic_content_catalogue'] as $m) {
        $mig = require $base.'/database/migrations/'.$m.'.php';
        $mig->{$label}();
    }
    echo $label, ' done; ar rows: ', \Illuminate\Support\Facades\DB::table('translations')->where('locale', 'ar')->count(), "\n";
    exit;
}
$out = []; $qc = 0; $sc = 0; $listening = false;
foreach ($urls as $u) {
    $ms = []; $q = 0; $s = 0; $bytes = 0;
    for ($i = 0; $i < 8; $i++) {
        $qc = 0; $sc = 0;
        $req = Illuminate\Http\Request::create($u, 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/141.0', 'HTTP_HOST' => '127.0.0.1']);
        $t = hrtime(true);
        $res = $kernel->handle($req);
        $dt = (hrtime(true) - $t) / 1e6;
        $kernel->terminate($req, $res);
        if (! $listening) { \Illuminate\Support\Facades\DB::listen(function ($e) use (&$qc, &$sc) { $qc++; if (preg_match('/from\s+"?settings"?/i', $e->sql)) { $sc++; } }); $listening = true; }
        if ($i > 0) { $ms[] = $dt; }
        $q = $qc; $s = $sc; $bytes = strlen((string) $res->getContent());
        $status = $res->getStatusCode();
    }
    sort($ms);
    $out[$u] = ['status' => $status, 'ms' => round($ms[intdiv(count($ms), 2)], 1), 'queries' => $q, 'settings_reads' => $s, 'html_bytes' => $bytes];
}
file_put_contents($base.'/storage/arc-logs/speed-'.$label.'.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

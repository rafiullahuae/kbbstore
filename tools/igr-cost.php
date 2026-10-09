<?php
// Lane IGR: server ms (warm median of 7, after one cold run), query count and
// HTML bytes for the homepage and /kbeautybliss-spotted/, in the preview's own
// database. Run through tools/igr-cost.sh, which gives it the preview env.
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

$q = 0;
DB::listen(function () use (&$q) { $q++; });
$out = [];
foreach (['/', '/kbeautybliss-spotted/'] as $url) {
    app()->handle(Illuminate\Http\Request::create($url)); // warm the process
    $runs = [];
    foreach (range(0, 7) as $i) {
        if ($i === 0) { Illuminate\Support\Facades\Cache::flush(); }
        SettingsService::forgetMemo();
        $q = 0;
        $t = hrtime(true);
        $res = app()->handle(Illuminate\Http\Request::create($url));
        $runs[] = ['ms' => round((hrtime(true) - $t) / 1e6, 1), 'q' => $q, 'bytes' => strlen($res->getContent()), 'status' => $res->getStatusCode()];
    }
    $warm = array_slice($runs, 1);
    usort($warm, fn ($a, $b) => $a['ms'] <=> $b['ms']);
    $out[$url] = ['cold' => $runs[0], 'warm' => $warm[3]];
}
echo json_encode($out), "\n";

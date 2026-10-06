<?php
// Lane SG: server ms and query count for /kbeautybliss-spotted/ with 3 and 40
// ticked Instagram posts, cold (caches flushed) and warm (median of 7), in the
// preview's own database. Run through artisan tinker with the preview env
// (tools/ig2-preview.sh prints how); it restores the 40-post selection after.
use App\Models\InstagramPost;
use App\Services\SettingsService;
use App\Services\SpottedInstagram;
use App\Services\SpottedSettings;
use Illuminate\Support\Facades\DB;

$q = 0;
DB::listen(function () use (&$q) { $q++; });
$ids = InstagramPost::query()->orderBy('id')->pluck('id')->all();
$out = [];
app()->handle(Illuminate\Http\Request::create("/kbeautybliss-spotted/")); // warm the process (boot, first settings read) before measuring
foreach ([3, 40] as $n) {
    app(SpottedInstagram::class)->select(array_slice($ids, 0, $n));
    $runs = [];
    foreach (range(0, 7) as $i) {
        if ($i === 0) {
            SpottedInstagram::flush();
            SpottedSettings::flush();
        }
        SettingsService::forgetMemo();
        $q = 0;
        $t = hrtime(true);
        $res = app()->handle(Illuminate\Http\Request::create('/kbeautybliss-spotted/'));
        $ms = (hrtime(true) - $t) / 1e6;
        $runs[] = ['ms' => round($ms, 1), 'q' => $q, 'cards' => substr_count($res->getContent(), 'class="sig-card"'), 'bytes' => strlen($res->getContent())];
    }
    $warm = array_slice($runs, 1);
    usort($warm, fn ($a, $b) => $a['ms'] <=> $b['ms']);
    $out[$n] = ['cold' => $runs[0], 'warm_median' => $warm[3]];
}
app(SpottedInstagram::class)->select(array_slice($ids, 0, 40));
echo json_encode($out, JSON_PRETTY_PRINT), "\n";

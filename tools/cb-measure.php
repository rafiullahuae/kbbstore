<?php
/*
 * Lane CB: server ms, queries, settings-map reads and HTML bytes for a list of
 * storefront paths, measured IN-PROCESS against the preview's database (run
 * through tools/cb-tinker.sh, so the env is the preview's). Each path is
 * requested once to warm, then CB_RUNS times; the median is reported.
 *
 *     sh tools/cb-tinker.sh "require 'tools/cb-measure.php';"
 */
$paths = array_filter(explode(',', (string) (getenv('CB_PATHS') ?: '/,/collections/cbbanner/,/collections/cbplain/,/brands/anua/,/product/'.\App\Models\Product::query()->where('status', 'publish')->orderBy('id')->value('slug').'/')));
$runs = (int) (getenv('CB_RUNS') ?: 9);
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$reads = 0;
\Illuminate\Support\Facades\Event::listen([\Illuminate\Cache\Events\CacheHit::class, \Illuminate\Cache\Events\CacheMissed::class], static function ($e) use (&$reads): void {
    if ($e->key === 'kbb.settings') {
        $reads++;
    }
});

foreach ($paths as $path) {
    $ms = [];
    $qs = [];
    $bytes = 0;
    $status = 0;

    for ($i = 0; $i <= $runs; $i++) {
        \App\Models\Setting::flushMap();
        \App\Services\SettingsService::forgetMemo();
        app()->forgetScopedInstances();
        $reads = 0;
        $n = 0;
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $t = hrtime(true);
        $request = \Illuminate\Http\Request::create($path, 'GET');
        $response = $kernel->handle($request);
        $elapsed = (hrtime(true) - $t) / 1e6;
        $kernel->terminate($request, $response);
        $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        if ($i === 0) {
            continue; // warm
        }

        $ms[] = $elapsed;
        $qs[] = $n;
        $r = $reads;
        $bytes = strlen((string) $response->getContent());
        $status = $response->getStatusCode();
    }

    sort($ms);
    $counts = array_count_values($qs);
    arsort($counts);
    $q = (int) array_key_first($counts); // the usual count (a deferred write can add one now and then)
    printf("%-34s %3d  %6.1f ms  %3d queries  %2d settings-map reads  %7d bytes\n", $path, $status, $ms[intdiv(count($ms), 2)], $q, $r, $bytes);
}

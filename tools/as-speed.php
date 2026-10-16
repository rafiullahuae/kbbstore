<?php

declare(strict_types=1);

/*
 * Lane AS: server ms (median of 15 after one warm-up), queries and settings-map
 * reads for product, category and brand -- English and /ar -- on the
 * tools/arr-preview.sh database. tools/arr-speed.php's method, trimmed.
 *
 *   sh tools/as-speed.sh <tag>      (writes storage/as-logs/speed-<tag>.json)
 */

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$pages = [
    'product' => '/product/azelaic-10-hyaluron-redness-soothing-pad/',
    'category' => '/collections/cleansers/',
    'brand' => '/brands/anua/',
];

$queries = 0;
$reads = 0;
DB::listen(function () use (&$queries) { $queries++; });
Event::listen([CacheHit::class, CacheMissed::class], function ($e) use (&$reads) {
    if ($e->key === 'kbb.settings' || $e->key === 'kbb.settings.map') {
        $reads++;
    }
});

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$out = [];

foreach (['en' => '', 'ar' => '/ar'] as $lang => $pre) {
    foreach ($pages as $label => $path) {
        $times = [];
        for ($i = 0; $i < 16; $i++) {
            \App\Services\SettingsService::forgetMemo();
            \App\Services\Translation\TranslationStore::forgetMemo();
            app()->forgetScopedInstances();
            $queries = 0;
            $reads = 0;
            $req = Request::create('http://127.0.0.1'.$pre.$path, 'GET', [], [], [], [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
                'HTTP_ACCEPT' => 'text/html',
            ]);
            $t = hrtime(true);
            $res = $kernel->handle($req);
            $ms = (hrtime(true) - $t) / 1e6;
            $kernel->terminate($req, $res);
            if ($i > 0) {
                $times[] = $ms;
            }
        }
        sort($times);
        $html = (string) $res->getContent();
        $out[$lang][$label] = [
            'status' => $res->getStatusCode(),
            'median_ms' => round($times[intdiv(count($times), 2)], 1),
            'queries' => $queries,
            'settings_reads' => $reads,
            'html_bytes' => strlen($html),
        ];
    }
}

$file = base_path('storage/as-logs/speed-'.(getenv('KBB_SPEED_TAG') ?: 'run').'.json');
file_put_contents($file, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
echo 'wrote ', $file, "\n";

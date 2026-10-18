<?php

declare(strict_types=1);

/*
 * Lane BT: server ms (median of 15 after one warm-up), queries, settings-map
 * reads, HTML bytes (raw and gzip) and render-blocking CSS bytes for home,
 * product, category and brand, English and /ar, on the tools/arr-preview.sh
 * database. tools/arr-speed.php's method. Run on the base tree and on this
 * branch with the same fixture:
 *
 *   KBB_SPEED_TAG=before sh tools/bt-artisan.sh tinker --execute="require 'tools/bt-speed.php';"
 */

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$pages = [
    'home' => '/', 'product' => '/product/azelaic-10-hyaluron-redness-soothing-pad/',
    'category' => '/collections/toners/', 'brand' => '/brands/anua/',
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
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/141.0 Mobile Safari/537.36 Instagram 350.0',
                'HTTP_ACCEPT' => 'text/html', 'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
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
        preg_match_all('#<link rel="stylesheet" href="(/build/assets/[^"]+\.css)"#', $html, $m);
        $cssBytes = 0;
        foreach ($m[1] as $href) {
            $cssBytes += (int) @filesize(public_path(ltrim($href, '/')));
        }
        $out[$lang][$label] = [
            'status' => $res->getStatusCode(),
            'median_ms' => round($times[intdiv(count($times), 2)], 1),
            'queries' => $queries,
            'settings_reads' => $reads,
            'html_bytes' => strlen($html),
            'html_gzip' => strlen(gzencode($html, 6)),
            'blocking_css_files' => count($m[1]),
            'blocking_css_bytes' => $cssBytes,
            'scripts' => substr_count($html, '<script'),
        ];
    }
}

@mkdir(base_path('storage/bt-logs'), 0777, true);
$file = base_path('storage/bt-logs/speed-'.(getenv('KBB_SPEED_TAG') ?: 'run').'.json');
file_put_contents($file, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
echo 'wrote ', $file, "\n";

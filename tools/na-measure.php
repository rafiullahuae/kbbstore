<?php

declare(strict_types=1);

/*
 * Lane NA: server ms and queries for the product, category, brand and New
 * Arrivals pages, through the HTTP kernel in one warm process, against the
 * preview database tools/na-preview.sh built. Median of 7 after 2 warm-ups.
 *
 *   sh tools/na-measure.sh      (sets the preview's environment, then runs this)
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$pages = [
    'product' => '/product/na-1/',
    'category' => '/collections/mac-cat-0/',
    'brand' => '/brands/mac-0/',
    'new-arrivals' => '/collections/new-arrivals/',
];
$out = [];
$GLOBALS['naq'] = 0;
\Illuminate\Support\Facades\DB::listen(function () { $GLOBALS['naq']++; });

foreach ($pages as $name => $url) {
    $ms = [];
    $q = [];
    $status = 0;
    $bytes = 0;

    for ($i = 0; $i < 9; $i++) {
        $GLOBALS['naq'] = 0;
        \App\Services\SettingsService::forgetMemo();
        $request = Illuminate\Http\Request::create($url, 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0 Safari/537.36', 'HTTP_HOST' => '127.0.0.1']);
        $t = hrtime(true);
        $response = $kernel->handle($request);
        $elapsed = (hrtime(true) - $t) / 1e6;
        $n = $GLOBALS['naq'];
        $kernel->terminate($request, $response);
        $status = $response->getStatusCode();
        $bytes = strlen((string) $response->getContent());

        if ($i >= 2) {
            $ms[] = $elapsed;
            $q[] = $n;
        }
    }

    sort($ms);
    sort($q);
    $out[$name] = ['status' => $status, 'ms' => round($ms[3], 1), 'queries' => $q[3], 'html_bytes' => $bytes];
}

echo json_encode($out, JSON_PRETTY_PRINT)."\n";

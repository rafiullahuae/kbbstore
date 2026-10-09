<?php
/*
 * Lane AN: server ms, queries and settings-map reads for a request, measured
 * inside one PHP process against the tools/an-preview.sh database.
 *
 *   php tools/an-measure.php GET  /product/an-snail-96-mucin-essence/ [N]
 *   php tools/an-measure.php POST /api/viewed 'p=/shop/&t=Shop&n=1' [N]
 *
 * Prints p50 / p95 ms over N runs after one warm-up, the query count and the
 * kbb.settings cache reads of the last run. APP_ENV=testing only so the CSRF
 * check steps aside for the POST; the same for the before and after runs.
 */
$method = strtoupper($argv[1]);
$uri = $argv[2];
$body = $method === 'POST' ? (string) ($argv[3] ?? '') : '';
$n = (int) ($argv[$method === 'POST' ? 4 : 3] ?? 40);
$app0 = dirname(__DIR__);
$dir = $app0.'/storage/framework/testing/an-preview';
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'KBB_PUBLIC_PATH' => $dir.'/webroot',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dir.'/preview.sqlite',
    'SESSION_DRIVER' => 'database', 'CACHE_STORE' => 'file',
    'APP_CONFIG_CACHE' => $dir.'/compiled/m-config.php', 'APP_ROUTES_CACHE' => $dir.'/compiled/m-routes.php',
    'APP_EVENTS_CACHE' => $dir.'/compiled/m-events.php', 'APP_SERVICES_CACHE' => $dir.'/compiled/m-services.php',
    'APP_PACKAGES_CACHE' => $dir.'/compiled/m-packages.php',
] as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $_SERVER[$k] = $v;
}
require $app0.'/vendor/autoload.php';

parse_str($body, $fields);
$times = [];
$queries = 0;
$reads = 0;
$status = 0;
for ($i = 0; $i <= $n; $i++) {
    $app = require $app0.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $q = 0;
    $r = 0;
    $app['events']->listen(Illuminate\Database\Events\QueryExecuted::class, static function () use (&$q): void { $q++; });
    $app['events']->listen([Illuminate\Cache\Events\CacheHit::class, Illuminate\Cache\Events\CacheMissed::class], static function ($e) use (&$r): void { if ($e->key === 'kbb.settings') { $r++; } });
    $req = Illuminate\Http\Request::create($uri, $method, $fields, [], [], [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
        'REMOTE_ADDR' => '203.0.113.'.(10 + $i % 200), 'HTTP_HOST' => '127.0.0.1',
    ]);
    $t = hrtime(true);
    $res = $kernel->handle($req);
    $ms = (hrtime(true) - $t) / 1e6;
    $kernel->terminate($req, $res);
    $status = $res->getStatusCode();
    if ($i > 0) {
        $times[] = $ms;
    }
    $queries = $q;
    $reads = $r;
}
sort($times);
$p = static fn (float $f): float => $times[(int) floor(($n - 1) * $f)];
printf("%-4s %-48s status %d  p50 %6.1f ms  p95 %6.1f ms  queries %d  settings-map reads %d  (n=%d)\n", $method, $uri, $status, $p(0.5), $p(0.95), $queries, $reads, $n);

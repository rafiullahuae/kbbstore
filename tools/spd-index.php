<?php
/*
 * Lane SP (speed) -- the PREVIEW-ONLY front controller. Never shipped: the
 * preview webroot gets this instead of public-web-root/index.php so every
 * response carries its own server-side numbers, measured the same way on the
 * old tree, HEAD and the fix:
 *
 *   X-Spd-Ms     ms from LARAVEL_START to the response being ready to send
 *   X-Spd-Q      SQL statements run before the response
 *   X-Spd-Qms    their summed time (ms, as Laravel's QueryExecuted reports)
 *   X-Spd-Mem    PHP peak memory (KB)
 *
 * Work done AFTER the response (defer(), terminating callbacks) is logged to
 * ../spd-after.log with its own time and query count, so it can be reported
 * separately -- and so it is visible that php -S (no fastcgi_finish_request)
 * keeps the connection open for it while PHP-FPM would not.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$base = __DIR__.'/../kbb-upgrade-app';
require $base.'/vendor/autoload.php';
$app = require_once $base.'/bootstrap/app.php';

$GLOBALS['spd'] = ['q' => 0, 'ms' => 0.0, 'sql' => []];
$app['events']->listen(\Illuminate\Database\Events\QueryExecuted::class, static function ($e): void {
    $GLOBALS['spd']['q']++;
    $GLOBALS['spd']['ms'] += $e->time;
    $GLOBALS['spd']['sql'][] = round($e->time, 2).'  '.$e->sql;
});

$GLOBALS['spd']['cache'] = [];
$app['events']->listen([\Illuminate\Cache\Events\CacheHit::class, \Illuminate\Cache\Events\CacheMissed::class], static function ($e): void {
    $GLOBALS['spd']['cache'][$e->key] = ($GLOBALS['spd']['cache'][$e->key] ?? 0) + 1;
});

$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$ready = microtime(true);
$before = $GLOBALS['spd'];
$response->headers->set('X-Spd-Ms', (string) round(($ready - LARAVEL_START) * 1000, 2));
$response->headers->set('X-Spd-Q', (string) $before['q']);
$response->headers->set('X-Spd-Qms', (string) round($before['ms'], 2));
$response->headers->set('X-Spd-Mem', (string) round(memory_get_peak_usage(false) / 1024));
$response->headers->set('X-Spd-Cache', (string) array_sum($before['cache']));
if (is_file(__DIR__.'/../slow.on')) {
    // 1.6 Mbps for the page's bytes, at a typical 6:1 gzip ratio (spd-router.php).
    usleep((int) (strlen((string) $response->getContent()) / 6 * 5));
}
$response->send();
$kernel->terminate($request, $response);
$end = microtime(true);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
if (! preg_match('#\.(css|js|png|jpe?g|webp|svg|woff2?|ico|txt|json)(\?|$)#', $uri)) {
    @file_put_contents(__DIR__.'/../spd-after.log', json_encode([
        'uri' => $uri, 'purpose' => (string) ($_SERVER['HTTP_SEC_PURPOSE'] ?? ''), 'ms' => round(($ready - LARAVEL_START) * 1000, 2), 'after_ms' => round(($end - $ready) * 1000, 2),
        'q' => $before['q'], 'after_q' => $GLOBALS['spd']['q'] - $before['q'],
        'cache_reads' => array_sum($before['cache']), 'top_keys' => array_slice((function ($c) { arsort($c); return $c; })($before['cache']), 0, 8, true),
    ])."\n", FILE_APPEND);
    if (getenv('SPD_SQL') || is_file(__DIR__.'/../spd-sql.on')) {
        @file_put_contents(__DIR__.'/../spd-sql/'.substr(sha1($uri), 0, 10).'.txt', $uri."\n".implode("\n", $GLOBALS['spd']['sql'])."\n");
    }
}

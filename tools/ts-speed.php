<?php
/*
 * Lane TS: server time and queries for one storefront request, in a process of
 * its own (as PHP-FPM serves it: no static memo carried over), against the
 * preview database tools/ts-preview.sh built.
 *
 *   php tools/ts-speed.php <app root> <sqlite file> <path>
 *
 *   php tools/ts-speed.php <app root> <sqlite file> <path> [runs]
 *
 * Prints "<ms> <queries of the first run> <status> <settings-map reads>".
 * Run it against a worktree of the base commit and against this branch for
 * before/after.
 */
[$self, $root, $db, $path] = $argv;
$runs = (int) ($argv[4] ?? 1);

foreach ([
    'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db, 'KBB_PUBLIC_PATH' => $root.'/public',
    'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'array', 'APP_URL' => 'http://127.0.0.1',
    'APP_CONFIG_CACHE' => sys_get_temp_dir().'/ts-speed-none-config.php', 'APP_ROUTES_CACHE' => sys_get_temp_dir().'/ts-speed-none-routes.php',
] as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $_SERVER[$k] = $v;
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$n = 0;
$mapReads = 0;
$app->booted(function () use ($app, &$n, &$mapReads) {
    $app['db']->listen(function ($q) use (&$n, &$mapReads) {
        $n++;
        if (str_contains($q->sql, 'from "settings"') && str_contains($q->sql, 'autoload')) {
            $mapReads++;
        }
    });
});

$times = [];
$queries = [];
for ($i = 0; $i < $runs; $i++) {
    $n = 0;
    $mapReads = 0;
    $request = Illuminate\Http\Request::create($path, 'GET', [], [], [], ['HTTP_HOST' => '127.0.0.1', 'HTTP_USER_AGENT' => 'Mozilla/5.0 ts-speed']);
    $t = hrtime(true);
    $response = $kernel->handle($request);
    $times[] = (hrtime(true) - $t) / 1e6;
    $queries[] = $n;
    $kernel->terminate($request, $response);
}

// One run: that request as a fresh process serves it. Several (a warm
// worker, as PHP-FPM with opcache keeps one): the median of the later half.
$tail = $runs > 1 ? array_slice($times, intdiv($runs, 2)) : $times;
sort($tail);
printf("%.1f %d %d %d\n", $tail[intdiv(count($tail), 2)], $queries[0], $response->getStatusCode(), $mapReads);

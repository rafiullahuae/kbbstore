<?php
/*
 * Lane CK2: server time and queries for one storefront request in a process
 * of its own, against a given database copy -- tools/ts-speed.php, plus a
 * basket, so the cart and the checkout draw what a shopper sees.
 *
 *   php tools/ck2-speed.php <app root> <sqlite file> <path> <runs> [cart token]
 *
 * Prints "<median ms of the later half> <queries of the first run> <status>
 * <settings-map reads>". Run it against the base tree and this branch, on the
 * same database file, alternating.
 */
[$self, $root, $db, $path] = $argv;
$runs = (int) ($argv[4] ?? 1);
$token = $argv[5] ?? '';

foreach ([
    'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db, 'KBB_PUBLIC_PATH' => $root.'/public',
    'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'array', 'APP_URL' => 'http://127.0.0.1',
    'APP_CONFIG_CACHE' => sys_get_temp_dir().'/ck2-speed-none-config.php', 'APP_ROUTES_CACHE' => sys_get_temp_dir().'/ck2-speed-none-routes.php',
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

$cookies = [];
if ($token !== '') {
    // The cookie as the browser sends it: encrypted with the app key, prefixed.
    preg_match('/^APP_KEY=(?:base64:)?(.+)$/m', (string) file_get_contents($root.'/.env'), $km);
    $enc = new Illuminate\Encryption\Encrypter(base64_decode(trim($km[1])), 'AES-256-CBC');
    $name = App\Services\CartService::COOKIE;
    $cookies[$name] = $enc->encrypt(Illuminate\Cookie\CookieValuePrefix::create($name, $enc->getKey()).$token, false);
}

$times = [];
$queries = [];
for ($i = 0; $i < $runs; $i++) {
    $n = 0;
    $mapReads = 0;
    $request = Illuminate\Http\Request::create($path, 'GET', [], $cookies, [], ['HTTP_HOST' => '127.0.0.1', 'HTTP_USER_AGENT' => 'Mozilla/5.0 ck2-speed']);
    $t = hrtime(true);
    $response = $kernel->handle($request);
    $times[] = (hrtime(true) - $t) / 1e6;
    $queries[] = $n;
    $kernel->terminate($request, $response);
}

$tail = $runs > 1 ? array_slice($times, intdiv($runs, 2)) : $times;
sort($tail);
printf("%.1f %d %d %d %d\n", $tail[intdiv(count($tail), 2)], $queries[0], $response->getStatusCode(), $mapReads, end($queries));

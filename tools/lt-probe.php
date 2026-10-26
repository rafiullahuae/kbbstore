<?php

declare(strict_types=1);

/*
 * Lane LT: one shop request through the real HTTP kernel, in a process of its
 * own, against the preview's database (env from tools/lt-preview.sh): prints
 * the path, server ms, query count and settings-map reads.
 *
 *   php tools/lt-probe.php /ar/product/<slug>/
 *
 * One process per request, as under PHP-FPM, so no memo is warm that a
 * shopper's request would find cold. The file cache is shared, as on the host.
 */

$path = $argv[1] ?? '/';

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$queries = 0;
$settingsReads = 0;
$app->booted(function () use ($app, &$queries, &$settingsReads) {
    $app['db']->listen(function ($q) use (&$queries, &$settingsReads) {
        $queries++;
        if (str_contains($q->sql, 'from "settings"') || str_contains($q->sql, 'from `settings`')) {
            $settingsReads++;
        }
    });
});

$request = Illuminate\Http\Request::create($path, 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0 Safari/537.36', 'HTTP_HOST' => '127.0.0.1']);
$t = hrtime(true);
$response = $kernel->handle($request);
$ms = (hrtime(true) - $t) / 1e6;
$kernel->terminate($request, $response);

echo json_encode(['path' => $path, 'status' => $response->getStatusCode(), 'ms' => round($ms, 1), 'queries' => $queries, 'settings_queries' => $settingsReads, 'bytes' => strlen((string) $response->getContent()), 'location' => $response->headers->get('Location')]), "\n";

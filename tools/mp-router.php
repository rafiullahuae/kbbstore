<?php

declare(strict_types=1);

/*
 * Lane MP preview only: `php -S` router. Serves files, and otherwise boots the
 * app with Meta / Google / TikTok faked at the HTTP client, so the Connect
 * tabs' Check buttons can be photographed in both states with no network.
 * The state comes from MP_FAKE in the webroot's mp-fake.txt: "ok" or "bad".
 * Nothing in app/ knows it exists.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = $_SERVER['DOCUMENT_ROOT'] ?? '';

if ($path !== '/' && ! str_contains($path, '..') && is_file($root.$path)) {
    return false;
}

$app_root = getenv('MP_APP');
require $app_root.'/vendor/autoload.php';
$app = require $app_root.'/bootstrap/app.php';
$mode = trim((string) @file_get_contents($root.'/mp-fake.txt')) ?: 'ok';

$app->booted(function () use ($mode): void {
    $H = \Illuminate\Support\Facades\Http::class;
    $H::fake([
        'graph.facebook.com/*' => function ($r) use ($mode, $H) {
            if ($mode !== 'ok') {
                return $H::response(['error' => ['message' => 'Invalid OAuth access token - Cannot parse access token', 'type' => 'OAuthException', 'code' => 190]], 400);
            }

            return str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/events')
                ? $H::response(['events_received' => 1, 'messages' => [], 'fbtrace_id' => 'AbCdEf'])
                : $H::response(['id' => '111122223333444', 'name' => 'Extra Beauty website']);
        },
        'www.google-analytics.com/debug/*' => $mode === 'ok'
            ? $H::response(['validationMessages' => []])
            : $H::response(['validationMessages' => [['fieldPath' => 'measurement_id', 'description' => 'Measurement ID G-WRONG01 is not a valid web stream.', 'validationCode' => 'VALUE_INVALID']]]),
        'www.google-analytics.com/*' => $H::response('', 204),
        'business-api.tiktok.com/*' => $mode === 'ok'
            ? $H::response(['code' => 0, 'message' => 'OK', 'request_id' => 'abc'])
            : $H::response(['code' => 40105, 'message' => 'The access token is invalid or has been revoked.', 'request_id' => 'abc']),
    ]);
});

$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request = \Illuminate\Http\Request::capture());
$response->send();
$kernel->terminate($request, $response);

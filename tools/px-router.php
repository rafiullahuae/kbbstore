<?php

declare(strict_types=1);

/*
 * Lane PX preview only: `php -S` router that serves files, and fakes the OLD
 * server at the transport so "Fetch missing pictures from the old server" can be
 * driven in Chromium with no network. Nothing in app/ knows it exists.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = $_SERVER['DOCUMENT_ROOT'] ?? '';

if ($path !== '/' && ! str_contains($path, '..') && is_file($root.$path)) {
    return false;
}

require '/home/user/lane-px/vendor/autoload.php';
$app = require '/home/user/lane-px/bootstrap/app.php';

$app->booted(function ($app): void {
    $app->instance(\App\Services\Import\OldServerPictures::class, new \App\Services\Import\OldServerPictures(transport: function (array $o): array {
        usleep(120000);
        $p = (string) parse_url($o[CURLOPT_URL], PHP_URL_PATH);

        if (str_contains($p, 'Cream-3') || str_contains($p, 'lost')) {
            return ['status' => 404, 'headers' => ['content-type' => 'text/html'], 'body' => 'Not Found', 'errno' => 0, 'error' => ''];
        }

        $im = imagecreatetruecolor(600, 600);
        imagefill($im, 0, 0, imagecolorallocate($im, 230, 170, 150));
        ob_start();
        imagejpeg($im, null, 80);

        return ['status' => 200, 'headers' => ['content-type' => 'image/jpeg'], 'body' => (string) ob_get_clean(), 'errno' => 0, 'error' => ''];
    }));
});

$app->handleRequest(\Illuminate\Http\Request::capture());

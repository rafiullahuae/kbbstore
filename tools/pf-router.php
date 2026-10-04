<?php
/* php -S router for the Lane PF Lighthouse runs: gzip on the text assets, as
   the live box (nginx) sends them. `php -S` serves a static file uncompressed,
   so a 225 KB stylesheet the shop sends as 39 KB was being timed by
   Lighthouse's simulated 4G at full size and every FCP/LCP came out seconds
   slower than the shop. Run with `-d zlib.output_compression=On`: documents
   and the text assets below are compressed by PHP; every other static file is
   served here too, with compression switched OFF for it — letting `php -S`
   serve it after PHP had already promised gzip produced
   ERR_INVALID_HTTP_RESPONSE for every picture and font. Range requests (video)
   go to the m1 router, also uncompressed. Identical for the BEFORE and AFTER
   trees, which is what makes the two columns comparable. */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
$file = $root.$path;

// A form post answers with a redirect whose headers PHP has already sized;
// compressing it closed the connection mid-login. Only GETs are timed.
// The admin is not timed either, and its 1 MB screen came back as a gzip
// header over a body Chrome refused (ERR_CONNECTION_CLOSED).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || str_starts_with($path, '/admin')) {
    ini_set('zlib.output_compression', '0');
}

if ($path === '/' || str_contains($path, '..') || ! is_file($file)) {
    return require __DIR__.'/m1-router.php';
}

$text = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'json' => 'application/json', 'txt' => 'text/plain', 'html' => 'text/html'];
$bin = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif',
    'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ico' => 'image/x-icon', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'pdf' => 'application/pdf'];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

if (! isset($text[$ext])) {
    ini_set('zlib.output_compression', '0');
}

if (! empty($_SERVER['HTTP_RANGE'])) {
    return require __DIR__.'/m1-router.php';
}

header('Content-Type: '.($text[$ext] ?? $bin[$ext] ?? 'application/octet-stream'));
header('Cache-Control: public, max-age=31536000, immutable');

if (! isset($text[$ext])) {
    header('Content-Length: '.filesize($file));
}

readfile($file);

return true;

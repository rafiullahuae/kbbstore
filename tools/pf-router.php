<?php
/* php -S router for the Lane PF Lighthouse runs: the m1 router, plus gzip on
   the text assets. `php -S` serves a static file uncompressed, so a 225 KB
   stylesheet that the live box (nginx, gzip) sends as 39 KB was being timed
   by Lighthouse's simulated 4G at full size, and every FCP/LCP number came out
   seconds slower than the shop. With `-d zlib.output_compression=On` the
   documents are compressed by PHP; this serves .css/.js/.svg/.json through PHP
   as well so they are compressed the same way. Identical for the BEFORE and
   AFTER trees, which is what makes the two columns comparable. */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
$file = $root.$path;
$types = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'json' => 'application/json'];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

if ($path !== '/' && ! str_contains($path, '..') && is_file($file) && isset($types[$ext]) && empty($_SERVER['HTTP_RANGE'])) {
    header('Content-Type: '.$types[$ext]);
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($file);

    return true;
}

return require __DIR__.'/m1-router.php';

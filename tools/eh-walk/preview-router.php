<?php
/* Static files out of the app's public/, everything else to the front
   controller (tools/fy-card-walk/preview-router.php, with the path from the
   environment rather than a lane's worktree). */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$public = getenv('CO_APP').'/public';
$file = realpath($public.$uri);

if ($uri !== '/' && $file && str_starts_with($file, $public) && is_file($file)) {
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json',
              'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
              'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2'];
    header('Content-Type: '.($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($file);
    return;
}

require __DIR__.'/index.php';

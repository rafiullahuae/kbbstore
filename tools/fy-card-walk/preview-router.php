<?php
/* Fall-through router for php -S.
   `return false` cannot be used here: it makes the built-in server serve from
   its own document root, which is this scratchpad directory, not the app's
   public/. The files are read and sent explicitly instead. */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$public = '/home/user/kbb-wt/fy/public';
$file = realpath($public.$uri);

if ($uri !== '/' && $file && str_starts_with($file, $public) && is_file($file)) {
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json',
              'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
              'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: '.($types[$ext] ?? 'application/octet-stream'));
    readfile($file);
    return;
}

require __DIR__.'/index.php';

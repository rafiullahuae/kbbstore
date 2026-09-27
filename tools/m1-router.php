<?php
/* php -S router for the Lane M1 preview: serve real files, hand everything else
   to the front controller. Without it `php -S` gives every /uploads/ request to
   index.php and a PNG comes back as text/html — which looks exactly like a
   broken image in a screenshot and is only the preview. */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__).$path;

if ($path !== '/' && ! str_contains($path, '..') && is_file($file)) {
    return false;
}

require ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__).'/index.php';

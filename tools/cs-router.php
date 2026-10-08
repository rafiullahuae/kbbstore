<?php

declare(strict_types=1);

/*
 * Lane CS: `php -S` front controller for tools/cs-preview.sh. Real files are
 * served as files; everything else goes to the front controller. The browser
 * reaches extrabeauty.ae and kbeautybliss.com through Chromium's
 * --host-resolver-rules over plain http on the preview's port, so the shop
 * sees the real host names. Nothing in app/ knows this file exists.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$docroot = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;

if ($path !== '/' && ! str_contains($path, '..') && is_file($docroot.$path)) {
    return false;
}

require $docroot.'/index.php';

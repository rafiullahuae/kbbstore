<?php

declare(strict_types=1);

/*
 * The php -S router for every browser harness in this directory.
 *
 * The recipe docs/GB-MEDIA-AND-REDIRECTS.md §12 documents names a `router.php`
 * that each lane was recreating by hand at the repository root; this is that
 * file, kept once and beside the scripts that need it.
 *
 * TWO THINGS IT HAS TO GET RIGHT.
 *
 * 1. THE WEB ROOT IS NOT THE APPLICATION ROOT. `bootstrap/app.php` ends in
 *    usePublicPath(), so public_path() is `public-web-root/`, not `public/`.
 *    Serve the built assets from there -- `cp -al public/build
 *    public-web-root/build` -- or every page renders with no CSS and every
 *    measurement is of an unstyled document.
 *
 * 2. THE ROOT IS RESOLVED FROM THE REPOSITORY, NOT FROM __DIR__. This file
 *    lives two levels down, so `__DIR__ . '/public-web-root'` is
 *    tests/browser/public-web-root and every request 500s on a missing
 *    index.php. dirname(__DIR__, 2) is the checkout.
 *
 * Run from the repository root:
 *
 *   KBB_PUBLIC_PATH=$PWD/public-web-root SESSION_DRIVER=file \
 *     DB_DATABASE=/tmp/preview.sqlite APP_URL=http://127.0.0.1:8912 \
 *     php -S 127.0.0.1:8912 -t public-web-root tests/browser/preview-router.php &
 */

$root = dirname(__DIR__, 2) . '/public-web-root';
$uri = urldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = $root . $uri;

// A real file on disk is served by the built-in server itself.
if ($uri !== '/' && is_file($file)) {
    return false;
}

require_once $root . '/index.php';

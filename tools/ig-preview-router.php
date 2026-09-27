<?php
/*
 * Preview router for `php -S`, so this repo can be browsed locally. Lane IG.
 *
 * ── WHY THIS EXISTS AT ALL ──────────────────────────────────────────────────
 *
 * `php artisan serve` does NOT work in this checkout and the failure is
 * confusing: bootstrap/app.php ends in usePublicPath() naming a directory on one
 * server in the world (CLAUDE.md says leave that line alone), and artisan serve
 * requires <that path>/index.php — so every request dies with "Failed opening
 * required '/home/u815237650/.../index.php'". The repo does not track a
 * public/index.php either; the real front controller is public-web-root/index.php,
 * which install.php places on the customer's hosting.
 *
 * So: serve public/ with PHP's own server, hand anything that is not a real file
 * to ig-preview-front.php, and set KBB_PUBLIC_PATH so usePublicPath() points here.
 *
 *   KBB_PUBLIC_PATH=$PWD/public APP_ENV=igpreview \
 *     php -S 127.0.0.1:8951 -t public tools/ig-preview-router.php
 *
 * Screenshots and local browsing only. Nothing here is shipped in a package.
 */
$root = dirname(__DIR__).'/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = $root.$path;
if ($path !== '/' && is_file($file)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__.'/ig-preview-front.php';

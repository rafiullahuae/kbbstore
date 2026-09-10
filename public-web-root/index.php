<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|------------------------------------------------------------------------------
| Front controller for easywebsol.com/kbb-upgrade/
|------------------------------------------------------------------------------
|
| The Laravel application lives outside the web root, so this file has to point
| back at it. Rather than hard-coding a path that depends on how your hosting
| panel roots the domain, it checks the usual places and uses the first one that
| is actually there. If none match it says so plainly instead of showing a blank
| white page, which is the single most confusing failure on shared hosting.
|
*/

$candidates = [
    __DIR__.'/..',                              // app is the parent folder
    __DIR__.'/../../kbb-upgrade-app',           // app beside public_html
    __DIR__.'/../kbb-upgrade-app',              // app inside public_html
    __DIR__.'/../../../kbb-upgrade-app',        // one level deeper
    __DIR__.'/../laravel-app',
    __DIR__.'/../../laravel-app',
];

$base = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate.'/bootstrap/app.php') && is_file($candidate.'/vendor/autoload.php')) {
        $base = $candidate;
        break;
    }
}

if ($base === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "KBB: could not find the Laravel application.\n\n";
    echo "Looked for bootstrap/app.php AND vendor/autoload.php in:\n";
    foreach ($candidates as $candidate) {
        $real = realpath($candidate) ?: $candidate;
        $hasBootstrap = is_file($candidate.'/bootstrap/app.php') ? 'yes' : 'no ';
        $hasVendor = is_file($candidate.'/vendor/autoload.php') ? 'yes' : 'no ';
        echo "  bootstrap:$hasBootstrap  vendor:$hasVendor   $real\n";
    }
    echo "\nIf 'vendor:no' everywhere, run run-composer.php first.\n";
    echo "If none of these paths are right, send this page to Claude.\n";
    exit;
}

// Maintenance mode...
if (file_exists($maintenance = $base.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $base.'/vendor/autoload.php';

(require_once $base.'/bootstrap/app.php')
    ->handleRequest(Request::capture());

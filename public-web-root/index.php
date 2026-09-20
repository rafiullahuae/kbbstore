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
    /*
     * `kbb-app` as well as `kbb-upgrade-app`. install.php offers this shorter
     * name and the setup guide uses it, and a list that knew one name and not
     * the other produced the worst failure this file can have: the installer
     * found the application, migrated 355 tables, created the owner account and
     * said "your shop is ready" -- and then every page was this error, because
     * the front controller was looking somewhere else. Found by installing into
     * a folder called kbb-app and then opening the site.
     */
    __DIR__.'/../../kbb-app',
    __DIR__.'/../kbb-app',
    __DIR__.'/../../../kbb-app',
    /*
     * Cloudways, and any host that gives you a `private_html` beside the web
     * root. It is the ONLY writable folder there that is not served -- the
     * application directory itself is owned by root, so the app physically
     * cannot live beside public_html the way it does on cPanel. Found by
     * installing on one: git refused with "could not create work tree dir:
     * Permission denied", and `private_html` was the answer sitting next to it.
     *
     * Both spellings, because the repo can be cloned into private_html itself
     * or into a kbb-app folder inside it.
     */
    __DIR__.'/../private_html',
    __DIR__.'/../private_html/kbb-app',
    __DIR__.'/../laravel-app',
    __DIR__.'/../../laravel-app',
];

$base = null;

/*
 * WHAT THE INSTALLER RECORDED, BEFORE ANY GUESSING.
 *
 * Guessing from a candidate list is a fallback, not a design: it works for the
 * folder names somebody thought of, and it is silent and total when the owner
 * picks a different one. install.php knows exactly where the application is --
 * it just finished installing into it -- so it writes that path here and this
 * file trusts it ahead of the list.
 *
 * Still verified rather than believed: a path whose bootstrap/app.php or
 * vendor/ has since moved falls through to the candidates below, so a stale
 * file degrades to today's behaviour instead of breaking the site.
 */
if (is_file(__DIR__.'/kbb-app-path.php')) {
    $recorded = @include __DIR__.'/kbb-app-path.php';

    if (is_string($recorded) && $recorded !== ''
        && is_file($recorded.'/bootstrap/app.php')
        && is_file($recorded.'/vendor/autoload.php')) {
        $base = $recorded;
    }
}

if ($base === null) {
    foreach ($candidates as $candidate) {
        if (is_file($candidate.'/bootstrap/app.php') && is_file($candidate.'/vendor/autoload.php')) {
            $base = $candidate;
            break;
        }
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

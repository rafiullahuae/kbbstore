<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the admin nav dispatch fix — Lane DF.
 *
 * ONE BLADE FILE CHANGED, and it is the console: admin/app.blade.php. Three
 * edits, all of them about an id that is routable but has no renderer:
 *
 *   - renderPlaceholder()'s map is lifted out of it as `const PLACEHOLDERS`,
 *     so the deep-link boot can ask whether a `p-` address names a real card
 *     before it follows one. Asking the MAP and not the prefix is the point:
 *     ?go=p-anything with a prefix test reaches renderPlaceholder with nothing
 *     to draw and throws during start-up. Measured in Chromium — #content was
 *     left completely empty and the page reported "Cannot read properties of
 *     undefined (reading '0')".
 *   - `const LATE_RENDERED` names the ids go() answers with the DASHBOARD,
 *     because its dispatch object has no entry for them and ends
 *     `||renderDash`: 'media' and 'tax'.
 *   - the deep-link boot admits those ids and replays for them.
 *
 * WITHOUT THIS THE PACKAGE LANDS AND EVERY ONE OF THOSE LINKS STILL FAILS, and
 * fails in the way that is hardest to report. The server renders Blade from
 * storage/framework/views, so the old compiled console goes on being served
 * from a file that is no longer on disk. The owner follows the link he was told
 * is fixed, and /admin?go=media opens a page headed "Content · Media Library"
 * whose body is the dashboard — the right heading over the wrong screen, with
 * no error anywhere on it. There is nothing on that page for him to send back,
 * which is why this is worse than the "could not be loaded" card the previous
 * package removed.
 *
 * The console is ONE compiled view: app.blade.php pulls its partials in with
 * @include, so the parent's cached compile carries all of them. This change is
 * in app.blade.php itself, so the parent is what has to go; the views are
 * cleared wholesale anyway.
 *
 * NO ROUTE CHANGED, NO SCHEMA CHANGED, NO ROW WRITTEN. Nothing here adds an
 * endpoint. The Media Library and Business Details screens were already
 * rendering and already fetching from endpoints that are already registered;
 * what was missing was the second call to go(). The route and package caches
 * are dropped with the views because the cost is nil and a half-cleared cache
 * is harder to reason about than an empty one.
 *
 * OPcache is dropped for the same reason and buys little here on its own: no
 * PHP class changed in this package. It costs one call and removes the question.
 *
 * Nothing here positions a column with an AFTER clause, the thing that made
 * nine earlier migrations silent no-ops on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the admin deep-link boot — Lane DA.
 *
 * ONE BLADE FILE CHANGED, and it is the console: admin/app.blade.php. The
 * block that reads `?go=` / `#` a few lines after buildNav() now queues one
 * conditional replay of that navigation for after the document is parsed, so
 * that the sixteen screens drawn by a LATER window.go wrapper are reachable by
 * a link and not only by a click on the sidebar.
 *
 * WITHOUT THIS THE PACKAGE LANDS AND EVERY ONE OF THOSE LINKS STILL FAILS. The
 * server renders Blade from storage/framework/views, so the old compiled
 * console goes on being served from a file that is no longer on disk — and the
 * symptom is the exact symptom this package exists to remove. The owner opens
 * the link he was told is fixed and reads "could not be loaded — the admin
 * script did not finish starting up. Reload the page." again, which is the
 * message most likely of all of them to be reported as "the update did not
 * work".
 *
 * The console is ONE compiled view: app.blade.php pulls its seventeen partials
 * in with @include, so the parent's cached compile carries all of them. This
 * change is in app.blade.php itself, so the parent is what has to go; the views
 * are cleared wholesale anyway.
 *
 * NO ROUTE CHANGED, NO SCHEMA CHANGED, NO ROW WRITTEN. Nothing here adds an
 * endpoint — every screen the replay now reaches was already rendering, and
 * already fetching from endpoints that are already registered. The only thing
 * that was missing was the second call to go(). The route and package caches
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

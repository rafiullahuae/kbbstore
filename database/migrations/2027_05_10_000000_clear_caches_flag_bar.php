<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Clear the compiled caches for the flag bar. (Lane FB)
 *
 * ── WHAT IS STALE IN THIS PACKAGE ─────────────────────────────────────────
 *
 * No route file. Views and config:
 *
 *   A NEW BLADE, and the layout that includes it. resources/views/partials/
 *   flag-bar.blade.php is new and layouts/store.blade.php now has an @include
 *   for it. On this host the compiled views outlive the files they came from,
 *   and a cached copy of the layout is a copy with no strip in it — which is
 *   exactly the "applied the package and nothing happened" report.
 *
 *   CHANGED STYLESHEET NAMES. kbb.css gained the strip's rules, so the Vite
 *   bundle's content hash moved and the manifest in this package names a file
 *   a cached config may not know about.
 *
 * ▲ AND `migrations` IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT ALL.
 *   UpdateRunner::hasMigrations() reads that flag and never looks at the files.
 *   Build the package with `php artisan kbb:package <version> --since=<ref>`.
 *
 * The guard wraps a call that writes no model state, so nothing is left dirty
 * for a later save to re-send — the distinction CLAUDE.md's swallowed-exception
 * landmine turns on.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['view:clear', 'config:clear', 'route:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable) {
                // A cache that cannot be cleared is one the next deploy clears.
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: this migration has no schema and no data.
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Clear the compiled caches for 2.60.324.
 *
 * ── WHAT IS STALE IN THIS PACKAGE, AND IT IS NOT A ROUTE ──────────────────
 *
 * This one adds NO route file. Its staleness is entirely views and config:
 *
 *   CHANGED BLADES, and several are large. The Appearance -> Set screen is
 *   rebuilt whole, its preview document gains a direction and a locale, both
 *   checkout partials gain their Stripe locale options, the invoice's
 *   stylesheet is converted to logical properties, the cart squeeze's bloom
 *   gains its RTL twin, and admin/app.blade.php gains two button bindings. A
 *   cached compiled view is the OLD one, and on this host the compiled views
 *   outlive the files they came from.
 *
 *   CHANGED STYLESHEET NAMES. The Vite bundle's content hashes moved, so the
 *   manifest in this package names files a cached config may not know about.
 *
 * ▲ AND `migrations` IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT ALL.
 *   UpdateRunner::hasMigrations() reads that flag and never looks at the files,
 *   so a package carrying a migration without declaring it copies this to the
 *   server and never executes it -- five packages once shipped eight migrations
 *   exactly that way. The builder sets the flag from the presence of a file
 *   like this one, which is also why a package whose only change is a route
 *   line must ship one: 2.60.322 was built with `migrations: false` and
 *   rebuilt before it went anywhere.
 *
 * ── WHY EVERY CALL IS GUARDED, AND WHY THAT IS SAFE HERE ──────────────────
 *
 * A host with no cached views answers `view:clear` perfectly happily; a
 * read-only bootstrap/cache throws. A cache that cannot be cleared is one the
 * next deploy clears, and it is never a reason to fail an update and strand the
 * shop mid-package.
 *
 * The guard is safe in the sense CLAUDE.md's swallowed-exception landmine is
 * about: it wraps a call that writes NO model state, so there is nothing left
 * dirty for a later save to re-send. A guarded write that leaves state behind
 * does not contain a failure, it seeds one; this leaves none.
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

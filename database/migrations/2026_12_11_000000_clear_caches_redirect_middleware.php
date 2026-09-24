<?php

declare(strict_types=1);

use App\Http\Middleware\CheckRedirects;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the redirect table becoming readable at all.
 *
 * ── WHY THIS ONE IS NOT OPTIONAL ─────────────────────────────────────────
 *
 * NO NEW ROUTES, and this migration still has to run, which is the opposite of
 * the usual reason. `php artisan route:cache` compiles the ROUTER'S MIDDLEWARE
 * STACK into bootstrap/cache/routes-*.php alongside the routes themselves, and
 * `config:cache` freezes the rest of the boot. A package that adds a global
 * middleware to a host carrying either one adds it to a file nothing reads: the
 * code lands, the class is never called, and the symptom is that the change
 * "did not apply" with nothing in any log to say so. warm_caches_2_60_4 writes
 * both files from inside the migration set, so any host that has ever run the
 * set has them.
 *
 * ── WHAT CHANGED ─────────────────────────────────────────────────────────
 *
 * `App\Http\Middleware\CheckRedirects` is registered as global middleware, from
 * `AppServiceProvider::boot()` (which ships) and from `bootstrap/app.php`
 * (which does not, and is left carrying it anyway for a host that was hand
 * edited). It has been written as middleware since the day it was created and
 * was registered as one nowhere, so the only live reader of the `redirects`
 * table was the 404 handler — which means:
 *
 *     A REDIRECT ROW FOR AN ADDRESS THE SHOP ALREADY ANSWERS COULD NOT FIRE.
 *
 * Measured against a running server, not inferred: rows pointing `/shop/`, a
 * category archive and a product address at `/PROOF-INERT/`, all enabled and
 * all matching `getPathInfo()` byte for byte, left all three answering exactly
 * as before. The old shop has five years of URLs and every one that collides
 * with a slug this application serves kept serving the wrong page.
 *
 * ── AND THE INDEX ────────────────────────────────────────────────────────
 *
 * CheckRedirects answers "no redirect for this path" out of a cached set of
 * `redirects.source` values rather than a query, which is what keeps it off
 * StorefrontQueryBudgetTest's books — a storefront page costs ZERO queries
 * against `redirects`. Every write to the table drops that set through
 * `Redirect::booted()`, but a mass delete through the query builder fires no
 * model events, so the key is forgotten here as well: applying a package can
 * never leave a stale index behind.
 *
 * NO SCHEMA CHANGE AND NO SETTING ROWS. A shop with no redirect row for an
 * address it serves sees nothing move.
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

        // Best-effort, exactly as CheckRedirects::flushIndex() is: on a fresh
        // install this runs before the cache store has a table of its own, and
        // an index that cannot yet exist must not fail the migration set.
        try {
            CheckRedirects::flushIndex();
        } catch (\Throwable $e) {
            // Nothing to drop.
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files; the redirects table is now read before the\n"
                ."router instead of only from the 404 handler, so a row for an address the shop\n"
                ."already answers can finally fire.\n";
        }
    }

    public function down(): void {}
};

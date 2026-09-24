<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for /sitemap.xml, /robots.txt and /llms.txt leaving the
 * stateful half of the `web` middleware group.
 *
 * ── WHY THIS ONE HAS TO RUN ──────────────────────────────────────────────
 *
 * NO NEW ROUTES — the three URIs are exactly the three URIs they were. What
 * changes is the MIDDLEWARE STACK behind them, and `php artisan route:cache`
 * compiles that stack into bootstrap/cache/routes-*.php along with the routes
 * themselves. On a host carrying a compiled route file, the edit to
 * routes/web.php lands on disk and the router never reads it: the three files
 * keep running StartSession, keep leaving with a Set-Cookie, and
 * Store\SeoFilesController keeps declining to mark them cacheable — which is
 * the correct, safe behaviour and therefore the SILENT one. The symptom is
 * "the change did nothing", with nothing in any log to say why.
 *
 * warm_caches_2_60_4 writes both compiled files from inside the migration set,
 * so any host that has ever run the set has them. Same shape, same reason, as
 * 2026_12_11_000000_clear_caches_redirect_middleware.
 *
 * ── WHAT CHANGED, AND WHAT A SHOP SEES ───────────────────────────────────
 *
 * The two halves are described in full on Store\SeoFilesController::STATELESS.
 * The short version: those three documents are the same bytes for every
 * visitor, they were the most-refetched URLs on the site, and they carried no
 * Cache-Control because a `public` response leaving the `web` group carries a
 * session cookie with it — which is a shared-cache hazard, not a saving.
 *
 * NO SCHEMA CHANGE AND NO SETTING ROWS. And nothing moves on a host where the
 * routes/web.php line has not been applied: the controller's guard is
 * `$request->hasSession()`, so with the stateful middleware still in place the
 * three files leave with byte-for-byte the headers they leave with today. This
 * migration is safe to apply before that line and safe to apply after it.
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
            echo "Cleared {$cleared} compiled files; /sitemap.xml, /robots.txt and /llms.txt can\n"
                ."now be served without a session, and answer `public, max-age=3600` once they\n"
                ."are. Until the routes/web.php line is applied they are unchanged.\n";
        }
    }

    public function down(): void {}
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the ask bucket becoming answerable.
 *
 * ── THE ROUTE IS NEW EVEN THOUGH THE FILE IS NOT ───────────────────────────
 *
 * `routes/urls-media-admin.php` has been required from `routes/web.php` since
 * Lane GB shipped it, so it is tempting to read "no new route file" as "no
 * cache to clear". That is the trap CLAUDE.md names: `php artisan route:cache`
 * compiles THE ROUTES IT FOUND AT THE TIME into
 * `bootstrap/cache/routes-*.php`, and `warm_caches_2_60_4` writes that file
 * from inside the migration set, so any host that has ever run the set has one.
 *
 * `POST /admin-api/urls-media/decisions` is a route that file did not carry
 * when the cache was compiled. Without this migration the code lands, the
 * controller method exists, and the endpoint answers 404 — with nothing in any
 * log saying why, on a host with no shell to run `route:clear` on.
 *
 * ── AND THE BLADE VIEW ──────────────────────────────────────────────────────
 *
 * `resources/views/admin/app.blade.php` carries the screen that calls it.
 * Compiled views live in `storage/framework/views` and are keyed by the source
 * path, not by its contents on some hosts; clearing them is what makes the new
 * panel appear rather than the previous render of the old one.
 *
 * ── WHAT MOVES ON THE SHOP ──────────────────────────────────────────────────
 *
 * Nothing. `redirect_decisions` is created empty by the migration beside this
 * one, an empty table means no answers, and no answers means
 * `RedirectMap::propose()` returns exactly the three buckets it returned
 * before. The screen gains a panel; the storefront is byte-identical until
 * somebody presses a button on it.
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
            echo "Cleared {$cleared} compiled files; Store -> Import -> Addresses & pictures can now\n"
                ."record an answer to the redirect map's questions, one row or a whole question at a\n"
                ."time. Nothing on the storefront moves until an answer is given.\n";
        }
    }

    public function down(): void {}
};

<?php

declare(strict_types=1);

use App\Http\Middleware\CheckRedirects;
use App\Support\LocaleSlugs;
use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Arabic-slug work and the merchant returns
 * category.
 *
 * ── WHY THIS ONE IS NOT OPTIONAL, THOUGH IT ADDS NO ROUTE ───────────────────
 *
 * `App\Http\Middleware\ResolveLocaleSlugs` is new GLOBAL middleware, and
 * `php artisan route:cache` compiles the router's middleware stack into
 * bootstrap/cache/routes-*.php alongside the routes. A package that adds global
 * middleware to a host carrying that file adds it to a file nothing reads: the
 * code lands, the class is never called, and the symptom is that the change "did
 * not apply" with nothing in any log to say so. That is the argument
 * 2026_12_11_000000_clear_caches_redirect_middleware.php makes for the same
 * reason, and warm_caches_2_60_4 is why every host has the file.
 *
 * `App\Support\Seo` also changed on the canonical and hreflang path, which the
 * compiled Blade views reach through layouts/store.blade.php, so the view cache
 * goes too.
 *
 * ── AND THE TWO NEW CACHE ENTRIES ───────────────────────────────────────────
 *
 * `kbb.locale_slugs.v1` and the redirect source index. Neither can hold anything
 * on a host applying this for the first time — the table is being created in the
 * migration beside this one — but a host that has applied a build of this work
 * before must not keep an index that predates `redirects.locale`.
 *
 * NOTHING MOVES. `seo_arabic_slugs` is not seeded, so the policy reads `shared`;
 * `merchant_returns` is not seeded, so the returns markup is decided by the days
 * box exactly as before; `redirects.locale` is NULL on every existing row, which
 * means "both languages", which is what every row already meant.
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
        // install this runs before the cache store has a table of its own.
        try {
            CheckRedirects::flushIndex();
            LocaleSlugs::flush();
        } catch (\Throwable $e) {
            // Nothing to drop.
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files. Arabic addresses can now be a second slug\n"
                ."per row instead of the shared one, and a shop that does not accept returns can\n"
                ."say so in its markup. Both ship OFF: the Arabic policy reads 'shared', which is\n"
                ."what this shop does today, and the returns box is untouched until somebody\n"
                ."chooses an answer.\n";
        }
    }

    public function down(): void {}
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches and the two runtime caches that would otherwise hide
 * the menu-URL repair — Lane DS.
 *
 * NO NEW ROUTES. The route cache is cleared anyway, as a precaution and not as
 * the point of the file, for the reason CLAUDE.md gives about a host with no
 * shell. Two RUNTIME caches are the point, and without them the update lands
 * complete and a shopper sees no change at all:
 *
 *   kbb.nav.primary / kbb.nav.mobile / kbb.nav.footer
 *                       NavigationService caches the whole menu tree, URLs and
 *                       all, for five minutes per slot. 2026_11_07_000000
 *                       rewrites the rows and flushes these itself; this is the
 *                       belt to that migration's braces, and it also covers a
 *                       shop where the rewrite matched nothing because the
 *                       owner had already hand-edited a row.
 *
 *   kbb.home.routine    HomeController caches the six routine steps for fifteen
 *                       minutes. The cached array now carries a RESOLVED slug —
 *                       one that was checked against the categories table —
 *                       where before it carried whatever was hard-coded. Left
 *                       in place, the home page would go on publishing
 *                       /product-category/cleansing/ and
 *                       /product-category/moisturizers/, both of which 404,
 *                       for a quarter of an hour after the update.
 *
 *                       HomeController reads this defensively (it derives the
 *                       link OUTSIDE the cache, so a pre-update entry cannot
 *                       throw), so this forget is about correctness and not
 *                       about avoiding an error.
 *
 * COMPILED BLADE matters too: resources/views/store/home.blade.php now reads
 * $step['url'] instead of building the path from $step['slug']. A stale
 * compiled copy would go on building the old path from the new data — and the
 * new data no longer guarantees a `slug` at all, so on a shop with no matching
 * category that is an undefined-key error on the front page rather than a dead
 * link. The template and the controller have to take effect together.
 *
 * Files changed that need no cache clear beyond the OPcache reset below:
 *
 *   app/Support/LegacyCategoryUrls.php        new; the recognition list and the
 *                                             one safe transformation.
 *   app/Services/MenuDemo.php                 the demo-content fallback tree.
 *   app/Http/Controllers/Admin/
 *     MegaMenuApiController.php               the admin's "seed demo menu".
 *
 * OPcache: on a host with no shell the PHP a package writes is not the PHP the
 * server runs until OPcache lets go of the old copy — the standing reason
 * behind the withdrawn packages 2.60.102-.106 in CLAUDE.md.
 *
 * No schema changed. Nothing here positions a column with an AFTER clause.
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

        // Best-effort: a cache driver that is unreachable during the update
        // must not fail the package. The nav cache expires in five minutes and
        // the routine cache in fifteen regardless.
        foreach (['kbb.nav.primary', 'kbb.nav.mobile', 'kbb.nav.footer', 'kbb.home.routine'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files; flushed the menu and routine caches.\n";
        }
    }

    public function down(): void {}
};

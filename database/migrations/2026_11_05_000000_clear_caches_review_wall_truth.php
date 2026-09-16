<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the /reviews rebuild — Lane DM.
 *
 * WHAT THIS PACKAGE CHANGES.
 *
 *   resources/views/store/review-wall.blade.php   rewritten
 *   resources/views/partials/announcement.blade.php   comment only
 *   app/Http/Controllers/Store/PageController.php     reviewWall()
 *   app/Http/Controllers/Store/SeoFilesController.php sitemap entry
 *   app/Support/ReviewWall.php                        new
 *
 * WHY THE COMPILED VIEWS MUST GO. store/review-wall.blade.php was, until this
 * package, one `@verbatim` block: a static page with a JavaScript array of
 * twelve invented customers in it. Its compiled file in
 * storage/framework/views is that page, byte for byte, and Laravel serves a
 * compiled view whenever the source's mtime is not newer than the compile's.
 * On this host a package is unzipped rather than checked out, and an unzip that
 * preserves an archive timestamp older than the deploy can leave exactly that
 * condition true — which would mean the fabricated page going on being served
 * from cache after the package that removed it was applied. Everything else in
 * this file is the standing convention; this one is the specific risk.
 *
 * NO ROUTE IS ADDED. /reviews has been registered since the 2.60.41 baseline
 * and its target method is unchanged; only what the method does has changed.
 * The route cache is cleared with the rest anyway, because the cost is nil and
 * a half-cleared cache is the harder thing to reason about — the same reasoning
 * as 2026_10_27_000000_clear_caches_review_screens_design.
 *
 * OPCACHE is the other half, and it is load-bearing here. Three PHP files
 * change, one of them new. The host cannot be restarted or shelled into, so the
 * PHP a package writes is not the PHP the server runs until OPcache lets go of
 * the old copy: without this, App\Support\ReviewWall could be missing from the
 * cached class map while PageController is expected to call it. That is the
 * standing reason behind the withdrawn packages 2.60.102-.106 in CLAUDE.md.
 *
 * NO CACHE KEY IS EVICTED because this lane adds none. The page reads live --
 * see the header of App\Support\ReviewWall for why a sixth
 * Cache::forget('kbb.home.reviews') in a sixth file was not the right trade.
 *
 * No schema change, and nothing here positions a column with an AFTER clause,
 * the thing that made nine earlier migrations silent no-ops on MySQL.
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

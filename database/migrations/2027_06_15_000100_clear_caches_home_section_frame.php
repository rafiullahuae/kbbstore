<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the homepage-frame round.             (Lane BG)
 *
 * ── NO ROUTE CHANGED, AND THIS IS STILL NOT OPTIONAL ────────────────────────
 *
 * The convention in CLAUDE.md is about routes, and this round adds none. It is
 * here for the other half of what a `clear_caches_*` migration does, and for
 * one thing neither half covers:
 *
 *   1. `bootstrap/cache/config.php` and the compiled service files, because
 *      `banner_runs_edge_to_edge` writes `banner_sets` underneath every cache
 *      that reads it.
 *
 *   2. `kbb.home.rails` and `kbb.home.brands`, the homepage's own memos. The
 *      section classes are rendered into the page and the page's rails are
 *      cached; without this the banner keeps its corners until the TTL runs
 *      out, which looks exactly like a package that did not apply.
 *
 *   3. `kbb.settings`, because `homepage_sections` is read through it. The
 *      stored payload does not change in this release — the two new keys fall
 *      back to their defaults, which is the whole reason there is no data
 *      migration for them — but Setting::map() memoises the WHOLE TABLE, and a
 *      server whose cached copy predates the release is a server reading the
 *      old array through the new schema.
 *
 * Blade is the reason the view files go: store/home.blade.php did NOT change
 * this round, which sounds like a reason to leave the compiled views alone and
 * is not. The classes the sections carry come out of
 * App\Services\HomepageSections, and a compiled view is only a cache of the
 * TEMPLATE — but `storage/framework/views` also holds the compiled admin
 * console, which did change, and a stale copy of it is a Homepage screen with
 * no Section background control on it while the shop already has the setting.
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

        Cache::forget('kbb.settings');
        Cache::forget('kbb.modules');
        Cache::forget('kbb.module_settings');
        Cache::forget('kbb.home.rails');
        Cache::forget('kbb.home.brands');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files and five cached reads.\n";
        }
    }

    public function down(): void {}
};

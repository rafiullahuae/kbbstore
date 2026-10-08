<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/*
 * Lane AMP: the names decoded by 2027_10_10_090000 are held, as they were, in
 * every cache that keeps a name -- the home rails and blocks, the shop and
 * category sidebars, the menus, the search panel's opening lists, the
 * translation map, the merchant feed, the recommendation lists. Left alone the
 * owner would see "SKIN&amp;LAB" for up to an hour after applying the package
 * and reasonably conclude it was not fixed. Product cards need nothing: a card's
 * signature includes the name, so the first view after the change redraws it.
 *
 * Compiled views are cleared too: layouts/store.blade.php and
 * store/page.blade.php changed (the <title> reads the title as text).
 *
 * ▲ NAMED KEYS AND THE CLASSES' OWN FLUSHES, NEVER Cache::flush() -- the store
 *   holds sessions on some drivers. Guarded: a cache that will not clear costs
 *   minutes, an update that dies half-applied costs the updater.
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
            base_path('bootstrap/cache/events.php'),
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

        try {
            foreach ([
                'kbb.shop.cats', 'kbb.shop.brands', 'kbb.admin.cats', 'kbb.admin.brands', 'kbb.admin.search-index',
                'kbb.search.brandnames', 'kbb.search.starter.brands', 'kbb.search.starter.products',
                'kbb.nav.primary', 'kbb.nav.mobile', 'kbb.nav.footer', 'kbb.bt.cats',
            ] as $key) {
                Cache::forget($key);
            }

            foreach ([
                [\App\Http\Controllers\Store\HomeController::class, 'flushCache'],
                [\App\Http\Controllers\Store\ShopController::class, 'flushSidebarCache'],
                [\App\Support\ProductTabs::class, 'flush'],
                [\App\Support\Shortcodes::class, 'flush'],
                [\App\Support\CategoryTree::class, 'flushCaches'],
                [\App\Services\GridSections::class, 'flush'],
                [\App\Services\BuyTogetherPairs::class, 'forget'],
                [\App\Services\Seo\MerchantFeed::class, 'forget'],
                [\App\Services\Translation\TranslationStore::class, 'flush'],
            ] as [$class, $method]) {
                if (method_exists($class, $method)) {
                    $class::$method();
                }
            }

            // The recommendation lists of every product whose name was decoded.
            if (\Illuminate\Support\Facades\Schema::hasTable('entity_decode_undo')) {
                \Illuminate\Support\Facades\DB::table('entity_decode_undo')->where('table_name', 'products')
                    ->distinct()->pluck('row_id')->each(static fn ($id) => \App\Services\ProductRecs::forget((int) $id));
            }
        } catch (\Throwable) {
            // See above: rebuilt by the next request that needs it.
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files and the cached names.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};

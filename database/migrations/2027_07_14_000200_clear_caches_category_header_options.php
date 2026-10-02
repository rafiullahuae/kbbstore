<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane PY: the category title header on every category, with its options.
 *
 * TWO JOBS.
 *
 * 1. The compiled views: components/kbb-title-header, store/shop and the two
 *    admin partials changed, and a stale compiled copy would keep drawing the
 *    2.60.346 header. The usual sweep, as every clear_caches_* migration here.
 *
 * 2. TWO STORED SETTINGS ARE DELETED, SO THE DEFAULTS THE OWNER ASKED FOR ARE
 *    WHAT HIS SHOP ACTUALLY GETS -- the same move Lane PR made for
 *    `layout_load_mode`. Pressing Save on Appearance -> Site layout stores
 *    EVERY field on the screen, so a shop whose owner saved once after
 *    2.60.346 holds `layout_cat_header_align = center` and
 *    `layout_cat_header_text = light`, and a new default would never reach it.
 *
 *      layout_cat_header_align  he asked, in as many words: "by default make
 *                               the title name left side as before". The new
 *                               default is `start` (left in English, right in
 *                               Arabic); `left` is no longer an option at all.
 *      layout_cat_header_text   now has an `auto` (white on a picture, dark on
 *                               the light box), which is the only value that
 *                               reads on both. A stored `light` would put white
 *                               words on the pale box on every category.
 *
 *    Only those two. Every other stored header value -- heights, darkness,
 *    the switches -- is something he may have chosen and is left alone.
 */
return new class extends Migration
{
    private const RESET = ['layout_cat_header_align', 'layout_cat_header_text'];

    public function up(): void
    {
        // Guarded: a fresh install runs every migration in order and this one
        // must not be the reason a brand-new database fails to build.
        try {
            if (Schema::hasTable('settings')) {
                DB::table('settings')->whereIn('key', self::RESET)->delete();
            }
        } catch (\Throwable $e) {
            if (app()->runningInConsole()) {
                echo 'Category header: could not read the settings table ('.$e->getMessage()."); nothing to reset.\n";
            }
        }

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

        // Best effort: on a host with no cache table or a cold store this is a
        // no-op, and every one of these values is re-read from the database.
        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings', 'kbb.shop.cats', 'kbb.shop.brands'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
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

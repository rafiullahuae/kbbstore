<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane SEO: the Google Merchant Center feed, its admin screen, and the image
 * sitemap switched on.
 *
 * 1. CLEAR THE COMPILED CACHES. Two new route files (routes/merchant-feed.php,
 *    routes/merchant-feed-admin.php) and a new capability (`marketing.feed`):
 *    a cached route table would 404 both, and the cached role map would refuse
 *    the screen to everybody. Same list as every clear_caches_* migration.
 *
 * 2. "PRODUCT IMAGES IN SITEMAP" -> INCLUDED. A DEFAULT THE OWNER ASKED FOR.
 *    He sent the SEO checklist and said "check ... if not, then do the needful";
 *    the shop is moving onto kbeautybliss.com, whose Google Images index is the
 *    asset to protect, and <image:image> entries under each product are how the
 *    sitemap tells Google which pictures belong to which page. CLAUDE.md rule 1
 *    (30 September): what he asked for ships ON. A stored '0' is overwritten
 *    because the SEO screen posts every field on every save, so a '0' there is
 *    the old shipped default written back, not a decision. To undo: Store ->
 *    SEO & Meta -> Settings -> Sitemap & robots -> "Product images in sitemap"
 *    -> Not included.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            $now = now();
            $exists = DB::table('settings')->where('key', 'sitemap_images')->exists();

            if ($exists) {
                DB::table('settings')->where('key', 'sitemap_images')->update(['value' => '1', 'updated_at' => $now]);
            } else {
                DB::table('settings')->insert(['key' => 'sitemap_images', 'value' => '1', 'created_at' => $now, 'updated_at' => $now]);
            }

            try {
                \App\Models\Setting::flushMap();
                \Illuminate\Support\Facades\Cache::forget('kbb.settings');
            } catch (\Throwable) {
            }
        }

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

        try {
            \Illuminate\Support\Facades\Cache::forget(\App\Support\AdminRoles::CACHE_KEY);
        } catch (\Throwable) {
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

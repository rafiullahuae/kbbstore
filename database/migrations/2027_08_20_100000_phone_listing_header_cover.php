<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 2.60.393 — the phone category page, as the owner asked: "the background
 * image on mobile should cover the whole area by middle and center, doesn't
 * matter what size the image is". The new default for Appearance → Site layout
 * → Category header → "Show the whole picture on phones" is OFF; a shop that
 * saved the old ON gets the new answer too (he asked). Its switch stays, to
 * put the whole picture back. Then the compiled views and the settings caches.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'layout_cat_header_phone_whole')->update(['value' => '0']);

        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        foreach (['kbb.settings', 'kbb.settings.map'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
            }
        }

        if (app()->runningInConsole()) {
            echo "Phone category pages: no Filters button, 1 / 2 column buttons, the header picture covers the header.\n";
        }
    }

    public function down(): void {}
};

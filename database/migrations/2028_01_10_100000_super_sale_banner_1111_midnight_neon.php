<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * 2.60.645 -- the owner, 6 November 2026: "give me the first 11.11 design
 * patch, that is urgent to update on the website".
 *
 * Puts the /super-sale/ banner on template m1 (11.11 Mega Sale · Midnight
 * Neon) with its words: title MEGA SALE / تخفيضات ميجا, the big number 11.11,
 * and NO line under the logos (the owner: "remove the line only one day, 11
 * november"). Everything else he set -- coupon, gifts, model photo, sizes --
 * stays exactly as it is.
 *
 * His previous words and template are kept in super_sale_banner_pre_1111 (only
 * the first time), so going back to Super Sale restores his own words.
 *
 * Literals only (ArabicShippableTest's rule since 2.60.512). Running it twice
 * changes nothing the second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('settings')) {
            return;
        }

        $row = DB::table('settings')->where('key', 'super_sale_banner')->first();
        $cfg = $row !== null ? json_decode((string) $row->value, true) : null;
        $cfg = is_array($cfg) ? $cfg : [];

        if (! DB::table('settings')->where('key', 'super_sale_banner_pre_1111')->exists()) {
            $keep = [];
            foreach (['template', 'title_en', 'title_ar', 'tagline_en', 'tagline_ar', 'num_en', 'num_ar'] as $k) {
                if (array_key_exists($k, $cfg)) {
                    $keep[$k] = $cfg[$k];
                }
            }
            DB::table('settings')->insert([
                'key' => 'super_sale_banner_pre_1111',
                'value' => json_encode($keep, JSON_UNESCAPED_UNICODE),
                'autoload' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $cfg = array_merge($cfg, [
            'template' => 'm1',
            'title_en' => 'MEGA SALE',
            'title_ar' => 'تخفيضات ميجا',
            'tagline_en' => '',
            'tagline_ar' => '',
            'num_en' => '11.11',
            'num_ar' => '11.11',
        ]);

        $value = json_encode($cfg, JSON_UNESCAPED_UNICODE);

        if ($row !== null) {
            DB::table('settings')->where('key', 'super_sale_banner')->update(['value' => $value, 'updated_at' => now()]);
        } else {
            DB::table('settings')->insert(['key' => 'super_sale_banner', 'value' => $value, 'autoload' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        // The settings map is cached (SettingsService::CACHE_KEY), and the
        // banner's PHP and view changed: clear both so the next page shows it.
        Cache::forget('kbb.settings');
        Cache::forget('kbb.settings.map');
        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $f) {
            @unlink($f);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    public function down(): void
    {
        $prev = DB::table('settings')->where('key', 'super_sale_banner_pre_1111')->value('value');
        $row = DB::table('settings')->where('key', 'super_sale_banner')->first();
        if ($row === null) {
            return;
        }
        $cfg = json_decode((string) $row->value, true);
        $cfg = is_array($cfg) ? $cfg : [];
        foreach (['template', 'title_en', 'title_ar', 'tagline_en', 'tagline_ar', 'num_en', 'num_ar'] as $k) {
            unset($cfg[$k]);
        }
        $keep = json_decode((string) $prev, true);
        $cfg = array_merge($cfg, is_array($keep) ? $keep : []);
        DB::table('settings')->where('key', 'super_sale_banner')->update(['value' => json_encode($cfg, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        Cache::forget('kbb.settings');
        Cache::forget('kbb.settings.map');
    }
};

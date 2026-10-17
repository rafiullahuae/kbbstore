<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 17 October 2026: the owner renamed a homepage section's heading to
 * "Recommended for you". /ar printed it in English under an Arabic line,
 * because its Arabic twin (`<key>_ar`, Appearance -> Homepage -> Homepage
 * content -> Arabic wording) was never written. This writes "مقترحة لكِ"
 * into that twin, for every homepage wording field whose stored English is
 * "Recommended for you" -- ONLY where the twin has no row, so Arabic he typed
 * is never touched. Literals and framework calls only.
 */
return new class extends Migration
{
    private const KEYS = [
        'home_ab_eyebrow', 'home_ab_title',
        'home_bs_eyebrow', 'home_bs_title', 'home_bs_sub', 'home_bs_btn',
        'home_br_eyebrow', 'home_br_title', 'home_br_sub', 'home_br_btn',
        'home_tr_eyebrow', 'home_tr_title', 'home_tr_sub', 'home_tr_btn',
        'home_cc_eyebrow', 'home_cc_title', 'home_cc_sub', 'home_cc_btn',
        'home_bl_eyebrow', 'home_bl_title', 'home_bl_sub', 'home_bl_more', 'home_bl_btn',
        'home_u54_eyebrow', 'home_u54_title', 'home_u54_sub', 'home_u54_btn',
        'home_ft_l_title', 'home_ft_l_text', 'home_ft_l_btn', 'home_ft_l_alt',
        'home_ft_r_title', 'home_ft_r_text', 'home_ft_r_btn', 'home_ft_r_alt',
        'home_hb_title', 'home_hb_sub', 'home_hb_btn_text',
        'spotted_home_title', 'spotted_home_sub', 'spotted_btn_text',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $twins = array_map(static fn (string $k): string => $k.'_ar', self::KEYS);
        $stored = DB::table('settings')->whereIn('key', array_merge(self::KEYS, $twins))->pluck('value', 'key')->all();
        $now = now();
        $written = 0;

        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $stored) || array_key_exists($key.'_ar', $stored)) {
                continue;
            }

            $english = html_entity_decode(strip_tags((string) $stored[$key]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $english))) !== 'recommended for you') {
                continue;
            }

            DB::table('settings')->insert([
                'key' => $key.'_ar', 'value' => 'مقترحة لكِ', 'autoload' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $written++;
        }

        if ($written > 0) {
            try {
                foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings'] as $cacheKey) {
                    Cache::forget($cacheKey);
                }
            } catch (\Throwable) {
                // A cache that cannot forget expires on its own.
            }
        }
    }

    public function down(): void {}
};

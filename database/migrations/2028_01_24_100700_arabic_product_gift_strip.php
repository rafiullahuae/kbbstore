<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane GS: the Arabic for the free gifts strip on the product page
 * (App\Services\ProductGiftStrip; the owner chose design KL, 13 Nov 2026).
 * Published, as Lane ARF published the rest -- the Arabic shop is Arabic by
 * default -- and shaped exactly as 2028_01_03_100050 (COD refusal).
 *
 * ONLY WHERE NO ROW EXISTS: an Arabic he typed himself is his. LITERALS ONLY
 * (the 2.60.512 rule): no App\ class, the updater may still run the old ones.
 * ProductGiftStripTest pins these constants to InterfaceStrings and the drafts.
 */
return new class extends Migration
{
    /** field => [English, Arabic] */
    private const ROWS = [
        'store.product.gift_strip_1' => ['Free gifts with every order', 'هدايا مجانية مع كل طلب'],
        'store.product.gift_strip_2' => ['Limited time offer. Don\'t miss out!', 'عرض لفترة محدودة. لا تفوّت الفرصة!'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $now = now();

        foreach (self::ROWS as $field => [$english, $arabic]) {
            $exists = DB::table('translations')
                ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                ->where('field', $field)->exists();

            if (! $exists) {
                DB::table('translations')->insert([
                    'locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => $field, 'value' => $arabic,
                    'status' => 'published', 'source' => 'manual', 'source_hash' => sha1($english),
                    'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        try {
            foreach (['kbb.translations.ar', 'kbb.translations.en'] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable) {
            // The loader re-reads on the next request either way.
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('translations')) {
            foreach (self::ROWS as $field => [, $arabic]) {
                DB::table('translations')
                    ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                    ->where('field', $field)->where('value', $arabic)
                    ->delete();
            }
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane CODR: the Arabic for the sentence a refuser reads on Place order,
 * "Cash on delivery isn't available for this order. Please choose another
 * payment method." (store.wording.msg_cod_unavailable, App\Support\ArabicWording).
 * Published, as Lane ARF published the rest -- the Arabic shop is Arabic by
 * default. Shaped exactly as 2027_11_09_200000 (the Tabby title).
 *
 * ONLY WHERE NO ROW EXISTS: an Arabic he typed himself is his. LITERALS ONLY
 * (the 2.60.512 rule): no App\ class, the updater may still run the old ones.
 * CodRefusalsTest pins these two constants to ArabicWording.
 */
return new class extends Migration
{
    private const FIELD = 'store.wording.msg_cod_unavailable';

    private const ENGLISH = 'Cash on delivery isn\'t available for this order. Please choose another payment method.';

    private const ARABIC = 'الدفع عند الاستلام غير متاح لهذا الطلب. يُرجى اختيار طريقة دفع أخرى.';

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $exists = DB::table('translations')
            ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
            ->where('field', self::FIELD)->exists();

        if (! $exists) {
            $now = now();
            DB::table('translations')->insert([
                'locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => self::FIELD, 'value' => self::ARABIC,
                'status' => 'published', 'source' => 'manual', 'source_hash' => sha1(self::ENGLISH),
                'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
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
            DB::table('translations')
                ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                ->where('field', self::FIELD)->where('value', self::ARABIC)
                ->delete();
        }
    }
};

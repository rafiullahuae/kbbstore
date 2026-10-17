<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Arabic for the live shop's own Tabby title (17 October 2026).
 *
 * The owner: "you didn't translate the Tabby Installments for arabic version".
 * Payments -> Tabby carries the title "Tabby Installments", which matched none
 * of App\Support\ArabicWording's payment rows (they hold the shipped
 * "Tabby: Pay in 4 Installments"), so /ar/checkout listed it in English.
 * ArabicWording now has the pair (wording.pay_tabby_installments); this
 * publishes its Arabic, as Lane ARF published the rest -- the Arabic shop is
 * Arabic by default. Display only: the order record, the gateway and every
 * payment-lock test read the English untouched.
 *
 * ONLY WHERE NO ROW EXISTS: an Arabic he typed himself is his. LITERALS ONLY
 * (the 2.60.512 rule): no App\ class, the updater may still run the old ones.
 */
return new class extends Migration
{
    private const FIELD = 'store.wording.pay_tabby_installments';

    private const ENGLISH = 'Tabby Installments';

    private const ARABIC = 'تقسيط مع تابي';

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

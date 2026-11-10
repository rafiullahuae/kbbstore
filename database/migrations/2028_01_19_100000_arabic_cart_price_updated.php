<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane RP (order #56680): the Arabic for the three sentences a shopper reads
 * when a basket line is brought up to today's price -- the "Price updated from
 * ... to ..." note under the line, Place order's refusal when a price moved
 * under the page, and the link back to the new total (store.wording.*,
 * App\Support\ArabicWording). Published, as 2028_01_03_100050 (Lane CODR) is:
 * the Arabic shop is Arabic by default.
 *
 * ONLY WHERE NO ROW EXISTS: an Arabic he typed himself is his. LITERALS ONLY
 * (the 2.60.512 rule): no App\ class, the updater may still run the old ones.
 * CartPriceRefreshTest pins these rows to ArabicWording.
 */
return new class extends Migration
{
    /** field => [English, Arabic] */
    private const ROWS = [
        'store.wording.price_updated' => [
            'Price updated from :from to :to',
            'تم تحديث السعر من :from إلى :to',
        ],
        'store.wording.msg_prices_changed' => [
            'A price in your bag has changed since this page was opened, so nothing has been charged. Please check the new total before you pay.',
            'تغيّر سعر منتج في حقيبتكِ منذ فتح هذه الصفحة، لذلك لم يتم خصم أي مبلغ. يُرجى مراجعة الإجمالي الجديد قبل الدفع.',
        ],
        'store.wording.price_review_link' => [
            'See the new total',
            'عرض الإجمالي الجديد',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $existing = DB::table('translations')
            ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
            ->whereIn('field', array_keys(self::ROWS))
            ->pluck('field')->all();

        $now = now();
        $insert = [];

        foreach (self::ROWS as $field => [$english, $arabic]) {
            if (in_array($field, $existing, true)) {
                continue;
            }

            $insert[] = [
                'locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => $field, 'value' => $arabic,
                'status' => 'published', 'source' => 'manual', 'source_hash' => sha1($english),
                'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        if ($insert !== []) {
            DB::table('translations')->insert($insert);
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
        if (! Schema::hasTable('translations')) {
            return;
        }

        foreach (self::ROWS as $field => [, $arabic]) {
            DB::table('translations')
                ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                ->where('field', $field)->where('value', $arabic)
                ->delete();
        }
    }
};

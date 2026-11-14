<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane FBT -- the owner, 14 November 2026: "The buy these together section
 * should be named frequently bought together and it should show related
 * products [...] if it has a matching moisturizer or toner or eye cream those
 * should be shown".
 *
 *   1. THE HEADING. The default moved in the code (InterfaceStrings,
 *      ArabicInterfaceDrafts). The Arabic is a row of `translations`, so the
 *      row that still holds the OLD shipped Arabic is swapped for the new one
 *      and published; a row he wrote himself is not the old text and is left
 *      alone. No row at all: the new one is inserted, published. His own
 *      Heading boxes (bt_title / bt_title_ar) are swapped only when they hold
 *      the old standard heading word for word, which then empties them so the
 *      new standard heading shows.
 *
 *   2. "MATCHING PRODUCTS FIRST" (bt_same_brand) ON. Its default moved from
 *      off to on because he asked for matching products; a stored "off" --
 *      which the admin's Save writes for every untouched switch -- is moved
 *      once. The switch is on Appearance -> Product page -> Buy these together.
 *
 * LITERALS ONLY (the 2.60.512 rule): no App\ class. Running it twice changes
 * nothing the second time.
 */
return new class extends Migration
{
    private const FIELD = 'store.buy_together.heading';

    private const EN_OLD = 'Buy these together';

    private const EN_NEW = 'Frequently bought together';

    private const AR_OLD = 'اشترِها معًا';

    private const AR_NEW = 'يُشترى معًا عادةً';

    public function up(): void
    {
        try {
            $this->heading();
            $this->settings();
        } catch (\Throwable $e) {
            if (app()->runningInConsole()) {
                echo 'Frequently bought together: '.$e->getMessage()."\n";
            }
        }

        try {
            foreach (['kbb.translations.ar', 'kbb.translations.en', 'kbb.settings', 'kbb.settings.map'] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable) {
            // The loaders re-read on the next request either way.
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('translations')) {
            DB::table('translations')
                ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                ->where('field', self::FIELD)->where('value', self::AR_NEW)
                ->update(['value' => self::AR_OLD, 'source_hash' => sha1(self::EN_OLD), 'updated_at' => now()]);
        }
    }

    private function heading(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $now = now();
        $row = DB::table('translations')
            ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
            ->where('field', self::FIELD)->first();

        if ($row === null) {
            DB::table('translations')->insert([
                'locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => self::FIELD, 'value' => self::AR_NEW,
                'status' => 'published', 'source' => 'manual', 'source_hash' => sha1(self::EN_NEW),
                'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);

            return;
        }

        if ((string) $row->value === self::AR_OLD) {
            DB::table('translations')->where('id', $row->id)->update([
                'value' => self::AR_NEW, 'status' => 'published', 'source_hash' => sha1(self::EN_NEW),
                'reviewed_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function settings(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (['bt_title' => self::EN_OLD, 'bt_title_ar' => self::AR_OLD] as $key => $old) {
            DB::table('settings')->where('key', $key)->where('value', $old)
                ->update(['value' => '', 'updated_at' => now()]);
        }

        DB::table('settings')->where('key', 'bt_same_brand')->whereIn('value', ['', '0', 'false'])
            ->update(['value' => '1', 'updated_at' => now()]);
    }
};

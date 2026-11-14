<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane GS2: the product page's free gifts strip in one line (the owner,
 * 14 Nov 2026: "Free gifts with every order. Limited time only! use this text
 * in one line"). Its Arabic, published as 2028_01_24_100700 published the
 * first wording, and shaped exactly like it.
 *
 * ONLY WHERE THE OWNER HAS NOT EDITED IT: a row is inserted where none exists,
 * and a row is moved to the new Arabic only while it still holds the wording
 * Lane GS published. An Arabic he typed himself is his. LITERALS ONLY (the
 * 2.60.512 rule): no App\ class. ProductGiftStripTest pins these constants to
 * InterfaceStrings and the drafts. Safe to run again.
 */
return new class extends Migration
{
    /** field => [English, Arabic now, Arabic Lane GS published] */
    private const ROWS = [
        'store.product.gift_strip_1' => ['Free gifts with every order.', 'هدايا مجانية مع كل طلب.', 'هدايا مجانية مع كل طلب'],
        'store.product.gift_strip_2' => ['Limited time only!', 'لفترة محدودة فقط!', 'عرض لفترة محدودة. لا تفوّت الفرصة!'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $now = now();

        foreach (self::ROWS as $field => [$english, $arabic, $before]) {
            $row = DB::table('translations')
                ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                ->where('field', $field);

            if (! (clone $row)->exists()) {
                DB::table('translations')->insert([
                    'locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => $field, 'value' => $arabic,
                    'status' => 'published', 'source' => 'manual', 'source_hash' => sha1($english),
                    'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);

                continue;
            }

            (clone $row)->where('value', $before)->update([
                'value' => $arabic, 'source_hash' => sha1($english), 'updated_at' => $now,
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
            foreach (self::ROWS as $field => [, $arabic, $before]) {
                DB::table('translations')
                    ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                    ->where('field', $field)->where('value', $arabic)
                    ->update(['value' => $before]);
            }
        }
    }
};

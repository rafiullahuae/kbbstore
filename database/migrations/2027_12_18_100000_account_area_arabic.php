<?php

declare(strict_types=1);

use App\Services\Translation\AccountAreaStrings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * THE REDESIGNED ACCOUNT AREA IN ARABIC (Lane ACC, 2.60.597). Every new word
 * of the account bar, menu, dashboard, orders search, order steps and the
 * Profile & password page, published so /ar reads Arabic the day the package
 * lands. The same rows AccountAreaStrings::ROWS gives InterfaceStrings and
 * ArabicInterfaceDrafts, so the three cannot disagree.
 *
 * SAFE ON A SHOP THAT HAS MOVED ON: a key that already has an Arabic row (the
 * owner typed one, or an earlier run wrote it) is left exactly as it is.
 * Written published/manual with the English source's sha1, the shape the
 * translation screens write. The translation caches are forgotten afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $rows = [];

        foreach (AccountAreaStrings::ROWS as $key => [$english, $arabic]) {
            $rows['store.' . $key] = [$english, $arabic];
        }

        $have = DB::table('translations')->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
            ->whereIn('field', array_keys($rows))->pluck('field')->all();
        $now = now();
        $insert = [];

        foreach ($rows as $field => [$english, $arabic]) {
            if (in_array($field, $have, true)) {
                continue;
            }

            $insert[] = [
                'locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => $field, 'value' => $arabic,
                'status' => 'published', 'source' => 'manual', 'source_hash' => sha1($english),
                'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        foreach (array_chunk($insert, 50) as $chunk) {
            DB::table('translations')->insert($chunk);
        }

        try {
            foreach (['kbb.translations.ar', 'kbb.translations.en'] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable) {
            // A cache that cannot forget expires on its own.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        foreach (AccountAreaStrings::ROWS as $key => [, $arabic]) {
            DB::table('translations')->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                ->where('field', 'store.' . $key)->where('value', $arabic)->delete();
        }
    }
};

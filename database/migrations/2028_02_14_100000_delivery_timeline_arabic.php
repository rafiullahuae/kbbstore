<?php

declare(strict_types=1);

use App\Services\Translation\DeliveryTimelineStrings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * THE DELIVERY TIMELINE IN ARABIC (Quiqup P3). Every word of the road map,
 * the live view link and the two delivery push messages, published so /ar
 * reads Arabic the day the package lands. The same rows
 * DeliveryTimelineStrings::ROWS gives InterfaceStrings and
 * ArabicInterfaceDrafts, so the three cannot disagree.
 *
 * ONLY MISSING OR UNCHANGED ROWS. A key with no Arabic row is inserted
 * (published, manual, with the English source's sha1 -- the shape the
 * translation screens write). A row that exists but was never published (an
 * unreviewed draft) is replaced with this wording. A PUBLISHED row -- the
 * owner's own words, or an earlier run of this -- is left exactly as it is.
 * Safe to run again. The translation caches are forgotten afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $rows = [];

        foreach (DeliveryTimelineStrings::ROWS as $key => [$english, $arabic]) {
            $rows['store.' . $key] = [$english, $arabic];
        }

        $have = DB::table('translations')->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
            ->whereIn('field', array_keys($rows))->get(['id', 'field', 'status'])->keyBy('field');
        $now = now();
        $insert = [];

        foreach ($rows as $field => [$english, $arabic]) {
            $row = $have->get($field);

            if ($row === null) {
                $insert[] = [
                    'locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => $field, 'value' => $arabic,
                    'status' => 'published', 'source' => 'manual', 'source_hash' => sha1($english),
                    'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
            } elseif ($row->status !== 'published') {
                DB::table('translations')->where('id', $row->id)->update([
                    'value' => $arabic, 'status' => 'published', 'source' => 'manual', 'source_hash' => sha1($english),
                    'reviewed_at' => $now, 'updated_at' => $now,
                ]);
            }
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

        foreach (DeliveryTimelineStrings::ROWS as $key => [, $arabic]) {
            DB::table('translations')->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                ->where('field', 'store.' . $key)->where('value', $arabic)->delete();
        }
    }
};

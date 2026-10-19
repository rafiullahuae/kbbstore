<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane VPA: the colour/size picker's one new interface string, in Arabic,
 * PUBLISHED -- the Arabic shop is Arabic by default (2027_11_06_100000,
 * "i don't need anything in english in arabic version"). The draft sits in
 * ArabicInterfaceDrafts beside it, so Translation -> Strings shows and edits
 * it like every other. Written only where no Arabic row exists, so nothing
 * the owner typed is touched. Literals and framework calls only.
 *
 * The axis names ("Shade", "Size") need no row: App\Support\VariantOptions
 * prints الدرجة / الحجم / اللون for the common ones until the owner types his
 * own on Translation -> Content -> Attributes.
 */
return new class extends Migration
{
    private const ROWS = [
        'store.product.variant_sku' => ['SKU: :sku', 'رمز المنتج: :sku'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $have = DB::table('translations')->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
            ->whereIn('field', array_keys(self::ROWS))->pluck('field')->all();
        $now = now();
        $insert = [];

        foreach (self::ROWS as $field => [$english, $arabic]) {
            if (in_array($field, $have, true)) {
                continue;
            }

            $insert[] = [
                'locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => $field, 'value' => $arabic,
                'status' => 'published', 'source' => 'manual', 'source_hash' => sha1($english),
                'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        if ($insert === []) {
            return;
        }

        DB::table('translations')->insert($insert);

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

        foreach (self::ROWS as $field => [, $arabic]) {
            DB::table('translations')->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                ->where('field', $field)->where('value', $arabic)->delete();
        }
    }
};

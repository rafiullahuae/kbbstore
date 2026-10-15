<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane AR round 3 -- the hybrid search's Arabic text: one row per product,
 * its PUBLISHED Arabic name in the one spelling both sides are compared in.
 * App\Support\ArabicSearch reads it; Translation's saved/deleted hook keeps it
 * fresh after today.
 *
 * LITERALS AND FRAMEWORK APIs ONLY. The updater can run a migration while the
 * previous release's classes are still in opcache (2.60.512 broke live exactly
 * so), so nothing here calls a class of this shop: the normalisation below is
 * a copy of ArabicSearch::normalise(), and ArabicSearchTest holds the two
 * together. Re-running is harmless: the table is created once and the rows
 * are written with updateOrInsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('search_texts_ar')) {
            Schema::create('search_texts_ar', function (Blueprint $t) {
                $t->unsignedBigInteger('product_id')->primary();
                $t->text('text');
            });
        }

        if (Schema::hasTable('translations')) {
            $norm = static function (string $s): string {
                $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
                $s = strtr($s, [
                    'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
                    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                    '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                ]);
                $s = mb_strtolower($s);
                $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

                return trim((string) preg_replace('/\s+/u', ' ', $s));
            };

            DB::table('translations')
                ->where('locale', 'ar')
                ->where('group', 'products')
                ->where('field', 'name')
                ->where('status', 'published')
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($norm) {
                    foreach ($rows as $row) {
                        $text = $norm((string) $row->value);

                        if ($text !== '') {
                            DB::table('search_texts_ar')->updateOrInsert(['product_id' => (int) $row->item_id], ['text' => $text]);
                        }
                    }
                });
        }

        try {
            Cache::forget('kbb.search.ar.ready');
            Cache::forget('kbb.search.ar.v1');
        } catch (\Throwable) {
            // The cache is a convenience here; the next search rebuilds it.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_texts_ar');
    }
};

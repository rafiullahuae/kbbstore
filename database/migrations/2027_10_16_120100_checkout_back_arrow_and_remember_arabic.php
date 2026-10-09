<?php

declare(strict_types=1);

use App\Models\Translation;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane QK8, the checkout's Arabic.
 *
 * 1. The owner: "Remember my details line, please replace to > Remember my
 *    shipping details on this device". The English of
 *    store.checkout.remember_me moved; where its Arabic row holds EXACTLY the
 *    shipped wording (تذكّر بياناتي على هذا الجهاز) it becomes
 *    تذكّر تفاصيل الشحن على هذا الجهاز and keeps its status, so a published
 *    line stays published. A wording the owner typed himself is left alone.
 *    (The QK6 pattern, 2027_10_16_100000.)
 *
 * 2. The round back arrow beside the Checkout heading is read aloud as "Back
 *    to cart" (store.checkout.head_back); its Arabic is seeded as a draft to
 *    review, like every other new string.
 */
return new class extends Migration
{
    private const OLD_REMEMBER = 'تذكّر بياناتي على هذا الجهاز';

    private const NEW_KEYS = ['store.checkout.head_back'];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $drafts = ArabicInterfaceDrafts::all();
        $field = TranslationStore::normaliseKey('store.checkout.remember_me');
        $english = InterfaceStrings::english($field);

        DB::table('translations')
            ->where('locale', 'ar')
            ->where('group', Translation::GROUP_UI)
            ->where('item_id', 0)
            ->where('field', $field)
            ->where('value', self::OLD_REMEMBER)
            ->update([
                'value' => $drafts['store.checkout.remember_me'],
                'source_hash' => $english === null ? null : sha1($english),
                'updated_at' => now(),
            ]);

        $have = array_flip(DB::table('translations')
            ->where('locale', 'ar')
            ->where('group', Translation::GROUP_UI)
            ->where('item_id', 0)
            ->whereIn('field', array_map([TranslationStore::class, 'normaliseKey'], self::NEW_KEYS))
            ->pluck('field')
            ->all());

        $now = now();
        $insert = [];

        foreach (self::NEW_KEYS as $key) {
            $f = TranslationStore::normaliseKey($key);

            if (isset($have[$f]) || ! isset($drafts[$key])) {
                continue;
            }

            $en = InterfaceStrings::english($f);

            $insert[] = [
                'locale' => 'ar',
                'group' => Translation::GROUP_UI,
                'item_id' => 0,
                'field' => $f,
                'value' => $drafts[$key],
                'status' => Translation::STATUS_DRAFT,
                'source' => Translation::SOURCE_MACHINE,
                'source_hash' => $en === null ? null : sha1($en),
                'reviewed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($insert !== []) {
            DB::table('translations')->insert($insert);
        }

        try {
            TranslationStore::flush();
        } catch (\Throwable) {
            // A stale map over a cache is not worth an update dying half-applied.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        // Only the machine draft this migration could have written.
        DB::table('translations')
            ->where('locale', 'ar')
            ->where('group', Translation::GROUP_UI)
            ->where('item_id', 0)
            ->whereIn('field', array_map([TranslationStore::class, 'normaliseKey'], self::NEW_KEYS))
            ->where('status', Translation::STATUS_DRAFT)
            ->where('source', Translation::SOURCE_MACHINE)
            ->delete();
    }
};

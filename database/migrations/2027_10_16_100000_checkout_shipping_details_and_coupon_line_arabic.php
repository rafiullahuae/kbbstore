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
 * Lane QK6, the checkout's Arabic.
 *
 * 1. The owner: "Change Shipping address section heading to Shipping Details".
 *    The English of store.checkout.step_shipping moved; its Arabic row still
 *    says عنوان الشحن ("shipping address"). Where that row holds EXACTLY the
 *    shipped wording, it becomes تفاصيل الشحن ("shipping details") and keeps
 *    its status — a published heading stays published, so /ar/ follows the
 *    request. A wording the owner typed himself is left alone.
 *
 * 2. The coupon line's tappable code is read aloud as "Apply coupon GLOW";
 *    its Arabic is seeded as a draft to review, like every other new string.
 */
return new class extends Migration
{
    private const OLD_SHIPPING = 'عنوان الشحن';

    private const NEW_KEYS = ['store.checkout.cline_apply_label'];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $drafts = ArabicInterfaceDrafts::all();
        $field = TranslationStore::normaliseKey('store.checkout.step_shipping');
        $english = InterfaceStrings::english($field);

        DB::table('translations')
            ->where('locale', 'ar')
            ->where('group', Translation::GROUP_UI)
            ->where('item_id', 0)
            ->where('field', $field)
            ->where('value', self::OLD_SHIPPING)
            ->update([
                'value' => $drafts['store.checkout.step_shipping'],
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

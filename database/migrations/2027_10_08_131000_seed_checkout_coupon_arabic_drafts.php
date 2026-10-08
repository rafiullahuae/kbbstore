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
 * Lane CP: the Arabic drafts for the checkout's coupon line -- applied, removed,
 * the Remove button, the nine refusals CouponService can give
 * (store.checkout.coupon_*), and "too many tries" (store.js.coupon_slow_down).
 * DRAFTS, as every shipped Arabic string is: nothing reaches /ar until the owner
 * approves them in the translation console. Same body as
 * 2027_10_08_120200_seed_sold_out_dialog_arabic_drafts.
 */
return new class extends Migration
{
    /** The keys this migration is for, so it cannot drift into seeding the whole file. */
    private const KEYS = [
        'store.checkout.coupon_applied',
        'store.checkout.coupon_applied_plain',
        'store.checkout.coupon_removed',
        'store.checkout.coupon_remove',
        'store.checkout.coupon_err_invalid',
        'store.checkout.coupon_err_not_started',
        'store.checkout.coupon_err_expired',
        'store.checkout.coupon_err_used_up',
        'store.checkout.coupon_err_minimum',
        'store.checkout.coupon_err_maximum',
        'store.checkout.coupon_err_account',
        'store.checkout.coupon_err_already_used',
        'store.checkout.coupon_err_no_items',
        'store.js.coupon_slow_down',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $drafts = ArabicInterfaceDrafts::all();

        $existing = array_flip(array_map(
            static fn ($f): string => TranslationStore::normaliseKey((string) $f),
            DB::table('translations')
                ->where('locale', 'ar')
                ->where('group', Translation::GROUP_UI)
                ->where('item_id', 0)
                ->whereIn('field', self::KEYS)
                ->pluck('field')
                ->all()
        ));

        $now = now();
        $insert = [];

        foreach (self::KEYS as $key) {
            $field = TranslationStore::normaliseKey($key);

            if (isset($existing[$field]) || ! isset($drafts[$key])) {
                continue;
            }

            $english = InterfaceStrings::english($field);

            $insert[] = [
                'locale' => 'ar',
                'group' => Translation::GROUP_UI,
                'item_id' => 0,
                'field' => $field,
                'value' => $drafts[$key],
                'status' => Translation::STATUS_DRAFT,
                'source' => Translation::SOURCE_MACHINE,
                'source_hash' => $english === null ? null : sha1($english),
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
            // Drafts are not served, so a stale map here cannot even be wrong —
            // and an update that dies half-applied over a cache is the shape
            // CLAUDE.md records against UpdateRunner::recordManifest().
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        // Only rows this migration could have written: a draft from a machine.
        DB::table('translations')
            ->where('locale', 'ar')
            ->where('group', Translation::GROUP_UI)
            ->where('item_id', 0)
            ->whereIn('field', self::KEYS)
            ->where('status', Translation::STATUS_DRAFT)
            ->where('source', Translation::SOURCE_MACHINE)
            ->delete();
    }
};

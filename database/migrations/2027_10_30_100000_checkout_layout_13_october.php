<?php

declare(strict_types=1);

use App\Models\Translation;
use App\Services\CartPage;
use App\Services\CheckoutLayout;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 2.60.495, the 13 October checkout and cart layout.
 *
 * 1. Keeps the values this package overwrites (CheckoutLayout::snapshot(),
 *    once), so Appearance -> Checkout page -> 13 October layout -> "Put back
 *    as before" restores exactly what the owner had.
 * 2. Writes the cart's checkout button wording he chose ("Secure checkout").
 *    Every other change is a NEW switch whose shipped default is his choice,
 *    so nothing else is written.
 * 3. Adds the Arabic drafts for the new interface words.
 *
 * No table, no column, nothing about an order or a payment.
 */
return new class extends Migration
{
    private const NEW_KEYS = [
        'store.checkout.step_delivery_info',
        'store.checkout.cart_icon_label',
        'store.checkout.order_discount',
        'store.checkout.total_savings',
        'store.checkout.coupon_remove_label',
        'store.checkout.summary_toggle',
        'store.checkout.apple_pay',
    ];

    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            $layout = app(CheckoutLayout::class);
            $layout->snapshot();
            app(CartPage::class)->save(CheckoutLayout::CART_CHANGED);
        }

        if (! Schema::hasTable('translations')) {
            return;
        }

        $drafts = ArabicInterfaceDrafts::all();
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
        // Put the stored value back; the new switches simply stop being read.
        if (Schema::hasTable('settings')) {
            $raw = DB::table('settings')->where('key', CheckoutLayout::SNAPSHOT)->value('value');
            $kept = is_string($raw) ? json_decode($raw, true) : null;
            $before = is_array($kept) ? ($kept['co_label'] ?? null) : null;
            DB::table('settings')->updateOrInsert(['key' => 'cartpage_co_label'], ['value' => $before]);
        }
    }
};

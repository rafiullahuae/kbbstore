<?php

declare(strict_types=1);

use App\Services\CheckoutPage;
use App\Services\SettingsService;
use App\Services\WhatsAppButton;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane QK8. Three things the owner asked for on the checkout, written as
 * STORED choices so the shop is in his state the moment the package is
 * applied, whatever a schema default is later moved to (the QK7 pattern):
 *
 *   - "the payment methods boxes, please turn off the background color, and
 *     the border color should be grey ... upon selection also no background
 *     should come."  Appearance -> Checkout page -> Payment boxes ->
 *     Background tint OFF, Unselected border GREY.
 *   - "the icon i want beside left side of Checkout heading" (a round back
 *     arrow to the cart).  Appearance -> Checkout page -> Back to shop & cart
 *     -> Back arrow beside the Checkout heading ON.
 *   - "the whatsapp floating button should be turn off on cart and checkout
 *     completely."  Appearance -> WhatsApp button -> Design -> Show on the
 *     cart page OFF, Show on checkout OFF.
 *
 * A value already stored (the package applied twice, or the owner moved it
 * since) is left exactly as it is -- with two exceptions he asked for in so
 * many words:
 *
 *   - "and the top coupon line, turned off."  Appearance -> Checkout page ->
 *     Coupon line -> Coupon line OFF, whatever it was (QK6 shipped it on).
 *   - "in the Delivery heading, include Express word, so it will be Free
 *     express delivery over AED 199".  Appearance -> Checkout page ->
 *     Delivery labels -> Note beside "Delivery" (UAE only): moved to the new
 *     wording ONLY where it still holds the old shipped wording, English and
 *     Arabic each; a wording he typed himself is his.
 */
return new class extends Migration
{
    private const CHECKOUT = ['pay_bg' => false, 'pay_unsel' => 'grey', 'head_back' => true];

    private const WHATSAPP = ['show_cart' => false, 'show_checkout' => false];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        SettingsService::forgetMemo();

        $checkout = array_filter(self::CHECKOUT, static fn (string $key): bool => ! self::stored('checkoutpage_'.$key), ARRAY_FILTER_USE_KEY);

        if ($checkout !== []) {
            app(CheckoutPage::class)->save($checkout);
        }

        $whatsapp = array_filter(self::WHATSAPP, static fn (string $key): bool => ! self::stored('waf_'.$key), ARRAY_FILTER_USE_KEY);

        if ($whatsapp !== []) {
            app(WhatsAppButton::class)->save($whatsapp);
        }

        app(CheckoutPage::class)->save(['cline_on' => false]);

        foreach (['dl_note' => [CheckoutPage::DL_NOTE_OLD, CheckoutPage::DL_NOTE], 'dl_note_ar' => [CheckoutPage::DL_NOTE_AR_OLD, CheckoutPage::DL_NOTE_AR]] as $key => [$old, $new]) {
            if (DB::table('settings')->where('key', 'checkoutpage_'.$key)->value('value') === $old) {
                app(CheckoutPage::class)->save([$key => $new]);
            }
        }

        SettingsService::forgetMemo();
    }

    public function down(): void
    {
        //
    }

    private static function stored(string $key): bool
    {
        return DB::table('settings')->where('key', $key)->exists();
    }
};

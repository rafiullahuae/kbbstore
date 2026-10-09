<?php

declare(strict_types=1);

use App\Services\CheckoutPage;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane QK9. The owner, 9 October, on a phone screenshot of the live checkout
 * with the reviews card and the gap under it crossed out:
 *
 *   "remove the rating row, and also the empty space, so the place order
 *    section and the payment section will have same white background and
 *    merged with a slightly grey separator line. and also the place order
 *    block width is more, match to the payment gateway block width, so it
 *    will look as one block."
 *
 * Written as STORED choices so the shop is in his state the moment the package
 * is applied, whatever a schema default is later moved to (the QK7/QK8
 * pattern), and each one is a switch he can move back:
 *
 *   - Appearance -> Checkout page -> Trust & reviews -> "Reviews &
 *     authenticity card under Payment" OFF.
 *   - Appearance -> Checkout page -> Mobile · Layout -> "Payment and Place
 *     order as one card" ON.
 *
 * A value already stored (the package applied twice, or he moved it since) is
 * left exactly as it is.
 */
return new class extends Migration
{
    private const CHECKOUT = ['trust_card' => false, 'm_merge' => true];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        SettingsService::forgetMemo();

        $checkout = array_filter(self::CHECKOUT, static fn (string $key): bool => ! DB::table('settings')->where('key', 'checkoutpage_'.$key)->exists(), ARRAY_FILTER_USE_KEY);

        if ($checkout !== []) {
            app(CheckoutPage::class)->save($checkout);
        }

        SettingsService::forgetMemo();
    }

    public function down(): void
    {
        //
    }
};

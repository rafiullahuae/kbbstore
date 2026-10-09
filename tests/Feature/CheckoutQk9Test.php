<?php

declare(strict_types=1);

/*
 * Lane QK9 — the owner, 9 October, on an iPhone screenshot of the live
 * checkout with the reviews card and the gap under it crossed out:
 *
 *   "remove the rating row, and also the empty space, so the place order
 *    section and the payment section will have same white background and
 *    merged with a slightly grey separator line. and also the place order
 *    block width is more, match to the payment gateway block width, so it
 *    will look as one block. the motive is to bring the Place Order button
 *    more near to the payment block."
 *
 * What the shop looked like: "4 Payment" in its card, then a SEPARATE white
 * card "★★★★★ 4.8 from 1,281 reviews / 100% authentic K-beauty", then a 40px
 * gap, then a full-bleed white "Your bag" card (390px wide against the
 * Payment card's 350) with Place order in it — 381.7px from the last payment
 * box to the button. Merged: 250.9px, no gap, the same 350px.
 */

use App\Models\Cart;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::query()->delete();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 1500, 'enabled' => true, 'position' => 0]);
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    qk9Forget();
});

function qk9Forget(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    Cache::forget('kbb.store.rating');
}

function qk9Save(array $values): void
{
    app(CheckoutPage::class)->save($values);
    qk9Forget();
}

function qk9Get(string $path = '/checkout/'): string
{
    $product = Product::create(['slug' => 'qk9-'.Str::random(8), 'name' => 'Glass Skin Set', 'status' => 'publish',
        'is_visible' => true, 'price' => 37500, 'stock_status' => 'instock']);
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 37500]);

    test()->flushSession();
    app('auth')->forgetGuards();
    app(CartService::class)->forget();

    return (string) test()->withCredentials()
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get($path)->assertOk()->getContent();
}

function qk9Reviews(int $n = 6): void
{
    $pid = Product::create(['slug' => 'qk9r-'.Str::random(8), 'name' => 'Reviewed', 'status' => 'publish',
        'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock'])->id;
    for ($i = 0; $i < $n; $i++) {
        Review::query()->insert(['source' => 'sorina', 'product_id' => $pid, 'author_name' => 'R'.$i, 'rating' => 5,
            'content' => 'ok', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
    }
    qk9Forget();
}

function qk9Css(): string
{
    return (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));
}

function qk9BuiltCss(): string
{
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    return (string) file_get_contents(public_path('build/'.$manifest['resources/css/kbb/kbb-checkout.css']['file']));
}

it('does not draw the reviews card on checkout by default, and draws it again when switched on', function () {
    /*
     * DEFECT this guards: a separate white card, "★★★★★ 4.8 from 1,281
     * reviews / 100% authentic K-beauty", stood between the payment methods
     * and the Place order block on the phone, pushing the button 130px
     * further down. The owner crossed it out.
     *
     * MUTATION: default `trust_card` back to true in CheckoutPage::SCHEMA (or
     * drop the @if round the @include in checkout.blade.php) -> the first
     * expectation is red. Delete the @include instead -> the switched-on half
     * is red, which is the control he can move back.
     */
    qk9Reviews();

    expect(CheckoutPage::SCHEMA['trust_card'][0])->toBe('bool')
        ->and(CheckoutPage::SCHEMA['trust_card'][2])->toBeFalse()
        ->and(CheckoutPage::TABS['trust'][2][0])->toBe('trust_card');

    $off = qk9Get();
    expect($off)->not->toContain('class="kbb-reassure"')
        ->and($off)->not->toContain('from 6 reviews')
        // what he did NOT ask about stays: the line under Place order
        ->and($off)->toContain('class="trust"');

    qk9Save(['trust_card' => true]);
    $on = qk9Get();
    expect(substr_count($on, 'class="kbb-reassure"'))->toBe(1)
        ->and($on)->toContain('5.0 from 6 reviews');
});

it('joins the bag block onto the Payment card on a phone, one class switched by one control', function () {
    /*
     * DEFECT this guards: the Place order block was a second, wider card
     * (full bleed, 390px at 390) under a 40px gap (the grid's 24px bottom
     * padding plus the block's 16px top margin), so payment and Place order
     * read as two unrelated boxes.
     *
     * The merged card is the stylesheet's default and OFF is the class
     * (`cop-nomerge`), the same shape as every other switch on this page, so
     * the shipped page carries no new class at all.
     *
     * MUTATION: default `m_merge` to false -> `cop-nomerge` lands on the
     * section and the first section expectation is red. Delete any of the
     * three merged rules from kbb-checkout.css -> the rule expectations are
     * red.
     */
    expect(CheckoutPage::SCHEMA['m_merge'][2])->toBeTrue()
        ->and(CheckoutPage::TABS['mobile'][2])->toContain('m_merge');

    preg_match('#<section class="kbb-checkout[^"]*"#', qk9Get(), $m);
    expect($m[0] ?? '')->not->toContain('cop-nomerge');

    qk9Save(['m_merge' => false]);
    preg_match('#<section class="kbb-checkout[^"]*"#', qk9Get(), $m);
    expect($m[0] ?? '')->toContain(' cop-nomerge');

    foreach ([qk9Css(), qk9BuiltCss()] as $css) {
        $flat = preg_replace('/\s+/', '', $css);
        // no gap between the two halves
        expect($flat)->toContain('.kbb-checkout:not(.cop-nomerge).co-grid{padding-bottom:0}')
            // the Payment card gives up its bottom edge, corners and shadow
            ->and($flat)->toContain('.kbb-checkout:not(.cop-nomerge).formbox{border-bottom:none;border-end-start-radius:0;border-end-end-radius:0;box-shadow:none}')
            // the bag block takes the grid's width and gutter, square on top,
            // its --line-2 top border the one separator, one shadow below
            ->and($flat)->toContain('.kbb-checkout:not(.cop-nomerge).kbb-mobile-order{width:min(calc(100%-2*var(--cop-padx)),calc(var(--cop-d-max,1040px)-2*var(--cop-padx)));margin:0auto24px;border-start-start-radius:0;border-start-end-radius:0;box-shadow:012px36px-24px')
            // the form card's own shadow; the minifier writes it as hex
            ->and(preg_match('/cop-nomerge\)\.kbb-mobile-order\{[^}]*box-shadow:012px36px-24px(rgba\(42,34,40,\.22\)|#2a222838)\}/', $flat))->toBe(1);
    }

    // phone only: the merged rules live inside the max-width:900px block
    $css = qk9Css();
    $mobileAt = strpos($css, "/* ------------------------------- MOBILE -------------------------------- */");
    expect($mobileAt)->not->toBeFalse()
        ->and(strpos($css, '.kbb-checkout:not(.cop-nomerge) .formbox'))->toBeGreaterThan($mobileAt);
});

it('keeps every hook the live totals, the floating bar and the placing overlay use', function () {
    /*
     * The merge adds no wrapper: the bag block is still .kbb-mobile-order with
     * its .kbb-order-slot (repainted by checkout.js on a quantity, coupon or
     * delivery change) and its Place order button (the floating bar's
     * IntersectionObserver target and the overlay's fallback).
     *
     * MUTATION: wrap the two blocks in a new element that renames or doubles
     * .kbb-mobile-order -> a count here is red.
     */
    $html = qk9Get();
    expect(substr_count($html, '<div class="kbb-mobile-order">'))->toBe(1)
        ->and(substr_count($html, '<div class="kbb-order-slot">'))->toBe(2)
        ->and($html)->toContain("document.querySelector('.kbb-checkout .kbb-mobile-order .place')")
        ->and(preg_match('#<div class="kbb-mobile-order">.*?<button[^>]*class="place[^"]*"[^>]*data-place#s', $html))->toBe(1);
});

it('writes the owner\'s two choices on migrate, and leaves a value he stored alone', function () {
    // MUTATION: drop a key from the migration's CHECKOUT list -> its row is
    // absent and red here.
    DB::table('settings')->where('key', 'like', 'checkoutpage_%')->delete();
    qk9Forget();
    $migration = base_path('database/migrations/2027_10_16_130000_checkout_payment_and_place_order_one_card.php');
    (require $migration)->up();
    qk9Forget();

    expect(app(CheckoutPage::class)->get('trust_card'))->toBeFalse()
        ->and(app(CheckoutPage::class)->get('m_merge'))->toBeTrue()
        ->and(DB::table('settings')->where('key', 'checkoutpage_trust_card')->exists())->toBeTrue()
        ->and(DB::table('settings')->where('key', 'checkoutpage_m_merge')->exists())->toBeTrue();

    qk9Save(['trust_card' => true, 'm_merge' => false]);
    (require $migration)->up();
    qk9Forget();

    expect(app(CheckoutPage::class)->get('trust_card'))->toBeTrue()
        ->and(app(CheckoutPage::class)->get('m_merge'))->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/**
 * The cart panel's free-delivery bar, in the panel DRAWN WITH THE PAGE.
 *
 * The owner, 11 October: "the delivery bar is not showing on the cart panel,
 * i have enabled it, but not showing, even cleared the cache too."
 * Appearance → Cart panel → Content → Free-delivery progress bar was on.
 *
 * What it looked like on the shop: open the bag from the header on any page
 * and the band "AED X away from free delivery" was missing. Add something and
 * it appeared, because the panel redrawn after an add was given the shop's
 * country and the one drawn with the page (CartDrawerComposer) was not, so
 * ShippingService::zoneFor(null) found no zone and no threshold.
 *
 * MUTATION: drop the country from CartDrawerComposer's totals() call and both
 * cases here are red; FreeDeliveryBarCartCheckoutSwitchTest stays green,
 * because it only ever asks /api/cart/drawer.
 */
beforeEach(function () {
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'free_shipping', 'title' => 'Free delivery',
        'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1,
    ]);
    app(SettingsService::class)->set('bundles_enabled', false);
    app(SettingsService::class)->set('store_country', 'AE');
    SettingsService::forgetMemo();
});

function cpbPage(int $priceFils): string
{
    $product = Product::create([
        'slug' => 'cpb-' . Str::random(8), 'name' => 'Snail Essence', 'status' => 'publish',
        'is_visible' => true, 'price' => $priceFils, 'stock_status' => 'instock',
    ]);
    // A basket that never chose a country: the state of every shopper who has
    // not reached the checkout yet.
    $cart = Cart::create([
        'token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'last_activity_at' => now(),
    ]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $priceFils]);
    app(CartService::class)->forget();

    $html = (string) test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/product/' . $product->slug . '/')->assertOk()->getContent();

    preg_match('#<div id="kcCart">(.*?)<div class="dbody">#s', $html, $m);

    return $m[1] ?? '';
}

it('draws the bar, part-filled, in the panel that comes with the page', function () {
    $band = cpbPage(9950);

    expect($band)->toContain('<div class="kc-ship">')
        ->toContain('<div class="kc-fill" style="width:50%"></div>');
});

it('says free delivery is unlocked, with a full bar, over the threshold', function () {
    $band = cpbPage(25000);

    expect($band)->toContain('<div class="t done">')
        ->toContain('<div class="kc-fill" style="width:100%"></div>');
});

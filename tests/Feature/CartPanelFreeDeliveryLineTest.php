<?php

/**
 * The free delivery line in the cart panel (2.60.517).
 *
 * The owner: "on cart pane, i want another text row, only will show if user
 * got free delivery as per their cart total". His pick of eight: design C, a
 * plain line with no box, a celebration icon before and a green tick after,
 * "Congrats! You’ve got free delivery". Appearance -> Cart panel -> Free
 * delivery line.
 *
 * Before: nothing beside Subtotal said so; the only cue was the bar at the
 * top of the panel.
 */

use App\Models\Cart;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartPanel;
use App\Services\CartService;
use App\Services\SettingsService;
use Tests\Support\ArabicShop;

beforeEach(function () {
    $uae = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'flat_rate', 'title' => 'Delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
    ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'free_shipping', 'title' => 'Free delivery', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1]);
    SettingsService::forgetMemo();
});

function fdlCart(int $price): Cart
{
    $product = Product::create([
        'slug' => 'fdl-'.uniqid(), 'name' => 'Line Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => $price, 'stock_status' => 'instock',
    ]);
    $cart = Cart::create(['token' => bin2hex(random_bytes(16)), 'status' => 'active', 'currency' => 'AED', 'shipping_country' => 'AE']);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $price]);

    return $cart;
}

function fdlPage(Cart $cart, string $path = '/'): string
{
    SettingsService::forgetMemo();

    return (string) test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get($path)->assertOk()->getContent();
}

it('ships on with his sentence, and shows once the bag reaches the free-delivery amount, above Subtotal', function () {
    // MUTATION: drop `$left <= 0` from the drawer and the AED 120 bag below
    // draws the line too -- red.
    expect(CartPanel::SCHEMA['fd_on'][2])->toBeTrue()
        ->and(CartPanel::FD_TEXT)->toBe('Congrats! You’ve got free delivery');

    $html = fdlPage(fdlCart(25000));
    expect(substr_count($html, 'class="kc-fdl"'))->toBe(1)
        ->and($html)->toContain('<span class="kc-fdl-t">Congrats! You’ve got free delivery</span>')
        ->and(preg_match('#class="kc-fdl".*?</div>\s*(<div class="kc-cch"|<div class="sumrow tot">)#s', $html))->toBe(1);

});

it('draws nothing while the bag is below the free-delivery amount (AED 120 of 199)', function () {
    // Its own request: a second page in one test keeps the first basket.
    $html = fdlPage(fdlCart(12000));

    expect($html)->toContain('<div class="kc-ship">')->and($html)->not->toContain('class="kc-fdl"');
});

it('hides when switched off', function () {
    app(CartPanel::class)->save(['fd_on' => false]);
    expect(fdlPage(fdlCart(25000)))->not->toContain('class="kc-fdl"');
});

it('says it in Arabic on /ar/', function () {
    ArabicShop::on();
    expect(fdlPage(fdlCart(25000), '/ar/'))->toContain('<span class="kc-fdl-t">مبروك! حصلتِ على توصيل مجاني</span>');
});

it('escapes the owner\'s wording', function () {
    app(CartPanel::class)->save(['txt_fd' => '<b>Free</b> & yours']);
    $html = fdlPage(fdlCart(25000));

    expect($html)->toContain('&lt;b&gt;Free&lt;/b&gt; &amp; yours')->and($html)->not->toContain('<b>Free</b> & yours');
});

it('stays on one line and inside the panel: no wrap, sized from its own width (CSS)', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    expect($css)->toContain('.kc-fdl{container-type:inline-size;display:flex;align-items:center;gap:6px;max-width:100%;overflow:hidden;white-space:nowrap;')
        ->and($css)->toContain('.kc-fdl-ok{flex:none;width:16px;height:16px;margin-inline-start:auto;color:#2e9e6b}');
});


/* ─── 2.60.518: the shopper's own country, and the owner's country list ─── */

function fdlSaudi(): void
{
    // Saudi Arabia: free delivery from AED 500, its own zone.
    $sa = ShippingZone::create(['name' => 'Saudi Arabia', 'position' => 1]);
    ShippingZoneLocation::create(['shipping_zone_id' => $sa->id, 'type' => 'country', 'code' => 'SA']);
    ShippingMethod::create(['shipping_zone_id' => $sa->id, 'type' => 'flat_rate', 'title' => 'GCC', 'cost' => 5000, 'enabled' => true, 'position' => 0]);
    ShippingMethod::create(['shipping_zone_id' => $sa->id, 'type' => 'free_shipping', 'title' => 'Free', 'cost' => 0, 'min_amount' => 50000, 'enabled' => true, 'position' => 1]);
}

function fdlPageFrom(Cart $cart, string $country): string
{
    SettingsService::forgetMemo();

    return (string) test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->withHeader('CF-IPCountry', $country)
        ->get('/')->assertOk()->getContent();
}

it('measures a shopper abroad against THEIR country: AED 250 from Saudi Arabia is not free delivery (500 there)', function () {
    /*
     * The owner: "this should work as per the user geo location ... in uae we
     * offer free delivery on 199 but in rest of the countries the free
     * delivery criteria is different." Before: the panel measured everyone
     * against the UAE's AED 199, so this shopper read "You've unlocked free
     * delivery!" and "Congrats!" on a bag that pays AED 50 delivery.
     * MUTATION: drop the $kbbGeoFree override in the drawer and this is red.
     */
    fdlSaudi();
    $html = fdlPageFrom(fdlCart(25000), 'SA');

    expect($html)->not->toContain('class="kc-fdl"')
        ->and($html)->not->toContain('class="t done"')
        ->and($html)->toContain('AED</span> 250');
});

it('congratulates a shopper abroad once THEIR amount is reached', function () {
    fdlSaudi();

    expect(fdlPageFrom(fdlCart(60000), 'SA'))->toContain('<span class="kc-fdl-t">Congrats! You’ve got free delivery</span>');
});

it('leaves a shopper in the shop\'s own country exactly as before', function () {
    fdlSaudi();

    expect(fdlPageFrom(fdlCart(25000), 'AE'))->toContain('class="kc-fdl"');
});

it('shows only in the countries the owner lists, by code or by name', function () {
    fdlSaudi();
    app(CartPanel::class)->save(['fd_countries' => 'AE']);

    expect(fdlPageFrom(fdlCart(60000), 'SA'))->not->toContain('class="kc-fdl"');
});

it('reads the country list forgivingly: codes, names, spaces; an empty or unreadable list is every country', function () {
    expect(CartPanel::countryListed('AE, SA', 'sa'))->toBeTrue()
        ->and(CartPanel::countryListed('United Arab Emirates; Oman', 'OM'))->toBeTrue()
        ->and(CartPanel::countryListed('AE', 'SA'))->toBeFalse()
        ->and(CartPanel::countryListed('', 'SA'))->toBeTrue()
        ->and(CartPanel::countryListed('nowhere, ???', 'SA'))->toBeTrue()
        ->and(CartPanel::countryListed('AE', null))->toBeTrue();
});

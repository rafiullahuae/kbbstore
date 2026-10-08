<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Services\CartService;
use App\Services\SettingsService;
use App\Services\ShippingService;
use Database\Seeders\ShippingSeeder;

/*
 * "The place order button is not working at all ... in chrome working, in
 * mozilla browser not working." (the owner, 8 October, on 2.60.430)
 *
 * Firefox, unlike Chrome, persists a button's dynamic DISABLED state across
 * page loads (MDN, <button>: "use the autocomplete attribute to control
 * this"). A Place order button disabled mid-press (lock() / the overlay) came
 * back disabled after a reload or a return to the page and never answered
 * again: the owner's report. Reproduced in Chromium by restoring the state
 * during parse, as Firefox does: on 2.60.430 the button stayed dead for cash
 * and card; with this fix both placed their order.
 *
 * The body _token is dropped from the JSON/FormData posts as well, so only the
 * header (this load's window.KBB.csrf) is read. @csrf already prints
 * autocomplete="off", so Firefox does not restore it today; this keeps a
 * stale body token from ever outranking the header.
 *
 * Mutations (each turns this file red): drop autocomplete="off" from either
 * Place order button; remove liven() from placing-overlay; remove the
 * `_token` delete from any of the three request builders.
 */
beforeEach(function () {
    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', 0);
    (new ShippingSeeder())->run();
    ShippingService::flushZones();
});

function ffCheckoutHtml(): string
{
    $product = Product::create([
        'slug' => 'ff-' . uniqid(), 'name' => 'Firefox Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock',
    ]);
    $cart = Cart::create(['token' => bin2hex(random_bytes(16)), 'status' => 'active', 'currency' => 'AED', 'shipping_country' => 'AE']);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5000]);

    return (string) test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/checkout/')
        ->assertOk()
        ->getContent();
}

it('tells Firefox not to restore a disabled Place order button', function () {
    $html = ffCheckoutHtml();

    preg_match_all('#<button[^>]*data-place="1"[^>]*>#', $html, $buttons);
    expect($buttons[0])->not->toBeEmpty();

    foreach ($buttons[0] as $button) {
        expect($button)->toContain('autocomplete="off"')->not->toContain('disabled');
    }
});

it('brings every Place order button back to life on a fresh load', function () {
    $html = ffCheckoutHtml();

    expect($html)->toContain('function liven() { if (!busy) { buttons().forEach(function (b) { b.disabled = false; }); } }')
        ->and($html)->toContain("document.addEventListener('DOMContentLoaded', liven)")
        ->and($html)->toContain('if (!event.persisted) { liven(); }');
});

it('sends this page load\'s token in the header, never a restored body token', function () {
    $html = ffCheckoutHtml();

    // placing-overlay (cash, Tabby, Tamara).
    expect($html)->toContain("init.body.delete('_token');");

    // The card partial only renders with Stripe on; read it as shipped.
    $card = file_get_contents(resource_path('views/partials/checkout/stripe-elements.blade.php'));
    expect($card)->toContain('delete out._token;');

    $wallets = file_get_contents(resource_path('views/partials/checkout/express-wallets.blade.php'));
    expect($wallets)->toContain('delete out._token;');

    $js = file_get_contents(resource_path('js/kbb/checkout.js'));
    expect($js)->toContain("if (token && window.KBB && window.KBB.csrf) token.value = window.KBB.csrf;");
});

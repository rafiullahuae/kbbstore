<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Support\Str;

/**
 * THE CART PANEL'S PRODUCT OPENS THE PRODUCT.                (Lane CPL, 2.60.489)
 *
 * The owner, 12 October: "on the cart panel, the product is not clickable,
 * neither product image. it should be clickable and go to the product page, if
 * customer wants to go there. this issue is only in the cart panel."
 *
 * What it looked like: in the bag's panel the 42px picture and the name were
 * plain boxes (`<div class="kc-th">`, `<div class="kc-nm">`), so tapping either
 * did nothing -- while the Browsed tab beside it already linked its picture.
 *
 * MUTATION: put the `<div class="kc-th" …>` back for the basket line and the
 * first case is red; drop the `<a>` inside `.kc-nm` and the second is.
 */
function cplPanel(): array
{
    $product = Product::create([
        'slug' => 'cpl-snail-essence', 'name' => 'Snail Essence', 'status' => 'publish',
        'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
    ]);
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 9900]);
    app(CartService::class)->forget();

    $html = (string) test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/shop/')->assertOk()->getContent();

    preg_match('#<div id="kcCart">(.*?)<div class="dfoot">#s', $html, $m);

    return [$m[1] ?? '', $product];
}

it('links the line’s picture to the product page', function () {
    [$panel, $product] = cplPanel();

    expect($panel)->toMatch('#<a class="kc-th" href="[^"]*/product/cpl-snail-essence/"#')
        ->and($panel)->not->toContain('<div class="kc-th"');
});

it('links the line’s name to the product page, and keeps the name as it was', function () {
    [$panel] = cplPanel();

    expect($panel)->toMatch('#<div class="kc-nm"><a href="[^"]*/product/cpl-snail-essence/">Snail Essence</a></div>#');
});

it('leaves the quantity, remove and price controls exactly where they were', function () {
    [$panel] = cplPanel();

    expect($panel)->toContain('data-kcq=')
        ->toContain('data-kcrm=')
        ->toContain('class="kc-pr"');
});

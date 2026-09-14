<?php

/**
 * The mini-cart drawer on the FIRST add of a brand-new session.
 *
 * Reported from the live site: in a fresh incognito window the panel slid open
 * empty on the first add and only rendered from the second add onwards.
 *
 * The cart cookie is issued on the response to that first add — the request
 * that carries it arrives with no cookies at all. CartDrawerComposer decided
 * whether there was anything to show from `$request->hasCookie()`, so on that
 * one request it short-circuited to the empty state; and because a view
 * composer runs AFTER the data handed to the view and overwrites it, that empty
 * state replaced the payload CartController had already computed correctly.
 *
 * These tests exercise a genuinely cookie-less request and assert on the
 * CONTENT of the drawer fragment, not on the status code.
 */

use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Support\Str;

function firstAddProduct(string $name, int $priceFils = 5500): Product
{
    return Product::create([
        'slug' => Str::slug($name) . '-' . uniqid(),
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'price' => $priceFils,
        'stock_status' => 'instock',
    ]);
}

it('renders the added line in the drawer on a cookie-less first add', function () {
    $product = firstAddProduct('Ginseng Essence Water');

    // No cookies whatsoever — a brand-new incognito window.
    $response = $this->postJson('/api/cart/add', ['product_id' => $product->id, 'quantity' => 1]);

    $response->assertOk()->assertJson(['ok' => true, 'count' => 1]);

    $drawer = $response->json('drawer');

    expect($drawer)->toContain('Ginseng Essence Water')
        ->and($drawer)->not->toContain('Your bag is empty');
});

it('issues the cart cookie on that same first response', function () {
    $product = firstAddProduct('Rice Toner');

    $response = $this->postJson('/api/cart/add', ['product_id' => $product->id]);

    $response->assertCookie(CartService::COOKIE);
    expect(Cart::query()->where('status', 'active')->count())->toBe(1);
});

it('still renders correctly on the second add, with the cookie present', function () {
    $first = firstAddProduct('Snail Mucin 96');
    $second = firstAddProduct('Peach Sleeping Mask');

    $this->postJson('/api/cart/add', ['product_id' => $first->id]);

    $cart = Cart::query()->where('status', 'active')->firstOrFail();

    $drawer = $this
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->postJson('/api/cart/add', ['product_id' => $second->id])
        ->assertOk()
        ->json('drawer');

    expect($drawer)->toContain('Snail Mucin 96')->toContain('Peach Sleeping Mask');
});

it('leaves the drawer empty for a visitor who has added nothing', function () {
    firstAddProduct('Never Added');

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('Your bag is empty');
    // Rule 27: no cart row is conjured for a browsing visitor or a crawler.
    expect(Cart::query()->count())->toBe(0);
});

it('keeps the browsed row listed after it is added, on the first add too', function () {
    $product = firstAddProduct('Barrier Cream');

    // kbb_viewed is the only cookie a first-time visitor can already carry:
    // the product page writes it from JavaScript, before any cart exists.
    $drawer = $this
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie('kbb_viewed', (string) $product->id)
        ->postJson('/api/cart/add', ['product_id' => $product->id])
        ->assertOk()
        ->json('drawer');

    // Both tabs: the line in Cart, and the row still standing in Browsed with
    // the tick that says it is in the bag.
    expect($drawer)->toContain('data-brow="' . $product->id . '"')
        ->and($drawer)->toContain('kc-badd in');
});

<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use App\Support\AdminCapabilities;
use Illuminate\Support\Str;

/**
 * THE LIVE PREVIEW IS THE REAL PAGE, AND IT LEAVES NOTHING BEHIND (Lane CK).
 *
 * Appearance → Checkout page / Cart page / Cart panel draw the shop's own page
 * beside the size controls. It is rendered by App\Support\ShopLivePreview on a
 * sample basket inside a transaction that is always rolled back. These pin:
 *   - each route answers the role that may use its screen, and nobody else;
 *   - the HTML is the shop's own markup (the checkout form, the cart page, the
 *     panel opened), in Arabic and right to left on lang=ar;
 *   - it carries no script and an inert body, so nothing in it can run or post;
 *   - no cart row, no cart cookie and no session change survives a preview.
 */
function ckLiveOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'Owner', 'email' => 'ck-live-'.Str::random(8).'@example.com',
        'password' => bcrypt('secret'), 'role' => $role]);
}

function ckLiveProducts(): void
{
    foreach (['Rice Toner', 'Snail Essence', 'Sun Serum'] as $i => $name) {
        Product::create(['slug' => 'ck-live-'.$i.'-'.Str::random(5), 'name' => $name, 'status' => 'publish',
            'is_visible' => true, 'price' => 50 + $i, 'stock_status' => 'instock']);
    }
}

it('draws the real checkout, cart page and opened cart panel on a sample basket', function (string $path, string $marker) {
    ckLiveProducts();
    $carts = Cart::query()->count();

    $r = test()->actingAs(ckLiveOwner(), 'admin')->get('/admin-api/'.$path.'/live?lang=en')->assertOk();

    // MUTATION: stop stripping the scripts in ShopLivePreview. RED.
    expect($r->getContent())->not->toContain('<script')->and($r->getContent())->toContain('<body inert')
        ->and($r->getContent())->toContain($marker)
        ->and($r->getContent())->toContain(e((string) Product::query()->visible()->where('stock_status', 'instock')->where('price', '>', 0)->orderBy('id')->value('name')))
        // MUTATION: commit instead of rolling back in ShopLivePreview. RED.
        ->and(Cart::query()->count())->toBe($carts)
        ->and(collect($r->headers->getCookies())->map->getName()->all())->not->toContain(CartService::COOKIE);
})->with([
    'checkout' => ['checkout-page', 'id="kbbCheckoutForm"'],
    'cart page' => ['cart-page', 'id="cartPage"'],
    'cart panel' => ['cart-panel', '<aside class="drawer on '],
]);

it('draws the Arabic page right to left on lang=ar', function () {
    ckLiveProducts();

    $html = test()->actingAs(ckLiveOwner(), 'admin')->get('/admin-api/checkout-page/live?lang=ar')->assertOk()->getContent();

    expect($html)->toContain('dir="rtl"')->and($html)->toContain('id="kbbCheckoutForm"');
});

it('leaves the admin session exactly as it was', function () {
    ckLiveProducts();
    $owner = ckLiveOwner();

    test()->actingAs($owner, 'admin')->withSession(['ck_probe' => 'kept'])
        ->get('/admin-api/cart-page/live')->assertOk()
        ->assertSessionHas('ck_probe', 'kept');

    expect(session()->has('kbb_unfinished_payment'))->toBeFalse();
});

it('answers nobody who is not signed in, and is mapped to each screen\'s own capability', function () {
    test()->getJson('/admin-api/checkout-page/live')->assertStatus(401);

    expect(AdminCapabilities::forPath('GET', 'admin-api/checkout-page/live'))->toBe('checkoutpage.manage')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/cart-page/live'))->toBe('cartpage.manage')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/cart-panel/live'))->toBe('content.manage');
});

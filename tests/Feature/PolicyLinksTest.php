<?php

declare(strict_types=1);

/**
 * Shipping & Delivery and Returns Information under the cart and checkout
 * totals, and the content-page styles. (Lane TP)
 *
 * THE OWNER, 6 October 2026: "also apply the links in the main footer and two
 * links on cart / checkout pages … also fix any paragraph etc issue". The
 * defects pinned, as they looked on the shop:
 *
 *   1. Neither the cart nor the checkout linked to any policy: a shopper
 *      about to pay had no way to read the delivery or returns terms without
 *      leaving for the footer.
 *   2. On the checkout the two links drew as plain ink text, no underline:
 *      kbb-checkout.css's `.kbb-checkout a{color:inherit;text-decoration:none}`
 *      loads after kbb.css and out-ranked `.kbb-pol a`.
 *   3. The FAQ's section headings (h2, "PAYMENT OPTIONS") had no rule under
 *      .policy-body and fell through to the shop's display h2; a table had no
 *      cells to read.
 *
 * The switches: Appearance → Cart page → Summary & trust → "Shipping &
 * Delivery and Returns Information links under the totals", and Appearance →
 * Checkout page → Trust & reviews → "… links under Place order". Both ship OFF
 * since 2.60.417 -- the owner, on the checkout: "remove these links. only in
 * the footer link, replace those two links." The slim footer's two links now
 * carry Shipping & Delivery and Returns Information (SlimFooterPolicyLinksTest);
 * these switches stay so the pair can come back, and the cases below switch
 * them on to prove they still work.
 *
 * MUTATION NOTES, RUN:
 *   · remove the @include from cart-inner.blade.php → RED (case 1, cart).
 *   · remove the @include from checkout/order-block.blade.php → RED (case 1).
 *   · ship either `policy_links` default as true → RED (case 3).
 *   · write the selector back as `.kbb-pol a{` → RED (case 2).
 *   · delete the `.policy-body h2{` rule → RED (case 3).
 */

use App\Models\Cart;
use App\Models\Product;
use App\Services\CartPage;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use Illuminate\Support\Str;

function tpBasketPage(string $path): string
{
    $product = Product::create([
        'slug' => 'tp-'.Str::random(8), 'name' => 'Heartleaf Toner', 'status' => 'publish',
        'is_visible' => true, 'price' => 8900, 'stock_status' => 'instock',
    ]);
    $cart = Cart::create([
        'token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 8900]);

    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get($path)
        ->assertOk()
        ->getContent();
}

function tpForget(): void
{
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    App\Models\Setting::flushMap();
}

/*
 * Lane EC, 10 October: the Returns Information link is gone from the pair —
 * the owner: "remove the returns words completely, we don't offer returns."
 * A shopper about to pay was being pointed at a returns policy the shop does
 * not have. MUTATION: put the /refund_returns/ anchor back in
 * partials/policy-links.blade.php → every case here is red.
 */
const TP_LINKS = '<p class="kbb-pol"><a href="/delivery/">Shipping &amp; Delivery</a></p>';

it('puts Shipping & Delivery (and no returns link) under the cart totals, on both layouts, and takes it away when switched off', function () {
    foreach (['squeeze', 'classic'] as $layout) {
        app(CartPage::class)->save(['layout' => $layout, 'policy_links' => true]);
        tpForget();

        expect(substr_count(tpBasketPage('/cart/'), TP_LINKS))->toBe(1, $layout);
    }

    app(CartPage::class)->save(['policy_links' => false]);
    tpForget();
    expect(tpBasketPage('/cart/'))->not->toContain('kbb-pol');
});

it('puts the same two links under Place order on the checkout, and takes them away when switched off', function () {
    app(CheckoutPage::class)->save(['policy_links' => true]);
    tpForget();
    $html = tpBasketPage('/checkout/');

    expect($html)->toContain(TP_LINKS)
        ->and(strpos($html, TP_LINKS))->toBeGreaterThan(strpos($html, 'data-place="1"'));

    app(CheckoutPage::class)->save(['policy_links' => false]);
    tpForget();
    expect(tpBasketPage('/checkout/'))->not->toContain('kbb-pol');
});

it('ships both switches off, on the tabs the owner opens', function () {
    expect(CartPage::SCHEMA['policy_links'][2])->toBeFalse()
        ->and(CheckoutPage::SCHEMA['policy_links'][2])->toBeFalse()
        ->and(CartPage::TABS['summary'][2])->toContain('policy_links')
        ->and(CheckoutPage::TABS['trust'][2])->toContain('policy_links');
});

it('styles the links above the checkout\'s own link reset, and the content pages\' headings, lists and tables', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $checkout = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));

    // The reset the links have to out-rank: (0,1,1). p.kbb-pol a is (0,1,2).
    expect($checkout)->toContain('.kbb-checkout a{color:inherit;text-decoration:none}')
        ->and($css)->toContain('p.kbb-pol a{display:inline-block;padding:7px 2px;color:var(--muted,#756C74);text-decoration:underline;')
        ->and($css)->not->toContain("\n.kbb-pol a{");

    expect($css)->toContain('.kbb-home .policy-body h2{font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;')
        ->toContain('.kbb-home .policy-body > :first-child{margin-top:0}')
        ->toContain('.kbb-home .policy-body ul{list-style:disc}')
        ->toContain('.kbb-home .policy-body th,.kbb-home .policy-body td{border:1px solid var(--line-2);padding:8px 12px;');

    // And the built bundle carries them (public/build is what the server serves).
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $built = (string) file_get_contents(public_path('build/'.$manifest['resources/css/kbb/kbb.css']['file']));
    expect($built)->toContain('p.kbb-pol a{')->toContain('.policy-body h2{');
});

<?php

declare(strict_types=1);

/*
 * The cart and checkout footer's two links go to the real policy pages.
 *
 * The owner, 6 October, on the checkout with two links crossed out under
 * Place order and a tick beside the slim footer's "Shipping policy · Terms of
 * service": "remove these links. only in the footer link, replace those two
 * links." On the shop the footer's links went to /shipping-policy and
 * /terms-of-service -- neither is a route here, so both were 404s -- while a
 * second pair of links sat under Place order.
 *
 * Now the slim footer reads "Shipping & Delivery" -> /delivery/ and "Returns
 * Information" -> /refund_returns/, the pair under Place order is gone, and
 * 2027_09_06_100000 moves a saved link only while it still holds the old
 * default.
 *
 * MUTATION NOTES, RUN:
 *   · put SlimFooter's l1_url default back to '/shipping-policy' → RED (case 1).
 *   · ship CheckoutPage's `policy_links` default as true → RED (case 1).
 *   · drop the "still holds the old default" check in the migration → RED (case 2).
 */

use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use App\Services\SettingsService;
use App\Services\SlimFooter;
use Illuminate\Support\Str;

function sfplCheckout(): string
{
    $product = Product::create([
        'slug' => 'sfpl-'.Str::random(8), 'name' => 'Heartleaf Toner', 'status' => 'publish',
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
        ->get('/checkout/')
        ->assertOk()
        ->getContent();
}

function sfplForget(): void
{
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    App\Models\Setting::flushMap();
}

it('links the checkout footer to Shipping & Delivery and Privacy policy, and drops the pair under Place order', function () {
    // Lane QK8: "remove the Returns information from the checkout footer, as
    // we don't offer returns, keep the Privacy Policy there."
    $html = sfplCheckout();

    expect($html)->toContain('<a href="/delivery/">Shipping &amp; Delivery</a>')
        ->and($html)->toContain('<a href="/privacy-policy/">Privacy policy</a>')
        ->and($html)->not->toContain('Returns Information')
        ->and($html)->not->toContain('/shipping-policy')
        ->and($html)->not->toContain('/terms-of-service')
        ->and($html)->not->toContain('class="kbb-pol"');
});

it('moves a saved footer link only while it still holds the old default', function () {
    $settings = app(SettingsService::class);
    $settings->set(SlimFooter::PREFIX.'l1_text', 'Shipping policy');
    $settings->set(SlimFooter::PREFIX.'l1_url', '/shipping-policy');
    $settings->set(SlimFooter::PREFIX.'l2_text', 'Our terms');
    $settings->set(SlimFooter::PREFIX.'l2_url', '/terms-and-conditions/');
    sfplForget();

    (require database_path('migrations/2027_09_06_100000_slim_footer_links_to_policy_pages.php'))->up();
    sfplForget();

    $footer = app(SlimFooter::class)->all();
    expect($footer['l1_text'])->toBe('Shipping & Delivery')
        ->and($footer['l1_url'])->toBe('/delivery/')
        ->and($footer['l2_text'])->toBe('Our terms')
        ->and($footer['l2_url'])->toBe('/terms-and-conditions/');
});

it('puts the checkout\'s back-to-top arrow at the far end of its own row, not the start', function () {
    /*
     * Owner, 10 October, desktop checkout: "the back to top icon is showing
     * down left, it should be on right bottom". The links-at-end row takes a
     * full line, so the arrow wraps onto one of its own, and under the default
     * `between` alignment its margin was 0 -- a lone item at the START.
     * Measured in Chromium at 1280 (docs/lane-sh-shots): left edge 140 before,
     * 1110 after, 20px in from the content's right edge; 390 unchanged.
     *
     * MUTATION, RUN: delete the `.sf-links-end ~ .sf-top` rule and this is red.
     */
    $html = sfplCheckout();
    $rule = '.kbb-slimfoot.sf-up-on .sf-links-end ~ .sf-top{margin-inline-start:auto}';

    expect(substr_count($html, $rule))->toBe(1)
        ->and(strpos($html, $rule))->toBeGreaterThan(strpos($html, '.kbb-slimfoot:is(.sf-a-center,.sf-a-end,.sf-a-between) .sf-top{margin-inline-start:0}'))
        ->and($html)->toContain('class="sf-links sf-links-end"');
});

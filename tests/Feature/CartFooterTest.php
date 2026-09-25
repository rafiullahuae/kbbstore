<?php

declare(strict_types=1);

/*
 * =============================================================================
 * NO FOOTER ON THE CART PAGE — AND A FOOTER EVERYWHERE ELSE
 * =============================================================================
 *
 * The owner's words: "on cart there will be no footer! give optin to turn on
 * off, and by default keep the footer turned off on the cart page completely."
 *
 * So `cartpage_footer_on` ships FALSE, and this is the one control on the cart
 * page screen whose default does NOT reproduce today's rendering. That is
 * deliberate and it is the request; StorefrontEnglishUnchangedTest's base
 * commit was moved forward for exactly this diff.
 *
 * The expensive half of that promise is the word "cart". layouts/store.blade.php
 * is extended by every page in the shop, so the obvious edit — a condition in
 * the layout that reads the cart setting — is a switch that turns the footer off
 * SHOP-WIDE the first time somebody mis-reads it. The mechanism here cannot do
 * that: store/cart.blade.php declares a `no-footer` section and the layout asks
 * `View::hasSection('no-footer')`, so a page that never declares the section
 * cannot lose its footer whatever the setting says. The block at the bottom of
 * this file is what holds that, page by page.
 *
 * And it has to work on BOTH cart layouts. `cartpage_layout` ships `classic`,
 * so a switch that only took effect on `squeeze` would take effect for almost
 * nobody. Every assertion below is made twice.
 *
 * Each block names the MUTATION that turns it red.
 */

use App\Models\Cart;
use App\Models\Product;
use App\Services\CartPage;
use App\Services\CartService;
use Illuminate\Support\Str;

/** A product that is fit to appear on the shop, a product page and in a basket. */
function cartFooterProduct(): Product
{
    return Product::create([
        'slug' => 'cf-'.Str::random(6),
        'name' => 'Rice Probiotics Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12000,
        'stock_status' => 'instock',
    ]);
}

function cartFooterCart(): Cart
{
    /*
     * A UUID, because `carts.token` is a `uuid()` column -- char(36) on MySQL,
     * and MySQL ENFORCES that width. This helper used to fabricate
     * Str::random(40), which SQLite stores whole (it does not enforce VARCHAR
     * or CHAR length at all) and MySQL refuses outright:
     *
     *   SQLSTATE[22001] 1406 Data too long for column 'token' at row 1
     *
     * Five cases in this file, green on SQLite and red on a real server. The
     * shop itself was never at risk -- every production write of this column
     * (CartService::create(), ManualOrderBuilder::buildDraftCart(),
     * PageCostDataset) is `(string) Str::uuid()`, exactly 36 characters, and
     * the cookie token is only ever read back as a WHERE value -- so no
     * shopper has lost a basket to this. It was the fixture that was wrong.
     * Writing what production writes is also what every other cart fixture in
     * the suite does.
     */
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED']);

    $cart->items()->create([
        'product_id' => cartFooterProduct()->id,
        'quantity' => 2,
        'unit_price' => 12000,
    ]);

    return $cart;
}

/** The cart page, fetched the way a shopper with a basket fetches it. */
function cartFooterGet(Cart $cart): string
{
    return (string) test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/cart')
        ->assertOk()
        ->getContent();
}

/*
 * The site footer, identified by the thing only it emits.
 *
 * partials/footer.blade.php is the ONLY template under resources/views that
 * opens a `<footer>` on a page served by layouts/store.blade.php — blog.blade
 * and post.blade have one of their own, and neither is in this walk. Checking
 * for the tag alone would therefore be enough, but the WhatsApp link is checked
 * with it so that a future footer rewrite which drops the tag for a <div> does
 * not quietly turn every assertion in this file green.
 */
function cartFooterPresent(string $html): bool
{
    return str_contains($html, '<footer>') && str_contains($html, 'wa.me/');
}

/* ------------------------------------------------------------------------
 | 1. The setting
 |------------------------------------------------------------------------*/

it('ships with the cart-page footer switched off', function () {
    expect(app(CartPage::class)->get('footer_on'))->toBeFalse();

    // Off means off — not "hidden with CSS", which is a footer that still
    // ships in the HTML and still gets read out by a screen reader.
    expect(CartPage::SCHEMA['footer_on'][0])->toBe('bool')
        ->and(CartPage::SCHEMA['footer_on'][2])->toBeFalse();
});
// MUTATION: change the default in CartPage::SCHEMA to true. RED here and in
// every block below that asserts the shipped cart page has no footer.

it('puts the switch on a tab, where somebody can reach it', function () {
    // A schema key that is on no tab is a setting nobody can turn on, which
    // for an opt-in is the whole feature missing. CartPageScreenTest holds the
    // general rule; this pins the tab it belongs on.
    expect(CartPage::TABS['layout'][2])->toContain('footer_on');

    // The label has to be unmistakable on a screen of sixty-nine controls.
    expect(CartPage::SCHEMA['footer_on'][1])->toBe('Show the site footer on the cart page');

    // And the help text has to say the thing that is easiest to get wrong:
    // this is the cart page only.
    expect(strtolower(CartPage::SCHEMA['footer_on'][3]))
        ->toContain('the cart page only')
        ->toContain('every other page');
});
// MUTATION: drop 'footer_on' from TABS['layout']. RED on the first assertion.

/* ------------------------------------------------------------------------
 | 2. The cart page, on BOTH layouts
 |------------------------------------------------------------------------*/

it('serves the classic cart page with no footer at all', function () {
    expect(app(CartPage::class)->get('layout'))->toBe('classic');

    expect(cartFooterPresent(cartFooterGet(cartFooterCart())))->toBeFalse();
});

it('serves the squeezed cart page with no footer either', function () {
    app(CartPage::class)->save(['layout' => 'squeeze']);

    expect(cartFooterPresent(cartFooterGet(cartFooterCart())))->toBeFalse();
});
// MUTATION for both: move the @section('no-footer') declaration in
// store/cart.blade.php inside the `@if ($kbbCartPage->squeezed())` block. RED
// on the classic test — which is the layout this shop is actually on.

it('brings the footer back on the classic cart page when the switch is on', function () {
    app(CartPage::class)->save(['footer_on' => true]);

    expect(cartFooterPresent(cartFooterGet(cartFooterCart())))->toBeTrue();
});

it('brings the footer back on the squeezed cart page when the switch is on', function () {
    app(CartPage::class)->save(['layout' => 'squeeze', 'footer_on' => true]);

    expect(cartFooterPresent(cartFooterGet(cartFooterCart())))->toBeTrue();
});
// MUTATION for both: delete `|| View::hasSection('no-footer')` from
// layouts/store.blade.php. GREEN — the footer comes back because nothing
// removes it any more. The pair above is what catches that; these two are what
// catch the opposite mistake, a switch wired to nothing.

/* ------------------------------------------------------------------------
 | 3. EVERY OTHER PAGE KEEPS ITS FOOTER
 |------------------------------------------------------------------------*/

it('leaves the footer on the homepage, a product page and the shop', function () {
    $product = cartFooterProduct();

    $pages = [
        '/' => $this->get('/'),
        '/shop/' => $this->get('/shop/'),
        '/product/'.$product->slug.'/' => $this->get('/product/'.$product->slug.'/'),
    ];

    foreach ($pages as $path => $response) {
        $html = (string) $response->assertOk()->getContent();

        expect(cartFooterPresent($html))->toBeTrue(
            "{$path} lost its footer. The cart-page switch is supposed to reach the cart page and "
            .'nothing else; if this is red, the condition moved into the shared layout.'
        );
    }
});
// MUTATION: replace the @unless in layouts/store.blade.php with the naive
// "read the setting in the layout" edit —
// `@unless (View::hasSection('bare') || ! app(\App\Services\CartPage::class)->get('footer_on'))`.
// RED on all three.

it('still leaves the footer on those pages once a shopper has a basket', function () {
    /*
     * The one that would catch a leak, and the reason it is a separate test.
     *
     * Sections live on the view factory for the duration of a render. If the
     * cart page's `no-footer` section could survive into a later render in the
     * same process — a queue worker, or a suite that renders thirty pages back
     * to back — the footer would vanish from whichever page happened to be
     * rendered next, and it would look like flake rather than like this.
     *
     * So: render the cart page FIRST, in this process, then the three pages
     * above, and check none of them picked it up.
     */
    $product = cartFooterProduct();
    $cart = cartFooterCart();

    expect(cartFooterPresent(cartFooterGet($cart)))->toBeFalse();

    foreach (['/', '/shop/', '/product/'.$product->slug.'/'] as $path) {
        expect(cartFooterPresent((string) $this->get($path)->assertOk()->getContent()))
            ->toBeTrue("{$path} rendered after the cart page and came out without a footer — the "
                .'section leaked out of the render that declared it');
    }
});

it('names the cart page as the only template that declares the section', function () {
    /*
     * The property the whole design rests on, asserted directly rather than
     * inferred from three pages that happen to be in the walk. If a second
     * template ever declares `no-footer`, the promise in the help text — "the
     * footer still appears on every other page" — stops being true, and the
     * page it stops being true for is one nobody thought to add here.
     */
    $declaring = [];

    $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

    foreach ($dir as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $src = (string) file_get_contents($file->getPathname());

            if (str_contains($src, "@section('no-footer'")) {
                $declaring[] = str_replace(resource_path('views').'/', '', $file->getPathname());
            }
        }
    }

    expect($declaring)->toBe(['store/cart.blade.php']);
});
// MUTATION: add @section('no-footer', '1') to store/checkout.blade.php. RED.

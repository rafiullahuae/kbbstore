<?php

declare(strict_types=1);

/**
 * The header cart badge, on a page that loaded with an EMPTY cart.
 *
 * Reported from the live shop, in the owner's words: "the cart icon in header
 * don't count the qnty, when i add to cart from the homepage, it remains dead,
 * until i visit any other page."
 *
 * ── WHY IT WAS DEAD, AND WHY THE OBVIOUS TEST WOULD NOT HAVE CAUGHT IT ──────
 *
 * Both badges used to be wrapped in a Blade conditional:
 *
 *     @if (($kbbCartCount ?? 0) > 0)<i id="cartCt">{{ $kbbCartCount }}</i>@endif
 *
 * so on a page loaded with an empty cart the element was not in the document at
 * all. resources/js/kbb/cart.js `setCartCount()` then does
 *
 *     const badge = document.getElementById(id); if (!badge) return;
 *
 * and returns having done nothing. The count only ever appeared on the NEXT
 * page load -- which is exactly "it remains dead until i visit any other page",
 * and exactly why adding from the home page (where a first-time visitor's cart
 * is always empty) never moved it.
 *
 * A case that asserted only the count > 0 rendering passes today AND passed on
 * the bug, because the bug was entirely in the zero branch. So every case below
 * that matters asserts on the EMPTY cart: the node is present and hidden, not
 * absent.
 *
 * The wishlist badge two lines above in the same partial already did it right
 * (`<i id="kbbWishCt" style="display:...">`, always rendered), and
 * wishlist.js `setBadge()` restores `''`. The cart badges now match that shape.
 *
 * ── THE HIDDEN STATE HAS TO BE ONE THE UPDATER CAN OVERRIDE ─────────────────
 *
 * setCartCount() restores `display:grid` for #cartCt and `display:''` for
 * #tabCartCt (the header badge lays out as a grid, the tab-bar one does not).
 * Both of those beat an inline `display:none`, and neither `.ib i` nor
 * `.tabbar i` in resources/css/kbb/kbb.css declares a `display` of its own, so
 * clearing the inline value on #tabCartCt falls through to the stylesheet and
 * the badge shows. Hiding by any other means -- a class the JS does not remove,
 * `visibility`, a zero width -- would leave the badge invisible after an add
 * with nothing here going red, so the hidden state is pinned as a literal below.
 *
 * ── MUTATIONS THESE CATCH, EACH ONE RUN ─────────────────────────────────────
 *
 *   - either badge wrapped back in `@if (($kbbCartCount ?? 0) > 0)`
 *   - #cartCt's shown state changed from `grid` to `none`
 *   - #tabCartCt's hidden state changed from `none` to `''` (always visible)
 *   - `{{ $kbbCartCount ?? 0 }}` replaced with a hard-coded 0, which would keep
 *     the element present but break the server-rendered first paint
 */

use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/** The one attribute-order-independent way to read a badge out of the page. */
function cartBadgeTag(string $html, string $id): ?string
{
    return preg_match('/<i id="' . preg_quote($id, '/') . '"[^>]*>.*?<\/i>/s', $html, $m)
        ? $m[0]
        : null;
}

function cartBadgeProduct(string $name): Product
{
    return Product::create([
        'slug' => Str::slug($name) . '-' . uniqid(),
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 4200,
        'stock_status' => 'instock',
    ]);
}

/** Load a storefront page carrying the cart cookie of an already-filled cart. */
function cartBadgePageWithCart(Tests\TestCase $test, string $uri = '/'): string
{
    $cart = Cart::query()->where('status', 'active')->firstOrFail();

    return $test
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get($uri)
        ->assertOk()
        ->getContent();
}

it('renders the header cart badge on an empty cart, hidden rather than absent', function () {
    // No cookies at all: a brand-new visitor landing on the home page, which is
    // the exact journey in the report.
    $html = $this->get('/')->assertOk()->getContent();

    $badge = cartBadgeTag($html, 'cartCt');

    // Present. This is the whole bug: getElementById() has to find something.
    expect($badge)->not->toBeNull();
    // And hidden, by an inline display the updater's `grid` overrides.
    expect($badge)->toContain('display:none');
});

it('renders the mobile tab-bar cart badge on an empty cart too', function () {
    app(SettingsService::class)->setModule('mobile_tabbar', true);

    $html = $this->get('/')->assertOk()->getContent();

    $badge = cartBadgeTag($html, 'tabCartCt');

    expect($badge)->not->toBeNull();
    expect($badge)->toContain('display:none');
});

it('shows the header badge with the real count on first paint', function () {
    // The server-rendered path still has to be right: fixing the JS path by
    // breaking this one would swap one dead badge for another.
    $product = cartBadgeProduct('Centella Ampoule');
    $this->postJson('/api/cart/add', ['product_id' => $product->id, 'quantity' => 3])->assertOk();

    $badge = cartBadgeTag(cartBadgePageWithCart($this), 'cartCt');

    expect($badge)->not->toBeNull()
        ->and($badge)->not->toContain('display:none')
        // `grid` is what setCartCount() restores, so first paint and every
        // later update agree on the layout rather than differing by one add.
        ->and($badge)->toContain('display:grid')
        ->and($badge)->toContain('>3<');
});

it('shows the tab-bar badge with the real count on first paint', function () {
    app(SettingsService::class)->setModule('mobile_tabbar', true);

    $product = cartBadgeProduct('Rice Sleeping Mask');
    $this->postJson('/api/cart/add', ['product_id' => $product->id, 'quantity' => 2])->assertOk();

    $badge = cartBadgeTag(cartBadgePageWithCart($this), 'tabCartCt');

    expect($badge)->not->toBeNull()
        ->and($badge)->not->toContain('display:none')
        ->and($badge)->toContain('>2<');
});

it('keeps the badge present and hidden on every storefront page, not just home', function () {
    // "until i visit any other page" -- the shop and product pages load with an
    // empty cart just as often as the home page does.
    $product = cartBadgeProduct('Yuja Niacin Serum');

    foreach (['/', '/shop/', '/product/' . $product->slug . '/'] as $uri) {
        $badge = cartBadgeTag($this->get($uri)->assertOk()->getContent(), 'cartCt');

        expect($badge)->not->toBeNull("no #cartCt on {$uri}");
        expect($badge)->toContain('display:none');
    }
});

it('hides by inline display, the one thing setCartCount actually overrides', function () {
    // setCartCount() writes badge.style.display. A hidden state expressed any
    // other way -- a class, visibility, width:0 -- survives the update and the
    // badge stays invisible after an add, which is the reported symptom wearing
    // a different hat. Pinned against the JS that has to undo it.
    $js = file_get_contents(base_path('resources/js/kbb/cart.js'));

    expect($js)->toContain("badge.style.display")
        ->and($js)->toContain("'grid'");

    $badge = cartBadgeTag($this->get('/')->assertOk()->getContent(), 'cartCt');

    expect($badge)->toContain('style="display:none"');
});

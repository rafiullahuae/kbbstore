<?php

/*
 * The cart page's heading, and the switch on its discount box.
 *
 * Three separate faults, one page. Each is pinned from BOTH sides — the markup
 * and the stylesheet that has to agree with it — because this project's
 * signature failure is a change that is real in one half and absent in the
 * other, and passes every "is it wired up" check there is.
 *
 *   1. "(14 items)" floated in dead space. The span in the h1 carried
 *      class="lead", and `.lead` in kbb.css is the FORM-FIELD LEADING-ICON
 *      class: position:absolute, left:13px, top:50%, translateY(-50%). So the
 *      count was taken out of flow and pinned to the nearest positioned
 *      ancestor. Measured at a 390px viewport before the fix, the count sat at
 *      y=411 while its own <h1> sat at y=149 — 262px below the heading it is
 *      part of, at the far left of the page. kbb-cart.css styled
 *      `.kbb-cartpage .lead` but never reset `position`, so the absolute rule
 *      was never in danger of losing.
 *
 *   2. A generic class name is the disease, not the symptom. `position:static`
 *      layered on top would have fixed this one page and left the collision in
 *      place for the next person to use `.lead` in a heading. The class is now
 *      `cart-count`, which nothing else claims. The id stays `cartLead` because
 *      resources/js/kbb/cart.js (another lane's file) looks it up by id.
 *
 *   3. The discount box on the cart page had no switch at all — `coupon_hint`
 *      governs only the promo SUGGESTION printed under it. It is now the
 *      `cart_coupon_field` module, off by default.
 */

use App\Models\Cart;
use App\Models\ModuleToggle;
use App\Models\Product;
use App\Services\CartService;
use App\Services\ModuleRegistry;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/** Committed content for a path, as git has it. What ships is what is committed. */
function cartLayoutTracked(string $path): ?string
{
    $out = shell_exec('git -C '.escapeshellarg(base_path()).' show HEAD:'.escapeshellarg($path).' 2>/dev/null');

    return ($out === null || $out === '') ? null : $out;
}

/** The built stylesheet the cart page actually loads, resolved through the Vite manifest. */
function cartLayoutBuiltCss(string $entry): string
{
    $manifest = json_decode((string) cartLayoutTracked('public/build/manifest.json'), true);

    expect($manifest)->toBeArray()->toHaveKey($entry);

    $file = $manifest[$entry]['file'] ?? null;
    expect($file)->not->toBeNull();

    $css = cartLayoutTracked('public/build/'.$file);
    expect($css)->not->toBeNull("public/build/{$file} is referenced by the manifest but is not committed.");

    return (string) $css;
}

function cartLayoutCart(): Cart
{
    $product = Product::create([
        'slug' => 'cart-layout-'.Str::random(8),
        'name' => 'Glow Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12000,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 14, 'unit_price' => 12000]);

    return $cart;
}

/** A browser carrying that cart's cookie. Same cookie handling as the other cart tests. */
function cartLayoutGet(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/cart');
}

/* ------------------------------------------------------------------------
 | 1. The heading count
 |------------------------------------------------------------------------*/

it('renders the cart count with a class the form-field icon rule cannot claim', function () {
    $html = cartLayoutGet(cartLayoutCart())->assertOk()->getContent();

    // The count is still in the heading, still carries the id cart.js updates.
    expect($html)->toContain('<span class="cart-count" id="cartLead">')
        ->and($html)->toContain('(14 items)');

    // And nothing on this page carries the icon class any more.
    expect($html)->not->toContain('class="lead" id="cartLead"');

    // Inside the <h1>, not after it: "beside the heading" is a DOM fact before
    // it is a CSS one, and a count that has escaped the h1 can be put anywhere
    // by any later rule.
    expect((bool) preg_match('#<h1>Your Bag <span class="cart-count" id="cartLead">\([^<]*\)</span></h1>#', $html))
        ->toBeTrue('The count is no longer the last child of the cart <h1>.');
});

it('has no cart-page rule left that the absolute .lead rule would beat', function () {
    // Comments in both files discuss `.lead` deliberately, and the whole point
    // of this change is that the reasoning is written down next to it. Only
    // declarations count.
    $strip = fn (string $css) => (string) preg_replace('#/\*.*?\*/#s', '', $css);

    $cart = $strip((string) file_get_contents(base_path('resources/css/kbb/kbb-cart.css')));
    $base = $strip((string) file_get_contents(base_path('resources/css/kbb/kbb.css')));

    // The collision itself, stated: kbb.css still owns `.lead` as an absolutely
    // positioned form-field icon. That is fine — it is what the class is for.
    // This assertion exists so that if `.lead` ever stops being positioned, the
    // reasoning in this file is known to be stale rather than quietly wrong.
    expect($base)->toContain('.lead,.trail{position:absolute');

    // The cart page must not style `.lead` at all any more. A rule that sets
    // type but not `position` cannot win against the rule above, which is
    // exactly how this shipped.
    expect($cart)->not->toContain('.kbb-cartpage .lead');

    expect($cart)->toContain('.kbb-cartpage .cart-count')
        ->and($cart)->toContain('position:static');
});

it('ships a built cart stylesheet carrying the renamed class', function () {
    // resources/css is not what the server serves. public/build is, and a
    // package has shipped with stale assets in this project before.
    $css = cartLayoutBuiltCss('resources/css/kbb/kbb-cart.css');

    expect($css)->toContain('.cart-count')
        ->and($css)->not->toContain('.kbb-cartpage .lead');
});

/* ------------------------------------------------------------------------
 | 2. The mobile checkout's horizontal overflow
 |------------------------------------------------------------------------*/

it('clips the free-shipping celebration so it cannot widen the checkout', function () {
    /*
     * Measured, not guessed. At a real 390x844 viewport with free delivery
     * unlocked, documentElement.scrollWidth was 397 against clientWidth 390:
     * two `.fs-cheer i` particles at right:396.21 and 397.21. They are
     * decorative (aria-hidden), and their animations run `forwards`, so the
     * final keyframe — opacity:0, translated outward — sticks for the life of
     * the page. Invisible, still laid out, still scrollable.
     *
     * `clip` and not `hidden`: `hidden` on one axis forces the other to `auto`,
     * which would clip the vertical half of the burst through an 8px-tall bar.
     */
    $src = (string) preg_replace('#/\*.*?\*/#s', '',
        (string) file_get_contents(base_path('resources/css/kbb/kbb-checkout.css')));

    expect($src)->toContain('.kbb-checkout .freebar{overflow-x:clip}');
    expect($src)->not->toContain('.kbb-checkout .freebar{overflow-x:hidden}');

    expect(cartLayoutBuiltCss('resources/css/kbb/kbb-checkout.css'))->toContain('overflow-x:clip');
});

/* ------------------------------------------------------------------------
 | 3. The discount box switch
 |------------------------------------------------------------------------*/

it('registers cart_coupon_field as a live module that is off by default', function () {
    expect(ModuleRegistry::REGISTRY)->toHaveKey('cart_coupon_field');

    [$group, $name, $desc, $default, $screen, $route, $surface, $band, $where, $status]
        = ModuleRegistry::REGISTRY['cart_coupon_field'];

    expect($default)->toBeFalse('The cart-page discount box must default to off.')
        ->and($group)->toBe('cart')
        // `live` in this registry means something on the storefront reads
        // moduleEnabled() for the key. A `live` row nothing reads is the fault
        // the seo_engine/product_sorting package existed to correct.
        ->and($status)->toBe('live')
        // The admin's hover card draws a wireframe per surface; an unknown one
        // renders a blank card.
        ->and($surface)->toBe('cartpage')
        ->and($band)->toBe('mid');
});

it('hides the cart discount box when nothing has been configured', function () {
    expect(ModuleToggle::where('module', 'cart_coupon_field')->exists())->toBeFalse();

    $html = cartLayoutGet(cartLayoutCart())->assertOk()->getContent();

    expect($html)->not->toContain('id="kbbCartCoupon"')
        ->and($html)->not->toContain('data-kcpcoupon')
        // The hint only makes sense beside the box it hints at.
        ->and($html)->not->toContain('class="cohint"');

    // Off, not deleted: the rest of the summary is untouched.
    expect($html)->toContain('Order Summary')
        ->and($html)->toContain('Proceed to checkout');
});

it('shows the cart discount box once the module is turned on', function () {
    app(SettingsService::class)->setModule('cart_coupon_field', true);

    $html = cartLayoutGet(cartLayoutCart())->assertOk()->getContent();

    expect($html)->toContain('id="kbbCartCoupon"')
        ->and($html)->toContain('data-kcpcoupon');
});

it('keeps a choice an install has already made', function () {
    /*
     * The half of "off by default" that is easy to get wrong. A default belongs
     * in the registry, where it applies only when no row exists. Writing the
     * default into module_toggles would turn "nobody has been asked" into "the
     * owner chose off", and a re-run would then overwrite a real decision.
     *
     * Asserted two ways: the stored value wins over the default, and no
     * migration in the tree writes this key at all.
     */
    ModuleToggle::create(['module' => 'cart_coupon_field', 'enabled' => true]);

    expect(app(SettingsService::class)->moduleEnabled('cart_coupon_field', false))->toBeTrue();
    expect(cartLayoutGet(cartLayoutCart())->assertOk()->getContent())->toContain('id="kbbCartCoupon"');

    $writers = [];
    foreach (glob(base_path('database/migrations/*.php')) ?: [] as $file) {
        $source = (string) file_get_contents($file);
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $source);
        $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

        if (str_contains($code, 'cart_coupon_field')) {
            $writers[] = basename($file);
        }
    }

    expect($writers)->toBe([], 'A migration writes cart_coupon_field: '.implode(', ', $writers));
});

it('leaves the checkout page its own discount box', function () {
    // The module gates the CART page only. The checkout's coupon box is a
    // different surface and is not part of this switch.
    $checkout = (string) file_get_contents(base_path('resources/views/store/checkout.blade.php'));

    expect($checkout)->toContain('id="kbb_coupon_code"')
        ->and($checkout)->toContain('id="kbb_apply_coupon"')
        ->and($checkout)->not->toContain('cart_coupon_field');
});

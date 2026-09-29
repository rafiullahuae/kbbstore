<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Services\CartService;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * THE CART PAGE CLIPPED TWO THINGS IT SHOULD NOT HAVE — Lane CR
 * =============================================================================
 *
 * The owner, with two screenshots of his live basket:
 *
 *   "i have change the padding etc for desktop for set rows, but still the set
 *    name and the quanity button is hiding, and also the tiny popup also
 *    hiding inside the set row, it should show on top of and not hide
 *    anywhere."
 *
 * TWO DEFECTS, ONE FAMILY: an `overflow:hidden` whose job somebody else was
 * relying on.
 *
 * ── 1. THE POPUP ───────────────────────────────────────────────────────────
 *
 * `resources/css/kbb/kbb-cart.css` gave `.kbb-cartpage .items` a 13px radius
 * and `overflow:hidden` to cut it. `.kset-pop` is `position:absolute` off
 * `.kset`, which is inside that panel, so the clip reached it: an overflow
 * ancestor clips an absolutely-positioned descendant whenever it is in that
 * descendant's containing-block chain, and a `position:relative` `.kset` inside
 * a static `.items` is exactly that.
 *
 * MEASURED IN CHROMIUM, popup open on the LAST row of a four-row basket. A clip
 * does not shrink getBoundingClientRect — clipping is a paint effect — so the
 * evidence is document.elementFromPoint() 8px inside the popup's own bottom
 * edge, which reports what the engine would really hit-test there:
 *
 *                popup overflows .items by     probe hits, before    after
 *     390px               69px                     `sum`          `kset-pop`
 *     1280px              65px                     `wrap`         `kset-pop`
 *
 * On the FIRST row the probe hit the popup before the change too, because a
 * popup that fits inside the panel was never clipped — which is why this read
 * as intermittent.
 *
 * ── 2. THE NAME AND THE STEPPER ────────────────────────────────────────────
 *
 * `store/cart-squeeze.blade.php` draws EVERY basket line at a fixed height:
 * `.kbb-cartpage.cpg-squeeze .ci{height:var(--cpg-row-h);…;overflow:hidden}`.
 * That is the whole point of the dense layout and it is right for an ordinary
 * line. A SET line carries a block that height was never sized for — the fanned
 * stack and its button, between the name and the stepper — and `.cmid` is
 * `justify-content:center`, so what does not fit overflows at BOTH ends and the
 * clip takes exactly the two things at the two ends: the name at the top and
 * the quantity stepper at the bottom, leaving the circles, the button and the
 * saving in the middle. That is the owner's screenshot, element for element.
 *
 * MEASURED on a four-line basket with Appearance → Set → Desktop · Set row on
 * the cart page carrying his kind of change (row padding 30px):
 *
 *                 row box      name              stepper
 *     390px      446..542    405..444  (41px above the row)  554..583 (41px below)
 *     1280px     419..515    416..435  (3px clipped)         489..518 (3px clipped)
 *
 * At 390 the name and the stepper were entirely outside the painted box. And it
 * is why MORE padding did not help him: SetAppearance's row padding wins on
 * specificity but never touches `height`, so it is less room inside the same
 * 96px.
 *
 * After the fix, same basket, same settings: the set row is 167px at 390 and
 * 163px at 1280, every ordinary row is still exactly 96px, and nothing is
 * clipped at either end.
 *
 * ── MUTATION NOTES, BOTH RUN ───────────────────────────────────────────────
 *
 * 1. Put `overflow:hidden` back on `.kbb-cartpage .items` in
 *    resources/css/kbb/kbb-cart.css and rebuild: the first case is red.
 * 2. Change `min-height:var(--cpg-row-h)` back to `height:var(--cpg-row-h)` in
 *    the `.cpg-squeeze … .ci.ci-set` rule and rebuild: the second case is red.
 * 3. Delete the `ci-set` marker from store/cart-inner.blade.php: the third case
 *    is red — a rule scoped to a class nothing carries matches nothing, which
 *    is the ProductStyles shape this project has already paid for.
 * 4. Edit resources/css/kbb/kbb-cart.css WITHOUT running `npx vite build`: the
 *    fourth case is red, naming the two that disagree.
 */

/** Committed content for a path, as git has it. What ships is what is committed. */
function crTracked(string $path): ?string
{
    $out = shell_exec('git -C '.escapeshellarg(base_path()).' show HEAD:'.escapeshellarg($path).' 2>/dev/null');

    return ($out === null || $out === '') ? null : $out;
}

/**
 * The built stylesheet the cart page actually loads, resolved through the Vite
 * manifest — NOT resources/css. package.json defines no `build` script and CI
 * does not build assets, so the source file and the served file are two
 * different questions and only one of them reaches the shop.
 */
function crBuiltCartCss(): string
{
    $manifest = json_decode((string) crTracked('public/build/manifest.json'), true);
    $entry = 'resources/css/kbb/kbb-cart.css';

    expect($manifest)->toBeArray()->toHaveKey($entry);

    $file = $manifest[$entry]['file'] ?? null;
    expect($file)->not->toBeNull();

    $css = crTracked('public/build/'.$file);
    expect($css)->not->toBeNull("public/build/{$file} is referenced by the manifest but is not committed.");

    return (string) $css;
}

/** A basket holding an ordinary product and a set, as the owner's does. */
function crCartWithASet(): Cart
{
    $plain = Product::create([
        'slug' => 'cr-plain-'.Str::random(8), 'name' => 'Glow Serum', 'type' => 'simple',
        'status' => 'publish', 'is_visible' => true, 'price' => 9500, 'stock_status' => 'instock',
    ]);

    $set = Product::create([
        'slug' => 'cr-set-'.Str::random(8), 'name' => 'Glass Skin Set', 'type' => 'set',
        'status' => 'publish', 'is_visible' => true, 'price' => 12000, 'stock_status' => 'instock',
    ]);

    for ($i = 0; $i < 3; $i++) {
        $member = Product::create([
            'slug' => 'cr-mem-'.Str::random(8), 'name' => 'Member '.$i, 'type' => 'simple',
            'status' => 'publish', 'is_visible' => true, 'price' => 6000, 'stock_status' => 'instock',
        ]);

        ProductSetItem::create([
            'set_product_id' => $set->id, 'member_product_id' => $member->id,
            'quantity' => 1, 'position' => $i,
        ]);
    }

    $cart = Cart::create([
        'token' => Str::random(32), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $plain->id, 'quantity' => 1, 'unit_price' => 9500]);
    $cart->items()->create(['product_id' => $set->id, 'quantity' => 1, 'unit_price' => 12000]);

    return $cart;
}

function crCartHtml(Cart $cart): string
{
    return (string) test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/cart')->assertOk()->getContent();
}

/** Whitespace out, so a reformat of the source cannot turn any of this red. */
function crTight(string $css): string
{
    return (string) preg_replace('/\s+/', '', $css);
}

it('does not clip the basket panel, and keeps the corners it was clipping for', function () {
    $css = crTight(crBuiltCartCss());

    // The panel itself: radius kept, clip gone.
    expect($css)->toContain('.kbb-cartpage.items{')
        ->and($css)->toMatch('/\.kbb-cartpage\.items\{[^}]*border-radius:13px/');

    preg_match('/\.kbb-cartpage\.items\{([^}]*)\}/', $css, $panel);

    /*
     * str_contains() and not ->toContain(): Pest's string toContain() takes a
     * LIST of needles, so a "message" passed beside one is silently asserted as
     * a second needle. Found by running this file.
     */
    expect(str_contains($panel[1] ?? '', 'overflow:hidden'))->toBeFalse(
        'The basket panel clips again. `.kset-pop` is absolute off a `.kset` inside it, so an '
        .'overflow ancestor clips it — measured at 390 and 1280, the bottom 69px and 65px of the '
        .'popup on the LAST row hit-tested to the page behind it.'
    );

    // The corners the clip used to produce, now stated. Logical, so /ar mirrors
    // from the same declaration — a physical `border-top-left-radius` here
    // would round the wrong corner on Arabic.
    expect($css)->toContain('.kbb-cartpage.items>.ci:first-child{border-start-start-radius:12px;border-start-end-radius:12px}')
        ->and($css)->toContain('.kbb-cartpage.items>.ci:last-child{border-end-start-radius:12px;border-end-end-radius:12px}');
});

it('lets a set row be as tall as a set row, and leaves every other row capped', function () {
    $css = crTight(crBuiltCartCss());

    /*
     * The exception, and it is scoped three ways: to the squeezed layout, to
     * the basket panel, and to a SET row. (0,5,0) against the squeezed sheet's
     * own (0,3,0), so it wins inside that sheet's media queries too.
     */
    expect(str_contains($css, '.kbb-cartpage.cpg-squeeze.items.ci.ci-set{height:auto;min-height:var(--cpg-row-h);overflow:visible}'))->toBeTrue(
        'The set row is capped again. A fixed height plus overflow:hidden on a row whose .cmid is '
        .'justify-content:center takes the name off the top and the stepper off the bottom, which is '
        .'exactly what the owner photographed.'
    );

    /*
     * AND THE DENSE LAYOUT IS STILL DENSE. The fix is an exception for one row,
     * never the removal of the row height — a shop that chose "Squeezed" chose
     * thirteen lines on a phone, and this must not quietly undo that.
     */
    $squeeze = crTight((string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php')));

    expect($squeeze)->toContain('.kbb-cartpage.cpg-squeeze.ci{height:var(--cpg-row-h);');
});

it('marks the set line and keeps its name and its quantity stepper in the page', function () {
    /*
     * A rule scoped to a class nothing carries matches nothing — the shape
     * ProductStyles shipped, where twenty controls reached no page for
     * releases. So this renders the real cart page and reads the bytes back
     * rather than asserting about a stylesheet.
     */
    $html = crCartHtml(crCartWithASet());

    expect(substr_count($html, 'class="ci ci-set"'))->toBe(1, 'exactly one set line');
    expect(substr_count($html, '<div class="ci">'))->toBe(1, 'exactly one ordinary line, marked as it always was');

    /*
     * The set line, from its own marker to its Remove control — a window and
     * not a fixed length, because the FIRST set on a page carries the
     * partial's @once <style> and <script> inside its own .cmid, which is
     * about 20KB between the name and the stepper.
     */
    $at = (int) strpos($html, 'class="ci ci-set"');
    $end = (int) strpos($html, 'data-kcprm', $at);
    $row = substr($html, $at, $end - $at + 40);

    expect($row)->toContain('Glass Skin Set')
        ->and($row)->toContain('<div class="cn">')
        ->and($row)->toContain('<div class="qty">')
        ->and($row)->toContain('data-kset-toggle');

    // The name comes BEFORE the fan and the stepper AFTER it, which is the
    // order the row's own height has to accommodate.
    $name = strpos($row, '<div class="cn">');
    $fan = strpos($row, 'class="kset-fan"');
    $qty = strpos($row, '<div class="qty">');

    expect($name)->toBeLessThan($fan)->and($fan)->toBeLessThan($qty);
});

it('ships the stylesheet it compiled, not the one it edited', function () {
    /*
     * `npx vite build` is manual in this repository and CI does not build
     * assets, so a rule added to resources/css and never rebuilt is real here
     * and absent on the live shop. Both of the rules above are compared against
     * the BUILT file; this is what notices when the two stop being the same
     * change.
     */
    $source = crTight((string) file_get_contents(resource_path('css/kbb/kbb-cart.css')));
    $built = crTight(crBuiltCartCss());

    foreach ([
        '.kbb-cartpage.items>.ci:last-child{border-end-start-radius:12px;border-end-end-radius:12px}',
        '.kbb-cartpage.cpg-squeeze.items.ci.ci-set{height:auto;min-height:var(--cpg-row-h);overflow:visible}',
    ] as $rule) {
        expect(str_contains($source, $rule))->toBeTrue($rule.' is missing from resources/css/kbb/kbb-cart.css');
        expect(str_contains($built, $rule))->toBeTrue($rule.' is in the source but not in public/build — run `npx vite build`');
    }
});

it('reaches the popup with no change to what an ordinary basket renders', function () {
    /*
     * Rule 1. The two rules above are CSS and cannot move a byte of markup, and
     * this says so rather than assuming it: a basket with no set in it renders
     * its row exactly as it always did, marker and all.
     */
    app(SettingsService::class)->forgetMemo();

    $plain = Product::create([
        'slug' => 'cr-only-'.Str::random(8), 'name' => 'Plain Serum', 'type' => 'simple',
        'status' => 'publish', 'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => Str::random(32), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);
    $cart->items()->create(['product_id' => $plain->id, 'quantity' => 1, 'unit_price' => 5000]);

    $html = crCartHtml($cart);

    expect($html)->toContain('<div class="ci">')
        ->and($html)->not->toContain('ci-set')
        ->and($html)->not->toContain('kset-');
});

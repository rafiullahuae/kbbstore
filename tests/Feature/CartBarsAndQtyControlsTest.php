<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Product;
use App\Services\CartPage;
use App\Services\CartService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * THE DELIVERY ROW'S OWN TYPE CONTROLS, AND THE QUANTITY STEPPER'S SIZE
 * =============================================================================
 *
 * Two requests, one lane:
 *
 *   "AND give also option to control the font size, bold etc for delivery
 *    sticky row."
 *   "also give option to control the size of the - + quanity icon etc."
 *
 * NOTHING HERE PINS A LITERAL. This repo has been burned twice by tests that
 * pinned a number out of a stylesheet (`z-index:40`, `font-size:clamp(`) and
 * went red the next time somebody touched an unrelated rule, and once by a
 * test that pinned a BROKEN url and stayed green over a button that had never
 * worked. So every list below is READ OUT OF THE CODE:
 *
 *   - which settings move the page is discovered by moving each one and
 *     watching cssVariables(), not by naming four keys here;
 *   - which custom properties exist is parsed from what cssVariables() emits;
 *   - the preview's obligation is derived from that same discovered list.
 *
 * The consequence worth having is that a control added to this schema NEXT
 * year, by a lane that has never read this file, is covered the day it lands:
 * if it moves the page and not the preview, this goes red.
 *
 * MUTATIONS — each was applied, run, and the result recorded beside it:
 *
 *   M1  Drop `* var(--cpg-qty-s)` from --cpg-qty-h in cart-squeeze.blade.php.
 *       RED on "the stepper scales as one shape". Run and confirmed.
 *   M2  Delete the `'--qs:'` line from pvVars() in cart-page-screen.blade.php.
 *       RED on "every control that moves the page moves the preview". Run and
 *       confirmed.
 *   M3  Make cssVariables() emit a constant for --cpg-addr-f instead of the
 *       saved value. RED on "each new control moves exactly one property".
 *       Run and confirmed.
 *   M4  Put the literal `font-weight:600` back on .cpg-addrbtn. RED on "every
 *       property the service emits is read by a rule". Run and confirmed.
 */
function barsQtyRoutes(): void
{
    if (app('router')->getRoutes()->hasNamedRoute('cart.address')) {
        return;
    }

    Route::middleware('web')->group(base_path('routes/cart-address.php'));
    app('router')->getRoutes()->refreshNameLookups();
}

function barsQtyCart(): Cart
{
    $product = Product::create([
        'slug' => 'bq-'.Str::random(8),
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

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 12000]);

    return $cart;
}

/**
 * The custom properties as the BROWSER would receive them: parsed off the
 * style attribute of the real rendered page, not off the service's return
 * value. A property the service builds and the view forgets to print is the
 * failure this shape catches and a unit test on cssVariables() does not.
 *
 * @return array<string, string>
 */
function barsQtyRendered(Cart $cart): array
{
    $html = test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/cart')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('id="cartPage"');

    preg_match('/<div class="kbb-cartpage[^"]*" id="cartPage" style="([^"]*)"/', (string) $html, $m);

    expect($m)->not->toBeEmpty('the cart page wrapper carries no style attribute at all');

    $out = [];

    foreach (explode(';', html_entity_decode($m[1], ENT_QUOTES)) as $pair) {
        if (str_contains($pair, ':')) {
            [$name, $value] = explode(':', $pair, 2);
            $out[trim($name)] = trim($value);
        }
    }

    return $out;
}

/** A value for this key that is NOT its default, taken from the key's own schema. */
function barsQtyOtherValue(string $key): mixed
{
    $def = CartPage::SCHEMA[$key];

    return match ($def[0]) {
        'range' => $def[2] === $def[4]['max'] ? $def[4]['min'] : $def[4]['max'],
        'bool' => ! $def[2],
        'select' => collect(array_keys($def[4]))->first(fn ($k) => (string) $k !== (string) $def[2]),
        default => null,
    };
}

/**
 * Every schema key whose value reaches the page as a custom property, found by
 * moving each one and watching what comes out. Discovered, never listed.
 *
 * @return list<string>
 */
function barsQtyVisualKeys(): array
{
    $page = app(CartPage::class);
    $page->save(['layout' => 'squeeze']);

    $base = $page->cssVariables();
    $keys = [];

    foreach (CartPage::SCHEMA as $key => $def) {
        if ($key === 'layout' || ! in_array($def[0], ['range', 'bool', 'select'], true)) {
            continue;
        }

        $page->save([$key => barsQtyOtherValue($key)]);

        if ($page->cssVariables() !== $base) {
            $keys[] = $key;
        }

        $page->save([$key => $def[2]]);
    }

    return $keys;
}

function barsQtySqueezeCss(): string
{
    return (string) \Tests\Support\CartPageStyles::all();
}

function barsQtyScreenSrc(): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/cart-page-screen.blade.php'));
}

/* ------------------------------------------------------------------------
 | 1. The new controls reach the rendered page, and touch nothing else
 |------------------------------------------------------------------------*/

it('moves exactly one custom property per new control, on the real page', function () {
    /*
     * "the control MOVES the rendered output" — set it, render the cart page
     * over HTTP, and compare the property map against the same page at its
     * defaults.
     *
     * EXACTLY ONE, which is the half that says the control is surgical. The
     * owner's standing order is "without disturbing anything existing", and a
     * delivery-row slider that also nudged the checkout row would satisfy
     * "something changed" while breaking the thing he asked for.
     */
    barsQtyRoutes();
    $cart = barsQtyCart();
    $page = app(CartPage::class);

    $page->save(['layout' => 'squeeze']);
    $before = barsQtyRendered($cart);

    foreach (['addr_font', 'addr_bold', 'addr_btn_bold', 'qty_size'] as $key) {
        $page->save([$key => barsQtyOtherValue($key)]);
        $after = barsQtyRendered($cart);
        $page->save([$key => CartPage::SCHEMA[$key][2]]);

        $changed = array_keys(array_diff_assoc($after, $before));

        expect($changed)->toHaveCount(
            1,
            "'{$key}' should move one custom property on the rendered page and moved "
            .count($changed).' ('.implode(', ', $changed).')'
        );

        // And the property it moved is one a rule in the stylesheet reads —
        // otherwise it is emitted into a vacuum and the slider does nothing.
        expect(barsQtySqueezeCss())->toContain('var('.$changed[0]);
    }
});

/* ------------------------------------------------------------------------
 | 2. Nothing is emitted into a vacuum
 |------------------------------------------------------------------------*/

it('has a rule reading every custom property the service emits', function () {
    /*
     * The property names are PARSED OUT OF cssVariables(), so this covers the
     * whole set rather than the four this lane added, and a property that
     * stops being read — by a rule reverting to a literal, say — is caught
     * whoever emits it.
     */
    app(CartPage::class)->save(['layout' => 'squeeze']);

    $names = [];

    foreach (explode(';', app(CartPage::class)->cssVariables()) as $pair) {
        $names[] = trim(explode(':', $pair, 2)[0]);
    }

    expect($names)->not->toBeEmpty();

    $css = barsQtySqueezeCss();

    foreach ($names as $name) {
        expect(str_contains($css, 'var('.$name))->toBeTrue(
            "nothing in cart-squeeze.blade.php reads {$name}, so the setting behind it saves and moves nothing"
        );

        // A fallback in the stylesheet's own block too, so the sheet still
        // draws today's page when the style attribute never arrives.
        expect(str_contains($css, $name.':'))->toBeTrue(
            "{$name} has no fallback declaration in the .cpg-squeeze block"
        );
    }
});

/* ------------------------------------------------------------------------
 | 3. The stepper grows as one shape, and still follows the row height
 |------------------------------------------------------------------------*/

it('scales the stepper as one shape and keeps it derived from the row height', function () {
    /*
     * THE TWO PROPERTIES THAT MUST BOTH CARRY THE MULTIPLIER. --cpg-qty-h is
     * the box, --cpg-qty-f is the − / + glyph and the digit between them. With
     * the multiplier on only one of them the ratio between them changes with
     * the slider, and at one end of its travel the digit overflows a box that
     * did not grow with it. With it on both, every setting draws today's
     * stepper at a different scale, which cannot overflow because today's does
     * not.
     *
     * AND STILL OFF --cpg-row-h, which is not decoration either: "if i adjust
     * the height of rows then inner content must adjust automatically". A
     * pixel size here would be a direct reversal of that.
     */
    $css = barsQtySqueezeCss();

    preg_match('/--cpg-qty-h:([^;]+);/', $css, $h);
    preg_match('/--cpg-qty-f:([^;]+);/', $css, $f);

    expect($h)->not->toBeEmpty()->and($f)->not->toBeEmpty();

    foreach (['--cpg-qty-h' => $h[1], '--cpg-qty-f' => $f[1]] as $name => $expr) {
        expect(str_contains($expr, 'var(--cpg-row-h)'))->toBeTrue(
            "{$name} no longer derives from the row height, so the stepper has stopped "
            .'adjusting when the row is made shorter'
        );

        expect(str_contains($expr, 'var(--cpg-qty-s)'))->toBeTrue(
            "{$name} does not carry the stepper multiplier, so the box and the glyph scale "
            .'apart and the digit can outgrow its box'
        );
    }

    // Nothing measures anything — the sizing stays in CSS, as the page's own
    // header promises.
    expect($css)->not->toContain('ResizeObserver')
        ->and($css)->not->toContain('getBoundingClientRect');
});

/* ------------------------------------------------------------------------
 | 4. The preview moves with every one of them
 |------------------------------------------------------------------------*/

it('moves the admin preview with every control that moves the page', function () {
    /*
     * The owner has asked four times for this preview, and a preview that
     * lies about a control is worse than none — that exact bug is open on the
     * Mobile Header screen.
     *
     * BOTH LISTS ARE DERIVED. The keys come from barsQtyVisualKeys(), which
     * finds them by moving each setting and watching cssVariables(); the
     * preview's properties are parsed out of pvVars(). Neither is written
     * here, so this holds for controls nobody has thought of yet.
     */
    $src = barsQtyScreenSrc();

    $vars = substr($src, (int) strpos($src, 'function pvVars()'));
    $vars = substr($vars, 0, (int) strpos($vars, 'function pvRows('));

    $keys = barsQtyVisualKeys();

    // Sanity: the discovery found something. A silently empty list would make
    // every assertion below vacuous.
    expect(count($keys))->toBeGreaterThan(10);

    foreach ($keys as $key) {
        expect(str_contains($vars, "'{$key}'"))->toBeTrue(
            "'{$key}' changes the shop's custom properties but pvVars() never reads it — "
            .'the control saves, reports success and the drawing does not move'
        );
    }

    // Half two: every property pvVars() emits is read by a rule in the preview
    // stylesheet. Emitting it is not the same as drawing with it.
    preg_match_all("/'(--[a-z-]+):'/", $vars, $m);

    expect($m[1])->not->toBeEmpty();

    foreach ($m[1] as $name) {
        expect(str_contains($src, 'var('.$name))->toBeTrue(
            "the preview emits {$name} and no rule reads it, so it is drawn into a vacuum"
        );
    }
});

/* ------------------------------------------------------------------------
 | 5. The preview draws the things these controls change
 |------------------------------------------------------------------------*/

it('draws the stepper on the rows tab and the delivery row on the bars tab', function () {
    /*
     * A variable that lands on an element the preview never draws is the same
     * failure as one nothing reads: the slider moves and the owner sees
     * nothing. The stepper belongs to the tab qty_size is on, and the docked
     * address row to the tab the addr_* controls are on — read off TABS rather
     * than assumed, because a key moved between tabs would otherwise leave
     * this passing about the wrong screen.
     */
    $src = barsQtyScreenSrc();

    $tabOf = function (string $key): string {
        foreach (CartPage::TABS as $tab => [, , $keys]) {
            if (in_array($key, $keys, true)) {
                return $tab;
            }
        }

        return '';
    };

    expect($tabOf('qty_size'))->toBe('rows')
        ->and($tabOf('addr_font'))->toBe('bars')
        ->and($tabOf('addr_bold'))->toBe('bars')
        ->and($tabOf('addr_btn_bold'))->toBe('bars');

    // The 'rows' tab draws pvRows(), which must contain the stepper element
    // the qty rules paint.
    $rows = substr($src, (int) strpos($src, 'function pvRows('));
    $rows = substr($rows, 0, (int) strpos($rows, 'function pvRail('));
    expect(str_contains($rows, 'cpv-qty'))->toBeTrue(
        'the rows tab draws no quantity stepper, so its size control shows nothing'
    );

    // The 'bars' tab draws pvBars(), which must contain the address row in
    // both of its states — the heading and the button are what addr_bold and
    // addr_btn_bold paint.
    $bars = substr($src, (int) strpos($src, 'function pvBars('));
    $bars = substr($bars, 0, (int) strpos($bars, 'function pvSheet('));
    expect(str_contains($bars, 'cpv-ab'))->toBeTrue('the bars tab draws no address row')
        ->and(str_contains($bars, 'class="bt"'))->toBeTrue(
            'the drawn address row has no button, so its weight and size controls show nothing'
        );
});

/* ------------------------------------------------------------------------
 | 6. The defaults are still the page that ships
 |------------------------------------------------------------------------*/

it('leaves the classic page untouched and the squeezed page at the weights it has today', function () {
    /*
     * The guarantee the rest of the schema rests on, restated for the four
     * controls added here. On `classic` — what every shop ships with — the
     * service emits nothing at all, so none of this exists on the page.
     *
     * On `squeeze` the new properties DO appear, and each one is the value the
     * stylesheet used to hold as a literal: 1 for the two multipliers, 500 for
     * the heading the `.who b` rule painted by hand, 600 for the button. The
     * declarations are new; the rendering is not.
     */
    $page = app(CartPage::class);

    expect($page->get('layout'))->toBe('classic')
        ->and($page->cssVariables())->toBe('');

    $page->save(['layout' => 'squeeze']);
    $vars = [];

    foreach (explode(';', $page->cssVariables()) as $pair) {
        [$name, $value] = explode(':', $pair, 2);
        $vars[$name] = $value;
    }

    // Read the weights the shop painted before these settings existed straight
    // out of the stylesheet's own fallback block, so this cannot drift from it.
    $css = barsQtySqueezeCss();

    foreach (['--cpg-addr-f', '--cpg-addr-bold', '--cpg-addrbtn-bold', '--cpg-qty-s'] as $name) {
        preg_match('/'.preg_quote($name, '/').':\s*([^;]+);/', $css, $m);

        expect($m)->not->toBeEmpty("{$name} has no fallback in the stylesheet to compare against");
        expect((float) $vars[$name])->toBe(
            (float) $m[1],
            "at its default {$name} does not match the value the stylesheet falls back to, "
            .'so switching the layout on changes a shop that has saved nothing'
        );
    }
});

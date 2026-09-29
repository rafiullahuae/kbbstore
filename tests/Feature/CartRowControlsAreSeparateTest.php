<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Services\CartPage;
use App\Services\CartService;
use App\Services\SetAppearance;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * TWO SETS OF ROW CONTROLS, AND NEITHER CAN REACH THE OTHER'S ROW — Lane CR
 * =============================================================================
 *
 * The owner:
 *
 *   "on the cart page.. for product rows and set rows. i need totally different
 *    controls like height spacing, padding etc. the products rows controls will
 *    be on the Cart Page under appearance as we have already, but make more
 *    controls of spacing etc. and for set rows, make controls that only
 *    controls the set rows. not the other products rows."
 *
 * He has asked for this twice. The first time — with four exclamation marks —
 * was because Appearance → SET was moving the padding of every row in the
 * basket. This file is that promise as a test, from both directions.
 *
 * ── THE SCOPING, AND WHY IT IS NOT A CASCADE QUESTION ──────────────────────
 *
 *   ordinary rows   `.kbb-cartpage .items .ci:not(.ci-set)`   (0,4,0)
 *   a set's row     `.kbb-cartpage .items .ci.ci-set`         (0,4,0)
 *
 * Equal specificity, and no element can match both — `:not()` takes the
 * specificity of its argument, so the two are exactly as strong as each other
 * and exactly as strong as they need to be against the compiled sheet's
 * `.kbb-cartpage .ci` at (0,2,0) and the squeezed sheet's (0,3,0). Two
 * selectors that cannot both match one element never argue.
 *
 * ── AND NEITHER SCREEN CAN HIDE A ROW'S OWN CONTENTS ───────────────────────
 *
 * The defect this lane was given was a set row losing its name and its stepper
 * to a fixed `height` with `overflow:hidden`. So the height control on both
 * screens is a MINIMUM, and the fourth case below walks EVERY new control to
 * both ends of its range and asserts that no value of any of them can put
 * `height:`, `overflow:hidden`, `display:none` or `visibility:hidden` into a
 * cart row's rules. The bad state is unreachable rather than discouraged.
 *
 * ── MUTATION NOTES, ALL RUN ────────────────────────────────────────────────
 *
 * 1. Change `:not(.ci-set)` to `.ci` in CartPage::rowBlock(): the second case
 *    is red — the cart-page controls now reach a set's row.
 * 2. Change `.ci.ci-set` to `.ci.ci` in SetAppearance::css(): the third case is
 *    red — the set controls reach every row, which is the owner's original
 *    complaint.
 * 3. Make `ci_min_h` emit `height:` instead of `min-height:` in either service:
 *    the fourth case is red, naming the key and the value.
 * 4. Delete the @include from store/cart.blade.php: the fifth case is red at
 *    zero — the "built, never wired up" shape, not an absence assertion.
 * 5. Raise any ci_* default in either schema: the first case is red, because
 *    the emitter stops being silent on a shop that has moved nothing.
 */

function crSettings(): SettingsService
{
    SettingsService::forgetMemo();

    return app(SettingsService::class);
}

/** A basket with an ordinary line and a set line, rendered through the real page. */
function crTwoLineCartHtml(): string
{
    $plain = Product::create([
        'slug' => 'crs-plain-'.Str::random(8), 'name' => 'Glow Serum', 'type' => 'simple',
        'status' => 'publish', 'is_visible' => true, 'price' => 9500, 'stock_status' => 'instock',
    ]);

    $set = Product::create([
        'slug' => 'crs-set-'.Str::random(8), 'name' => 'Glass Skin Set', 'type' => 'set',
        'status' => 'publish', 'is_visible' => true, 'price' => 12000, 'stock_status' => 'instock',
    ]);

    for ($i = 0; $i < 2; $i++) {
        $member = Product::create([
            'slug' => 'crs-mem-'.Str::random(8), 'name' => 'Member '.$i, 'type' => 'simple',
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

    return (string) test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/cart')->assertOk()->getContent();
}

it('adds no rule and no byte to a shop that has moved nothing', function () {
    /*
     * Rule 1, on both screens at once. Every new default was copied off
     * resources/css/kbb/kbb-cart.css declaration by declaration, so a shop that
     * applies this package and touches nothing renders the page it rendered
     * yesterday — and the two emitters say so by answering the empty string
     * rather than by restating the defaults, which would be right in pixels and
     * wrong in bytes.
     */
    crSettings();

    expect(app(CartPage::class)->rowCss())->toBe('');
    expect(app(SetAppearance::class)->storefrontCss())->toBe('');

    $html = crTwoLineCartHtml();

    expect($html)->not->toContain('kbb-cartrows')
        ->and($html)->not->toContain('id="kbb-set"');
});

it('lets the cart-page controls shape an ordinary row and never a set row', function () {
    $s = crSettings();
    $s->set('cartpage_ci_pad_t', 20);
    $s->set('cartpage_ci_thumb', 72);
    $s->set('cartpage_ci_name_f', 160);
    $s->set('cartpage_ci_min_h', 110);

    SettingsService::forgetMemo();
    $css = app(CartPage::class)->rowCss();

    expect($css)->toContain('.kbb-cartpage .items .ci:not(.ci-set){min-height:110px')
        ->and($css)->toContain('padding-block:20px 11px')
        ->and($css)->toContain('.ci:not(.ci-set) .cth{width:72px;height:72px')
        ->and($css)->toContain('.ci:not(.ci-set) .cn{font-size:16px');

    /*
     * THE HALF THAT MATTERS. Not "the selector is right" but "the other row is
     * not named at all" — there is no `.ci-set` anywhere in what this screen
     * emits except inside the `:not()` that excludes it.
     */
    expect(str_replace(':not(.ci-set)', '', $css))->not->toContain('ci-set');

    // And it really reaches the page, rather than being a rule nobody serves.
    SettingsService::forgetMemo();
    $html = crTwoLineCartHtml();

    expect($html)->toContain('<style id="kbb-cartrows">')
        ->and($html)->toContain('.ci:not(.ci-set) .cth{width:72px');
});

it('lets the set controls shape a set row and never an ordinary row', function () {
    $s = crSettings();
    $s->set('setap_ci_min_h', 150);
    $s->set('setap_ci_thumb', 76);
    $s->set('setap_ci_name_f', 170);
    $s->set('setap_ci_box_align', 'center');

    SettingsService::forgetMemo();
    $css = app(SetAppearance::class)->storefrontCss();

    expect($css)->toContain('.kbb-cartpage .items .ci.ci-set{padding:11px 14px 11px;gap:12px;min-height:150px}')
        ->and($css)->toContain('.ci.ci-set .cth{width:76px;height:76px;border-radius:10px}')
        ->and($css)->toContain('.ci.ci-set .cn{margin-bottom:6px;font-size:17px}')
        ->and($css)->toContain('.ci.ci-set .kset{justify-content:center}');

    /*
     * Every cart-row selector this screen emits carries `.ci-set`. Counted
     * rather than eyeballed: `.ci` appears inside `.ci-set` too, so the test is
     * that the two counts are equal.
     */
    $cartRules = substr_count($css, '.kbb-cartpage .items .ci');
    $setRules = substr_count($css, '.kbb-cartpage .items .ci.ci-set');

    expect($cartRules)->toBe($setRules)
        ->and($setRules)->toBeGreaterThan(0)
        ->and($css)->not->toContain(':not(');

    SettingsService::forgetMemo();
    $html = crTwoLineCartHtml();

    expect($html)->toContain('<style id="kbb-set">')
        ->and($html)->toContain('.ci.ci-set .kset{justify-content:center}');
});

it('cannot be dragged into a row that clips its own name or stepper', function () {
    /*
     * ── THE CONTROL THE OWNER'S DEFECT WOULD HAVE NEEDED ───────────────────
     *
     * A set row lost its name and its stepper to `height` + `overflow:hidden`.
     * This walks every new control on BOTH screens to the bottom and the top of
     * its own range and asserts that nothing any of them can emit is a ceiling
     * or a clip. It is the difference between "we would not do that" and "that
     * cannot be done", which is the shape the set box's overhang control was
     * given for the same reason.
     */
    /*
     * TWO PROBES, because `height:` is legitimate on the row's PICTURE — `.cth`
     * is a square and a width without a height would leave it an oblong — and
     * is exactly what must never appear on the ROW. So the ceiling and the clip
     * are looked for in the row's own declaration block, and the two ways of
     * removing an element outright in every cart-row rule this screen emits.
     */
    $bannedOnRow = ['height:', 'overflow:', 'max-height'];
    $bannedAnywhere = ['display:none', 'visibility:hidden'];

    $walk = function (array $keys, string $prefix, callable $emit) use ($bannedOnRow, $bannedAnywhere) {
        foreach ($keys as $key => $spec) {
            if (! str_starts_with($key, 'ci_')) {
                continue;
            }

            $values = $spec[0] === 'select'
                ? array_keys($spec[4] ?? [])
                : [$spec[4]['min'] ?? 0, $spec[4]['max'] ?? 0];

            foreach ($values as $value) {
                $s = crSettings();
                $s->set($prefix.$key, $value);
                SettingsService::forgetMemo();

                $css = $emit();

                // The ROW's own block: `…{ … }` on a selector that ends at the
                // row itself rather than at one of its children.
                preg_match_all('/\.ci(?::not\(\.ci-set\)|\.ci-set)\{([^}]*)\}/', $css, $rows);
                // `min-height:` CONTAINS `height:`, and is the whole point of
                // this case, so it is taken out before the probe.
                $rowDecls = str_replace('min-height:', '', implode(';', $rows[1]));

                foreach ($bannedOnRow as $bad) {
                    expect(str_contains($rowDecls, $bad))->toBeFalse(
                        "{$prefix}{$key} at {$value} emitted `{$bad}` onto a cart ROW. "
                        .'A control that can cap or clip a row is the defect the owner photographed, '
                        .'with a slider in front of it.'
                    );
                }

                foreach ($bannedAnywhere as $bad) {
                    expect(str_contains($css, $bad))->toBeFalse(
                        "{$prefix}{$key} at {$value} removed part of a cart row with `{$bad}`."
                    );
                }

                $s->set($prefix.$key, $spec[2]);
            }
        }
    };

    $walk(CartPage::SCHEMA, 'cartpage_', fn (): string => app(CartPage::class)->rowCss());

    /*
     * The SET screen emits far more than cart rows, and its box and list rules
     * legitimately carry `display:none` for the parts that are switched off. So
     * only the cart-row rules are examined — which is exactly the claim being
     * made.
     */
    $walk(SetAppearance::SCHEMA, 'setap_', function (): string {
        $css = app(SetAppearance::class)->storefrontCss();
        preg_match_all('/\.kbb-cartpage \.items \.ci\.ci-set[^{]*\{[^}]*\}/', $css, $m);

        return implode('', $m[0]);
    });
});

it('serves the ordinary-row rules from exactly one place', function () {
    /*
     * THE FINISHED STATE, not the absence of one. Zero is the "built, never
     * wired up" shape this repository keeps finding — the ProductStyles
     * failure, twenty controls that reached no page for releases — and two
     * would emit the block twice into one <head>.
     */
    $cart = (string) file_get_contents(resource_path('views/store/cart.blade.php'));

    expect(substr_count($cart, "@include('partials.cart-row-css')"))->toBe(1);

    /*
     * AND ON THE SAME LINE AS THE @vite CALL. A directive on a line of its own
     * leaves that line's newline in the <head> of every cart page whether or
     * not the partial emits anything, which StorefrontEnglishUnchangedTest
     * catches at byte 2215. This is what keeps it from being "tidied" back.
     */
    expect($cart)->toContain("@vite('resources/css/kbb/kbb-cart.css')@include('partials.cart-row-css')");

    // One emitter, one caller — there is no second path to the shop.
    $partial = (string) file_get_contents(resource_path('views/partials/cart-row-css.blade.php'));

    expect(substr_count($partial, '->rowCss()'))->toBe(1);
});

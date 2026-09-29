<?php

declare(strict_types=1);

use Tests\Support\CssDirection;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  A WAS-PRICE IS QUIET, AND A NOW-PRICE IS NOT UNDERLINED.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The owner, 29 September 2026, with a screenshot of the checkout's Browsed tab
 * and an arrow pointing at "AED210AED147":
 *
 *   "the cut price should be grey and small, and actual price don't need to be
 *    underline etc. and give little bit spacing between both prices, also check
 *    this for mobile version too and fix. and everywhere else."
 *
 * ── WHAT THE DEFECT WAS, AND WHY IT WAS INVISIBLE TO EVERY OTHER TEST ─────
 *
 * Nothing was written wrongly. Three BROWSER DEFAULTS were never overridden:
 *
 *   1. <ins> is UNDERLINED by the UA stylesheet. So the price the shopper
 *      actually pays carried an underline, which on a price reads as a link.
 *   2. <del> inherits its size, weight and colour. So the price they are NOT
 *      paying was drawn at exactly the weight of the one they are.
 *   3. Inline elements carry no gap, so the two ran together as one string:
 *      "AED210AED147".
 *
 * A test that reads Blade would find nothing wrong, because the markup is
 * correct — <del> and <ins> are the right elements for a price that WAS and a
 * price that now IS, and they carry aria-hidden so a screen reader is not read
 * both. The defect lives entirely in what the stylesheet does NOT say.
 *
 * ── AND IT WAS IN TWO PLACES, WHICH IS WHY "EVERYWHERE ELSE" MATTERED ─────
 *
 * The checkout's Browsed tab (partials/checkout/browsed-item.blade.php) is the
 * one he photographed. Quick view (partials/quick-view.blade.php) prints the
 * same pair and had the same three faults — hidden by a rule in kbb-shop.css
 * that styled `s` while the markup printed `del`, so it LOOKED handled and
 * selected nothing. A dead selector is worse than a missing one: it answers the
 * question "is this styled?" with a yes.
 *
 * MUTATION, either file: delete the `ins{text-decoration:none}` declaration and
 * the underline case goes red naming the file. Delete the `del` size and the
 * quiet case goes red. RUN IT.
 */
function spqCss(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

/** @return array<string,string> property => value, for one selector */
function spqRule(string $css, string $selector): array
{
    $out = [];

    foreach (CssDirection::declarations($css) as $d) {
        if ($d['selector'] === $selector) {
            $out[$d['property']] = $d['value'];
        }
    }

    return $out;
}

dataset('struck price surfaces', [
    'checkout browsed tab' => [
        'resources/css/kbb/kbb-checkout.css',
        '.kbb-checkout .binfo .bp del',
        '.kbb-checkout .binfo .bp ins',
        12.0, // the row's own price size, which the was-price must come under
    ],
    'quick view' => [
        'resources/css/kbb/kbb-shop.css',
        '.qvm .qvprice del',
        '.qvm .qvprice ins',
        22.0,
    ],
]);

it('draws the was-price smaller and quieter than the price being charged', function (
    string $file, string $del, string $ins, float $rowSize
) {
    $rule = spqRule(spqCss($file), $del);

    expect($rule)->not->toBe([], "{$del} is not styled at all in {$file}.");

    expect(isset($rule['font-size']))->toBeTrue("{$del} does not set a size, so it inherits the charged price's.");
    expect((float) $rule['font-size'])->toBeLessThan(
        $rowSize,
        "{$del} is not smaller than the price beside it."
    );

    expect(isset($rule['color']))->toBeTrue("{$del} does not set a colour, so it is as dark as the charged price.");
    expect(str_contains($rule['color'], 'muted'))->toBeTrue(
        "{$del} should take the shop's muted token rather than a literal."
    );
})->with('struck price surfaces');

it('takes the browser underline off the price being charged', function (
    string $file, string $del, string $ins, float $rowSize
) {
    /*
     * The one the owner actually pointed at. <ins> is underlined by the UA
     * stylesheet, and an underlined price reads as a link to click.
     */
    $rule = spqRule(spqCss($file), $ins);

    expect($rule)->not->toBe([], "{$ins} is not styled at all in {$file}, so the UA underline stands.");
    expect($rule['text-decoration'] ?? '')->toBe(
        'none',
        "{$ins} does not clear the browser's underline."
    );
})->with('struck price surfaces');

it('puts real space between the two prices, on the logical axis', function (
    string $file, string $del, string $ins, float $rowSize
) {
    /*
     * "give little bit spacing between both prices". <del> is printed FIRST in
     * both of these, so the gap belongs to <ins> — and it is margin-inline-start
     * rather than margin-left, or Arabic puts the gap on the outside of the pair
     * instead of between them. That is the failure this repo has paid for twice
     * and it is invisible in an English screenshot.
     */
    $rule = spqRule(spqCss($file), $ins);

    expect(isset($rule['margin-inline-start']))->toBeTrue(
        "{$ins} has no inline-start margin, so the two prices run together as one string."
    );
    expect((float) $rule['margin-inline-start'])->toBeGreaterThan(
        0.0,
        "{$ins}'s gap is zero."
    );
    expect(isset($rule['margin-left']))->toBeFalse(
        "{$ins} uses a physical margin; on Arabic that puts the gap on the wrong side."
    );
})->with('struck price surfaces');

it('leaves no dead selector claiming to style a struck price', function () {
    /*
     * The quick-view rule styled `s` while the markup printed `del`, so it read
     * as handled and selected nothing — which is how that surface kept the
     * defect through every review of this area. The `s` selector is KEPT (a shop
     * rendering <s> in that block should still get the quiet treatment) but it
     * may never be the ONLY one.
     */
    $shop = spqCss('resources/css/kbb/kbb-shop.css');
    $markup = (string) file_get_contents(base_path('resources/views/partials/quick-view.blade.php'));

    expect(str_contains($markup, '<del>'))->toBeTrue('Quick view stopped printing <del>; re-point this test.');

    expect(str_contains($shop, '.qvm .qvprice del'))->toBeTrue(
        'kbb-shop.css styles a struck price that the quick-view markup does not print.'
    );
});

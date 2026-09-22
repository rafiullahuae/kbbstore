<?php

declare(strict_types=1);

/**
 * =============================================================================
 * THE DOCKED ROWS SIT AT bottom:0, AND THE VIEWPORT-UNIT LIFT IS NOT COMING BACK
 * =============================================================================
 *
 * The owner reported a white band on the cart page when scrolling back up on a
 * phone, with the Checkout button hidden under it. The diagnosis was that
 * `position:fixed` resolves `bottom` against the LAYOUT viewport — the tall one
 * the page has while the URL bar is retracted — so when the bar returns, the
 * block pinned to it hangs below the screen edge.
 *
 * The fix was `bottom: calc(100lvh - 100dvh)`. It was reasoned from the spec,
 * measured in headless Chromium with the number substituted by hand, and
 * shipped.
 *
 * ── AND IT WAS WRONG ON A REAL PHONE ────────────────────────────────────────
 *
 * Chrome on Android ALREADY resolves a fixed element's `bottom` against the
 * visual viewport once the URL bar is out. Subtracting (100lvh - 100dvh) on top
 * of that counts the browser chrome twice and pushes the bar UP by its height —
 * so the rows floated above the screen edge with the page showing through
 * underneath. The owner's screenshot has the free-delivery bar visible BELOW
 * the Proceed to Checkout row. That is the page, not a gap.
 *
 * Headless Chromium could never have caught it: it has no browser controls, so
 * `100dvh` can never differ from `100lvh` there. The measurement that mattered
 * was one person, one phone, one scroll.
 *
 * So this file now pins the opposite of what it used to, and says why. An
 * intermittent artifact during the URL bar's own animation was traded for a
 * permanent one on every scroll; `bottom:0` is correct at rest and correct once
 * the bar has finished moving, which is the state a shopper is in when they
 * reach for the button.
 *
 * MUTATION: put `bottom:calc(100lvh - 100dvh)` back on either rule. Red.
 */
function dvCss(): string
{
    return (string) \Tests\Support\CartPageStyles::all();
}

/** The declarations, with this file's long explanations stripped out. */
function dvDeclarations(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', dvCss());
}

it('pins the docked rows to the bottom with no viewport arithmetic', function () {
    $rule = substr(dvDeclarations(), (int) strpos(dvDeclarations(), '.cpg-docked{position:fixed'));
    $rule = substr($rule, 0, (int) strpos($rule, '}') + 1);

    expect(str_contains($rule, 'position:fixed'))->toBeTrue();
    expect(str_contains($rule, 'bottom:0'))->toBeTrue(
        'the docked rows are no longer pinned to the bottom'
    );

    expect(preg_match('/(?<![-\w])bottom:\s*calc/', $rule))->toBe(
        0,
        'the docked rows have a calculated bottom again. If that is the lvh/dvh lift, read this '
        .'file\'s header: it was measured wrong on a real phone and floats the bar above the edge'
    );
});

it('pins the address sheet the same way', function () {
    $rule = substr(dvDeclarations(), (int) strpos(dvDeclarations(), '.cpg-sheet{position:fixed'));
    $rule = substr($rule, 0, (int) strpos($rule, '}') + 1);

    expect(str_contains($rule, 'bottom:0'))->toBeTrue('the address sheet is no longer pinned to the bottom');
    expect(preg_match('/(?<![-\w])bottom:\s*calc/', $rule))->toBe(0, 'the sheet has a calculated bottom again');
});

it('uses no viewport-relative units anywhere in the cart stylesheet', function () {
    /*
     * The census, kept from the version of this file that pinned the lift —
     * inverted, but for the same purpose. Nothing on this page should move
     * because the browser chrome moved. A rule that wants to needs a reason
     * good enough to come and delete this assertion, and a phone to test on.
     */
    $declarations = dvDeclarations();

    foreach (['dvh', 'lvh', 'svh'] as $unit) {
        expect(substr_count($declarations, $unit))->toBe(
            0,
            "the cart stylesheet uses {$unit}; see this file's header before adding another"
        );
    }
});

it('leaves room at the end of the page for the bars to float over', function () {
    /*
     * --cpg-bars is padding at the foot of the document so the last basket row
     * is never under the bars. It deliberately carries NO viewport unit: the
     * document's height would then change every time the URL bar moved, and a
     * document that grows and shrinks under a scrolling finger drives the URL
     * bar in turn. That is a worse bug than the one this started as.
     */
    $declarations = dvDeclarations();

    expect(preg_match('/--cpg-bars:\s*calc\([^)]*\)/', $declarations, $m))->toBe(1);

    foreach (['dvh', 'lvh', 'svh', '100vh'] as $unit) {
        expect(str_contains($m[0], $unit))->toBeFalse(
            "--cpg-bars carries {$unit}, so the page height changes as the browser chrome moves"
        );
    }
});

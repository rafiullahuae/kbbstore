<?php

declare(strict_types=1);

/**
 * =============================================================================
 * A PANEL PARKED OFF SCREEN BY A TRANSFORM MUST ALSO BE HIDDEN
 * =============================================================================
 *
 * The owner reported, three times and on every page, "a weird white bar" that
 * appeared when he scrolled back up and vanished when he stopped. Two earlier
 * suspects were wrong: the docked rows' own geometry, and the floating tab bar.
 *
 * ── HOW IT WAS ACTUALLY FOUND ───────────────────────────────────────────────
 *
 * By rendering `/`, `/shop/` and `/cart` and cross-referencing the markup that
 * came back against every rule in kbb.css that is `position:fixed` and anchored
 * to the bottom. `.tabbar` was in NONE of the three. `.mmenu` was in all three.
 *
 *   .mmenu   position:fixed; top:var(--mm-top); bottom:0;
 *            background:#fff; z-index:120; border-radius:20px 20px 0 0;
 *            transform:translateY(100%)
 *
 * and `.mmenu{display:block}` on a phone, so it is in the layer tree on every
 * page. It was kept off screen by that transform ALONE — and `translateY(100%)`
 * is 100% of its own height, which comes from the viewport. When a phone's URL
 * bar slides in or out the sheet's height changes, its parked offset changes
 * with it, and the top edge of a white rounded panel is exposed at the bottom
 * of the screen for the length of that animation. At z-index 120 it covers
 * everything, including the cart's docked Checkout button.
 *
 * The rounded top corners in the owner's screenshot are that border-radius.
 *
 * A hidden element cannot paint wherever a transform leaves it. Its own sibling
 * `.msub` has carried `visibility:hidden` while parked since it was written;
 * `.mmenu` was the inconsistent one.
 *
 * MUTATION: remove `visibility:hidden` from .mmenu. Red.
 */
function ppCss(): string
{
    /*
     * COMMENTS STRIPPED FIRST, and this is not tidiness.
     *
     * Scanning to the next `}` from a selector finds the end of the first
     * COMMENT that contains one, not the end of the rule — and the comment
     * explaining this very fix quotes `.mmenu{display:block}`. The rule body
     * came back truncated mid-sentence and the test failed on an assertion
     * about a declaration that was there all along.
     *
     * A stylesheet parser that a prose comment can derail is not a parser.
     */
    return (string) preg_replace(
        '#/\*.*?\*/#s',
        '',
        (string) file_get_contents(base_path('resources/css/kbb/kbb.css'))
    );
}

/**
 * The body of a rule, by selector AND by something it must contain.
 *
 * A plain "first occurrence" search finds `.mmenu{display:block}` — a one-line
 * override inside a media query, a hundred lines before the rule that actually
 * positions the sheet. That is not a detail of this test: it is how a census
 * over a large stylesheet quietly checks the wrong rule and passes.
 */
function ppRule(string $selector, string $mustContain): string
{
    $css = ppCss();
    $at = 0;

    while (($at = strpos($css, $selector.'{', $at)) !== false) {
        $body = substr($css, $at, (int) strpos($css, '}', $at) - $at);

        if (str_contains($body, $mustContain)) {
            return $body;
        }

        $at++;
    }

    expect(false)->toBeTrue("no rule for {$selector} containing '{$mustContain}'");

    return '';
}

it('hides the mobile menu sheet while it is parked off screen', function () {
    $rule = ppRule('.mmenu', 'position:fixed');

    expect(str_contains($rule, 'transform:translateY(100%)'))->toBeTrue(
        'the sheet is parked some other way now; re-read this file before trusting the rest of it'
    );

    expect(str_contains($rule, 'visibility:hidden'))->toBeTrue(
        'the mobile menu sheet is parked by a transform alone, so a viewport change exposes its '
        .'top edge as a white bar over every page'
    );
});

it('gives the sheet its visibility back when it opens', function () {
    /*
     * Hiding it while parked is only half. Without the open state saying
     * otherwise the menu would slide up and stay invisible — which is a worse
     * bug than the one being fixed, and the obvious way to get this wrong.
     */
    $rule = ppRule('.mmenu.on', 'transform:none');

    expect(str_contains($rule, 'visibility:visible'))->toBeTrue(
        'the menu opens but stays invisible'
    );

    /*
     * And the delay is on the way OUT only. `visibility` is not an animatable
     * property, so it is stepped: delayed by the slide when closing, so the
     * sheet is still visible while it animates away, and instant when opening.
     */
    expect(str_contains(ppRule('.mmenu', 'position:fixed'), 'visibility 0s linear var(--mm-speed)'))->toBeTrue(
        'the closing visibility step is not delayed, so the sheet vanishes instead of sliding away'
    );

    expect(str_contains($rule, 'visibility 0s'))->toBeTrue(
        'the opening visibility step is delayed, so the menu appears late'
    );
});

it('leaves no other fixed panel parked by a transform alone', function () {
    /*
     * The census. Any rule that is position:fixed and parked with a transform
     * has the same failure available to it, and the only reason this one was
     * found is that the owner kept reporting it.
     *
     * `.mnav` is translated HORIZONTALLY (translateX(-105%)), so a change in
     * viewport height cannot expose it; it is excluded by that, not waved past.
     */
    $css = ppCss();

    preg_match_all('/\.([a-zA-Z][\w-]*)\s*\{([^}]*position:fixed[^}]*)\}/', $css, $rules, PREG_SET_ORDER);

    $naked = [];

    foreach ($rules as [$whole, $selector, $body]) {
        if (! preg_match('/transform:\s*translateY\(/', $body)) {
            continue;
        }
        if (str_contains($body, 'visibility:hidden') || str_contains($body, 'display:none')) {
            continue;
        }
        if (preg_match('/opacity:\s*0\b/', $body)) {
            continue;
        }

        $naked[] = ".{$selector}";
    }

    expect($naked)->toBe(
        [],
        'these fixed panels are parked vertically by a transform with nothing to stop them painting: '
        .implode(', ', $naked)
    );
});

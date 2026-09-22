<?php

declare(strict_types=1);

/**
 * =============================================================================
 * A CONTROL THAT TARGETS AN INLINE ELEMENT IS NOT A CONTROL
 * =============================================================================
 *
 * The owner set the rail's line height to its minimum and nothing moved. His
 * screenshot shows product names running to THREE lines under a rule that
 * clamps them to two — which is the tell. If the clamp were applying, the third
 * line would have been cut.
 *
 * The markup is `<span class="nm">`. `height`, `overflow` and vertical `margin`
 * do not apply to a non-replaced inline box, and an inline box's line boxes are
 * governed by the parent block's strut — so a smaller `line-height` on the span
 * alone changes nothing visible.
 *
 * Four controls were dead on the page while reading perfectly in the
 * stylesheet, and every test over them passed, because every one of those tests
 * asked whether the DECLARATION was present. None asked whether it could apply.
 *
 *   rec_lh      the two-line clamp never applied
 *   rec_gap     the name-to-price gap moved nothing
 *   (overflow)  nothing was ever clipped
 *
 * MUTATION: drop `display:block` from .cpg-card .nm. Red.
 */
function rtcRules(): string
{
    return (string) preg_replace(
        '#/\*.*?\*/#s',
        '',
        (string) file_get_contents(base_path('resources/views/store/cart-squeeze.blade.php'))
    );
}

function rtcRule(string $selector): string
{
    $css = rtcRules();
    $at = strpos($css, $selector.'{');

    expect($at)->not->toBeFalse("no rule for {$selector}");

    return substr($css, (int) $at, (int) strpos($css, '}', (int) $at) - (int) $at);
}

it('gives the rail name a block box, so its height and margin can apply', function () {
    $rule = rtcRule('.kbb-cartpage.cpg-squeeze .cpg-card .nm');

    /*
     * Any BLOCK-LEVEL display, not the literal `display:block`. The name is
     * `display:-webkit-box` so it can carry -webkit-line-clamp, which is the
     * only thing that puts an ellipsis on a multi-line name. Pinning the word
     * would have failed on a change that fixed the same bug better.
     */
    expect(preg_match('/display:\s*(block|flex|grid|-webkit-box|inline-block)/', $rule))->toBe(
        1,
        'the rail product name is an inline box; its height, overflow and margin are ignored and '
        .'its line-height is overruled by the parent strut'
    );

    // Three lines, then an ellipsis. text-overflow:ellipsis is single-line only.
    expect(str_contains($rule, '-webkit-line-clamp:3'))->toBeTrue(
        'a long product name no longer ends in an ellipsis'
    );
});

it('never sets a box property on an inline element in this stylesheet', function () {
    /*
     * The census, and the point of this file.
     *
     * Any rule that sets `height`, `overflow` or a vertical `margin` on an
     * element the markup renders as a span has the same silent failure. The
     * only reason this one surfaced is that the owner tried the slider and
     * said so.
     *
     * Checked against the classes the cart markup actually renders as spans,
     * read out of the template rather than listed here.
     */
    $markup = (string) file_get_contents(base_path('resources/views/store/cart-inner.blade.php'));

    preg_match_all('/<span class="([a-z][\w-]*)"/', $markup, $m);

    $spans = array_values(array_unique($m[1]));
    $css = rtcRules();
    $offenders = [];

    foreach ($spans as $class) {
        // Only rules scoped to the squeezed cart; the classic page is not ours.
        if (! preg_match('/\.cpg-squeeze[^{]*\.'.preg_quote($class, '/').'\{([^}]*)\}/', $css, $r)) {
            continue;
        }

        $body = $r[1];

        $boxy = preg_match('/(?<![-\w])height:/', $body)
            || preg_match('/(?<![-\w])overflow:/', $body)
            || preg_match('/margin-(top|bottom):/', $body);

        if (! $boxy) {
            continue;
        }

        if (preg_match('/display:\s*(block|flex|grid|inline-block|inline-flex|-webkit-box)/', $body)) {
            continue;
        }

        $offenders[] = ".{$class}";
    }

    expect($offenders)->toBe(
        [],
        'these classes are rendered as <span> and given a box property that an inline box ignores: '
        .implode(', ', $offenders).'. Give them a display, or the control behind them does nothing.'
    );
});

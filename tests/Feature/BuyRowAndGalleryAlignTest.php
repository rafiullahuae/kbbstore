<?php

declare(strict_types=1);

/**
 * THE BUY ROW LINES UP, AND THE PHOTOGRAPH STOPS AT THE CONTENT EDGE.
 *                                                        (2.60.331 follow-up)
 *
 * Two defects the owner found by looking at his own shop and marking them in
 * red on a screenshot. Both were in `resources/css/kbb/kbb-product.css`, and
 * both are asserted here against the STYLESHEET SOURCE rather than a rendered
 * page, because this repository forbids JavaScript that measures layout and
 * the browser numbers live in the commit message instead.
 *
 * ── WHAT THEY LOOKED LIKE ON THE SHOP ────────────────────────────────────
 *
 * 1. THE QUANTITY STEPPER SAT 6px BELOW `Add to cart` AND WAS 2px TALLER.
 *    Measured in Chromium, identical at 1280 and 390: the button 54px tall
 *    with its top at 705.53, the stepper 56px with its top at 711.53 and its
 *    bottom 8px lower.
 *
 *    THE 6px WAS NOT THE PRODUCT PAGE'S. `kbb.css` declares a BARE, UNSCOPED
 *    `.qty` inside its cart-drawer block -- among `.drawer`, `.ci`, `.cinfo`,
 *    `.dfoot` -- for the 26px quantity pill on a basket line, and it carries
 *    `margin-top:6px`. kbb.css loads on every page, so that margin reached the
 *    product page's stepper. kbb-product.css overrode the border and the radius
 *    and never the margin, so the margin went on applying for as long as both
 *    files existed.
 *
 *    The 2px was a box-model mismatch: `.addcart` is `height:54px` with no
 *    border and measures 54; the stepper took its height from 54px buttons and
 *    then ADDED 1.5px of border top and bottom.
 *
 * 2. THE PHOTOGRAPH BEGAN 22px LEFT OF THE PAGE'S CONTENT EDGE, so it
 *    overhung the logo and the "Product details" heading. Measured at 1920,
 *    1440 and 1280: exactly 22px every time, which is what says "structural"
 *    rather than "a breakpoint slipped".
 *
 * ── MUTATION NOTES, which are the whole proof these assert anything ──────
 *
 *   Delete `margin-top:0` from the `.qty` rule           -> case 1 red
 *   Delete `height:54px` from the `.qty` rule            -> case 2 red
 *   Restore `margin-inline:calc(... * -1) 0` on desktop  -> case 3 red
 *   Restore `padding-inline:var(--site-gutter,22px) 0`
 *     on the desktop `.gthumbs` rule                     -> case 4 red
 *   Remove the mobile gallery's two-sided bleed          -> case 5 red
 *
 * Case 5 exists because the fix for case 3 is one character away from also
 * flattening the phone, and the phone's full bleed is the Ledger look the
 * owner picked. A test that only forbade the bleed would be green on a shop
 * that had lost it everywhere.
 */

/** The declaration block of the LAST bare `.qty` rule in the product stylesheet. */
function kbbProductCss(): string
{
    return file_get_contents(base_path('resources/css/kbb/kbb-product.css'));
}

function kbbBareQtyRule(string $css): string
{
    preg_match_all('/(?:^|\})\s*\.qty\{([^}]*)\}/m', $css, $m);

    // There must BE one, or every assertion below would pass on an empty
    // string for a reason unrelated to its subject.
    expect($m[1])->not->toBeEmpty('no bare .qty rule found in kbb-product.css');

    return end($m[1]);
}

it('keeps the quantity stepper level with Add to cart', function () {
    $rule = kbbBareQtyRule(kbbProductCss());

    // The cart drawer's margin-top:6px arrives from kbb.css on every page and
    // is stopped HERE rather than there, because scoping it there would also
    // touch /cart and /checkout, which nobody asked to change.
    expect($rule)->toContain('margin-top:0');
})->group('pdp');

it('gives the stepper and the button the same declared height', function () {
    $css = kbbProductCss();
    $rule = kbbBareQtyRule($css);

    // box-sizing is border-box here, so 54px on the wrapper INCLUDES the
    // 1.5px borders that used to push the box to 56.
    expect($rule)->toContain('height:54px');

    preg_match('/\.addcart\{([^}]*)\}/', $css, $a);
    expect($a[1] ?? '')->toContain('height:54px');

    // And the buttons must fill the wrapper rather than set it, or the
    // wrapper's height is decoration and the box is 56 again.
    preg_match('/\.qty button\{([^}]*)\}/', $css, $b);
    expect($b[1] ?? '')->toContain('height:100%');
})->group('pdp');

it('stops the photograph at the content edge on a wide screen', function () {
    $css = kbbProductCss();

    // The desktop block, isolated: a naive search of the whole file would find
    // the MOBILE rule, which is supposed to bleed, and pass for the wrong
    // reason.
    $desktop = kbbMediaBlock($css, '(min-width:881px)');

    expect($desktop)->toContain('.pdp .gallery{margin-inline:0}');
    expect($desktop)->not->toContain('.pdp .gallery{margin-inline:calc');
})->group('pdp');

it('keeps the thumbnails lined up with the photograph they belong to', function () {
    $desktop = kbbMediaBlock(kbbProductCss(), '(min-width:881px)');

    // This padding existed only to cancel the gallery's negative margin. With
    // that margin gone it would push the strip a gutter to the RIGHT of the
    // picture.
    expect($desktop)->toContain('.pdp .gthumbs{margin-block-start:14px;padding-inline:0}');
})->group('pdp');

it('leaves the phone photograph bleeding to both screen edges', function () {
    $mobile = kbbMediaBlock(kbbProductCss(), '(max-width:880px)');

    // Two values, not one: `margin-inline:X` alone is both sides, and that is
    // what the phone wants. The desktop fault was the TWO-value form.
    expect($mobile)->toContain('.pdp .gallery{margin-inline:calc(var(--site-gutter,22px) * -1)}');
})->group('pdp');

/**
 * Return the body of one @media block, brace-matched.
 *
 * Not a regular expression: the blocks below nest, and a `[^}]*` would stop at
 * the first inner rule's closing brace and hand back a fragment that happens
 * not to contain whatever is being looked for -- a false green of exactly the
 * shape this repository keeps finding.
 */
function kbbMediaBlock(string $css, string $query): string
{
    $needle = '@media '.$query.'{';
    $start = strpos($css, $needle);
    expect($start)->not->toBeFalse("no @media {$query} block in kbb-product.css");

    $i = $start + strlen($needle);
    $depth = 1;
    $out = '';
    while ($i < strlen($css) && $depth > 0) {
        $c = $css[$i];
        if ($c === '{') {
            $depth++;
        } elseif ($c === '}') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        }
        $out .= $c;
        $i++;
    }

    expect($depth)->toBe(0, "unbalanced braces reading @media {$query}");

    return $out;
}

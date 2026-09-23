<?php

declare(strict_types=1);

/*
 * Appearance → Checkout page: the wiring between a saved number and the page.
 *
 * Three things can go wrong between a slider and a shop, and this project has
 * shipped all three:
 *
 *   1. The default is not today's value, so applying the package MOVES a live
 *      checkout. Pinned below against the stylesheet itself.
 *   2. The property reaches the page and no rule reads it, so the control
 *      saves, reports success and moves nothing.
 *   3. The property reaches the page and the rule that should have read it on
 *      a phone cannot, because an inline style attribute outranks a media
 *      query. That is the specific trap this screen's two-tab design walks
 *      into, and the `-d-`/`-m-` indirection is what avoids it.
 */

use App\Services\CheckoutPage;

function cop(): CheckoutPage
{
    return app(CheckoutPage::class);
}

function copCss(): string
{
    return (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));
}

/* ------------------------------------------------------------------------
 | 1. Untouched is untouched
 |------------------------------------------------------------------------*/

it('emits no attribute at all while every control is at its default', function () {
    expect(cop()->cssVariables())->toBe('')
        ->and(cop()->styleAttr())->toBe('')
        ->and(cop()->bodyClass())->toBe('');
});
// MUTATION: emit every property unconditionally. RED — and on the shop, a
// style attribute on a page StorefrontEnglishUnchangedTest compares byte for
// byte.

it('carries only what was actually moved', function () {
    cop()->save(['d_sec_pad' => 24]);

    // One property. Not the other fourteen at their defaults, which would make
    // the attribute unreadable in a page source and hide the one thing that is
    // not standard about this shop.
    expect(cop()->cssVariables())->toBe('--cop-d-secpad:24px')
        ->and(cop()->styleAttr())->toBe(' style="--cop-d-secpad:24px"');
});

it('ships the switch that is not a number as a class', function () {
    // A custom property can change a number inside a declaration. It cannot
    // decide whether `position:sticky` applies, so this one is a class.
    cop()->save(['d_sticky' => false]);

    expect(cop()->bodyClass())->toBe(' cop-nostick')
        ->and(cop()->cssVariables())->toBe('');

    expect(copCss())->toContain('.kbb-checkout.cop-nostick .summary{position:static}');
});

/* ------------------------------------------------------------------------
 | 2. Every default is the number the page already had
 |------------------------------------------------------------------------*/

it('states each default a second time as the stylesheet fallback', function () {
    $css = copCss();
    $c = cop()->all();

    /*
     * The fallback is what renders on a shop that sends no attribute, so a
     * fallback that disagrees with the schema is a package that moves a live
     * checkout on the way in. Both halves are written by hand in two files;
     * this is the only thing that makes them agree.
     */
    foreach ([
        '--cop-d-max' => 'd_max',
        '--cop-d-aside' => 'd_aside',
        '--cop-d-gap' => 'd_gap',
        '--cop-d-padx' => 'd_pad_x',
        '--cop-d-pady' => 'd_pad_y',
        '--cop-d-block' => 'd_block_gap',
        '--cop-d-secpad' => 'd_sec_pad',
        '--cop-d-asidepad' => 'd_aside_pad',
        '--cop-d-sticktop' => 'd_sticky_top',
        '--cop-m-padx' => 'm_pad_x',
        '--cop-m-pady' => 'm_pad_y',
        '--cop-m-gap' => 'm_gap',
        '--cop-m-block' => 'm_block_gap',
        '--cop-m-secpad' => 'm_sec_pad',
        '--cop-m-asidepad' => 'm_aside_pad',
        '--cop-d-rowh' => 'd_row_h',
        '--cop-d-rowpad' => 'd_row_pad',
        '--cop-d-rowgap' => 'd_row_gap',
        '--cop-m-rowh' => 'm_row_h',
        '--cop-m-rowpad' => 'm_row_pad',
        '--cop-m-rowgap' => 'm_row_gap',
    ] as $prop => $key) {
        expect($css)->toContain('var('.$prop.','.$c[$key].'px)');
    }
});
// MUTATION: change any fallback by a pixel. RED, and it names the property.

/* ------------------------------------------------------------------------
 | 3. The indirection that makes the Mobile tab work at all
 |------------------------------------------------------------------------*/

it('never hands the page a token the mobile query has to win back', function () {
    /*
     * THE WHOLE POINT. An inline style attribute beats every stylesheet rule,
     * a media query included. If this service emitted `--cop-secpad` directly,
     * the mobile reassignment could never win and every control on the Mobile
     * tab would save, report success and move nothing on a phone.
     *
     * So the attribute may only ever carry `-d-` and `-m-` SOURCES; the five
     * shared names are assigned by the stylesheet and nowhere else.
     */
    cop()->save([
        'd_pad_x' => 30, 'd_pad_y' => 30, 'd_block_gap' => 30, 'd_sec_pad' => 30, 'd_aside_pad' => 30,
        'm_pad_x' => 10, 'm_pad_y' => 10, 'm_block_gap' => 10, 'm_sec_pad' => 10, 'm_aside_pad' => 10,
    ]);

    $vars = cop()->cssVariables();

    foreach ([
        '--cop-padx', '--cop-pady', '--cop-block', '--cop-secpad', '--cop-asidepad',
        '--cop-rowh', '--cop-rowpad', '--cop-rowgap', '--cop-rowf', '--cop-qtys',
        '--cop-rowb', '--cop-rowpb',
    ] as $shared) {
        expect($vars)->not->toContain($shared.':');
    }

    // And both halves are there, so the stylesheet has something to choose.
    expect($vars)->toContain('--cop-d-secpad:30px')
        ->and($vars)->toContain('--cop-m-secpad:10px');
});
// MUTATION: rename --cop-d-secpad to --cop-secpad in CheckoutPage::VARS. RED.

it('reassigns all five shared tokens inside the mobile query', function () {
    $css = copCss();

    // The mobile block, from the query to its closing brace.
    $at = strpos($css, '@media(max-width:900px){');
    expect($at)->not->toBeFalse();

    $mobile = substr($css, (int) $at, 1400);

    foreach ([
        '--cop-padx:var(--cop-m-padx,',
        '--cop-pady:var(--cop-m-pady,',
        '--cop-block:var(--cop-m-block,',
        '--cop-secpad:var(--cop-m-secpad,',
        '--cop-asidepad:var(--cop-m-asidepad,',
        '--cop-rowh:var(--cop-m-rowh,',
        '--cop-rowpad:var(--cop-m-rowpad,',
        '--cop-rowgap:var(--cop-m-rowgap,',
        '--cop-rowf:var(--cop-m-rowf,',
        '--cop-qtys:var(--cop-m-qtys,',
        '--cop-rowb:var(--cop-m-rowb,',
        '--cop-rowpb:var(--cop-m-rowpb,',
    ] as $line) {
        expect($mobile)->toContain($line);
    }

    // And the breakpoint the service tells the admin screen about is the one
    // the stylesheet actually switches at.
    expect($css)->toContain('@media(max-width:'.CheckoutPage::MOBILE_MAX.'px){');
});

/* ------------------------------------------------------------------------
 | 4. The heading bars have to follow the padding they sit in
 |------------------------------------------------------------------------*/

it('derives the section heading bar from the same number as the section padding', function () {
    /*
     * `.sec > h2` is pulled out to the card edge by a negative margin that was
     * hard-coded -16px against a hard-coded 16px padding. Leave the margin at
     * -16 and move the padding and the bar floats inside its own section with
     * a stripe of white around it — the kind of break that only shows up on
     * the shop, because nothing in a unit test looks at a box.
     */
    $css = copCss();

    expect($css)->toContain('.kbb-checkout .sec{padding:var(--cop-secpad)')
        ->and($css)->toContain('margin:calc(var(--cop-secpad) * -1) calc(var(--cop-secpad) * -1) 13px')
        // 3px short on the inline-start side, because the bar carries a 3px
        // pink border there.
        ->and($css)->toContain('padding:10px var(--cop-secpad) 10px calc(var(--cop-secpad) - 3px)');
});

/* ------------------------------------------------------------------------
 | 5. Nothing here can change how many sections there are
 |------------------------------------------------------------------------*/

it('offers spacing and nothing structural', function () {
    /*
     * "please don't disturb any number of sections on desktop checkout and
     * mobile checkout page." The schema is the enforcement: every control is a
     * range, bar the one boolean, and there is no text, no select and no
     * layout switch for a future edit to slip a structural change into.
     */
    $types = array_map(fn ($def) => $def[0], CheckoutPage::SCHEMA);

    expect(array_values(array_unique($types)))->toEqualCanonicalizing(['range', 'bool'])
        // Three switches, and every one of them is a look: whether the summary
        // follows the scroll, and whether the summary rows are bold on each
        // surface. None of them adds or removes anything.
        ->and(array_keys($types, 'bool', true))->toBe(['d_sticky', 'd_row_bold', 'm_row_bold']);
});

/* ------------------------------------------------------------------------
 | 6. The order-summary product rows
 |------------------------------------------------------------------------*/

it('emits the two multipliers as unitless factors, not percentages', function () {
    // The stylesheet multiplies them into a px size, and `calc(12px * 115%)`
    // is not a length -- it computes to nothing and the rule is dropped.
    cop()->save(['d_row_font' => 115, 'm_qty_size' => 70]);

    expect(cop()->cssVariables())->toBe('--cop-d-rowf:1.15;--cop-m-qtys:0.7');
});
// MUTATION: emit the stored percentage. RED -- and on the shop, a summary whose
// text size slider does nothing at all.

it('turns one bold switch into the two weights the row actually uses', function () {
    /*
     * The name is 600 and the price is 700, and it has been since this page
     * existed. One weight for both would flatten a distinction nobody asked to
     * lose, so the switch carries a pair.
     */
    cop()->save(['d_row_bold' => false]);

    expect(cop()->cssVariables())->toBe('--cop-d-rowb:400;--cop-d-rowpb:500');

    // And back on emits nothing, because on IS the default.
    cop()->save(['d_row_bold' => true]);
    expect(cop()->cssVariables())->toBe('');
});

it('drives the summary line from the row tokens', function () {
    $css = copCss();

    expect($css)->toContain('.kbb-checkout .ci{display:flex;gap:var(--cop-rowgap);padding:var(--cop-rowpad) 0')
        ->and($css)->toContain('.kbb-checkout .cth{width:var(--cop-rowh);height:var(--cop-rowh);')
        ->and($css)->toContain('.kbb-checkout .cinfo .n{font-size:calc(12px * var(--cop-rowf));font-weight:var(--cop-rowb)')
        ->and($css)->toContain('.kbb-checkout .cprice{font-size:calc(12.5px * var(--cop-rowf));font-weight:var(--cop-rowpb)')
        // The stepper takes the multiplier on the box AND the glyph. One
        // without the other draws a control the page never renders.
        ->and($css)->toContain('.kbb-checkout .qty button{width:calc(23px * var(--cop-qtys));height:calc(23px * var(--cop-qtys));font-size:calc(13px * var(--cop-qtys))');
});

it('carries the multiplier into the mobile stepper override as well', function () {
    /*
     * THE TRAP THIS CATCHES. `.kbb-checkout .qty .co-q` inside the 900px query
     * is more specific than the base `.qty button` rule AND later in the file,
     * so on a phone it wins outright. Left as a hard-coded 23px it would mean
     * the Mobile tab's stepper slider saves, reports success and moves nothing
     * below 900px -- the same class of failure as an inline property beating a
     * media query, arriving by a different route.
     */
    $css = copCss();

    $at = strpos($css, '.kbb-checkout .qty .co-q{');
    expect($at)->not->toBeFalse();

    $rule = substr($css, (int) $at, 320);

    expect($rule)->toContain('width:calc(23px * var(--cop-qtys))')
        ->and($rule)->toContain('min-width:calc(23px * var(--cop-qtys))')
        ->and($rule)->toContain('font-size:calc(13px * var(--cop-qtys))')
        ->and($rule)->not->toMatch('/width:23px/');
});
// MUTATION: put 23px back in that block. RED.

it('leaves the order-received page on the numbers it has today', function () {
    /*
     * partials/checkout/received-line uses the same .ci/.cth/.cinfo/.cprice
     * classes, so the rules above reach it too. It renders inside
     * store/checkout-success.blade.php, which is NOT handed the style
     * attribute -- so every var() falls back and that page is unchanged at
     * every setting. Deliberate, and pinned here because the way it holds is a
     * file NOT being edited, which nothing else would notice.
     */
    $success = (string) file_get_contents(resource_path('views/store/checkout-success.blade.php'));

    expect($success)->toContain('<section class="kbb-checkout">')
        ->and($success)->not->toContain('CheckoutPage');
});

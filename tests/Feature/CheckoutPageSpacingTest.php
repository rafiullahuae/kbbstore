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

    foreach (['--cop-padx', '--cop-pady', '--cop-block', '--cop-secpad', '--cop-asidepad'] as $shared) {
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
        ->and(array_keys($types, 'bool', true))->toBe(['d_sticky']);
});

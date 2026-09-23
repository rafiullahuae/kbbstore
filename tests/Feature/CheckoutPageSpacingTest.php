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
        '--cop-d-rowp-t' => 'd_row_pt',
        '--cop-d-rowp-r' => 'd_row_pr',
        '--cop-d-rowp-b' => 'd_row_pb',
        '--cop-d-rowp-l' => 'd_row_pl',
        '--cop-d-rowgap' => 'd_row_gap',
        '--cop-m-rowh' => 'm_row_h',
        '--cop-m-rowp-t' => 'm_row_pt',
        '--cop-m-rowp-r' => 'm_row_pr',
        '--cop-m-rowp-b' => 'm_row_pb',
        '--cop-m-rowp-l' => 'm_row_pl',
        '--cop-m-rowgap' => 'm_row_gap',
        '--cop-d-headpady' => 'd_head_pad_y',
        '--cop-d-headpadx' => 'd_head_pad_x',
        '--cop-d-headmax' => 'd_head_max',
        '--cop-d-headlogo' => 'd_head_logo',
        '--cop-d-headbadge' => 'd_head_badge',
        '--cop-m-headpady' => 'm_head_pad_y',
        '--cop-m-headpadx' => 'm_head_pad_x',
        '--cop-m-headmax' => 'm_head_max',
        '--cop-m-headlogo' => 'm_head_logo',
        '--cop-m-headbadge' => 'm_head_badge',
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
        '--cop-rowh', '--cop-rowp-t', '--cop-rowp-r', '--cop-rowp-b', '--cop-rowp-l', '--cop-rowgap', '--cop-rowf', '--cop-qtys',
        '--cop-rowb', '--cop-rowpb',
        '--cop-headpady', '--cop-headpadx', '--cop-headmax', '--cop-headlogo', '--cop-headbadge',
        '--cop-ttitle', '--cop-tlead', '--cop-th2', '--cop-tlabel', '--cop-tinput', '--cop-tph', '--cop-ttrust',
    ] as $shared) {
        expect($vars)->not->toContain($shared.':');
    }

    // And both halves are there, so the stylesheet has something to choose.
    expect($vars)->toContain('--cop-d-secpad:30px')
        ->and($vars)->toContain('--cop-m-secpad:10px');
});
// MUTATION: rename --cop-d-secpad to --cop-secpad in CheckoutPage::VARS. RED.

it('reassigns every shared token inside the mobile query', function () {
    $css = copCss();

    /*
     * The mobile token rule, found by a line only IT contains and then read to
     * its own closing brace.
     *
     * Two earlier shapes of this were wrong in ways that read as a real
     * failure. A fixed 1400-character window stopped covering the block the
     * first time tokens were added to it. Counting from the first
     * `@media(max-width:900px){` broke the moment a sticky-header rule put an
     * earlier one in the file — it then measured the DESKTOP block and
     * reported every mobile reassignment missing.
     */
    $anchor = strpos($css, '--cop-padx:var(--cop-m-padx,');
    expect($anchor)->not->toBeFalse('the mobile token block moved — re-read this test');

    $open = strrpos(substr($css, 0, (int) $anchor), '.kbb-checkout{');
    $close = strpos($css, '}', (int) $anchor);
    expect($open)->not->toBeFalse()->and($close)->not->toBeFalse();

    $mobile = substr($css, (int) $open, (int) $close - (int) $open);

    foreach ([
        '--cop-padx:var(--cop-m-padx,',
        '--cop-pady:var(--cop-m-pady,',
        '--cop-block:var(--cop-m-block,',
        '--cop-secpad:var(--cop-m-secpad,',
        '--cop-asidepad:var(--cop-m-asidepad,',
        '--cop-rowh:var(--cop-m-rowh,',
        '--cop-rowp-t:var(--cop-m-rowp-t,',
        '--cop-rowp-r:var(--cop-m-rowp-r,',
        '--cop-rowp-b:var(--cop-m-rowp-b,',
        '--cop-rowp-l:var(--cop-m-rowp-l,',
        '--cop-rowgap:var(--cop-m-rowgap,',
        '--cop-rowf:var(--cop-m-rowf,',
        '--cop-qtys:var(--cop-m-qtys,',
        '--cop-rowb:var(--cop-m-rowb,',
        '--cop-rowpb:var(--cop-m-rowpb,',
        '--cop-headpady:var(--cop-m-headpady,',
        '--cop-headpadx:var(--cop-m-headpadx,',
        '--cop-headmax:var(--cop-m-headmax,',
        '--cop-headlogo:var(--cop-m-headlogo,',
        '--cop-headbadge:var(--cop-m-headbadge,',
        '--cop-ttitle:var(--cop-m-ttitle,',
        '--cop-tlead:var(--cop-m-tlead,',
        '--cop-th2:var(--cop-m-th2,',
        '--cop-tlabel:var(--cop-m-tlabel,',
        '--cop-tinput:var(--cop-m-tinput,',
        '--cop-tph:var(--cop-m-tph,',
        '--cop-ttrust:var(--cop-m-ttrust,',
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
        // Every switch is a look or a movement: whether a column follows the
        // scroll, whether the header does, whether the summary rows are bold,
        // and which parts of the two animations run. None adds or removes a
        // section, and none of them is a layout.
        ->and(array_keys($types, 'bool', true))->toBe([
            'd_sticky', 'd_row_bold', 'm_row_bold', 'd_head_sticky', 'm_head_sticky',
            'optin_on', 'optin_checked', 'notes_on',
            'addr_cue', 'addr_cue_icons', 'addr_cue_arrow', 'addr_cue_pulse', 'trust_tick',
        ]);
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

    expect($css)->toContain('.kbb-checkout .ci{display:flex;gap:var(--cop-rowgap);padding:var(--cop-rowp-t) var(--cop-rowp-r) var(--cop-rowp-b) var(--cop-rowp-l)')
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

/* ------------------------------------------------------------------------
 | 7. The header, the type factors and the two animations
 |------------------------------------------------------------------------*/

it('turns a speed into a duration, not into itself', function () {
    /*
     * The owner sets a SPEED and the stylesheet needs a DURATION. 200% fast is
     * half the time, not twice it — emitted the wrong way round, every slider
     * on the Attention & trust tab would run backwards, which is the kind of
     * bug that gets called "the animation is broken" and looked for anywhere
     * but here.
     */
    cop()->save(['addr_cue_speed' => 200, 'trust_tick_speed' => 50]);

    expect(cop()->cssVariables())->toBe('--cop-cue-t:0.5;--cop-tick-t:2');
});
// MUTATION: emit the ratio instead of its inverse. RED.

it('ships every switch as an OFF class, so all-on puts no class on the page', function () {
    expect(cop()->bodyClass())->toBe('');

    cop()->save([
        'd_head_sticky' => false, 'm_head_sticky' => false,
        'addr_cue' => false, 'trust_tick' => false,
    ]);

    // Order follows CLASS_VARS, which follows the schema. Named as OFF
    // switches so the default — everything on — is the empty string above and
    // not a list of classes the stylesheet then has to ignore.
    expect(cop()->bodyClass())
        ->toBe(' cop-dhead-static cop-mhead-static cop-nocue cop-notick');
});

it('gives each surface its own header switch instead of one that must undo the other', function () {
    $css = copCss();

    expect($css)->toContain('@media(min-width:901px){.kbb-checkout.cop-dhead-static .co-head{position:static}}')
        ->and($css)->toContain('@media(max-width:900px){.kbb-checkout.cop-mhead-static .co-head{position:static}}');
});

it('lets the scroll offset follow the header it exists to clear', function () {
    /*
     * .co-head is sticky, so a field scrolled to the top of the viewport lands
     * under it; scroll-margin-top is what stops that, and it was the literal
     * 96px the bar happened to measure. Raise the logo or the padding and a
     * focused field goes back under the bar — on the invalid-field scroll, at
     * the moment of payment.
     *
     * 14 * 2 + 20 + 48 = 96, so this is the same number it always was until
     * one of those two moves.
     */
    expect(copCss())->toContain('scroll-margin-top:calc(var(--cop-headpady) * 2 + var(--cop-headlogo) + 48px)');
});

it('keeps the 16px field floor on a phone whatever the slider says', function () {
    /*
     * iOS Safari zooms the page when a field under 16px takes focus and does
     * not zoom back out. The shopper is left on a checkout wider than their
     * screen, mid-order, with no way back. max() is what makes the Mobile
     * tab's field-size slider able to raise this and unable to lower it.
     */
    expect(copCss())->toContain('font-size:max(16px, calc(16px * var(--cop-tinput)))');

    // And the control agrees with the stylesheet rather than offering a value
    // the CSS would silently ignore.
    expect(CheckoutPage::SCHEMA['m_t_input'][4]['min'])->toBe(100);
});
// MUTATION: drop the max(). RED — and on a phone, a zoomed checkout nobody can
// zoom back out of.

it('scales the section number with the heading it sits in', function () {
    // A bigger heading beside the same small disc is a bar that has lost its
    // shape, which is what a single font-size change would have produced.
    expect(copCss())
        ->toContain('.n{width:calc(21px * var(--cop-th2));height:calc(21px * var(--cop-th2))')
        ->toContain('font-size:calc(11.5px * var(--cop-th2))');
});

it('draws the authenticity tick rather than fading it in', function () {
    $css = copCss();

    // stroke-dashoffset from the path's own length to zero: the tick is drawn,
    // the way a hand draws it. An opacity fade would say "a tick appeared".
    expect($css)->toContain('.kbb-checkout .kr-tick{stroke-dasharray:9;stroke-dashoffset:0;')
        ->and($css)->toContain('@keyframes krTick{0%,6%{stroke-dashoffset:9}')
        ->and($css)->toContain('@keyframes krShield{')
        // Off and reduced-motion are the same RESTING state — the tick still
        // there, already drawn — not a blank shield.
        ->and($css)->toContain('.kbb-checkout.cop-notick .kr-tick{stroke-dashoffset:0}')
        ->and($css)->toMatch('/prefers-reduced-motion:reduce\)\{\s*\.kbb-checkout \.kr-tick/');

    // And the two paths carry the classes those rules need. The markup and the
    // stylesheet are in different files; this is what makes them agree.
    $partial = (string) file_get_contents(resource_path('views/partials/checkout/reassurance.blade.php'));

    expect($partial)->toContain('class="kr-shield"')->and($partial)->toContain('class="kr-tick"');
});

it('draws the address cue on the empty row only, and never on a chosen one', function () {
    /*
     * An animation pointing at a job already done is noise on the one page
     * where noise costs money. The cue markup sits inside the @else of the
     * chosen/empty branch, and the sheet — which MOVES the button into the
     * chosen row rather than rebuilding it — takes the halo class off it on
     * the way.
     */
    $partial = (string) file_get_contents(resource_path('views/partials/checkout-address.blade.php'));

    [$chosen, $empty] = explode('@else', $partial, 2);

    expect($chosen)->not->toContain('cka-cueic')
        ->and($chosen)->not->toContain('cka-arrow')
        ->and($chosen)->not->toContain('cka-cta')
        ->and($empty)->toContain('cka-cueic')
        ->and($empty)->toContain('cka-arrow')
        ->and($empty)->toContain('cka-cta');

    $sheet = (string) file_get_contents(resource_path('views/partials/address-sheet.blade.php'));

    expect($sheet)->toContain("btn.classList.remove('cka-cta')");

    // Every moving part is inside a reduced-motion guard, and none of it is
    // script: nothing is measured and nothing is observed.
    expect($partial)->toMatch('/@media \(prefers-reduced-motion:reduce\)\{[^}]*\.cka-cueic \.ci-home,\.cka-arrow\{animation:none\}/')
        ->and($partial)->not->toContain('<script');
});

it('hides the cue from a screen reader, which the row already tells in words', function () {
    $partial = (string) file_get_contents(resource_path('views/partials/checkout-address.blade.php'));

    // Three decorations between the prompt and the button are three
    // interruptions carrying nothing the sentence does not already say.
    expect(substr_count($partial, 'aria-hidden="true"'))->toBeGreaterThanOrEqual(4);
});

/* ------------------------------------------------------------------------
 | 8. The round that came from a photograph of the sheet
 |------------------------------------------------------------------------*/

it('keeps the placeholder rules in the file whose markup needs them', function () {
    /*
     * THE DEFECT THIS PINS, reported with a screenshot: the address sheet
     * opened and showed the line "Loading your addresses" as plain body text
     * with no placeholder under it.
     *
     * The sheet's markup lives in partials/address-sheet.blade.php and is
     * included by BOTH the cart page and the checkout. Its placeholder rules
     * lived in store/cart-squeeze.blade.php — the squeezed cart page's own
     * stylesheet — so `.cpg-sk`, `.cpg-skcard` and `.cpg-skline` had no rules
     * anywhere else, and `.cpg-vh`, which is what hides a status message from
     * the screen while leaving it for a screen reader, did not exist either.
     * Hence a visible sentence and no bars.
     */
    $sheet = (string) file_get_contents(resource_path('views/partials/address-sheet.blade.php'));
    $cart = (string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php'));

    foreach (['.cpg-sk{', '.cpg-skcard{', '.cpg-skline{', '.cpg-vh{'] as $rule) {
        expect($sheet)->toContain($rule);
        // And not in two places, which is how they would drift apart again.
        expect($cart)->not->toContain($rule);
    }

    // Every width class the skeleton emits has a rule, or a bar renders at
    // full width and the placeholder stops looking like a list.
    foreach (['w40', 'w45', 'w55', 'w65', 'w70', 'w85', 'w90'] as $w) {
        expect($sheet)->toContain('.cpg-skline.'.$w.'{');
    }
});
// MUTATION: move any of those rules back to cart-squeeze. RED.

it('hands the sheet its list with the page, so the first open needs no request', function () {
    /*
     * "i want that popup should open directly ... everything should
     * immediately load in real time with no delays."
     *
     * GET /cart/address answers with exactly CartAddressState::all() — see
     * CartAddressController::index(), which is one line — so rendering that
     * into the page makes the first open instant and the request unnecessary.
     * It is the same shopper's own addresses, in their own page, from the
     * session the endpoint would have answered from.
     */
    $sheet = (string) file_get_contents(resource_path('views/partials/address-sheet.blade.php'));

    expect($sheet)->toContain('$kbbSheetSeed = \App\Support\CartAddressState::all(request());')
        ->and($sheet)->toContain('var SEED = @json($kbbSheetSeed);')
        ->and($sheet)->toContain('var state = SEED || null;')
        // The placeholder is kept for the case the seed cannot cover, so a
        // stale page still has something to show rather than an empty sheet.
        ->and($sheet)->toContain('if (state === null) {');
});

it('stops the site-wide 44px logo target inflating the checkout bar', function () {
    /*
     * kbb.css gives every `.logo` a 44px minimum touch target below 900px, for
     * the storefront header. It also caught this bar: measured in Chromium at
     * 390px the checkout header was 73px tall against 58 on a desktop —
     * 14 + 44 + 14 — and no padding control could reach it, because the space
     * was inside the logo. That is the "empty spaces on top and bottom".
     */
    expect(copCss())->toContain('.kbb-checkout .co-head .logo{min-height:0}');
});

it('draws the notices band only when there is a notice', function () {
    // It rendered on every load, errors or not, and its 16px of top padding
    // was permanently empty space between the header and the page.
    $blade = (string) file_get_contents(resource_path('views/store/checkout.blade.php'));

    $at = strpos($blade, 'class="co-notices"');
    expect($at)->not->toBeFalse();

    expect(substr($blade, max(0, (int) $at - 400), 400))->toContain('@if ($errors->any())');
});

it('gives the mobile header-width control a range a phone can use', function () {
    /*
     * It shipped at 880–1600px, which is wider than every phone, so the slider
     * moved and the header did not — reported as "the width of header on
     * checkout page not working properly". A band narrower than the screen is
     * the only thing the control can do there.
     */
    expect(CheckoutPage::SCHEMA['m_head_max'][4]['min'])->toBeLessThan(360);
});

it('turns the two fields the owner asked to turn off, off', function () {
    $c = cop()->all();

    // The only two defaults in this schema that do not reproduce today's page,
    // and both were asked for in as many words.
    expect($c['notes_on'])->toBeFalse()
        ->and($c['optin_checked'])->toBeFalse()
        // The opt-in ROW still shows; it is the tick that is off.
        ->and($c['optin_on'])->toBeTrue();

    $blade = (string) file_get_contents(resource_path('views/store/checkout.blade.php'));

    // Not rendered rather than hidden: nothing posts customer_note while the
    // box is off, so place() needs no branch for it.
    expect($blade)->toContain("@if (\$kbbCoPage->get('notes_on'))")
        ->and($blade)->toContain("old('billing_kbb_whatsapp', \$kbbCoPage->get('optin_checked'))")
        ->and($blade)->not->toContain("old('billing_kbb_whatsapp', true)");
});

it('names a squeeze preset that is a list of sliders, not a mode', function () {
    /*
     * A stored "squeezed" flag overriding the sliders would leave every slider
     * showing a number the page was not using — the screen would lie, and the
     * owner would drag one and watch nothing move. The preset writes the
     * sliders instead, and Save stores what is on the screen.
     */
    expect(CheckoutPage::SQUEEZE)->not->toBeEmpty();

    // Every key it names exists, and every one of them is a slider — a preset
    // that flipped a switch would be a second thing happening under one button.
    foreach (CheckoutPage::SQUEEZE as $key) {
        expect(CheckoutPage::SCHEMA)->toHaveKey($key);
        expect(CheckoutPage::SCHEMA[$key][0])->toBe('range');
    }

    // Both surfaces, because the owner asked for an overall one.
    $sides = array_values(array_unique(array_map(fn ($k) => substr($k, 0, 2), CheckoutPage::SQUEEZE)));
    expect($sides)->toEqualCanonicalizing(['d_', 'm_']);

    // And NOT the page or header width: squeezing a layout does not mean
    // narrowing the page it sits on.
    expect(CheckoutPage::SQUEEZE)->not->toContain('d_max')
        ->and(CheckoutPage::SQUEEZE)->not->toContain('d_head_max')
        ->and(CheckoutPage::SQUEEZE)->not->toContain('m_head_max');
});

it('sizes the placeholder against the field rather than in pixels of its own', function () {
    /*
     * `em`, so the two sliders compound instead of fighting and there is no
     * third number to keep in step. And it is the one text on a phone that can
     * safely go small: iOS decides whether to zoom from the INPUT's font-size,
     * never the placeholder's.
     */
    expect(copCss())->toContain('::placeholder{font-size:calc(1em * var(--cop-tph))}')
        ->and(CheckoutPage::SCHEMA['m_t_ph'][4]['min'])->toBeLessThan(100);
});

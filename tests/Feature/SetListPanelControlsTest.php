<?php

declare(strict_types=1);

use App\Services\SetAppearance;
use Tests\Support\CssDirection;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  THE "HANGING PHOTOS" PANEL IS A SET OF CONTROLS, NOT A DRAWING. (Lane SA2)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The owner, 29 September 2026:
 *
 *   "ok the hanging photos style is fine. please proceed with that, and give
 *    full controls of everything."
 *
 * The treatment shipped as a block of LITERALS appended to the end of the
 * panel's @once <style>, layered over rules that were already var()-driven. It
 * looked right and it quietly took thirteen of Appearance → Set's controls out
 * of service — the photograph's size, its radius and its backing, the row's
 * padding and its gap, the hairline between rows, the disclosure's padding and
 * its colour, the footing's spacing and its rule — because a later rule at the
 * same specificity wins whatever the variable inside the earlier one says.
 *
 * ── THE THREE DEFECTS THESE CASES WOULD HAVE CAUGHT ────────────────────────
 *
 *  1. A CONTROL THAT DRIVES NOTHING. `p_photo` emitted `--ksl-ph:44px` and the
 *     panel drew 36 regardless. Green everywhere: the setting saved, the
 *     stylesheet carried the property, the page ignored it.
 *
 *  2. A `padding` SHORTHAND IN A BOX THAT HANGS THINGS OFF ITS INLINE-START
 *     EDGE. `padding:12px 14px 11px 20px` puts the 20 on the LEFT in every
 *     language while `margin-inline-start` puts the pull on the RIGHT on /ar.
 *     Measured in Chromium before the fix, three-member set:
 *
 *                                    1280       390
 *       chips hang past the panel     16px      17px     (10px is the design)
 *       footing past the panel         6px       7px     (0 is the design)
 *
 *     The footing's rule and all three money figures stood outside the blush
 *     panel on every Arabic set page. The RTL guard could not see it: only the
 *     LONGHANDS are in CssDirection::MAP, and a shorthand hides four of them.
 *
 *  3. TWO @media BLOCKS DECLARING THE SAME CUSTOM PROPERTIES. The bare list's
 *     said `--ksl-ph:36px` and the panel's drew 32px; the schema shipped 36.
 *     Three answers, and source order picked the one nobody had written down.
 *
 * Every case below is written against the SHIPPED style block rather than
 * against a rendered page, because that block is what a shop with no saved
 * settings actually gets — storefrontCss() answers the empty string until a
 * slider moves, so the fallbacks in this file ARE the shop.
 */
function saPanelCss(): string
{
    $partial = (string) file_get_contents(
        base_path('resources/views/partials/set-contents-panel.blade.php')
    );

    $open = strpos($partial, '<style>');
    $close = strpos($partial, '</style>');

    expect($open === false || $close === false)->toBeFalse('The panel has no closed style block.');

    $css = substr($partial, (int) $open + 7, (int) $close - (int) $open - 7);

    /*
     * COMMENTS OUT, and it is not tidiness: this block is nine tenths prose and
     * the prose QUOTES the rules it explains — "@media (max-width:480px)" and
     * "var(--ksl-x, <the literal>)" both appear in it. The first version of the
     * case that counts media queries found two and named a second block that
     * does not exist, and the fallback case reported `--ksl-x` reading
     * "<the literal the box shipped with>". Newlines are kept so a failure
     * still points at a plausible line.
     */
    return (string) preg_replace_callback(
        '#/\\*.*?\\*/#s',
        static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $css
    );
}

it('drives every tunable property of the panel from a control', function () {
    /*
     * DEFECT 1, generically. Not a list of thirteen properties somebody has to
     * remember to extend — every declaration under a `.ksl-panel` selector
     * whose property is one this screen has a slider for must read a
     * `var(--ksl-…)`. A fourteenth hard-coded number added to the treatment
     * fails here without anybody editing this file.
     *
     * `box-shadow` carries a `-1px` spread and an `rgba(42,34,40,…)` ink that
     * are deliberately constants — the ring width, the drop, the softness and
     * the strength inside it are all variables — so it is checked for
     * CONTAINING a var() like everything else rather than for being nothing but
     * one.
     *
     * MUTATION: put `width:36px` back on `.ksl-panel .ksl-ph` and this goes red
     * naming the selector and the property. RUN IT.
     */
    $tunable = [
        'background', 'border-radius', 'padding', 'padding-top', 'padding-block-start',
        'padding-block-end', 'padding-inline-start', 'padding-inline-end', 'gap',
        'grid-template-columns', 'width', 'height', 'margin-inline-start', 'margin-top',
        'box-shadow', 'color', 'font-size', 'font-weight', 'line-height', 'letter-spacing',
        'border-top',
    ];

    $bare = [];

    foreach (CssDirection::declarations(saPanelCss()) as $d) {
        if (! str_starts_with($d['selector'], '.ksl-panel')) {
            continue;
        }

        /*
         * The `ksl-noph` track rules are the exception and it is the honest
         * kind: they exist BECAUSE a custom property cannot take a track out of
         * a grid, so `minmax(0,1fr) auto` with no photograph column in it is
         * the whole content of the rule. A var() there would be a var for a
         * track that is not drawn.
         */
        if (str_contains($d['selector'], '.ksl-noph') && $d['property'] === 'grid-template-columns') {
            continue;
        }

        if (in_array($d['property'], $tunable, true) && ! str_contains($d['value'], 'var(--ksl-')) {
            $bare[] = $d['selector'].' | '.$d['property'].': '.$d['value'];
        }
    }

    expect($bare)->toBe([], "The panel hard-codes what Appearance → Set has a control for:\n  "
        .implode("\n  ", $bare));
});

it('hangs and pulls back off ONE inline-start inset', function () {
    /*
     * DEFECT 2. The panel's leading padding, the chips' pull and the footing's
     * pull-back are three readings of one number, and the whole of the drawing
     * is that they agree. Three literals cannot be made to agree by a reviewer;
     * one variable cannot be made to disagree at all.
     *
     * MUTATION: change the footing's `margin-inline-start` to
     * `calc(-1 * 20px)` — numerically identical today — and this goes red,
     * because the owner moving the panel's padding would then push the footing
     * out through the panel's edge exactly as /ar did.
     */
    $decl = [];

    foreach (CssDirection::declarations(saPanelCss()) as $d) {
        $decl[$d['selector'].'|'.$d['property']] = trim($d['value']);
    }

    expect($decl)->toHaveKey('.ksl-panel|padding-inline-start');
    expect($decl['.ksl-panel|padding-inline-start'])->toBe('var(--ksl-pps,20px)');

    foreach ([
        '.ksl-panel .ksl-ph|margin-inline-start',
        '.ksl-panel .ksl-foot|margin-inline-start',
        '.ksl-panel .ksl-foot|padding-inline-start',
    ] as $key) {
        expect($decl)->toHaveKey($key);
        expect(str_contains($decl[$key], 'var(--ksl-pps,'))->toBeTrue(
            "{$key} does not read the panel's own inline-start inset, so the two can drift apart — "
            .'which is the /ar defect this box shipped with.'
        );
    }

    /* And the footing pulls back by EXACTLY the inset, never by the chips' pull:
       the rule stops at the panel's edge and the chips are the only thing that
       crosses it. */
    expect($decl['.ksl-panel .ksl-foot|margin-inline-start'])->toBe('calc(-1 * var(--ksl-pps,20px))');
});

it('cannot be dragged into a state where the photographs stop hanging', function () {
    /*
     * The floor the brief calls not cosmetic, proved rather than asserted.
     *
     * The control is the OVERHANG and the chip's pull is `padding + overhang`,
     * so `pull > padding` reduces to `overhang > 0` — and the schema's minimum
     * of 1 is what makes that total. There is no clamp in PHP to get wrong at
     * one breakpoint and right at the other, which is the shape the alternative
     * would have taken.
     *
     * MUTATION: set `p_over`'s min to 0 and this goes red. Set the partial's
     * rule to `calc(-1 * var(--ksl-over,10px))` and the case above goes red.
     */
    foreach (['p_over', 'p_over_m'] as $key) {
        $options = SetAppearance::SCHEMA[$key][4];

        expect($options['min'])->toBeGreaterThanOrEqual(1,
            "{$key} can be set to zero, which puts the chips flush with the panel edge rather than outside it."
        );
    }

    /* Both ends of both sliders, through the real emitter: the pull is the sum,
       so the emitted pair can never make the chip sit inside the panel. */
    foreach ([['p_panel_ps', 'p_over'], ['p_panel_ps_m', 'p_over_m']] as [$padKey, $overKey]) {
        foreach ([SetAppearance::SCHEMA[$padKey][4]['min'], SetAppearance::SCHEMA[$padKey][4]['max']] as $pad) {
            foreach ([SetAppearance::SCHEMA[$overKey][4]['min'], SetAppearance::SCHEMA[$overKey][4]['max']] as $over) {
                expect($pad + $over)->toBeGreaterThan($pad,
                    "pull {$pad}+{$over} does not exceed the padding {$pad}"
                );
            }
        }
    }
});

it('keeps the legible and the tappable floor inside the slider itself', function () {
    /*
     * "A control that can go below it is a control that can break the shop."
     * SetContentsBoxTreatmentsTest pins the two floors on the SHIPPED rule;
     * this pins them on the range the owner can drag to, which is the half a
     * screenshot cannot show.
     *
     * MUTATION: set `p_photo`'s min back to 24 and this goes red.
     */
    foreach (['p_name', 'p_name_m'] as $key) {
        // Stored in tenths of a pixel: 125 is 12.5px.
        expect(SetAppearance::SCHEMA[$key][4]['min'])->toBeGreaterThanOrEqual(125,
            "{$key} can be dragged below the 12.5px legible floor."
        );
    }

    foreach (['p_photo', 'p_photo_m'] as $key) {
        expect(SetAppearance::SCHEMA[$key][4]['min'])->toBeGreaterThanOrEqual(30,
            "{$key} can be dragged below the 30px tappable floor."
        );
    }

    // And the shipped values are inside their own floors, which is the check
    // that catches a floor raised past the number the page already draws.
    foreach (SetAppearance::SCHEMA as $key => $def) {
        if ($def[0] === 'range') {
            expect($def[2])->toBeGreaterThanOrEqual($def[4]['min'], "{$key} ships below its own minimum");
            expect($def[2])->toBeLessThanOrEqual($def[4]['max'], "{$key} ships above its own maximum");
        }
    }
});

it('declares the phone’s numbers in exactly one media query', function () {
    /*
     * DEFECT 3. Two @media blocks setting the same custom properties is how the
     * panel's 32px photograph and the bare list's 36px both became true, with
     * source order deciding and the schema agreeing with the loser.
     *
     * MUTATION: add a second `@media (max-width:480px){.ksl{--ksl-ph:36px}}`
     * anywhere in the block and this goes red.
     */
    $css = saPanelCss();

    $blocks = preg_match_all('/@media[^{]*\{/', $css);

    expect($blocks)->toBe(1, 'The panel style block has more than one media query; '
        .'two of them declaring the same custom properties is how its phone sizes came apart.');
});

it('carries one fallback per custom property, and it is the shipped default', function () {
    /*
     * The general form of defect 1, and the case that makes the partial and the
     * schema one source of truth rather than two that happen to agree today.
     *
     * A `var(--ksl-x, LIT)` fallback is what a shop that has moved nothing
     * renders — storefrontCss() emits the empty string until a slider moves —
     * so a fallback that disagrees with its control's shipped default means the
     * admin screen is lying about what the page draws. It happened five times
     * over: the photograph read 40 in one rule and 36 in another while the
     * schema said 40 and the page drew 36.
     *
     * MUTATION: change any one `var(--ksl-ph,36px)` to `var(--ksl-ph,40px)` and
     * this goes red naming the property and both readings.
     */
    $css = saPanelCss();

    preg_match_all('/var\(\s*(--ksl-[A-Za-z0-9_-]+)\s*,\s*([^()]*?)\s*\)/', $css, $m, PREG_SET_ORDER);

    expect($m)->not->toBe([], 'The panel reads no custom properties at all.');

    $seen = [];

    foreach ($m as [, $property, $fallback]) {
        $seen[$property][$fallback] = true;
    }

    foreach ($seen as $property => $fallbacks) {
        expect(array_keys($fallbacks))->toHaveCount(1,
            "{$property} is read with more than one fallback — ".implode(' and ', array_keys($fallbacks))
            .' — so which one the shop draws is decided by source order.'
        );
    }

    /*
     * And each one against its control. The map is written out rather than
     * derived, because deriving it from listVars() would be the same mistake in
     * two places: the point of the case is that a HUMAN said which control owns
     * which property and a machine checks it still does.
     */
    $defaults = SetAppearance::defaults();

    /*
     * The three scales this schema stores in, as DIVISORS — whole units, tenths
     * of a pixel (the name is 13.5px and a slider stores integers) and
     * hundredths (a line height of 1.3, an opacity of .72). Compared as numbers
     * and not as strings: `.22` and `0.22` are the same fallback, the partial
     * writes one and SetAppearance::ratio() writes the other, and a case that
     * failed on that would be a case about punctuation.
     */
    $px = 1;
    $tenths = 10;
    $hundredths = 100;

    $map = [
        '--ksl-block' => ['p_block', $px],
        '--ksl-rowpad' => ['p_rowpad', $px],
        '--ksl-gap' => ['p_gap', $px],
        '--ksl-wgap' => ['p_wgap', $px],
        '--ksl-ph' => ['p_photo', $px],
        '--ksl-phr' => ['p_radius', $px],
        '--ksl-br' => ['p_brand', $px],
        '--ksl-nm' => ['p_name', $tenths],
        '--ksl-var' => ['p_var', $tenths],
        '--ksl-q' => ['p_qty', $px],
        '--ksl-more' => ['p_more', $tenths],
        '--ksl-morept' => ['p_morept', $px],
        '--ksl-morepb' => ['p_morepb', $px],
        '--ksl-foot' => ['p_foot', $px],
        '--ksl-wasf' => ['p_was_f', $px],
        '--ksl-pricef' => ['p_price_f', $px],
        '--ksl-savef' => ['p_save_f', $px],
        '--ksl-footsp' => ['p_footsp', $px],
        '--ksl-footgy' => ['p_footgy', $px],
        '--ksl-footgx' => ['p_footgx', $px],
        '--ksl-pr' => ['p_panel_r', $px],
        '--ksl-ppt' => ['p_panel_pt', $px],
        '--ksl-ppe' => ['p_panel_pe', $px],
        '--ksl-ppb' => ['p_panel_pb', $px],
        '--ksl-pps' => ['p_panel_ps', $px],
        '--ksl-over' => ['p_over', $px],
        '--ksl-ring' => ['p_ring', $tenths],
        '--ksl-shy' => ['p_sh_y', $px],
        '--ksl-shb' => ['p_sh_blur', $px],
        '--ksl-sha' => ['p_sh_a', $hundredths],
        '--ksl-headf' => ['p_head_f', $tenths],
        '--ksl-headgap' => ['p_head_gap', $px],
        '--ksl-headw' => ['p_head_w', $px],
        '--ksl-nmlh' => ['p_name_lh', $hundredths],
        '--ksl-brlh' => ['p_brand_lh', $hundredths],
        '--ksl-lh' => ['p_lh', $hundredths],
        '--ksl-brw' => ['p_brand_w', $px],
        '--ksl-brop' => ['p_brand_op', $hundredths],
        '--ksl-nmw' => ['p_name_w', $px],
        '--ksl-qw' => ['p_qty_w', $px],
        '--ksl-morew' => ['p_more_w', $px],
        '--ksl-footw' => ['p_foot_w', $px],
        '--ksl-wasw' => ['p_was_w', $px],
        '--ksl-savew' => ['p_save_w', $px],
    ];

    foreach ($map as $property => [$key, $scale]) {
        expect($seen)->toHaveKey($property);
        expect($defaults)->toHaveKey($key);

        $want = (int) $defaults[$key] / $scale;
        $got = (string) array_key_first($seen[$property]);

        expect((float) rtrim($got, 'pxem '))->toBe((float) $want,
            "{$property} falls back to {$got} while {$key} ships at {$want}: the screen and the page "
            .'disagree about what an untouched shop draws.'
        );
    }
});

it('gives the phone every _m default that differs from its laptop twin', function () {
    /*
     * The other half of the case above: the media query carries what DIFFERS
     * and nothing else, and what it carries has to be the `_m` default. A
     * number in there without a control, or a control whose phone value never
     * reaches the page, is the same defect from either end.
     *
     * MUTATION: delete `--ksl-pps:16px` from the block and this goes red saying
     * the phone draws the laptop's 20px inset while the screen says 16.
     */
    $css = saPanelCss();

    expect(preg_match('/@media[^{]*\{(.*)\}\s*$/s', $css, $mm))->toBe(1, 'No media query in the panel.');

    $phone = $mm[1];

    $defaults = SetAppearance::defaults();

    /*
     * The three scales this schema stores in, as DIVISORS — whole units, tenths
     * of a pixel (the name is 13.5px and a slider stores integers) and
     * hundredths (a line height of 1.3, an opacity of .72). Compared as numbers
     * and not as strings: `.22` and `0.22` are the same fallback, the partial
     * writes one and SetAppearance::ratio() writes the other, and a case that
     * failed on that would be a case about punctuation.
     */
    $px = 1;
    $tenths = 10;
    $hundredths = 100;

    $map = [
        '--ksl-block' => ['p_block', $px], '--ksl-rowpad' => ['p_rowpad', $px],
        '--ksl-gap' => ['p_gap', $px], '--ksl-wgap' => ['p_wgap', $px],
        '--ksl-ph' => ['p_photo', $px], '--ksl-phr' => ['p_radius', $px],
        '--ksl-br' => ['p_brand', $px], '--ksl-nm' => ['p_name', $tenths],
        '--ksl-var' => ['p_var', $tenths], '--ksl-q' => ['p_qty', $px],
        '--ksl-more' => ['p_more', $tenths], '--ksl-morept' => ['p_morept', $px],
        '--ksl-morepb' => ['p_morepb', $px], '--ksl-foot' => ['p_foot', $px],
        '--ksl-wasf' => ['p_was_f', $px], '--ksl-pricef' => ['p_price_f', $px],
        '--ksl-savef' => ['p_save_f', $px],
        '--ksl-footsp' => ['p_footsp', $px], '--ksl-footgy' => ['p_footgy', $px],
        '--ksl-footgx' => ['p_footgx', $px], '--ksl-pr' => ['p_panel_r', $px],
        '--ksl-ppt' => ['p_panel_pt', $px], '--ksl-ppe' => ['p_panel_pe', $px],
        '--ksl-ppb' => ['p_panel_pb', $px], '--ksl-pps' => ['p_panel_ps', $px],
        '--ksl-over' => ['p_over', $px], '--ksl-ring' => ['p_ring', $tenths],
        '--ksl-shy' => ['p_sh_y', $px], '--ksl-shb' => ['p_sh_blur', $px],
        '--ksl-headf' => ['p_head_f', $tenths], '--ksl-headgap' => ['p_head_gap', $px],
        '--ksl-nmlh' => ['p_name_lh', $hundredths], '--ksl-brlh' => ['p_brand_lh', $hundredths],
    ];

    foreach ($map as $property => [$key, $scale]) {
        $desktop = (int) $defaults[$key] / $scale;
        $phoneWant = (int) $defaults[$key.'_m'] / $scale;

        $declared = preg_match('/'.preg_quote($property, '/').':\s*([^;}]+)/', $phone, $d) === 1
            ? (float) rtrim(trim($d[1]), 'pxem ')
            : null;

        if ($desktop === $phoneWant) {
            expect($declared)->toBeNull(
                "{$property} is restated in the phone block at the laptop's own value; the block carries "
                .'what differs and nothing else, so this is a byte that says nothing.'
            );

            continue;
        }

        expect($declared)->toBe((float) $phoneWant,
            "{$key}_m ships at {$phoneWant} and the phone block "
            .($declared === null ? 'does not declare it at all, so the page draws the laptop\'s '.$desktop
                : 'declares '.$declared)
        );
    }
});

it('turns the panel, the count and the footing rule on and off by class', function () {
    /*
     * A custom property can change a number inside a rule; it cannot switch a
     * rule off, so these three are classes — and the case that matters is that
     * the OFF state is reachable and the ON state is what ships.
     *
     * MUTATION: drop `ksl-panel` from panelClass() and the treatment stops
     * selecting at all; this goes red on the first expectation and the shop
     * renders the bare list it was layered over.
     */
    $on = SetAppearance::defaults();

    expect(SetAppearance::panelClass($on))->toContain('ksl-panel');
    expect(SetAppearance::panelClass($on))->not->toContain('ksl-nocount');
    expect(SetAppearance::panelClass($on))->not->toContain('ksl-nofootrule');

    /* `p_rule_on` ships OFF, because the box the owner chose draws no hairlines
       between rows — it was a hard-coded `border-block-start:0` until this
       release, which made the control a no-op in the on position. */
    expect($on['p_rule_on'])->toBeFalse();
    expect(SetAppearance::panelClass($on))->toContain('ksl-norule');

    $off = ['p_panel_on' => false, 'p_count_on' => false, 'p_footrule_on' => false] + $on;

    expect(SetAppearance::panelClass($off))->not->toContain('ksl-panel');
    expect(SetAppearance::panelClass($off))->toContain('ksl-nocount');
    expect(SetAppearance::panelClass($off))->toContain('ksl-nofootrule');

    /* And each class has a rule behind it. A class nothing selects is a switch
       the owner can throw with no effect, which is the defect this whole file
       is about. */
    $css = saPanelCss();

    foreach (['.ksl-panel', '.ksl-nocount ', '.ksl-nofootrule '] as $needle) {
        expect(str_contains($css, $needle))->toBeTrue("Nothing in the panel selects on {$needle}");
    }
});

it('reaches the panel’s own settings from the storefront emitter', function () {
    /*
     * ProductStyles is this project's cautionary tale: twenty controls reached
     * no storefront page for releases because the only caller was the admin. So
     * the new properties are asserted on the real emitted stylesheet, at a
     * moved value, at both breakpoints.
     *
     * MUTATION: delete `'--ksl-pps:'.$n('p_panel_ps').'px'` from listVars() and
     * this goes red.
     */
    $values = SetAppearance::defaults();
    $values['p_panel_ps'] = 26;
    $values['p_panel_ps_m'] = 21;
    $values['p_over'] = 15;
    $values['p_ring'] = 45;
    $values['p_sh_a'] = 40;
    $values['p_panel_bg'] = '#EEDDEE';
    $values['p_head_f'] = 145;

    $css = SetAppearance::css($values);

    foreach (['--ksl-pps:26px', '--ksl-over:15px', '--ksl-ring:4.5px', '--ksl-sha:0.4',
        '--ksl-pbg:#EEDDEE', '--ksl-headf:14.5px'] as $decl) {
        expect(str_contains($css, $decl))->toBeTrue("The emitted stylesheet has no `{$decl}`");
    }

    /* The phone's own block, at the owner's own breakpoint. */
    $phone = substr($css, (int) strrpos($css, '@media (max-width:'.$values['p_bp'].'px)'));

    expect(str_contains($phone, '--ksl-pps:21px'))->toBeTrue(
        "The phone block does not carry the panel's own phone inset."
    );

    /* And nothing at all while everything is at the value the page ships with,
       which is rule 1 on the markup side and the reason applying this package
       moves nothing. */
    expect(app(SetAppearance::class)->storefrontCss())->toBe('');
});

it('draws the list on the product page and the admin preview, and nowhere else', function () {
    /*
     * ── TASK 2: THE SAME BOX, EVERYWHERE ELSE IT IS DRAWN ──────────────────
     *
     * The answer is that there is nowhere else, and this is the case that keeps
     * it true rather than a sentence in a report that goes stale.
     *
     * The two set partials are NOT the same shape and the difference is the
     * whole answer:
     *
     *   partials/set-row.blade.php          `.kset` — the fanned circles and
     *                                       the "What's inside" popup. SIX
     *                                       storefront surfaces: the cart
     *                                       drawer's line and its browsed rail,
     *                                       the cart page, the checkout
     *                                       summary, the order-received line
     *                                       and an order's detail page.
     *                                       SetRowSurfacesTest owns that list.
     *
     *   partials/set-contents-panel.blade   `.ksl` — "What is in this set", the
     *                                       hanging-photos panel. ONE
     *                                       storefront surface: the buy column
     *                                       of a set's own product page. It is
     *                                       sized for a 346–582px column, it
     *                                       reads `$product`, and a cart row is
     *                                       neither.
     *
     * So the sixty controls on Appearance → Set → … → Set list reach one page,
     * deliberately, and that is not an oversight to be widened later: the box
     * in a cart row IS the fan, which the owner chose on 28 September for that
     * job, and drawing a second full list there would be two answers to "what
     * is in this set" on one screen.
     *
     * The admin preview is the second include and it is the point: it draws the
     * real partial, so the owner sees the panel he is dragging.
     *
     * MUTATION: add `@include('partials.set-contents-panel')` to
     * resources/views/store/cart-inner.blade.php and this goes red naming it.
     */
    $includes = [];

    foreach (['resources/views'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with((string) $file, '.blade.php')) {
                continue;
            }

            $body = (string) file_get_contents((string) $file);

            /* The DIRECTIVE and not the name: every one of these files mentions
               the partial in prose, and a grep for the name finds the comments
               that explain why it is not included. */
            if (preg_match("/@include\(\s*'partials\.set-contents-panel'/", $body) === 1) {
                $includes[] = str_replace(base_path().'/', '', (string) $file);
            }
        }
    }

    sort($includes);

    expect($includes)->toBe([
        'resources/views/admin/previews/set-appearance.blade.php',
        'resources/views/store/product.blade.php',
    ], 'The set list is drawn somewhere new; it is sized for the buy column and reads $product.');
});

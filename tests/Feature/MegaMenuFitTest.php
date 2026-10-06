<?php

declare(strict_types=1);

use App\Services\HeaderSettings;
use App\Support\MegaMenuFit;
use Tests\Support\MegaMenuShape;

/**
 * Lane MG — desktop mega panels fit the site width.
 *
 * THE OWNER, over a desktop screenshot of the "All Brands" panel running off
 * the right edge of the screen: "The mega menu columns must be adjusted auto as
 * per the site width, it should [not] go outside in any case, the font size or
 * columns need to be squeezed auto as per the site width, and if in the mega
 * menu there's are more than 4 columns, then it should must start from the most
 * left of the site width, even if the parent menu is anywhere at any position.
 * there should be a nice edge type to show that this mega menu is for this
 * parent item."
 *
 * THE DEFECT ON THE SHOP: a panel was placed from its own parent and sized
 * min(220px × columns, 92vw). Measured in Chromium on a menu of his shape
 * (tests/Support/MegaMenuShape, tools/mg-shots.cjs): the nine-column brand
 * panel under a mid-row "All Brands" ran from x 403 to 1345 in a 1024 window,
 * 490 → 1668 at 1280, 541 → 1866 at 1440 and 745 → 2511 at 1920; the
 * two-column "Sunscreens", third from the end, 980 → 1420 at 1280. The page
 * did not scroll — `.mbar{overflow-x:clip}` CUT OFF everything past the edge,
 * which is what his screenshot shows. After: every panel inside the row's
 * content box at all four widths and in Arabic, scrollWidth equal to the
 * window, the parent always over its own panel (docs/lane-mg-shots).
 *
 * Appearance → Header → Navigation: "Fit mega menus to the site width" (ON),
 * "Start from the left beyond" (4 columns), "Smallest column width" (130px),
 * "Smallest mega menu text" (11.5px), "Pointer to the parent item" (ON).
 */
function mgBar(?array $items = null): string
{
    return view('partials.nav-bar', ['kbbNav' => $items ?? MegaMenuShape::items()])->render();
}

function mgCss(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb.css')));
}

/** Every opening `.navitem` tag, in order. */
function mgItems(string $html): array
{
    preg_match_all('#<div class="navitem[^"]*"[^>]*>#', $html, $m);

    return $m[0];
}

/* ═══════════════════════ 1. off is the shop as it was ═══════════════════════ */

it('prints the bar byte for byte as before this lane with the switch off', function () {
    /*
     * The fixture is the partial AS IT STOOD BEFORE THIS LANE (git HEAD at
     * 5476967e), rendered over the same menu in the same process — captured
     * from the old template, not from this branch, so it cannot agree with
     * itself by construction.
     *
     * MUTATION: print `mg-cols` whatever the switch says (drop the `!== null`
     * on the wrapper's echo). Red. Make MegaMenuFit::from() ignore `mega_fit`:
     * red. Both RUN.
     */
    app(HeaderSettings::class)->save(['mega_fit' => false]);

    expect(mgBar())->toBe((string) file_get_contents(base_path('tests/Fixtures/mega-menu-bar-before-mg.html')));
});

it('prints nothing new with the Mega Menu module off either', function () {
    app(\App\Services\SettingsService::class)->setModule('mega_menu', false);

    $html = mgBar();

    expect($html)->not->toContain('mg-')
        ->and($html)->not->toContain('class="drop');
});

/* ═══════════════════════ 2. on: what the bar prints ═══════════════════════ */

it('classes each panel by its columns and leaves every other item untouched', function () {
    /*
     * His menu: "All Brands" draws nine columns (ninety brands, ten a column),
     * past the four of "Start from the left beyond" -> mg-l. "Skincare" is set
     * to three and "Sunscreens" to two -> mg-a, they stay with their parents.
     * "Hair Care" has five children -> one column, mg-s. The eight items with
     * no panel stay `class="navitem"` to the byte. mg-p is the pointer, ON.
     *
     * MUTATION: `$columns > $this->leftFrom` -> `>=`: "Skincare" with the
     * threshold at 3 would go left; covered below. `> 1` -> `> 0`: Hair Care
     * reads mg-a and this goes red. RUN.
     */
    $items = mgItems(mgBar());

    expect($items)->toHaveCount(12)
        ->and($items[4])->toBe('<div class="navitem mg-l mg-p" style="--mega-cols:9;--mg-min:130px;--mg-fmin:11.5px">')
        ->and($items[10])->toBe('<div class="navitem mg-a mg-p" style="--mega-cols:3;--mg-min:130px;--mg-fmin:11.5px">')
        ->and($items[9])->toBe('<div class="navitem mg-a mg-p" style="--mega-cols:2;--mg-min:130px;--mg-fmin:11.5px">')
        ->and($items[3])->toBe('<div class="navitem mg-s mg-p">');

    foreach ([0, 1, 2, 5, 6, 7, 8, 11] as $plain) {
        expect($items[$plain])->toBe('<div class="navitem">');
    }
});

it('wraps each mega panel\'s columns once, and keeps the panel\'s own markup', function () {
    /*
     * Zero `.mg-cols` is the "built, never wired" shape; two would nest a grid
     * in a grid. The panel's own `--mega-cols:N` is unchanged, so anything
     * that reads it still does.
     */
    $html = mgBar();

    expect(substr_count($html, '<div class="mg-cols">'))->toBe(3)
        ->and($html)->toContain('<div class="drop mega" style="--mega-cols:9"><div class="mg-cols">')
        ->and($html)->toContain('<div class="drop mega" style="--mega-cols:3"><div class="mg-cols">')
        ->and($html)->toContain('<div class="drop mega" style="--mega-cols:2"><div class="mg-cols">')
        ->and(substr_count($html, '<div class="mcol-group">'))->toBe(14)
        // closed right before each panel's own </div>: three, plus the bar's own closing pair
        ->and(substr_count($html, '</div></div>'))->toBe(4);
});

it('moves the left threshold with its select and the pointer with its switch', function () {
    /*
     * MUTATION: read the threshold as the literal 4. Red on the first
     * expectation (Skincare's three columns are past 2). RUN.
     */
    app(HeaderSettings::class)->save(['mega_left_from' => '2']);
    expect(mgItems(mgBar())[10])->toStartWith('<div class="navitem mg-l mg-p"');

    app(HeaderSettings::class)->save(['mega_left_from' => '8', 'mega_pointer' => false]);
    $items = mgItems(mgBar());
    expect($items[4])->toStartWith('<div class="navitem mg-l"')   // nine columns, past eight
        ->and($items[10])->toStartWith('<div class="navitem mg-a"')
        ->and(implode('', $items))->not->toContain('mg-p');
});

it('ships ON at 4 columns, 130px and 11.5px, on the Navigation tab', function () {
    // He asked for it, so it ships on (CLAUDE.md, the 30-September reversal).
    $all = app(HeaderSettings::class)->all();

    expect($all['mega_fit'])->toBeTrue()
        ->and($all['mega_pointer'])->toBeTrue()
        ->and($all['mega_left_from'])->toBe('4')
        ->and($all['mega_col_min'])->toBe('130')
        ->and($all['mega_text_min'])->toBe('11.5')
        ->and(HeaderSettings::TABS['nav'][0])->toBe('Navigation')
        ->and(HeaderSettings::TABS['nav'][2])->toContain('mega_fit', 'mega_left_from', 'mega_col_min', 'mega_text_min', 'mega_pointer');
});

it('prints only integers and px from its own fixed lists into a style', function () {
    /*
     * Rule 5. HeaderSettings::cast() already refuses an option a select does
     * not have; MegaMenuFit refuses it AGAIN, against its own constant, so the
     * style attribute cannot carry a stored string even if the cast moved.
     *
     * MUTATION: print `$settings['mega_col_min']` straight into the style.
     * Red on the first expectation. RUN.
     */
    $fit = MegaMenuFit::from(['mega_fit' => true, 'mega_left_from' => '99', 'mega_col_min' => '130px;color:red', 'mega_text_min' => '<b>', 'mega_pointer' => true]);
    $item = MegaMenuShape::items()[4];

    expect($fit->itemStyle($item))->toBe('--mega-cols:9;--mg-min:130px;--mg-fmin:11.5px')
        ->and($fit->itemClass($item))->toBe(' mg-l mg-p');

    foreach (MegaMenuFit::TEXT_MIN as $key => $px) {
        expect(HeaderSettings::SCHEMA['mega_text_min'][4])->toHaveKey($key)
            ->and($px)->toMatch('/^\d+(\.5)?px$/');
    }

    expect(array_map('intval', array_keys(HeaderSettings::SCHEMA['mega_col_min'][4])))->toBe(MegaMenuFit::COL_MIN)
        ->and(array_map('intval', array_keys(HeaderSettings::SCHEMA['mega_left_from'][4])))->toBe(MegaMenuFit::LEFT_FROM)
        ->and(MegaMenuFit::from(['mega_fit' => false]))->toBeNull();
});

it('reads the header settings once, for both fits', function () {
    /*
     * The bar already read HeaderSettings::all() for "Fit the menu to the
     * row". This lane reuses that array; a second all() here would be a second
     * settings read on every shop page (CLAUDE.md, "Speed is frozen").
     */
    $bar = (string) file_get_contents(resource_path('views/partials/nav-bar.blade.php'));

    expect(substr_count($bar, 'HeaderSettings::class)->all()'))->toBe(1)
        ->and(substr_count($bar, 'MegaMenuFit::from($kbbHeader)'))->toBe(1)
        ->and(substr_count($bar, 'NavRowFit::style($kbbNav, $kbbMega, $kbbHeader)'))->toBe(1);
});

/* ═══════════════════════ 3. the stylesheet ═══════════════════════ */

it('anchors a wide panel to the site content edge, not to its parent', function () {
    /*
     * THE FIX ITSELF. Before it, `.drop`/`.mega` were `inset-inline-start:0`
     * from their own `.navitem{position:relative}` — so a panel began wherever
     * its parent did. Now the item is static, the row is the containing block,
     * and the panel's start is the row's content edge.
     *
     * MUTATION: delete `position:static` from this rule and from the
     * @supports one. Red here on the first expectation. In Chromium the items
     * are containing blocks again and every panel is measured against its
     * parent: at 1024 Skincare's ran to x 1086 against a content edge of 1002
     * (tools/mg-shots.cjs, inside:false). RUN.
     */
    $css = mgCss();

    expect($css)->toContain('.mbar .wrap > .navitem:is(.mg-l,.mg-a){position:static}')
        ->and($css)->toContain('.mbar .wrap:has(> .navitem:is(.mg-l,.mg-a,.mg-s)){position:relative}')
        ->and($css)->toContain('.mbar .wrap > .navitem.mg-l > .drop{inset-inline:var(--site-gutter);width:auto}')
        ->and($css)->toContain('.mbar .wrap > .navitem:is(.mg-l,.mg-a) > .drop{top:auto;margin-block-start:-1px}');
});

it('clamps every anchored panel between the content edges', function () {
    /*
     * With anchor positioning the panel starts at its parent but is clamped
     * between the content edges, from ONE pair of insets the hover bridges
     * share. Each is written for the side it lands on: anchor(start) in an
     * inline-START inset is not the same length as in an inline-END one.
     *
     * MUTATION: replace the clamp in --mg-s with plain `anchor(start)`. Red
     * here; in Chromium the two-column "Sunscreens" panel, third from the end,
     * runs from x 980 to 1420 at 1280 against a content edge of 1258 — the
     * same as with the switch off. RUN.
     */
    $css = mgCss();

    expect($css)->toContain('@supports (anchor-name: --a){')
        ->and($css)->toContain('--mg-s:clamp(var(--site-gutter), anchor(start), calc(100% - var(--site-gutter) - var(--mg-w)))')
        ->and($css)->toContain('--mg-e:max(var(--site-gutter), min(calc(100% - var(--site-gutter) - 220px * var(--mega-cols)), anchor(end)))')
        ->and($css)->toContain('inset-inline-start:var(--mg-s);inset-inline-end:var(--mg-e)}')
        ->and(substr_count($css, '--mg-s:clamp(var(--site-gutter), calc(anchor(end) - var(--mg-w)), calc(100% - var(--site-gutter) - var(--mg-w)))'))->toBe(2);

    // --mg-s only ever lands in an inline-START inset and --mg-e in an END one.
    preg_match_all('/([a-z-]+):var\(--mg-(s|e)\)/', $css, $m, PREG_SET_ORDER);
    expect($m)->not->toBeEmpty();
    foreach ($m as [, $prop, $side]) {
        expect($prop)->toBe($side === 's' ? 'inset-inline-start' : 'inset-inline-end');
    }
});

it('squeezes the columns and their text, and wraps rather than go below the floor', function () {
    /*
     * `columns: N min` — the browser uses fewer, longer columns once N will
     * not fit at the floor. The text follows the column width the panel
     * would give all N: 13px from 180px, the owner's floor below. Today's
     * 2/3/4-column panels have 181-183px columns, so they stay at 13px.
     *
     * MUTATION: drop `var(--mg-min, 130px)` from `columns`. Red here; in
     * Chromium the brand panel at 1024 draws nine 72px columns. RUN.
     */
    $css = mgCss();

    expect($css)->toContain('.mega > .mg-cols{flex:1;min-width:0;columns:var(--mega-cols) var(--mg-min, 130px);column-gap:36px;')
        ->and($css)->toContain('.mega .mg-cols .mcol a, .mega .mg-cols .mcol-link{font-size:clamp(var(--mg-fmin, 11.5px), calc((100cqi - (var(--mega-cols) - 1) * 36px) / var(--mega-cols) * .07222), 13px)}')
        ->and($css)->toContain('.mega > .mg-cols:has(.mcol){display:grid;grid-template-columns:repeat(auto-fill,minmax(max(var(--mg-min, 130px),');
});

it('draws the pointer from logical, symmetric parts and only while open', function () {
    $css = mgCss();

    expect($css)->toMatch('/\.mbar \.navitem\.mg-p > \.navlink::before\{content:"";display:none;position:absolute;z-index:71;inset-inline:0;[^}]*opacity:0;/')
        ->and($css)->toContain('.mbar .navitem.mg-p:hover > .navlink::before{opacity:1;translate:0 0}');
});

it('cannot reach the bar with the switch off: every rule needs a class only ON prints', function () {
    /*
     * The byte-identical guarantee is the markup's; this is the stylesheet's
     * half. A rule of this lane that matched plain `.navitem` or `.drop`
     * would restyle the OFF bar while its HTML stayed identical.
     *
     * MUTATION: write the squeeze as `.mega .mcol-group{...}`. Red. RUN.
     */
    $css = mgCss();
    $start = strpos($css, '.mbar .wrap:has(> .navitem:is(.mg-l,.mg-a,.mg-s))');
    $end = strpos($css, '.feat{');
    expect($start)->toBeInt()->and($end)->toBeGreaterThan($start);

    preg_match_all('/([^{}@]+)\{/', substr($css, $start, $end - $start), $m);
    $selectors = array_filter(array_map('trim', $m[1]), fn ($s) => $s !== '' && ! str_starts_with($s, '(') && ! str_starts_with($s, 'media') && ! str_starts_with($s, 'supports'));

    expect($selectors)->not->toBeEmpty();
    foreach ($selectors as $selector) {
        foreach (explode(', ', $selector) as $one) {
            expect($one)->toMatch('/\.mg-(l|a|s|p|cols)\b/', "this rule reaches the bar with the switch off: {$one}");
        }
    }
});

/*
 * 2.60.417. On the shop, hovering All Brands drew TWO pink lines under it: the
 * bar's ordinary hover underline (.navlink::after, scaled to 1 on hover) and,
 * 4px below it, the pointer on the panel's edge. The owner: "the underline
 * should not show as the panel highlight is showing." Wherever the pointer is
 * displayed -- mg-l and mg-s always, mg-a under anchor positioning -- the
 * underline stays at scaleX(0) on hover.
 *
 * Mutation: delete either `transform:scaleX(0)` rule in kbb.css and this is red.
 */
it('retracts the hover underline wherever the pointer is drawn', function () {
    $css = mgCss();

    expect($css)->toContain('.mbar .navitem:is(.mg-l,.mg-s).mg-p:hover > .navlink::after{transform:scaleX(0)}')
        ->and($css)->toMatch('/@supports \(anchor-name: --a\)\{.*?\.mbar \.navitem\.mg-a\.mg-p:hover > \.navlink::after\{transform:scaleX\(0\)\}/s');
});

<?php

declare(strict_types=1);

use App\Services\HeaderSettings;
use App\Support\NavRowFit;

/**
 * Lane NV — the desktop menu row fills itself, from nine items up.
 *
 * THE OWNER, 4 October, over a screenshot of the twelve-entry desktop menu
 * (Blog · Everything Under 54 AED · … · Brands ▾ · Skincare ▾) with a wide
 * empty stretch at the end of the row: "the top desktop menu items font size
 * should auto adjust if empty area there. i mean enlarge, and if more items,
 * then reduce the font size itself ... this formula will apply only if the menu
 * item has at least 9 menu items. less than that it will display as it is."
 *
 * THE DEFECT ON THE SHOP: at 1280 the row ended 166px short of its edge, at
 * 1600 425px and at 1920 505px, all at a fixed 13px, because nav-fit.js could
 * only LOWER the scale. Measured after: 0.1px or less at every width from 1001
 * to 1920, in English and Arabic, one row, scrollWidth equal to the window
 * (tests/browser/nv-nav-fill.mjs).
 *
 * Appearance → Header → Navigation: "Fit the menu to the row" (ON), "Fit it
 * from" (9 items), "Smallest text size" (10px), "Largest text size" (18px).
 */

/** A top-level menu of $n items shaped like the owner's: one pill, two parents. */
function nvMenu(int $n): array
{
    $labels = ['Blog', 'Everything Under 54 AED', 'Beauty Devices', 'Super Sale', 'Skincare Sets', 'Hair Care',
        'Lip Care', 'Toners', 'Moisturizers', 'Sunscreens', 'Masks', 'Serums', 'Cleansers', 'Eye Care', 'Brands', 'Skincare'];
    $items = [];

    for ($i = 0; $i < $n; $i++) {
        $items[] = [
            'label' => $labels[$i % count($labels)],
            'url' => '/shop/',
            'icon' => null,
            'badge' => null,
            'highlight_color' => $i === 3 ? '#E0567B' : null,
            'visibility' => 'always',
            'new_tab' => false,
            'columns' => null,
            'children' => $i >= $n - 2
                ? [['label' => 'Child', 'url' => '/shop/', 'icon' => null, 'highlight_color' => null, 'new_tab' => false, 'children' => []]]
                : [],
        ];
    }

    return $items;
}

function nvBar(int $n): string
{
    return view('partials.nav-bar', ['kbbNav' => nvMenu($n)])->render();
}

function nvCss(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb.css')));
}

/* ═══════════════════════ 1. the threshold ═══════════════════════ */

it('leaves an eight-item menu exactly as it was and fits a nine-item one', function () {
    /*
     * "less than that it will display as it is." Eight items must print the
     * bar this partial printed before the lane: `<div class="mbar">` with no
     * class, no style and the pill's literal `padding:4px 10px`. The same
     * eight items with the switch OFF are the reference, and must be the same
     * bytes — the setting is not allowed to reach a menu below the threshold.
     *
     * MUTATION: change `$n < (int) ...` to `$n <= (int) ...` in NavRowFit.
     * Red: nine items stop fitting. Change it to `$n < 8` and eight items fit.
     * Red the other way. RUN.
     */
    $eight = nvBar(8);

    expect($eight)->toContain('<div class="mbar"><div class="wrap">')
        ->and($eight)->toContain('border-radius:8px;padding:4px 10px"')
        ->and($eight)->not->toContain('nav-fill')
        ->and($eight)->not->toContain('--nav-');

    app(HeaderSettings::class)->save(['nav_fit' => false]);
    expect(nvBar(8))->toBe($eight, 'the fit switch changed a menu below the threshold');
    app(HeaderSettings::class)->save(['nav_fit' => true]);

    $nine = nvBar(9);

    expect($nine)->toContain('<div class="mbar nav-fill" style="--nav-n:9;')
        ->and($nine)->toContain('padding:4px calc(10px * var(--nav-scale))"');
});

it('moves the threshold with its select and turns off with its switch', function () {
    /*
     * MUTATION: read the threshold as the literal 9 instead of the setting.
     * Red on the first expectation. Ignore `nav_fit`: red on the last. RUN.
     */
    app(HeaderSettings::class)->save(['nav_fit_from' => '12']);
    expect(nvBar(11))->not->toContain('nav-fill')
        ->and(nvBar(12))->toContain('nav-fill');

    app(HeaderSettings::class)->save(['nav_fit_from' => '9', 'nav_fit' => false]);
    expect(nvBar(16))->toContain('<div class="mbar"><div class="wrap">')
        ->and(nvBar(16))->not->toContain('--nav-');
});

it('ships ON at nine items, between 10px and 18px, on the Navigation tab', function () {
    // He asked for it, so it ships on (CLAUDE.md, the 30-September reversal).
    $all = app(HeaderSettings::class)->all();

    expect($all['nav_fit'])->toBeTrue()
        ->and($all['nav_fit_from'])->toBe('9')
        ->and($all['nav_fit_min'])->toBe('10')
        ->and($all['nav_fit_max'])->toBe('18')
        ->and(HeaderSettings::TABS['nav'][0])->toBe('Navigation')
        ->and(HeaderSettings::TABS['nav'][2])->toContain('nav_fit', 'nav_fit_from', 'nav_fit_min', 'nav_fit_max');
});

/* ══════════════════ 2. the clamp, and what reaches the page ══════════════════ */

it('holds the text between the smallest and largest size, whatever the script asks for', function () {
    /*
     * The font is a clamp() of the owner's two sizes around the estimate times
     * the scale nav-fit.js sets. Without the clamp a sixteen-item menu at
     * 1001px fell to 8.6px and a nine-item one at 1920 rose past 24px.
     *
     * MUTATION: replace the clamp with `calc(var(--nav-f0) * var(--nav-scale))`.
     * Red. Print the max as the min: red on the style string. RUN.
     */
    expect(nvCss())->toContain('.mbar.nav-fill .navlink{font-size:clamp(var(--nav-min), calc(var(--nav-f0) * var(--nav-scale)), var(--nav-max))}');

    app(HeaderSettings::class)->save(['nav_fit_min' => '12', 'nav_fit_max' => '15']);
    expect(nvBar(12))->toContain(';--nav-min:12px;--nav-max:15px"');

    // Every option of the smallest size is below every option of the largest,
    // so no save can invert the clamp.
    $min = array_map('intval', array_keys(HeaderSettings::SCHEMA['nav_fit_min'][4]));
    $max = array_map('intval', array_keys(HeaderSettings::SCHEMA['nav_fit_max'][4]));
    expect(max($min))->toBeLessThan(min($max));
});

it('prints only integers and px integers, and refuses a value that is not one of its options', function () {
    /*
     * Rule 5: anything printed into the page from a setting is one of its own
     * options. A forged save of `nav_fit_max` = `18px;}body{display:none` is
     * refused by the select's cast and the default is printed instead.
     *
     * MUTATION: print `$settings['nav_fit_max']` without the (int) cast and let
     * the cast accept any string. Red. RUN (the cast half via ModuleSchema).
     */
    app(HeaderSettings::class)->save(['nav_fit_max' => '18px;}body{display:none', 'nav_fit_min' => '-4']);

    $style = NavRowFit::style(nvMenu(12), true, app(HeaderSettings::class)->all());

    expect($style)->toMatch('/^(--nav-[a-z0-9]+:\d+(px)?;)+--nav-max:\d+px$/')
        ->and($style)->toContain('--nav-min:10px;--nav-max:18px');

    // A label is never printed into the style, only counted.
    $evil = nvMenu(12);
    $evil[0]['label'] = '"><script>alert(1)</script>';
    expect(NavRowFit::style($evil, true, app(HeaderSettings::class)->all()))
        ->toMatch('/^(--nav-[a-z0-9]+:\d+(px)?;)+--nav-max:\d+px$/');
});

it('counts the row the way the stylesheet subtracts it', function () {
    /*
     * --nav-np are the items padded by --nav-pad-x, --nav-hl the pills with
     * their own 10px, --nav-gx the gaps beside ▾ and badges. Each one is a
     * term in the first-paint calc(); a miscount makes the first paint wrong
     * by that term on every page.
     */
    $items = nvMenu(12);
    $items[5]['badge'] = 'NEW';

    expect(NavRowFit::style($items, true, app(HeaderSettings::class)->all()))
        ->toStartWith('--nav-n:12;--nav-np:11;--nav-hl:1;--nav-gx:3;--nav-bx:1;--nav-w:');

    // With the Mega Menu module off there is no ▾, so no gap and no width for it.
    expect(NavRowFit::style($items, false, app(HeaderSettings::class)->all()))
        ->toStartWith('--nav-n:12;--nav-np:11;--nav-hl:1;--nav-gx:1;--nav-bx:1;');

    // Arabic is counted as Arabic, not as the "other" fallback.
    expect(NavRowFit::width('العناية بالبشرة'))->toBe(14 * 50 + 25)
        ->and(NavRowFit::width('Blog'))->toBe(64 + 3 * 52);
});

/* ═══════════════════ 3. CSS that cannot move the page ═══════════════════ */

it('changes nothing vertical, shares the leftover evenly, and opens the last two panels inward', function () {
    /*
     * GridPhotoLoadingTest pins that nothing deciding .navlink's HEIGHT follows
     * --nav-scale; this lane's rule is a second .navlink rule that test does not
     * read, so the same promise is made here: it sets the font size and nothing
     * else. The line box stays 19.5px and the bar 46.5px at every size (measured
     * 46.5 at 1001–1920, CLS 0).
     *
     * Leftover room is `space-between`, not an empty block at one end. The last
     * two panels open from the item's end edge at EVERY width on a fitted bar:
     * the row now reaches its end at 1920 too, where the unfitted bar's rule
     * (MenuBarNoSidewaysScrollTest) still lets them open outward.
     *
     * MUTATION: add `line-height:calc(19.5px * var(--nav-scale))` to the fill
     * rule. Red. Move the leftward rule into the 1599px media query. Red. RUN.
     */
    $css = nvCss();

    preg_match_all('/\.mbar\.nav-fill \.navlink\{([^}]*)\}/', $css, $m);
    expect($m[1])->toBe(['font-size:clamp(var(--nav-min), calc(var(--nav-f0) * var(--nav-scale)), var(--nav-max))']);

    expect($css)->toContain('.mbar.nav-fill .wrap{justify-content:space-between;flex-wrap:nowrap}')
        ->and($css)->toContain('.mbar.nav-fill .wrap.nav-wrap{flex-wrap:wrap}')
        ->and($css)->toContain('.mbar.nav-fill .navlink .ind{font-size:.615em}');

    $leftward = '.mbar.nav-fill .navitem:nth-last-child(-n+2) > .drop{inset-inline-start:auto;inset-inline-end:0}';
    expect($css)->toContain($leftward);

    $media = substr($css, (int) strpos($css, '@media (max-width:1599.98px){'));
    $media = substr($media, 0, (int) strpos($media, '}') + 1);
    expect($media)->not->toContain('nav-fill');
});

/* ═══════════════════ 4. no new measuring ═══════════════════ */

it('measures layout only where it already did, and nav-fit.js grows no observer', function () {
    /*
     * "No JavaScript that measures layout" (rule 4). nav-fit.js is the one
     * file that measures the menu, by its own header's argument, and this lane
     * extended it rather than adding a second measurer. The storefront scripts
     * that touch a measuring API (or an observer) are exactly the five that did
     * before this lane, measured on its base commit;
     * the PHP and Blade this lane added touch none; and nav-fit.js still has no
     * ResizeObserver, MutationObserver or requestAnimationFrame loop that could
     * make it re-fit in answer to its own change.
     *
     * MUTATION: add `el.getBoundingClientRect()` to resources/js/kbb/cart.js
     * (or any other storefront script). Red. Add a ResizeObserver to
     * nav-fit.js. Red. RUN.
     */
    $apis = ['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight',
        'scrollWidth', 'scrollHeight', 'getComputedStyle', 'ResizeObserver', 'IntersectionObserver'];
    $measuring = [];

    foreach (glob(resource_path('js/kbb/*.js')) ?: [] as $file) {
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents($file));

        foreach ($apis as $api) {
            if (str_contains($code, $api)) {
                $measuring[] = basename($file);
                break;
            }
        }
    }

    sort($measuring);
    expect($measuring)->toBe(['fbt.js', 'listing-load.js', 'nav-fit.js', 'pdp.js', 'ymal.js']);

    foreach ([app_path('Support/NavRowFit.php'), resource_path('views/partials/nav-bar.blade.php')] as $file) {
        foreach ($apis as $api) {
            expect(str_contains((string) file_get_contents($file), $api))->toBeFalse(basename($file).' names '.$api);
        }
    }

    $nav = (string) file_get_contents(resource_path('js/kbb/nav-fit.js'));
    foreach (['ResizeObserver', 'MutationObserver', 'requestAnimationFrame', 'setInterval'] as $loop) {
        expect(str_contains($nav, $loop))->toBeFalse('nav-fit.js uses '.$loop);
    }
    /*
     * And the one-row-until-measured rule has its way back: a fitted bar that
     * cannot fit at the smallest size wraps instead of clipping an item.
     * MUTATION: delete the toggle. Red here, and a 16-item menu at 1001px
     * loses Skincare off the end of the bar instead of wrapping it. RUN.
     */
    expect($nav)->toContain("wrap.classList.toggle('nav-wrap', needed > available + 0.5);");

    /*
     * THE DEFECT A SINGLE CORRECTION LEFT: once the font is held at the
     * smallest size, only the padding still follows the scale, so one
     * proportional step undershoots. A sixteen-item menu at 1001px wrapped to
     * two rows at 10px (bar 94px) where the old shrink-only bar fitted it on
     * one. The corrections are a bounded secant: at most three passes.
     * MUTATION: set the bound to `pass < 1`. The source assertion is red; in
     * the browser 16 items at 1001 go back to two rows
     * (tests/browser/nv-nav-fill.mjs, rowsUsed 2). RUN.
     */
    expect($nav)->toContain('for(let pass = 0; pass < 3 && needed > available && scale > FLOOR; pass++){');
    expect($nav)->toContain("if(mbar.classList.contains('nav-fill')){")
        ->and($nav)->toContain('const CEILING = 2;');
});

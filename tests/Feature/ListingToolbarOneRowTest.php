<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;

/**
 * FILTERS AND SORT ON ONE ROW, ON EVERY PHONE.                    (Lane PI-B)
 *
 * The owner's ~375px phone: "Filters" and "45 products" on one line, "Sort
 * [Featured ▾]" wrapped onto a second. Measured in Chromium before the fix:
 * two rows at 320, 360, 375 and 390 (the row needed 379px of content box);
 * after: one row at 320, 360, 375, 390 and 430 — the Filters button, the count
 * and the select share one vertical centre, the selected option's text fits
 * the select at every width ("Price: high to low" at 320 included),
 * scrollWidth equals the viewport, and the two controls stay 44px tall.
 * Desktop (1280) measured identical before and after: Show filters 126.3px,
 * count 71.1px, select 142px.
 *
 * Geometry is a browser's to prove, and the screenshots carry it. What a test
 * can hold is the mechanism, so each case below pins one piece of it.
 */

function ltrShopHtml(): string
{
    $category = Category::create(['name' => 'Skincare sets', 'slug' => 'ltr-sets']);
    $p = Product::create([
        'slug' => 'ltr-one', 'name' => 'Toolbar Product', 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock', 'category_id' => $category->id,
    ]);
    $p->categories()->syncWithoutDetaching([$category->id]);

    return test()->get('/'.ltrim((string) parse_url($category->url(), PHP_URL_PATH), '/'))->assertOk()->getContent();
}

/** One @media block of kbb-shop.css by its exact query, comments stripped. */
function ltrMedia(string $query): string
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb-shop.css')));
    $out = '';
    $offset = 0;

    while (($at = strpos($css, '@media '.$query.' {', $offset)) !== false) {
        $depth = 0;
        for ($i = strpos($css, '{', $at); $i < strlen($css); $i++) {
            $depth += $css[$i] === '{' ? 1 : ($css[$i] === '}' ? -1 : 0);
            if ($depth === 0) {
                break;
            }
        }
        $out .= substr($css, $at, $i - $at + 1);
        $offset = $i;
    }

    return $out;
}

it('makes "Sort" the select\'s label, so hiding the word cannot take its name away', function () {
    /*
     * It was a bare text node in a <div>: the select had NO accessible name,
     * and hiding the word on a phone would have left nothing to replace it.
     *
     * MUTATION, RUN: put `<div class="sortsel">` back in store/shop.blade.php
     * and this is red.
     */
    $html = ltrShopHtml();

    expect((bool) preg_match('#<label class="sortsel" for="sort"><span class="sortlbl">Sort</span>\s*<select id="sort"#', $html))
        ->toBeTrue('the word "Sort" is not the <label> of #sort');
});

it('hides the word visually only, under 411px, and never with display:none', function () {
    /*
     * display:none or visibility:hidden would take the label out of the
     * accessibility tree — the select would be nameless again on exactly the
     * phones that hide it. The clip pattern keeps it read.
     *
     * MUTATION, RUN: write `.sortlbl{display:none}` in the 410px block and this
     * is red.
     */
    $block = ltrMedia('(max-width: 410px)');

    expect($block)->toContain('.sortlbl{position:absolute;width:1px;height:1px')
        ->and($block)->toContain('clip-path:inset(50%)')
        ->and($block)->not->toMatch('/\.sortlbl\{[^}]*display:\s*none/')
        ->and($block)->not->toMatch('/\.sortlbl\{[^}]*visibility:\s*hidden/');
});

it('keeps the row from wrapping on phones, and lets each piece shrink with clamp()', function () {
    /*
     * MUTATION, RUN: drop `flex-wrap:nowrap` from the 900px block and Chromium
     * puts Sort back on a second row at 320–390; this is red.
     */
    $block = ltrMedia('(max-width: 900px)');

    expect($block)->toContain('.gtop{flex-wrap:nowrap;gap:clamp(')
        ->and($block)->toContain('.mobi-filter{flex:none;padding-inline:clamp(')
        ->and($block)->toContain('.gcount{flex:0 1 auto;min-width:0;font-size:clamp(')
        ->and($block)->toContain('.sortsel select{min-width:0;max-width:100%');
});

it('never shrinks the sort font below 16px on a phone, where iOS would zoom the page', function () {
    /*
     * The select is the one thing that could have been made to fit by a
     * smaller font, and that is the one thing that must not move: under 16px
     * iOS zooms the whole page when the select is tapped.
     *
     * MUTATION, RUN: add `font-size:14px` to `.sortsel select` in the 900px
     * block and this is red.
     */
    expect(ltrMedia('(max-width: 820px)'))->toContain('.sortsel select{font-size:16px}');

    foreach (['(max-width: 900px)', '(max-width: 410px)', '(max-width: 359px)'] as $q) {
        expect(ltrMedia($q))->not->toMatch('/\.sortsel select\{[^}]*font-size/');
    }
});

it('drops the word "Filters" only for the two long price sorts under 360px, from the checked option', function () {
    /*
     * At 320 with "Price: high to low" chosen there is not room for the word
     * "Filters" and the select's text; with "Featured" there is. :has() reads
     * the CHECKED option, so the word goes only when it must. font-size:0
     * hides the glyphs, not the text, so the button keeps its name.
     */
    $block = ltrMedia('(max-width: 359px)');

    expect($block)->toContain('.gtop:has(#sort option:checked:is([value="plow"],[value="phigh"])) .mobi-filter{font-size:0;gap:0}');
});

it('changes nothing above 900px, and ships what it says', function () {
    /*
     * Every new rule sits inside a phone query. The built stylesheet the
     * manifest serves carries them, so the screenshots are of this code.
     */
    $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb-shop.css')));
    $tail = substr($css, (int) strpos($css, '@media (max-width: 900px) {
  .gtop{flex-wrap:nowrap'));

    expect(preg_match_all('/@media \(max-width: (\d+)px\)/', $tail, $m))->toBe(3)
        ->and(max(array_map('intval', $m[1])))->toBe(900);

    $manifest = json_decode((string) file_get_contents(base_path('public/build/manifest.json')), true);
    $built = (string) file_get_contents(base_path('public/build/'.$manifest['resources/css/kbb/kbb-shop.css']['file']));

    expect($built)->toContain('.sortlbl')->toContain('flex-wrap:nowrap');
});

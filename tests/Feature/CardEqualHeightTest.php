<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductStyles;
use App\Services\SettingsService;

/**
 * =============================================================================
 * THE CARD HE ASKED FOR: NAME, RATING, PRICE, BUTTON — AND ONE HEIGHT
 *                                                                    Lane CARD
 * =============================================================================
 *
 * ── THE OWNER, VERBATIM ─────────────────────────────────────────────────────
 *
 * "i like this option, but i want to hide the brand name, category name by
 *  default. only name, rating (if any), pricing and cart buttons also make sure
 *  the grid must remain same heighted overall even if the name of the product is
 *  long. i need all equal height in desktop and mobile both. apply this
 *  everywhere."
 *
 * ── THE FOUR DEFECTS PINNED HERE, EACH AS IT LOOKED ON THE SHOP ─────────────
 *
 *  1. "EQUAL HEIGHT" WAS EQUAL BY ACCIDENT. `.kbb-pgrid … .kbb-card{height:100%}`
 *     equalises a card against THE OTHER CARDS IN ITS OWN ROW and says nothing
 *     about the row above it, and `.cp{margin-top:auto}` lines the buttons up
 *     inside a row for the same reason. The rating row is drawn only for a
 *     product that has reviews, so a grid row whose every product is unreviewed
 *     is shorter than the rows around it. Measured in Chromium on the shipped
 *     card, twelve products, showcase skin:
 *
 *         1280, 5 columns    468.91 / 442.91 / 468.89
 *         390, 2 columns     421.84 421.84 421.84 395.84 395.84 421.84
 *         320, 2 columns     five different heights, and seven on /shop
 *
 *     26px is the gap, and it is the rating row's 18px line plus its 8px margin
 *     exactly. Lane PG2 measured twelve identical numbers off a fixture whose
 *     every row happened to contain a reviewed product — see tools/card-seed.php
 *     for that arithmetic — which is why this file's fixture is built to put
 *     five unreviewed products on one row.
 *
 *  2. AND TWO MORE ROWS THAT WRAP AT 320. The brand line ("BEAUTY OF JOSEON" is
 *     two lines in a 104px text column, "MEDICUBE" is one) and a marked-down
 *     price ("AED 260  AED 208" wrapped, so `.cp` was 59px against 32.25px on
 *     the tile beside it). Both are card height that one product has and its
 *     neighbour does not.
 *
 *  3. THE BRAND AND CATEGORY SWITCHES REACHED THREE PAGES OF THE SHOP. The
 *     seven `.pc-no*` rules live ONLY in resources/css/kbb/kbb-grid-skins.css,
 *     and only home, the wishlist and a collection `@vite` that sheet: /shop,
 *     every category archive, every brand page and the related rail are styled
 *     from the shorter second copy inside kbb.css, which stops before them. So
 *     "Brand name: off" hid the brand on the homepage and left it on /shop.
 *     Hiding by default through CSS alone would have shipped exactly the
 *     "setting that will be reported as broken" this work was warned about.
 *
 *  4. A FIFTH PRODUCT GRID NOBODY WAS COUNTING.
 *     resources/views/partials/home/grid-section.blade.php opens a `.kbb-pgrid`
 *     through `class="{{ $gsClass }}"`, so DefaultCardStyleTest's scanner —
 *     which matches `class="[^"]*\bkbb-pgrid\b` — cannot see it. It is also the
 *     only grid that wraps each tile in a `.gs-cell`, so `height:100%` resolves
 *     against a different box there. "Apply this everywhere" is the sentence
 *     that makes an uncounted grid a defect rather than a curiosity.
 *
 * ── WHERE THE TWO CONTROLS ARE ──────────────────────────────────────────────
 *
 * Appearance → Product styles → Card content → "Brand name" and "Category
 * label". They are not new; what moved is their shipped value and the place the
 * decision is taken (components/product-card.blade.php, not a stylesheet).
 *
 * ── MUTATION NOTES, ALL RUN ─────────────────────────────────────────────────
 *
 * Named case by case below.
 */

/* ───────────────────────────────── fixtures ──────────────────────────────── */

function cehCss(string $file): string
{
    return (string) file_get_contents(base_path('resources/css/kbb/'.$file));
}

/** The showcase family block: its own first rule to the end of the sheet. */
function cehFamily(string $file): string
{
    $css = cehCss($file);
    $at = strpos($css, '.kbb-pgrid[data-skin^="showcase"]{');

    return $at === false ? '' : substr($css, $at);
}

/** The family with its comments stripped, so prose cannot satisfy a needle. */
function cehFamilyRules(string $file): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', cehFamily($file));
}

function cehProduct(string $slug, string $name, array $extra = []): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'ceh-house'], ['name' => 'Ceh Beauty House']);
    $category = Category::firstOrCreate(
        ['slug' => 'ceh-care'],
        ['name' => 'Skincare Sets and Barrier Repair Routines']
    );

    $product = Product::create(array_merge([
        'slug' => $slug,
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'brand_id' => $brand->id,
        'price' => 5000,
        'stock_status' => 'instock',
        'type' => 'simple',
        'rating' => 0.0,
        'review_count' => 0,
    ], $extra));

    $product->categories()->syncWithoutDetaching([$category->id]);

    return $product;
}

function cehGet(string $uri): string
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    return (string) test()->get($uri)->assertOk()->getContent();
}

/**
 * How many times a page carries an element with exactly this class.
 *
 * ── WHY A CLASS ATTRIBUTE AND NOT THE WORDS ─────────────────────────────────
 *
 * This repository has learned repeatedly that an assertion can be true for a
 * reason unrelated to what it claims: a product NAME asserted against a whole
 * page is satisfied by the cart drawer, by a `data-name` attribute or by
 * JSON-LD, and tools/plc-needle-scan.sh exists to measure exactly that. A brand
 * name is worse — "Ceh Beauty House" appears in the product page's own heading,
 * in its breadcrumb and in its structured data, so `not->toContain($brand)`
 * would be red on a page that draws no brand LINE at all.
 *
 * So the needle is the markup: `class="kbb-card-brand"`. Nothing but the tile
 * writes it, and it is the element whose absence is the thing being claimed.
 */
function cehCount(string $html, string $class): int
{
    return preg_match_all('/class="'.preg_quote($class, '/').'"/', $html);
}

/** The storefront pages that draw a product tile through a grid. */
function cehGridPages(): array
{
    return [
        '/shop/' => 'the shop listing',
        '/collections/ceh-care/' => 'a category archive',
        '/brands/ceh-house/' => 'a brand page',
        '/shop/?s=Toner' => 'a search',
        '/product/ceh-toner/' => "the product page's related rail",
    ];
}

/* ═════════ 1 · the two defaults, and the sentence that moved them ═════════ */

it('ships the brand line and the category eyebrow hidden, and keeps both switches', function () {
    /*
     * CLAUDE.md rule 1, as of 30 September: what the owner asked for ships ON
     * rather than waiting behind a switch he has to find, and the control is
     * built anyway because a change with no way back is worse than no change.
     * This is both halves of that in one case.
     *
     * MUTATION: put either default back to `true` and this is red naming the
     * key; on the shop the eyebrow and the BRAND line come back on every tile
     * of every grid. RUN, both.
     */
    expect(ProductStyles::SCHEMA['show_brand'][2])
        ->toBeFalse('Brand name no longer ships off, so the card draws a line the owner asked to hide')
        ->and(ProductStyles::SCHEMA['show_category'][2])
        ->toBeFalse('Category label no longer ships off, so the card draws an eyebrow the owner asked to hide');

    // The five he did not ask about are untouched, which is the half of rule 1
    // that did NOT change.
    foreach (['show_rating', 'show_was_price', 'show_discount', 'show_new', 'show_cart'] as $key) {
        expect(ProductStyles::SCHEMA[$key][2])
            ->toBeTrue($key.' moved, and the owner asked about the brand and the category only');
    }

    // Both are still controls, on the screen, in the tab they were always in.
    expect(ProductStyles::TABS['content'][2])
        ->toContain('show_brand')
        ->toContain('show_category');

    // And with no stored row — which is what a shop applying the package has —
    // that is what the service answers and what <body> says.
    expect(app(ProductStyles::class)->all()['show_brand'])->toBeFalse()
        ->and(app(ProductStyles::class)->all()['show_category'])->toBeFalse()
        ->and(app(ProductStyles::class)->bodyClass())->toBe('pc-nobrand pc-nocat');
});

/* ═════ 2 · and the card really stops drawing them, on every page ═════════ */

it('draws no brand line and no category eyebrow on any page with a grid', function () {
    /*
     * THE DEFECT THIS WOULD HAVE BEEN. Hiding the two with the `.pc-no*` body
     * classes alone hides them on the homepage, the wishlist and a collection —
     * the three pages that @vite kbb-grid-skins.css — and leaves them drawn on
     * /shop, every category archive, every brand page, the search results and
     * the related rail, because kbb.css's copy of the skins stops before those
     * seven rules. Measured: `grep -c pc-nobrand resources/css/kbb/kbb.css` is
     * 0 and `grep -c` on kbb-grid-skins.css is 1.
     *
     * THE TILE COUNT IS ASSERTED TOO, and that is not padding: "no brand line
     * on this page" is also true of a page that drew no cards at all, which is
     * what a 500 in the grid or a fixture that produced nothing looks like.
     *
     * MUTATION: drop `&& $kbbShows['show_brand']` from the card's `.cn` link
     * and this is red on all five pages with the count it found. RUN — five
     * pages, 1 brand line each except the brand page (7) and /shop (7).
     */
    cehProduct('ceh-toner', 'Toner');
    cehProduct('ceh-night-cream', 'Ultra Hydrating Ceramide Barrier Repair Night Cream With Panthenol and Squalane 100ml', [
        'rating' => 4.4, 'review_count' => 96,
    ]);

    foreach (cehGridPages() as $uri => $what) {
        $html = cehGet($uri);

        expect(cehCount($html, 'kbb-card-nm'))
            ->toBeGreaterThan(0, $what.' ('.$uri.') drew no product tile at all, so this case is blind there');

        expect(cehCount($html, 'kbb-card-brand'))
            ->toBe(0, $what.' ('.$uri.') still draws the brand line the owner asked to hide');

        expect(cehCount($html, 'kbb-card-cat'))
            ->toBe(0, $what.' ('.$uri.') still draws the category eyebrow the owner asked to hide');
    }
});

it('brings both back the moment the owner switches them on', function () {
    /*
     * WITHOUT THIS THE CASE ABOVE IS SATISFIED BY DELETING THE FEATURE. He may
     * want the brand line back — that is why this is two settings and not two
     * deletions — so the proof has to run in both directions.
     *
     * The eyebrow needs a page that HAS one: /shop passes `catLabel` null on
     * purpose (reading `$product->categories` in the tile would put one more
     * query on four pages), so the category archive is where it is asserted.
     *
     * MUTATION: delete the `@if` around `<span class="kbb-card-brand">`
     * entirely and the previous case is red; delete the whole span and THIS one
     * is red. Neither passes both. RUN.
     */
    cehProduct('ceh-toner', 'Toner');

    $settings = app(SettingsService::class);
    $settings->set('show_brand', true);
    $settings->set('show_category', true);

    $archive = cehGet('/collections/ceh-care/');

    expect(cehCount($archive, 'kbb-card-brand'))
        ->toBeGreaterThan(0, 'Brand name: on draws no brand line, so the control does nothing');
    expect(cehCount($archive, 'kbb-card-cat'))
        ->toBeGreaterThan(0, 'Category label: on draws no eyebrow, so the control does nothing');

    // And the shop listing gets the brand back as well — which is the page the
    // CSS switch could never reach.
    expect(cehCount(cehGet('/shop/'), 'kbb-card-brand'))
        ->toBeGreaterThan(0, 'Brand name: on does not reach /shop, which is the page the CSS switch missed');

    /*
     * AND IT IS STILL PRINTED UPPER-CASED, which is coverage bought back rather
     * than a new claim. EnglishRenderWalk::approvedRemovals()' two rules for
     * this change use `[^<]*` where the words go, so that walk no longer sees
     * how either line is printed; the tile writes `mb_strtoupper($brand)` and
     * this is the one place left that says so.
     *
     * MUTATION: drop the mb_strtoupper() and this is red. RUN.
     */
    expect($archive)->toContain('<span class="kbb-card-brand">CEH BEAUTY HOUSE</span>');
    expect($archive)->toContain('<div class="kbb-card-cat">Skincare Sets and Barrier Repair Routines</div>');
});

it('gates the rating row on the same screen, and still draws nothing for an unreviewed product', function () {
    /*
     * "rating (if any)" — so an unreviewed product draws no row, which this
     * shop has done since the round that built the tile, and a reviewed one
     * draws it. `show_rating` joined the two above in the card's PHP rather
     * than staying a CSS-only switch, because the stylesheet now RESERVES a
     * track for this row: a row hidden by CSS while its track was still
     * reserved would be a permanent empty band on /shop, where `.pc-norate`
     * does not reach.
     *
     * MUTATION: drop `&& $kbbShows['show_rating']` and the middle expectation
     * is red — "Stars and review count: off" then leaves the stars on the shop.
     * RUN.
     */
    cehProduct('ceh-toner', 'Toner');
    cehProduct('ceh-reviewed', 'Barrier Repair Cream', ['rating' => 4.9, 'review_count' => 1580]);

    expect(cehCount(cehGet('/shop/'), 'kbb-card-rate'))
        ->toBe(1, 'one of these two products has reviews and one has none, so the shop should draw exactly one rating row');

    app(SettingsService::class)->set('show_rating', false);

    expect(cehCount(cehGet('/shop/'), 'kbb-card-rate'))
        ->toBe(0, 'Stars and review count: off still draws the rating row');
});

it('clears the stored rows that would hide the new default from his own shop', function () {
    /*
     * THE DEFECT THIS EXISTS FOR, and it is the one that would have made the
     * whole change invisible on the live shop. ProductStyles::all() falls back
     * to the schema default ONLY when the row is absent, and Appearance →
     * Product styles posts its whole tab on Save — so a shop where that screen
     * has ever been saved carries `show_brand = 1` and `show_category = 1`, and
     * the moved default would never be seen. Applying the package would change
     * nothing, which is precisely what the owner asked against.
     *
     * The migration is RUN, not re-implemented: a test that repeated the delete
     * would pass against a migration that does not do it.
     *
     * MUTATION: empty the migration's DEFAULT_MOVED array and this is red with
     * the brand line still on the page. RUN.
     */
    cehProduct('ceh-toner', 'Toner');

    $settings = app(SettingsService::class);
    $settings->set('show_brand', true);
    $settings->set('show_category', true);

    expect(cehCount(cehGet('/collections/ceh-care/'), 'kbb-card-brand'))
        ->toBeGreaterThan(0, 'the stored rows changed nothing, so this proves nothing');

    $migration = require base_path('database/migrations/2027_06_05_000000_clear_caches_card_lines_off.php');
    $migration->up();

    SettingsService::forgetMemo();

    $html = cehGet('/collections/ceh-care/');

    expect(cehCount($html, 'kbb-card-nm'))->toBeGreaterThan(0, 'the page drew no tile, so this case is blind');
    expect(cehCount($html, 'kbb-card-brand'))->toBe(0, 'the migration left the stored brand row behind');
    expect(cehCount($html, 'kbb-card-cat'))->toBe(0, 'the migration left the stored category row behind');

    // And it does NOT touch the third key, which did not change value: a shop
    // that has switched the stars off chose that.
    $settings->set('show_rating', false);
    $migration->up();
    SettingsService::forgetMemo();

    expect(app(ProductStyles::class)->all()['show_rating'])
        ->toBeFalse('the migration cleared show_rating, which nobody asked it to move');
});

/* ═════════ 3 · the height is reserved, and it is reserved in CSS ═════════ */

it('reserves a track for every row of the card that a product may not have', function () {
    /*
     * THE MECHANISM, read out of the stylesheet. `.cb` is a grid with six
     * tracks: the eyebrow, the brand-plus-name, the rating, a `1fr` spacer, the
     * price and the button. A missing element leaves its track EMPTY AND STILL
     * SIZED, which is what makes a card with a rating row and a card without
     * one the same height — and is why nothing had to be added to the markup.
     *
     * MUTATION: take `var(--sc-rate-slot)` out of `.cb`'s grid-template-rows
     * and this is red; on the shop, rows of unreviewed products go back to
     * being 26px shorter than the rows around them. RUN, and re-measured: 1280
     * came back 468.91 / 442.91 / 468.89 again.
     */
    foreach (['kbb-grid-skins.css', 'kbb.css'] as $file) {
        $rules = cehFamilyRules($file);

        expect($rules)->not->toBe('', $file.' carries no showcase family block, so this case is blind');

        // The four reservations exist and are derived from the row's own numbers.
        foreach ([
            '--sc-cat-slot' => ['--sc-cat-fs', '--sc-cat-lh', '--sc-cat-mb'],
            '--sc-brand-slot' => ['--sc-brand-fs', '--sc-brand-lh', '--sc-brand-mb'],
            '--sc-name-slot' => ['--sc-name', '--sc-name-lh', '--sc-name-lines'],
            '--sc-rate-slot' => ['--sc-rate-fs', '--sc-rate-lh', '--sc-rate-mt'],
        ] as $slot => $terms) {
            expect(preg_match('/'.preg_quote($slot, '/').':\s*calc\(([^;]+)\)/', $rules, $m))
                ->toBe(1, $file.': '.$slot.' is not a calc() over the row\'s own numbers');

            foreach ($terms as $term) {
                expect(str_contains($m[1], $term))
                    ->toBeTrue($file.': '.$slot.' does not read '.$term.', so the reservation can drift from the row it reserves');
            }
        }

        // And `.cb` spends all four of them, in one grid-template-rows.
        expect(preg_match(
            '/\.kbb-pgrid\[data-skin\^="showcase"\] \.cb\{[^}]*grid-template-rows:\s*'
            .'\s*var\(--sc-cat-slot\)'
            .'\s*calc\(var\(--sc-brand-slot\) \+ var\(--sc-name-slot\)\)'
            .'\s*var\(--sc-rate-slot\)'
            .'\s*1fr/s',
            $rules
        ))->toBe(1, $file.': `.cb` no longer reserves the four rows in order — the card height goes back to depending on what each product happens to have');

        /*
         * AND A ROW THE OWNER HAS SWITCHED OFF RESERVES NOTHING. Without these
         * three, turning "Stars and review count" off leaves a 26px empty band
         * on every card — the row gone and its track still paid for.
         *
         * MUTATION: delete the `.pc-norate` line and the band is back. RUN;
         * measured 26px of white space under every name on /shop.
         */
        foreach ([
            'pc-nocat' => '--sc-cat-slot',
            'pc-nobrand' => '--sc-brand-slot',
            'pc-norate' => '--sc-rate-slot',
        ] as $class => $slot) {
            expect(preg_match(
                '/\.'.$class.' \.kbb-pgrid\[data-skin\^="showcase"\]\{'.preg_quote($slot, '/').':0px\}/',
                $rules
            ))->toBe(1, $file.': .'.$class.' does not zero '.$slot.', so a switched-off row still reserves its height');
        }
    }

    // The two copies of the family are byte-identical, which is what makes
    // everything above true on both halves of the shop. DefaultCardStyleTest
    // says the same thing; it is repeated because every claim in this case
    // depends on it.
    expect(cehFamily('kbb.css'))->toBe(cehFamily('kbb-grid-skins.css'));
});

it('lets the price take one line only, and truncates the struck original before the sale price', function () {
    /*
     * MEASURED AT 320px on the shop's own demo catalogue: `.cp` was 59px on a
     * marked-down tile and 32.25px on the tile beside it, because
     * `flex-wrap:wrap` let "AED 260  AED 208" break onto two lines in a 104px
     * text column. That is 26.75px of card height one product has and its
     * neighbour does not.
     *
     * The shortfall falls on `.kbb-card-reg` — flex-shrink 99 against 1 — so
     * the figure that ellipsises is the one that is crossed out. A struck price
     * losing its last character is a readable card; the price the shopper pays
     * losing one is a lie.
     *
     * MUTATION: put `flex-wrap:wrap` back and this is red; re-measured at 320,
     * /shop went from one card height to three. RUN.
     */
    foreach (['kbb-grid-skins.css', 'kbb.css'] as $file) {
        $rules = cehFamilyRules($file);

        expect(preg_match('/\.kbb-pgrid\[data-skin\^="showcase"\] \.cp\{[^}]*flex-wrap:nowrap/s', $rules))
            ->toBe(1, $file.': the price row may wrap again, and a second line there is card height its neighbour does not have');

        expect(preg_match('/\.kbb-pgrid\[data-skin\^="showcase"\] \.kbb-card-reg\{[^}]*flex:0 99 auto/s', $rules))
            ->toBe(1, $file.': the struck original no longer absorbs the shortfall, so the sale price is what gets truncated');

        expect(preg_match('/\.kbb-pgrid\[data-skin\^="showcase"\] \.kbb-card-price\{[^}]*flex:0 1 auto/s', $rules))
            ->toBe(1, $file.': the sale price no longer shrinks last');
    }
});

it('states the name clamp once, so the box and its reserved track cannot drift', function () {
    /*
     * `-webkit-line-clamp:2` and `height:calc(2 * 1.32em)` were written into
     * `.kbb-tile .kbb-card-nm`, and the family reserves a track out of the same
     * two numbers. Two places holding one fact is how they drift: a lane
     * clamping to three lines would get a name overflowing a track still
     * reserved for two, and nothing would say so.
     *
     * MUTATION: put the literal `2` back in the clamp and this is red. RUN.
     */
    $css = cehCss('kbb.css');

    expect(preg_match(
        '/\.kbb-tile \.kbb-card-nm\{[^}]*-webkit-line-clamp:var\(--sc-name-lines,\s*2\)[^}]*'
        .'line-height:var\(--sc-name-lh,\s*1\.32\)[^}]*'
        .'height:calc\(var\(--sc-name-lines,\s*2\) \* var\(--sc-name-lh,\s*1\.32\) \* 1em\)/s',
        $css
    ))->toBe(1, 'the name box no longer reads the family\'s own two numbers, so the clamp and the reserved track can disagree');

    // The fallbacks are what keep the other 31 skins byte-identical: none of
    // them declares --sc-name-lines or --sc-name-lh.
    $rules = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    $before = substr($rules, 0, (int) strpos($rules, '.kbb-pgrid[data-skin^="showcase"]{'));

    expect(str_contains($before, '--sc-name-lines:'))
        ->toBeFalse('a rule outside the showcase family declares --sc-name-lines, so it now reaches skins that never asked for it');
});

it('measures nothing in the browser to make the heights equal', function () {
    /*
     * "No JavaScript that measures layout" — this project sizes with calc() for
     * a reason, and two tests forbid the element-measuring APIs by name. This
     * lane's answer is six grid tracks, so nothing it added may reach for one.
     *
     * The harness is a different thing: tools/card-measure.cjs runs in
     * Playwright, against a preview, and is not served to anybody.
     *
     * MUTATION: add `el.getBoundingClientRect()` to resources/js/kbb/cart.js and
     * this is red. RUN.
     */
    /*
     * THE THREE TEMPLATES DefaultCardStyleTest DOES NOT COVER. That case names
     * the tile, components/product-grid and partials/home/grid; these are the
     * other three that draw the tile, and the fifth grid is one of them.
     *
     * resources/js/ IS NOT SCANNED HERE and that is deliberate rather than an
     * omission: home.js already calls getComputedStyle for something that has
     * nothing to do with the card (measured — it is there on the branch this
     * lane started from), so a blanket ban on the directory would be red for a
     * reason this lane neither caused nor may fix. The claim is about the card.
     */
    foreach ([
        'partials/home/grid-section.blade.php',
        'store/routines.blade.php',
        'store/shop.blade.php',
        'store/product.blade.php',
    ] as $view) {
        $source = (string) file_get_contents(resource_path('views/'.$view));

        foreach (['getBoundingClientRect', 'offsetHeight', 'clientHeight', 'getComputedStyle', 'offsetWidth'] as $api) {
            expect(str_contains($source, $api))
                ->toBeFalse($view.' measures layout in JavaScript: '.$api.'; the card is sized with calc()');
        }
    }

    /*
     * AND NEITHER STYLESHEET REACHES FOR A CONTAINER QUERY TO DO IT. A
     * container query would be the other way to make these heights equal and it
     * is not what was built: the tracks are calc() over the card's own type
     * scale, which needs no layout pass and no support fallback.
     */
    foreach (['kbb-grid-skins.css', 'kbb.css'] as $file) {
        expect(str_contains(cehFamilyRules($file), '@container'))
            ->toBeFalse($file.': the showcase family has grown a container query; the reservations are calc()');
    }
});

/* ═════════════ 4 · the fifth grid, and the one the tile skips ═════════════ */

it('knows every template that draws a product tile, including the grid nobody was counting', function () {
    /*
     * DefaultCardStyleTest enumerates four templates that open a `.kbb-pgrid`,
     * and its scanner matches `class="[^"]*\bkbb-pgrid\b`.
     * partials/home/grid-section.blade.php writes `class="{{ $gsClass }}"` —
     * assembled in PHP — so that case is green with five grids on the shop.
     * This one counts the templates that draw the TILE instead, which is the
     * question "apply this everywhere" actually asks, and it catches a grid
     * whose class is a variable.
     *
     * MUTATION: add `<x-product-card :product="$p" />` to any storefront
     * template and this is red with that file named. RUN, against
     * store/cart.blade.php — which is also the page the owner excluded, so the
     * failure reads correctly.
     */
    $callers = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'))
    ) as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $code = (string) preg_replace(
            ['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s'],
            '',
            (string) file_get_contents($file->getPathname())
        );

        if (str_contains($code, '<x-product-card')) {
            $callers[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    sort($callers);

    expect($callers)->toBe([
        'resources/views/components/product-grid.blade.php',
        // ▲ THE FIFTH GRID. It wraps every tile in a `.gs-cell`, so it is also
        // the one place `height:100%` on `.kbb-tile` resolves against something
        // other than the grid row. Measured on a published section from the
        // owner's own BEST SELLERS preset: one height at 320, 390 and 1280.
        'resources/views/partials/home/grid-section.blade.php',
        'resources/views/partials/home/grid.blade.php',
        'resources/views/store/product.blade.php',
        'resources/views/store/routines.blade.php',
        'resources/views/store/shop.blade.php',
    ], 'the list of templates that draw the product tile has changed, and every one of them has to get the card the owner asked for');

    /*
     * AND ONE OF THE SIX IS NOT A GRID AT ALL, WHICH IS WORTH SAYING RATHER
     * THAN LEAVING TO BE REDISCOVERED. store/routines.blade.php draws ONE tile
     * per step of a routine, outside any `.kbb-pgrid`, so "equal height across
     * a grid" is not a question there — but the brand line and the eyebrow are
     * hidden on it, because the decision is in the tile's own PHP.
     */
    $routines = (string) file_get_contents(resource_path('views/store/routines.blade.php'));

    expect(preg_match('/class="[^"]*\bkbb-pgrid\b/', $routines))
        ->toBe(0, 'the routines page has grown a product grid, so it needs measuring with the other five');
});

/* ═════════════ 5 · and the basket and the checkout are still out ═════════ */

it('still puts no product tile on the basket or the checkout', function () {
    /*
     * "exept cart and checkout pages", from the round before this one, and it
     * still stands. Asserted on the TILE's own class and not on `.kbb-card`:
     * partials/checkout/stripe-card.blade.php uses `.kbb-card` for the
     * card-payment box, which is the whole reason the tile carries `.kbb-tile`
     * as well.
     *
     * MUTATION: add `<x-product-card>` to store/cart.blade.php and this is red.
     * RUN.
     */
    cehProduct('ceh-toner', 'Toner');

    foreach (['/cart' => 'the basket', '/checkout' => 'the checkout'] as $uri => $what) {
        $html = (string) test()->get($uri)->getContent();

        expect(cehCount($html, 'kbb-card kbb-tile'))
            ->toBe(0, $what.' ('.$uri.') draws a product tile, and the owner excluded it by name');
    }
});

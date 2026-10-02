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

    // The ones he did not ask about are untouched, which is the half of rule 1
    // that did NOT change. (`show_discount` and `show_new` left this list on
    // 2 October 2026: the owner asked for those two off as well — Lane PR,
    // pinned in GridCardOwnerAsksTest.)
    foreach (['show_rating', 'show_was_price', 'show_cart'] as $key) {
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

    /*
     * And it does NOT touch the third key, which did not change value: a shop
     * that has switched the stars off chose that.
     *
     * BOTH RESETS, AND THE SECOND ONE IS NOT DECORATION. ProductStyles is bound
     * `scoped` and memoises its resolved values for the request — see
     * AppServiceProvider, and the 87ms a page that buys. `forgetMemo()` clears
     * SettingsService's static and leaves the ProductStyles instance holding
     * what it read BEFORE the migration ran, so this read the pre-migration
     * value and passed against a migration that had deleted the row. Every
     * helper in this file that re-renders does both; a case that pokes the
     * database directly has to as well.
     */
    $settings->set('show_rating', false);
    $migration->up();
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    expect(app(ProductStyles::class)->all()['show_rating'])
        ->toBeFalse('the migration cleared show_rating, which nobody asked it to move');
});

it('resolves the card settings once per request, not once per tile', function () {
    /*
     * ── THE DEFECT, AND IT COST 87ms A PAGE ────────────────────────────────
     *
     * components/product-card.blade.php reads three keys off ProductStyles and
     * it runs ONCE PER TILE. ProductStyles::all() walks its whole schema through
     * SettingsService::get(), and every one of those is a
     * `Cache::rememberForever` — so a 24-product /shop was doing 24 × 29 = 696
     * cache reads for a set of values that cannot change inside one request.
     *
     * Measured on this branch's own preview, thirty sequential renders of
     * /shop, three passes each way: 6799 / 5576 / 6315 ms against 3595 / 3811 /
     * 3363 ms. StorefrontQueryBudgetTest would never have seen it — it counts
     * queries, and this costs none — which is why rule 4 says measured rather
     * than asserted.
     *
     * TWO HALVES, AND EACH IS USELESS WITHOUT THE OTHER: the binding has to be
     * `scoped` (or every tile builds its own instance and the memo memoises
     * nothing), and the instance has to memoise (or the shared instance walks
     * the schema again for every tile).
     *
     * MUTATION 1: drop `$this->app->scoped(ProductStyles::class)` from
     * AppServiceProvider — red on the first expectation. RUN.
     * MUTATION 2: delete the `$this->resolved` memo in ProductStyles::all() —
     * red on the second. RUN.
     * MUTATION 3: delete `$this->resolved = null;` from save() — red on the
     * third, and on the shop Appearance → Product styles reads back the value
     * it had before you pressed Save. RUN.
     */
    expect(app(ProductStyles::class))
        ->toBe(app(ProductStyles::class), 'ProductStyles is not bound scoped, so every tile builds its own and memoises nothing');

    $styles = app(ProductStyles::class);

    expect($styles->all()['show_brand'])->toBeFalse();

    // A write that goes round the service: the memo is what keeps this from
    // being read again, and it is the whole point.
    app(SettingsService::class)->set('show_brand', true);
    SettingsService::forgetMemo();

    expect($styles->all()['show_brand'])
        ->toBeFalse('ProductStyles::all() read the settings again inside one request, which is 29 cache reads per tile');

    // …until something says the values have moved.
    $styles->forgetResolved();

    expect($styles->all()['show_brand'])
        ->toBeTrue('forgetResolved() does not drop the memo, so nothing can ever refresh it');

    /*
     * AND save() DROPS IT ITSELF, so the screen that writes and reads back in
     * one request is told the truth.
     */
    $styles->save(['show_brand' => false]);

    expect($styles->all()['show_brand'])
        ->toBeFalse('save() left the pre-save values memoised, so the admin screen reads back what it just overwrote');
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
     * MUTATION: replace `var(--sc-rate-slot)` in `.cb`'s grid-template-rows
     * with `auto` and this is red. RUN, and re-measured in Chromium with the
     * sheet rebuilt: the category page went from one height to
     * 392.27 / 418.25 / 418.27 at 1280 and 330.2 / 356.2 at 390 — rows of
     * unreviewed products 26px short again.
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
     * MUTATION: put `flex-wrap:wrap` back and this is red. RUN, and re-measured
     * with the sheet rebuilt: /shop went from one card height at 320 to
     * 321.2 and 347.95 — 26.75px, one line of the price — while 390 stayed at
     * a single 356.2, which is the measurement behind "nothing anybody can see
     * above 320 moves".
     */
    foreach (['kbb-grid-skins.css', 'kbb.css'] as $file) {
        $rules = cehFamilyRules($file);

        expect(preg_match('/\.kbb-pgrid\[data-skin\^="showcase"\] \.cp\{[^}]*flex-wrap:nowrap/s', $rules))
            ->toBe(1, $file.': the price row may wrap again, and a second line there is card height its neighbour does not have');

        expect(preg_match('/\.kbb-pgrid\[data-skin\^="showcase"\] \.kbb-card-reg\{[^}]*flex:0 99 auto/s', $rules))
            ->toBe(1, $file.': the struck original no longer absorbs the shortfall, so the sale price is what gets truncated');

        expect(preg_match('/\.kbb-pgrid\[data-skin\^="showcase"\] \.kbb-card-price\{[^}]*flex:0 1 auto/s', $rules))
            ->toBe(1, $file.': the sale price no longer shrinks last');

        /*
         * ── AND THE ROW'S HEIGHT DOES NOT FOLLOW THE TYPE INSIDE IT ─────────
         *
         * Two defects in one line, both measured rather than reasoned.
         *
         * `align-items:baseline` across two font sizes offsets the two boxes to
         * put their baselines on one line, and the union is taller than either:
         * `.cp` came back 34px on a marked-down card and 33px on the plain one
         * beside it, which put three card heights back on the category page at
         * 1280 (416.75 / 417.75 / 417.77). Centring makes both boxes the row's
         * own line-height and the row a constant 33px; the two figures still
         * read as one line, because both inherit the same 21px.
         *
         * And that line-height is a LENGTH, `calc(var(--sc-name) * 1.5)`, not
         * the ratio it would otherwise inherit — which is what lets the rule
         * below print the struck original smaller on a 320px phone without
         * costing the card 3px.
         *
         * MUTATION: put `align-items:baseline` back and this is red; measured,
         * the category page went from one card height at 1280 to three. RUN.
         */
        expect(preg_match('/\.kbb-pgrid\[data-skin\^="showcase"\] \.cp\{[^}]*align-items:center/s', $rules))
            ->toBe(1, $file.': the price row aligns on baselines again, and that makes a marked-down card 1px taller than its neighbour');

        expect(preg_match('/\.kbb-pgrid\[data-skin\^="showcase"\] \.cp\{[^}]*line-height:calc\(var\(--sc-name\) \* 1\.5\)/s', $rules))
            ->toBe(1, $file.': the price row\'s line-height is not a length any more, so its height follows the type inside it');

        /*
         * ── AND ON THE NARROWEST PHONE THE STRUCK ORIGINAL IS PRINTED SMALLER
         *
         * 320px is the narrowest card this grid draws. Measured there with a
         * Range around the words — `scrollWidth` on a block reports the
         * container and rounds 55.6 down to 55, which is how the first pass of
         * this reported a price that fitted while the screenshot read "AED 2…"
         * — the pair needs 51 + 8 + 55 in a 104px column. The rule takes the
         * STRUCK original from 12.5px to 10.5px (50.66 to 42.53) and the gap
         * from 8 to 5, which is 102.73, and leaves the sale price alone.
         *
         * MUTATION: delete the media query and this is red; measured at 320,
         * the struck original goes back to a 42.81px box around 45px of words
         * and prints "AED 2…". RUN.
         */
        expect(preg_match(
            '/@media \(max-width:380px\)\{\s*'
            .'\.kbb-pgrid\[data-skin\^="showcase"\] \.cp:has\(\.kbb-card-reg\)\{gap:5px\}\s*'
            .'\.kbb-pgrid\[data-skin\^="showcase"\] \.cp:has\(\.kbb-card-reg\) \.kbb-card-reg\{\s*'
            .'font-size:calc\(var\(--sc-name\) - 3\.5px\)\}/s',
            $rules
        ))->toBe(1, $file.': a marked-down price has no narrow-screen rule, so at 320px it is ellipsised');

        /*
         * ── AND TREATMENT C IS THE ONE THAT HAS TO WRAP ────────────────────
         *
         * `showcase-row` gives its width to the button: measured at 1280, five
         * columns, the tile is 230.8px, `.cb` takes 14px of padding each side,
         * the button is 121px and the column gap is 10 — which leaves the price
         * about 72px against the 114 a marked-down pair needs. That is not a
         * font-size away. `flex-wrap:nowrap` clipped it, and the shot showed the
         * sale price running under the button on five of twelve tiles.
         *
         * So this one treatment wraps and RESERVES the second line, which is
         * the same answer as everywhere else arrived at from the other end.
         * `row-gap:0` is part of it and was measured: the family's `gap:8px` is
         * both axes and only shows up once the container wraps, so the two
         * lines came to 48.5 against a 40.5 reservation and a marked-down card
         * was 8px taller than the plain one beside it (370.94 / 370.92 /
         * 362.92 on the three rows).
         *
         * MUTATION: delete the `flex-wrap:wrap` line and C is red — measured,
         * the sale price is clipped by the button again; delete `row-gap:0` and
         * C's grid goes back to three heights. RUN, both.
         */
        expect(preg_match(
            '/\.kbb-pgrid\[data-skin="showcase-row"\] \.cp\{[^}]*'
            .'flex-wrap:wrap;[^}]*align-content:flex-end;[^}]*'
            .'column-gap:8px;[^}]*row-gap:0;[^}]*'
            .'min-height:calc\(var\(--sc-name\) \* 1\.5 \* 2\)\}/s',
            $rules
        ))->toBe(1, $file.': treatment C does not wrap and reserve its price row, so its sale price is clipped by the button at 1280');

        // …and it is a DESKTOP answer. Below 700px C is the stacked card the
        // others are, its price has the whole text column, and a reserved
        // second line there would be an empty one on every card.
        expect(preg_match(
            '/@media \(max-width:700px\)\{.*\.kbb-pgrid\[data-skin="showcase-row"\] \.cp\{[^}]*'
            .'flex-wrap:nowrap;min-height:0\}/s',
            $rules
        ))->toBe(1, $file.': treatment C reserves a second price line on a phone, where it has the width not to');
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
        // ▲ NOT A SEVENTH GRID. (Lane PI-B) "Load more on scroll" fetches the
        // next page's tiles as a batch and appends them to a grid that is
        // already on the page — #grid on /shop and a category, the curated
        // listings' .kbb-pgrid — so these tiles land inside a grid this list
        // already names and get its card, its skin and its equal heights.
        'resources/views/partials/listing-batch.blade.php',
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

/* ═══ 6 · "everywhere" is bigger than the eight URLs somebody thought of ═══ */

it('draws the card the owner asked for on the four curated collections too', function () {
    /*
     * ── THE ROUND THAT FOUND THESE MEASURED EIGHT URLs AND CALLED IT EVERY ──
     *
     * /new-in, /best-sellers, /super-sale and /everything-under-54-aed are four
     * more pages of this shop that draw a product grid, and not one of them had
     * ever been looked at. They are easy to miss for a specific reason worth
     * writing down: CollectionController::COLLECTIONS keys them 'new-in',
     * 'best-sellers', 'super-sale' and 'under-54', which reads exactly like
     * /collections/<key>/ -- and routes/web.php mounts each at its own
     * TOP-LEVEL path instead. A walk that guessed the URL got four 404s, and a
     * 404 measures as "no product grid on this page", which reads like a pass.
     *
     * ── SO THE COUNT IS ASSERTED BEFORE ANYTHING ELSE IS ───────────────────
     *
     * Every claim below -- no brand line, no eyebrow -- is satisfied VACUOUSLY
     * by a page that drew no card at all. That is the false-green shape this
     * file's header lists twice, and the only guard against it is to say how
     * many tiles the page is supposed to carry and check that first. Four
     * products are created for each page's own selector, so the expected count
     * is a number this test chose rather than whatever the catalogue happens to
     * hold.
     *
     * MUTATION: make the card draw the brand line again -- remove the
     * `$showBrand &&` guard from components/product-card.blade.php -- and this
     * is red on all four pages with the brand count named. RUN.
     *
     * SECOND MUTATION, the one that proves the count is doing work: change the
     * expected tile count to 0 and change the four paths to /new-in-typo etc.
     * Without the count assertion the brand and eyebrow expectations stay GREEN
     * on four 404 pages. RUN; that is exactly how these four went unmeasured.
     */
    for ($i = 0; $i < 4; $i++) {
        cehProduct('ceh-cur-'.$i, 'Curated Ceramide Barrier Repair Night Cream '.$i, [
            // On sale AND under AED 54, so one set of products satisfies the
            // 'on_sale' and 'budget' selectors as well as 'newest'/'popular'.
            // `price` is the regular figure and `sale_price` the markdown --
            // there is no `regular_price` column; Product::effectivePrice() and
            // compareAtPrice() read exactly this pair. tools/card-seed.php
            // writes the same two.
            'price' => 6000,
            'sale_price' => 3000,
            'created_at' => now(),
        ]);
    }

    $pages = [
        '/new-in' => 'New In',
        '/best-sellers' => 'Best Sellers',
        '/super-sale' => 'Super Sale',
        '/everything-under-54-aed' => 'Everything under AED 54',
    ];

    foreach ($pages as $uri => $what) {
        $html = cehGet($uri);

        /*
         * ▲ FIRST, AND THE REST OF THE CASE DEPENDS ON IT.
         *
         * BOUNDED RATHER THAN EXACT, and the bound is stated rather than
         * discovered: at least the FOUR this test created for these four
         * selectors, and never more than CollectionController::PER_PAGE, which
         * is what a paginated collection may draw. An exact number is not
         * available to assert -- 2026_08_27_100000_seed_demo_catalogue draws a
         * demo catalogue into every test database, so these pages render the
         * demo products plus this test's four and the figure is a property of
         * that fixture rather than of this case. The floor is what does the
         * work: ZERO is the shape that makes every expectation below vacuous,
         * and zero is what a 404 or a mis-guessed URL produces.
         */
        $tiles = cehCount($html, 'kbb-card kbb-tile');

        expect($tiles)->toBeGreaterThanOrEqual(
            4,
            $what.' ('.$uri.') drew '.$tiles.' product tiles — fewer than the four this test created for it, so every expectation below it would be vacuous'
        );
        expect($tiles)->toBeLessThanOrEqual(
            24,
            $what.' ('.$uri.') drew '.$tiles.' product tiles, past CollectionController::PER_PAGE — it is not paginating'
        );

        expect(cehCount($html, 'kbb-card-brand'))
            ->toBe(0, $what.' still prints the brand line the owner asked to hide');

        expect(cehCount($html, 'kbb-card-cat'))
            ->toBe(0, $what.' still prints the category eyebrow the owner asked to hide');
    }
});

it('gives the Frequently Bought Together strip the stylesheet it never had', function () {
    /*
     * ── THE ONE PRODUCT TILE ON THIS SHOP THAT HAD NO CSS AT ALL ───────────
     *
     * partials/fbt.blade.php draws a tile per bundle item and is the only
     * product-tile surface outside the cart that never went through
     * <x-product-card>. Its own header says why nobody noticed: "The plugin
     * ships its own CSS for this block, so no theme stylesheet is involved" --
     * true of the WordPress original, where the KBB Modules plugin styled it.
     * THE PLUGIN'S CSS WAS NEVER PORTED, so in this Laravel app the block had
     * no rules whatsoever and rendered as a wall of inline text.
     *
     * Measured in Chromium on the preview, module on, before this block
     * existed -- .kbb-fbt-item heights:
     *
     *     @320    18 / 81 / 156 / 210      four heights
     *     @390    18 / 60 / 81  / 114      four heights
     *     @1280   18 / 114                 two heights
     *
     * and after: 175.94 at all three widths, one height.
     * docs/card-shots/fbt-before-390.png and fbt-after-390.png are the pair.
     *
     * MUTATION: replace `var(--fbt-name-slot)` in `.kbb-fbt-item`'s
     * grid-template-rows with `auto` and this is red. RUN, and re-measured in
     * Chromium with the sheet rebuilt: the strip went back to TWO heights at
     * all three widths -- 159.06 where the name fits one line and 175.94 where
     * it takes two -- which is the same defect as the rating row on the shared
     * card, arrived at from the other end. (The first draft of this note
     * guessed 151.94/168.83 from reading the CSS; the measurement is what
     * these numbers are, and the two did not agree.)
     *
     * A SECOND MUTATION for the clamp: delete `-webkit-line-clamp` from
     * `.kbb-fbt-name` and the track is still reserved but a three-line name
     * OVERFLOWS it, so the name runs into the price. RUN.
     */
    $css = cehCss('kbb-product.css');
    $rules = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    expect(str_contains($rules, '.kbb-fbt-item'))
        ->toBeTrue('the FBT strip has no stylesheet again — every item is as tall as its own name wraps');

    // The reservation is a calc() over the numbers the name row is drawn with,
    // so the track cannot drift from the row it reserves.
    expect(preg_match('/--fbt-name-slot:\s*calc\(([^;]+)\)/', $rules, $m))
        ->toBe(1, '--fbt-name-slot is not a calc() over the name row\'s own numbers');

    foreach (['--fbt-name-fs', '--fbt-name-lh', '--fbt-name-lines'] as $term) {
        expect(str_contains($m[1], $term))
            ->toBeTrue('--fbt-name-slot does not read '.$term.', so the reservation can drift from the row it reserves');
    }

    // And the item SPENDS it, in a grid-template-rows that also reserves the
    // picture and the price. `auto` in the middle track is the defect.
    expect(preg_match(
        '/\.kbb-fbt-item\{[^}]*grid-template-rows:\s*var\(--fbt-thumb\)\s+var\(--fbt-name-slot\)\s+var\(--fbt-price-slot\)/s',
        $rules
    ))->toBe(1, '.kbb-fbt-item no longer reserves the three rows in order — the strip goes back to one height per name length');

    // The clamp and the track read the SAME property, which is what stops a
    // three-line name overflowing a two-line reservation.
    expect(preg_match('/\.kbb-fbt-name\{[^}]*-webkit-line-clamp:\s*var\(--fbt-name-lines\)/s', $rules))
        ->toBe(1, '.kbb-fbt-name is not clamped to the number of lines its track reserves');

    /*
     * AND THE PRICE ROW IS A LENGTH, not a ratio. Declared unitless the
     * struck/sale pair takes a taller line box than a plain price and puts a
     * second height back on the strip — the pixel `.cp` already paid for on the
     * shared card.
     */
    expect(preg_match('/\.kbb-fbt-price\{[^}]*line-height:\s*calc\(/s', $rules))
        ->toBe(1, '.kbb-fbt-price\'s line-height is not a length, so the row follows the type inside it');
});

it('knows every product tile on this shop that is NOT the shared card', function () {
    /*
     * The case above this one enumerates the templates that call
     * <x-product-card>. This one is its other half, and it is the half that
     * found the defect: a template can draw a product tile WITHOUT calling the
     * component, and then "apply this everywhere" silently does not reach it.
     *
     * Two exist. Both are listed here so a third cannot arrive unnoticed:
     *
     *   partials/fbt.blade.php        .kbb-fbt-item — the bundle strip on the
     *                                 product page. IN SCOPE, and it is the one
     *                                 this round fixed.
     *   store/cart-inner.blade.php    .cpg-card — the recommended rail, drawn
     *                                 only under the squeeze cart layout. OUT
     *                                 of scope: the owner excluded the cart and
     *                                 the checkout by name, and case 5 above
     *                                 pins that exclusion.
     *
     * MUTATION: give any other storefront template its own product tile — a
     * `<div class="kbb-fbt-item">` in store/shop.blade.php will do — and this
     * is red with that file named. RUN.
     */
    $drawers = [];

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

        foreach (['kbb-fbt-item', 'cpg-card'] as $needle) {
            if (preg_match('/class="[^"]*\b'.preg_quote($needle, '/').'\b/', $code)) {
                $drawers[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }
    }

    sort($drawers);

    expect($drawers)->toBe([
        'resources/views/partials/fbt.blade.php',
        'resources/views/store/cart-inner.blade.php',
    ], 'the set of templates drawing a product tile WITHOUT the shared card has changed — each one has to get the owner\'s card of its own, because none of them inherits it');
});

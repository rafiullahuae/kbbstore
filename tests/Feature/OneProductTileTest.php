<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\SettingsService;

/**
 * =============================================================================
 * ONE PRODUCT TILE, ON EVERY GRID                                      Lane PG
 * =============================================================================
 *
 * ── WHAT THE OWNER ASKED FOR, VERBATIM ──────────────────────────────────────
 *
 * "i need the product grid this one, everywhere on the whole site inner pages
 *  by default. on shop, on category pages, on product pages etc, and if the
 *  product titles goes long, still the product grid height must remain equal
 *  and adjusted. by default 5 columns on desktop and 2 columns on mobile. and
 *  also on category pages. keep off the left filters hidden by default. and
 *  user can view the filters by the option. and if any product doesn't have
 *  any review, then the rating bar will not show on that product inside grid."
 *
 * ── WHAT WAS ACTUALLY THERE ─────────────────────────────────────────────────
 *
 * FIVE grids and TWO complete cards:
 *
 *   .kbb-pgrid via <x-product-grid>      the skinned card   brand page, the
 *                                                           [kbb_products]
 *                                                           shortcode
 *   .kbb-pgrid via partials/home/grid    the skinned card   home rails, a
 *                                                           category, the
 *                                                           wishlist
 *   .grid on /shop via <x-product-card>  the OTHER card     the shop listing
 *                                                           AND every category
 *                                                           archive
 *   .rel on a product page               the OTHER card     "you may also like"
 *   .brw-grid on the brand landing       neither -- it lists BRANDS
 *
 * The two cards had the same job, different class names, and a different set of
 * bugs fixed in each. That is the state this file exists to keep ended.
 *
 * ── THE FOUR DEFECTS PINNED HERE, EACH AS IT LOOKED ON THE SHOP ─────────────
 *
 *  1. THE EMPTY RATING BAR. partials/home/grid.blade.php drew five hollow stars
 *     and "(0)" for every product nobody had reviewed, unconditionally. It is
 *     on every tile of docs/OWNER-GRID-REFERENCE.webp, and it reads as "rated
 *     badly" rather than "not rated yet".
 *
 *  2. A LONG NAME MADE ITS WHOLE ROW TALLER. Nothing clamped the name, so one
 *     ninety-character title stretched every tile beside it.
 *
 *  3. FOUR COLUMNS ON A DESKTOP where the owner asked for five.
 *
 *  4. THE FILTER RAIL STARTED OPEN on /shop and on every category archive, and
 *     there was no way to keep it shut across a navigation.
 *
 * ── MUTATION NOTES, ALL RUN ─────────────────────────────────────────────────
 *
 * Each case below names the edit that reddens it. They were made and reverted,
 * one at a time, against this file.
 */

/* ───────────────────────────────── fixtures ──────────────────────────────── */

function optBrand(): Brand
{
    return Brand::firstOrCreate(['slug' => 'opt-house'], ['name' => 'OPT House']);
}

function optCategory(): Category
{
    return Category::firstOrCreate(['slug' => 'opt-care'], ['name' => 'OPT Care']);
}

/** A visible, buyable product in the fixture category. */
function optProduct(string $slug, array $extra = []): Product
{
    $product = Product::create(array_merge([
        'slug' => $slug,
        'name' => 'OPT '.$slug,
        'status' => 'publish',
        'is_visible' => true,
        'brand_id' => optBrand()->id,
        'price' => 5000,
        'stock_status' => 'instock',
        'type' => 'simple',
        'rating' => 0.0,
        'review_count' => 0,
    ], $extra));

    $product->categories()->syncWithoutDetaching([optCategory()->id]);

    return $product;
}

/** One tile's markup, cut out of a rendered page by the product it links to. */
function optTile(string $html, string $slug): string
{
    foreach (array_slice(explode('<div class="kbb-card kbb-tile">', $html), 1) as $tile) {
        if (str_contains($tile, '/product/'.$slug.'/')) {
            return $tile;
        }
    }

    return '';
}

function optGet(string $uri, array $cookies = []): string
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    return (string) test()
        ->withUnencryptedCookies($cookies)
        ->get($uri)
        ->assertOk()
        ->getContent();
}

/** A declaration's value out of a block of CSS, or null. */
function optCssValue(string $block, string $property): ?string
{
    return preg_match('/(?:^|[;{])\s*'.preg_quote($property, '/').'\s*:\s*([^;}]+)/i', $block, $m)
        ? trim($m[1])
        : null;
}

/** The body of the FIRST rule whose selector list contains $selector exactly. */
function optCssBlock(string $selector, string $sheet = 'kbb.css'): string
{
    $css = (string) file_get_contents(base_path('resources/css/kbb/'.$sheet));

    // Comments out first: this sheet documents its own selectors in prose, and
    // a scanner that reads its comments finds rules that do not exist.
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    foreach (explode('}', $css) as $chunk) {
        $parts = explode('{', $chunk, 2);

        if (count($parts) !== 2) {
            continue;
        }

        $selectors = array_map('trim', explode(',', $parts[0]));

        if (in_array($selector, $selectors, true)) {
            return trim($parts[1]);
        }
    }

    return '';
}

/* ═════════════════════════ 1 · there is ONE card ═════════════════════════ */

it('has exactly one template that draws a product tile', function () {
    /*
     * THE SOURCE, not a page: a second card can exist and simply not be on the
     * page a case happens to render. Every Blade under resources/views is
     * scanned for the tile's root element, comments stripped first -- three
     * files in this repository name `.kbb-card` in prose, including the card's
     * own docblock, and a scanner that reads prose reports templates that do
     * not exist.
     *
     * `.kbb-card` ALONE WOULD BE WRONG as the needle, and that is worth stating:
     * partials/checkout/stripe-card.blade.php uses the same class for the
     * card-payment box. The tile is `.kbb-card.kbb-tile`, which is the whole
     * reason the second class exists.
     *
     * MUTATION: copy the tile's root <div> into any other storefront template
     * and this is red with that file named.
     */
    $drawing = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'))
    ) as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $code = (string) preg_replace(['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s'], '', $source);

        if (str_contains($code, 'class="kbb-card kbb-tile"')) {
            $drawing[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    /*
     * THE ADMIN CONSOLE IS EXCLUDED, AND THE NEXT CASE IS WHY THAT IS NOT A
     * HOLE.
     *
     * resources/views/admin/app.blade.php's skinCard() draws the SAME tile on
     * purpose: it is the live preview under Appearance -> Product styles, and a
     * preview whose markup has drifted from the card it previews is worse than
     * no preview -- it shows the owner a shape his shop does not have. It drew
     * the pre-unification tile until the integrator corrected it, and this case
     * went red on the correction, which is the wrong way round: it was
     * punishing the file for catching up.
     *
     * What this case is actually about is that no SHOPPER-FACING template
     * draws a second tile. So it asks about the storefront, and the case below
     * asks the admin preview the opposite question -- that it still carries the
     * same two classes -- so the preview cannot drift back silently either.
     */
    expect(array_values(array_filter(
        $drawing,
        static fn (string $f): bool => ! str_starts_with($f, 'resources/views/admin/')
    )))->toBe(['resources/views/components/product-card.blade.php']);
});

it('keeps the admin skin preview drawing the same tile as the shop', function () {
    /*
     * The other half of the case above. Appearance -> Product styles draws a
     * sample card so the owner can see a skin before choosing it, and it is
     * hand-written JavaScript rather than a render of the real component --
     * there is no product and no request. So it can drift, and it had: it was
     * missing `kbb-tile` (which carries the square thumbnail, the name clamp
     * and the stretched link), missing the name's own span, and its thumbnail
     * was 1/1.02 where the shop's is 1/1.
     *
     * Three classes pinned, not the whole markup. A preview does not have to be
     * byte-identical to the card -- it has no price logic, no wishlist, no
     * variants -- but it must share the root and the parts that decide its
     * SHAPE, because shape is the only thing a skin preview is for.
     *
     * MUTATION NOTE. Drop `kbb-tile` from skinCard()'s anchor and this is red,
     * and the preview goes back to showing a tile the shop stopped drawing.
     * RUN.
     */
    $admin = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $code = (string) preg_replace(['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s'], '', $admin);

    foreach ([
        'class="kbb-card kbb-tile"' => 'the tile root, so the preview inherits the shop\'s shape rules',
        'kbb-card-nm' => 'the name span, which is what the two-line clamp is applied to',
        '.skinprev .kbb-card-thumb{aspect-ratio:1/1;' => 'a SQUARE thumbnail, as the shop now draws',
    ] as $needle => $why) {
        expect(str_contains($code, $needle))->toBeTrue(sprintf(
            'resources/views/admin/app.blade.php must carry %s — %s.',
            var_export($needle, true),
            $why
        ));
    }
});

it('draws that one tile on the shop, a category, a brand page, the home rails and a product page', function () {
    $product = optProduct('opt-everywhere');

    $pages = [
        '/shop/' => 'the shop listing',
        '/collections/opt-care/' => 'a category archive',
        '/brands/opt-house/' => 'a brand page',
        '/' => 'the homepage rails',
    ];

    foreach ($pages as $uri => $what) {
        $html = optGet($uri);

        expect(str_contains($html, '<div class="kbb-card kbb-tile">'))
            ->toBeTrue($what.' ('.$uri.') rendered no product tile at all');
    }

    /*
     * The product page's related rail reaches the same card through the same
     * <x-product-card> tag, which is exactly why the one card lives in that
     * file: store/product.blade.php belongs to another lane and was not edited.
     */
    optProduct('opt-related-a');
    optProduct('opt-related-b');

    $pdp = optGet('/product/'.$product->slug.'/');

    expect(str_contains($pdp, '<div class="kbb-card kbb-tile">'))
        ->toBeTrue('the related rail on a product page is not drawing the one tile');
});

it('keeps the wishlist heart, quick view, Product Labels and a no-JS add to cart on the one tile', function () {
    /*
     * The skinned card had NONE of these and /shop had all four. Unifying onto
     * the skinned look without carrying them would have been a regression in
     * four working things dressed up as a design change, so each is asserted
     * here rather than trusted to the modules' own tests.
     *
     * MUTATION: delete any one of the four from product-card.blade.php and one
     * expectation below is red.
     */
    $settings = app(SettingsService::class);
    $settings->setModule('wishlist', true);
    $settings->setModule('quick_view', true);

    $product = optProduct('opt-controls');

    $tile = optTile(optGet('/shop/'), 'opt-controls');

    expect($tile)->not->toBe('', 'the tile for this product was not on /shop');

    expect(str_contains($tile, 'data-kbb-wish="'.$product->id.'"'))
        ->toBeTrue('Catalogue → Wishlist is on and the tile has no heart');
    expect(str_contains($tile, 'data-kbb-qv="'.$product->id.'"'))
        ->toBeTrue('Catalogue → Quick view is on and the tile has no quick-view button');

    // A REAL href, which is the one thing the skinned card's <span> gave up.
    expect(str_contains($tile, 'href="?add-to-cart='.$product->publicId().'"'))
        ->toBeTrue('the Add to cart button is not a link, so the tile needs JavaScript to sell anything');
    expect(str_contains($tile, 'data-kbb-add="'.$product->id.'"'))
        ->toBeTrue('cart.js has nothing to bind on this tile');
});

it('never nests one link inside another, which is what forced the tile off <a>', function () {
    /*
     * The skinned card wrapped the whole tile in `<a class="kbb-card" href=…>`.
     * It cannot any more: the tile holds a real `?add-to-cart=` link and two
     * <button>s, and `<a>` inside `<a>` is the one nesting the HTML parser
     * actively breaks apart -- the browser would have hoisted the Add to cart
     * button OUT of the card. The tile is a <div>, the name is the link, and
     * `.cn::after` stretches it over the whole card.
     *
     * MUTATION: make the tile's root `<a class="kbb-card kbb-tile" href=…>`
     * again and this is red on the nesting count.
     */
    optProduct('opt-nesting');

    $tile = optTile(optGet('/shop/'), 'opt-nesting');

    // Every <a> in the tile closes before the next one opens: no nesting.
    $depth = 0;

    foreach (preg_split('/(<a\b|<\/a>)/i', $tile, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $token) {
        if (strcasecmp($token, '</a>') === 0) {
            $depth--;
        } elseif (stripos($token, '<a') === 0) {
            $depth++;
        }

        expect($depth)->toBeLessThanOrEqual(1, 'the tile nests an <a> inside an <a>');
        expect($depth)->toBeGreaterThanOrEqual(0, 'the tile closes an </a> it never opened');
    }

    // And the stretched link is what makes the rest of the tile clickable.
    expect(optCssValue(optCssBlock('.kbb-tile .cn::after'), 'position'))->toBe('absolute');
    expect(optCssValue(optCssBlock('.kbb-tile .cn::after'), 'inset'))->toBe('0');
});

/* ════════════ 2 · no reviews, no rating bar — and heights still line up ═══ */

it('draws no rating bar at all for a product nobody has reviewed', function () {
    /*
     * THE DEFECT, as the owner saw it: five hollow stars and "(0)" under every
     * product on the homepage rails, on a category page and on the wishlist.
     *
     * MUTATION: in product-card.blade.php change `@if ($rc > 0)` to `@if (true)`
     * and this is red -- the unreviewed tile grows a `.kbb-card-rate`.
     */
    optProduct('opt-unreviewed', ['review_count' => 0, 'rating' => 0.0]);
    optProduct('opt-reviewed', ['review_count' => 7, 'rating' => 4.5]);

    // The SAME row of the SAME grid, so this cannot pass by rendering two
    // different pages under two different settings.
    $html = optGet('/collections/opt-care/');

    $unreviewed = optTile($html, 'opt-unreviewed');
    $reviewed = optTile($html, 'opt-reviewed');

    expect($unreviewed)->not->toBe('', 'the unreviewed product was not on the page');
    expect($reviewed)->not->toBe('', 'the reviewed product was not on the page');

    expect(str_contains($unreviewed, 'kbb-card-rate'))
        ->toBeFalse('an unreviewed product still draws a rating bar');
    expect(str_contains($unreviewed, '(0)'))
        ->toBeFalse('an unreviewed product still prints a review count of zero');

    expect(str_contains($reviewed, 'kbb-card-rate'))
        ->toBeTrue('a reviewed product lost its rating bar with the empty one');
    expect(str_contains($reviewed, '(7)'))
        ->toBeTrue('a reviewed product no longer states how many reviews it has');
});

it('holds the price row to the bottom of the tile so a missing rating bar cannot misalign it', function () {
    /*
     * The old /shop card reserved the rating row's height with an INVISIBLE
     * element. The one tile does not need one: the card is a flex column,
     * `.cb` grows, and `.cp` takes `margin-top:auto` -- so the price and the
     * button sit on the tile's bottom edge whatever is above them, and a tile
     * with a rating lines up with one without.
     *
     * This is the only mechanism holding that alignment, so it is asserted
     * rather than left to the screenshots.
     *
     * MUTATION: delete the `.kbb-tile>.cb>.cp` rule from kbb.css and this is
     * red -- and the browser evidence in docs/lane-pg-shots shows the price
     * rows stepping.
     */
    expect(optCssValue(optCssBlock('.kbb-tile'), 'height'))
        ->toBe('100%', 'the tile does not fill its grid row, so a row cannot have equal heights');
    expect(optCssValue(optCssBlock('.kbb-tile'), 'display'))->toBe('flex');
    expect(optCssValue(optCssBlock('.kbb-tile>.cb'), 'flex'))->toBe('1');
    expect(optCssValue(optCssBlock('.kbb-tile>.cb>.cp'), 'margin-top'))->toBe('auto');
});

/* ═════════════ 3 · a long name cannot make its row taller ════════════════ */

it('reserves exactly two lines for the product name whatever the name says', function () {
    /*
     * "if the product titles goes long, still the product grid height must
     *  remain equal and adjusted."
     *
     * IN CSS, AND MEASURED NOWHERE. `-webkit-line-clamp:2` cuts the name off and
     * `height:calc(2 * <line-height>)` reserves both lines whether or not the
     * text fills them, so a one-word name and a ninety-character name occupy
     * the same box. The reservation is in `em`, which resolves against the
     * element's own font size -- so it follows the skin instead of being a
     * pixel figure that stops being true under half of the 28.
     *
     * MUTATION: delete the `height` declaration and the clamp still truncates
     * but a one-line name takes one line -- the browser evidence at 390 and
     * 1280 is what shows the row stepping; delete `-webkit-line-clamp` and a
     * long name overflows its reserved box.
     */
    $block = optCssBlock('.kbb-tile .kbb-card-nm');

    expect($block)->not->toBe('', 'the name clamp has gone from kbb.css');

    /*
     * ▲ THE TWO AND THE 1.32 ARE PROPERTIES NOW, NOT LITERALS.     (Lane CARD)
     *
     * This read `->toBe('2')` and `calc(2 * <line-height>em)`. The showcase
     * family reserves a GRID TRACK for this box out of the same two numbers, so
     * having them written here as well is two places holding one fact: a lane
     * clamping to three lines would get a name overflowing a track still
     * reserved for two, and nothing would say so. The clamp reads
     * `--sc-name-lines` and `--sc-name-lh` with its old literals as the
     * FALLBACKS, so the other 31 skins — which declare neither — have
     * byte-for-byte the box they had, and this case still asserts exactly what
     * it asserted: two lines, clamped, and a height that is two of this
     * element's own lines.
     */
    expect(optCssValue($block, '-webkit-line-clamp'))->toBe('var(--sc-name-lines,2)');
    expect(optCssValue($block, 'overflow'))->toBe('hidden');

    $lineHeight = optCssValue($block, 'line-height');
    $height = optCssValue($block, 'height');

    expect($lineHeight)->toBe('var(--sc-name-lh,1.32)',
        'the clamp needs a line-height it can reserve two of');
    expect($height)->toBe('calc(var(--sc-name-lines,2) * var(--sc-name-lh,1.32) * 1em)',
        'the reserved height must be exactly two of this element\'s own lines, got: '.var_export($height, true));

    // And the two fallbacks ARE the numbers this case was written against, so
    // nothing moved for a skin that declares neither property.
    expect(optCssValue($block, '-webkit-line-clamp'))->toContain(',2)');
    expect(optCssValue($block, 'line-height'))->toContain(',1.32)');

    // NO SCRIPT ANYWHERE NEAR IT. Rule 4: this project sizes with calc().
    $card = (string) file_get_contents(resource_path('views/components/product-card.blade.php'));

    foreach (['getBoundingClientRect', 'offsetHeight', 'clientHeight', 'getComputedStyle'] as $api) {
        expect(str_contains($card, $api))
            ->toBeFalse('the product tile measures layout in JavaScript: '.$api);
    }
});

it('renders a one-word name and a ninety-character name into the same markup shape', function () {
    // The browser numbers are in docs/lane-pg-shots/measurements.json; what a
    // PHP test can add is that both names reach the SAME clamped element, so
    // the CSS above governs both.
    optProduct('opt-short', ['name' => 'Toner']);
    optProduct('opt-long', ['name' => str_pad('Ultra Hydrating Ceramide Barrier Repair Night Cream With Panthenol', 90, ' and squalane')]);

    $html = optGet('/collections/opt-care/');

    foreach (['opt-short', 'opt-long'] as $slug) {
        expect(preg_match('/<span class="kbb-card-nm">/', optTile($html, $slug)))
            ->toBe(1, $slug.' does not put its name in the clamped element');
    }
});

/* ═══════════ 4 · the eyebrow, and the query it deliberately does not cost ═ */

it('labels a category archive\'s tiles with the category and costs no query for it', function () {
    /*
     * The eyebrow is a caller's STRING, not something the card reads off
     * `$product->categories`. Reading the relation would have put it in the
     * card's load contract and therefore in the eager load of /shop, every
     * category archive, the product page and /routines -- one more query on
     * four pages. StorefrontQueryBudgetTest holds the totals; this holds the
     * shape that keeps them.
     *
     * MUTATION: change the card to read `$product->categories->first()` itself
     * and 'it renders every page that calls a contracted template without one
     * lazy load' in ComponentLoadContractTest is red on /shop.
     */
    optProduct('opt-eyebrow');

    /*
     * ▲ THE EYEBROW IS SWITCHED ON FOR THIS CASE.                   (Lane CARD)
     *
     * Appearance → Product styles → Card content → "Category label" ships OFF
     * now — the owner asked for it in as many words — and the tile reads that
     * key itself, so a shop at the shipped defaults draws no `.kbb-card-cat`.
     *
     * This case is not about the default. It is about WHERE the eyebrow's text
     * comes from — a caller's string rather than `$product->categories`, which
     * is what keeps it off four pages' eager loads — and that is unchanged. So
     * the control is turned on and the shape is asserted where the element
     * exists; CardEqualHeightTest pins the default, in both directions.
     */
    app(\App\Services\SettingsService::class)->set('show_category', true);
    \App\Services\SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    $archive = optTile(optGet('/collections/opt-care/'), 'opt-eyebrow');
    $shop = optTile(optGet('/shop/'), 'opt-eyebrow');

    expect(str_contains($archive, '<div class="kbb-card-cat">OPT Care</div>'))
        ->toBeTrue('a category archive does not name its category on the tile');

    // /shop has no single category, so it says nothing rather than guessing.
    expect(str_contains($shop, 'kbb-card-cat'))
        ->toBeFalse('/shop invented an eyebrow for a listing that has no one category');

    $card = (string) file_get_contents(resource_path('views/components/product-card.blade.php'));
    $code = (string) preg_replace(['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s'], '', $card);

    expect(str_contains($code, '->categories'))
        ->toBeFalse('the tile reads the categories relation, which every caller now has to eager-load');
});

/* ══════════════════ 5 · the filter rail starts hidden ════════════════════ */

it('starts with the filter rail hidden on /shop and on every category archive', function () {
    /*
     * "keep off the left filters hidden by default."
     *
     * A VISIBLE CHANGE TO A WORKING PAGE, and the rule-1 exception: a default
     * the owner asked for in as many words. Both URLs, because
     * CategoryArchiveController delegates to ShopController::index() and a fix
     * applied to one view has to be checked on both routes.
     *
     * MUTATION: drop the @section('body-class') line from store/shop.blade.php
     * and both expectations are red.
     */
    optProduct('opt-filters');

    foreach (['/shop/', '/collections/opt-care/'] as $uri) {
        $html = optGet($uri);

        expect(preg_match('/<body class="[^"]*\bfilters-hidden\b/', $html))
            ->toBe(1, $uri.' does not start with the filter rail hidden');
    }
});

it('remembers that a shopper opened the filters, across the click that uses them', function () {
    // Lane SO: the filter rail is drawn only while Appearance → Site layout →
    // Product grid → "Filters · laptop" is on; it ships off, as the owner asked
    // ("remove the filter at all"). This test is about the rail, so it turns it on.
    app(\App\Services\SettingsService::class)->set('layout_filters_d', '1');
    \App\Services\SettingsService::forgetMemo();
    /*
     * A preference that resets on every navigation is worse than no preference:
     * a shopper who opens the filters, ticks a brand and lands on the filtered
     * page would find them shut again, every time.
     *
     * The cookie is read on the SERVER, so the class is in the <body> tag as it
     * is sent -- a script that adds it after load paints the 250px sidebar and
     * then takes it away.
     *
     * MUTATION: change the read to `request()->cookie('kbb_filters') !== 'shut'`
     * -- i.e. any value but one means open -- and the last expectation here is
     * red, because a forged or stale cookie would then open the rail.
     */
    optProduct('opt-filters-open');

    $open = optGet('/collections/opt-care/', ['kbb_filters' => 'open']);

    expect(preg_match('/<body class="[^"]*\bfilters-hidden\b/', $open))
        ->toBe(0, 'the rail stayed hidden for a shopper who had opened it');

    // Anything that is not the one value meaning "opened" is the shipped
    // default: no cookie, a stale value, a forged one.
    foreach (['', 'hidden', 'OPEN', '1', 'yes'] as $value) {
        $html = optGet('/collections/opt-care/', ['kbb_filters' => $value]);

        expect(preg_match('/<body class="[^"]*\bfilters-hidden\b/', $html))
            ->toBe(1, 'a cookie value of '.var_export($value, true).' opened the filter rail');
    }
});

it('writes the cookie in the same click that hides or shows the rail', function () {
    // Lane SO: the filter rail is drawn only while Appearance → Site layout →
    // Product grid → "Filters · laptop" is on; it ships off, as the owner asked
    // ("remove the filter at all"). This test is about the rail, so it turns it on.
    app(\App\Services\SettingsService::class)->set('layout_filters_d', '1');
    \App\Services\SettingsService::forgetMemo();
    /*
     * The class and the cookie have to move together or the preference is a
     * setting that never gets set. Asserted on the markup because there is no
     * browser here; docs/lane-pg-shots has the rendered evidence.
     *
     * MUTATION: delete either `document.cookie=` from store/shop.blade.php and
     * one of these is red.
     */
    optProduct('opt-filter-buttons');

    $html = optGet('/shop/');

    expect(str_contains($html, "classList.add('filters-hidden');document.cookie='kbb_filters=hidden;"))
        ->toBeTrue('Hide closes the rail without remembering it');
    expect(str_contains($html, "classList.remove('filters-hidden');document.cookie='kbb_filters=open;"))
        ->toBeTrue('Show opens the rail without remembering it');
});

it('leaves the way back into the filters impossible to miss at both widths', function () {
    // Lane SO: the filter rail is drawn only while Appearance → Site layout →
    // Product grid → "Filters · laptop" is on; it ships off, as the owner asked
    // ("remove the filter at all"). This test is about the rail, so it turns it on.
    app(\App\Services\SettingsService::class)->set('layout_filters_d', '1');
    app(\App\Services\SettingsService::class)->set('layout_filters_m', '1');   // and the phone's button: both entry points
    \App\Services\SettingsService::forgetMemo();
    /*
     * The rail starts hidden, so #showFilters is the only thing on a desktop
     * page saying that filtering exists at all. It was pink text on a
     * pink-tinted pill -- a quiet secondary control beside the sort box. It is
     * the page's one solid button now, at the 44px-ish target size the rest of
     * the shop uses, and it carries the number of filters currently applied.
     *
     * On a phone the rail is an off-canvas sheet behind `.mobi-filter`, which
     * was always visible and did not move; it gains the same count.
     *
     * MUTATION: put `#showFilters`'s background back to var(--pink-soft) and
     * the colour expectation is red.
     */
    optProduct('opt-show-button');

    $html = optGet('/shop/?filter_brands=opt-house');

    expect(str_contains($html, 'id="showFilters"'))
        ->toBeTrue('there is no way back into the filters on a desktop page');

    // The applied-filter count, on both entry points.
    expect(substr_count($html, '<span class="fcount">'))
        ->toBe(2, 'the filter count is missing from one of the two entry points');

    $button = optCssBlock('#showFilters', 'kbb-shop.css');

    expect($button)->not->toBe('', 'the #showFilters rule has gone from kbb-shop.css');
    expect(optCssValue($button, 'background'))->toBe('var(--pink)');
    expect(optCssValue($button, 'color'))->toBe('#fff');

    $minHeight = (int) optCssValue($button, 'min-height');
    expect($minHeight)->toBeGreaterThanOrEqual(40,
        'the one way back into the filters is below a comfortable touch target: '.$minHeight.'px');
});

/* ═══════════════ 6 · five columns on a desktop, two on a phone ═══════════ */

it('derives five columns at a desktop row and two at a phone row from the shipped numbers', function () {
    /*
     * THE COUNT IS ARITHMETIC, NOT A BREAKPOINT -- see kbb.css, which carries
     * the one `repeat(auto-fill, minmax(min(…),1fr))` every product grid uses.
     * This reproduces that arithmetic against the SHIPPED numbers, so a change
     * to either the tile minimum or the floor has to come past this file.
     *
     * The rows are the real ones, measured in Chromium and recorded in
     * docs/lane-pg-shots/measurements.json: 1203px for a page-width grid at a
     * 1280px screen, 346px at 390px.
     *
     * MUTATION: put `tile` back to 260 in SiteLayout::SCHEMA and the desktop
     * expectation is red with 4 -- which is the shop the owner asked to change.
     */
    $tile = \App\Services\SiteLayout::SCHEMA['tile'][2];
    $gap = \App\Services\SiteLayout::SCHEMA['gap'][2];
    $floor = \App\Services\SiteLayout::SCHEMA['cols_floor'][2];
    $cap = \App\Services\SiteLayout::SCHEMA['cols_cap'][2];

    $columns = static function (int $row) use ($tile, $gap, $floor, $cap): int {
        // The three terms of the track, innermost first — the same order as the
        // declaration in kbb.css.
        $track = min(
            $row,
            ($row - ($floor - 1) * $gap) / $floor,
            max($tile, ($row - ($cap - 1) * $gap) / $cap)
        );

        return max(1, (int) floor(($row + $gap) / ($track + $gap)));
    };

    expect($columns(1203))->toBe(5, 'a desktop row does not hold five columns');
    expect($columns(346))->toBe(2, 'a phone row does not hold two columns');

    // And the floor is what holds the phone, not the tile: at 346px two 220px
    // tiles do not fit, and the floor term is what puts them there anyway.
    expect($tile * 2 + $gap)->toBeGreaterThan(346);
});

it('keeps the shipped tile minimum identical in the stylesheet and the schema', function () {
    /*
     * The number has exactly two homes and they must agree, or the shop and the
     * slider that moves it disagree about what "default" means.
     * SiteLayoutDefaultsMatchCssTest already pins this in general; this states
     * the value itself, so a reader of THIS file can see which shop it is about.
     *
     * MUTATION: change either copy alone and this is red.
     */
    $css = (string) file_get_contents(base_path('resources/css/kbb/kbb.css'));

    expect(preg_match('/--kbb-tile\s*:\s*(\d+)px/', $css, $m))->toBe(1);
    expect((int) $m[1])->toBe(220);
    expect(\App\Services\SiteLayout::SCHEMA['tile'][2])->toBe(220);
});

it('leaves /shop the same side gutter as every other page', function () {
    /*
     * ── THE DEFECT, MEASURED IN CHROMIUM ON A CATEGORY ARCHIVE AT 1280 ─────
     *
     * `.shop` declared `padding:22px 0 60px`. The `0` in that shorthand is a
     * HORIZONTAL padding, and kbb-shop.css loads after kbb.css, so it beat
     * `.wrap{padding-inline:var(--site-gutter)}`: `.wrap.shop` computed
     * padding-left 0, the product grid ran 0..1280 while the <h1> above it
     * started at x=22, and the first and last tiles sat flush against the edges
     * of the window with the page's own heading inset from them.
     *
     * Pre-existing, and this lane owns it because this lane made it visible:
     * with the filter rail shown, the thing flush to the left edge was the
     * rail, and nobody reads a sidebar's gutter. Hiding the rail by default put
     * the product grid there instead.
     *
     * MUTATION: put `padding:22px 0 60px` back and this is red on the
     * shorthand; the browser evidence in docs/lane-pg-shots is where the tiles
     * can be seen touching the window.
     */
    $block = optCssBlock('.shop', 'kbb-shop.css');

    expect($block)->not->toBe('', 'the .shop rule has gone from kbb-shop.css');

    expect(optCssValue($block, 'padding-block'))
        ->toBe('22px 60px', '.shop must set only its block padding, or it overrides the site gutter');

    foreach (['padding', 'padding-inline', 'padding-left', 'padding-right',
              'padding-inline-start', 'padding-inline-end'] as $property) {
        expect(optCssValue($block, $property))
            ->toBeNull('.shop declares '.$property.', which overrides .wrap\'s side gutter on /shop and on every category archive');
    }
});

/* ═══════ 7 · the owner's follow-up: square thumbnails, and a control that
             can reach the page it sits above ═══════════════════════════════ */

it('draws the product photograph in an exactly square frame', function () {
    /*
     * ── THE OWNER, LOOKING AT A CATEGORY ARCHIVE ───────────────────────────
     *
     * "on the categories / shop page, the grid style is still coming different.
     *  i need the same, with square image thumbnail."
     *
     * TWO separate things made it not square, and both are fixed:
     *
     *  1. /shop and every category archive drew the OTHER card, whose frame was
     *     `.pc .ph{height:180px}` — a fixed 180px box inside a ~240px column,
     *     which is landscape. That card is gone; the one tile's frame is
     *     `.kbb-card-thumb`.
     *  2. The skinned grid's own frame was `aspect-ratio:var(--kbb-ratio,1/1.02)`
     *     — a shade TALLER than square. 1.02 is 2%, which is exactly the kind of
     *     not-quite that gets reported as "the grid style is still coming
     *     different". The fallback is 1/1 now, in both copies of that sheet, and
     *     Appearance → Product styles → Image shape ships at Square so the
     *     setting and the stylesheet agree.
     *
     * `object-fit:cover` is what makes it work for real photographs: a portrait
     * bottle and a landscape box both fill the same square, cropped, rather than
     * letterboxed into different heights.
     *
     * MUTATION: put either fallback back to 1/1.02, or ProductStyles'
     * `image_ratio` default back to 'portrait', and one expectation here is red.
     * The browser numbers are in docs/lane-pg-shots/measurements.json, where
     * every tile on every page reports width === height.
     */
    foreach (['kbb.css', 'kbb-grid-skins.css'] as $sheet) {
        $css = (string) file_get_contents(base_path('resources/css/kbb/'.$sheet));
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        expect(str_contains($css, '1/1.02'))
            ->toBeFalse($sheet.' still declares the 1/1.02 frame, which is not square');
    }

    // `1` and `1/1` are the same ratio; this sheet spells it both ways, and
    // the browser numbers below the assertion are what actually settle it.
    expect(optCssValue(optCssBlock('.kbb-card-thumb'), 'aspect-ratio'))
        ->toBeIn(['1', '1/1'], 'the tile frame is not square');
    expect(optCssValue(optCssBlock('.kbb-card-img'), 'object-fit'))
        ->toBe('cover', 'the photograph is letterboxed rather than filling its square');

    // And the owner's own control agrees with the sheet.
    expect(\App\Services\ProductStyles::SCHEMA['image_ratio'][2])->toBe('square');
    expect(app(\App\Services\ProductStyles::class)->cssVariables())->toContain('--kbb-ratio:1/1');
});

it('offers every column count the grid can show, including the default', function () {
    /*
     * ── A CONTROL THAT CANNOT REACH ITS OWN PAGE ───────────────────────────
     *
     * The toolbar's switcher offered 2, 3 and 4 while the grid derived FIVE.
     * Every position on it was a step DOWN from what the shopper was already
     * looking at, and there was no way back to the default except editing the
     * URL — a control lying about the page it sits above, which is the same
     * defect Lane W1 removed when it stopped highlighting "4" unconditionally.
     *
     * WIDENED RATHER THAN REMOVED, and that was the choice: the owner asked for
     * five AND uses these buttons, so taking the control away would answer half
     * his sentence by deleting the other half.
     *
     * MUTATION: drop '5' from Facets::columns()' allowlist and the pin stops
     * applying; drop the `#grid[data-cols="5"]` rule and the click changes
     * nothing on screen. Either is red here.
     */
    optProduct('opt-cols');

    $html = optGet('/shop/');

    foreach (['2', '3', '4', '5'] as $n) {
        expect(str_contains($html, 'data-c="'.$n.'"'))
            ->toBeTrue('the column switcher cannot offer '.$n.' columns');
    }

    /*
     * The URL is honoured, and the pin reaches the grid.
     *
     * `GridSkins::DEFAULT` AND NOT THE LITERAL `classic`. This case is about
     * `data-cols`; the skin is only in the needle because it sits between
     * `id="grid"` and it in the rendered attribute order. Lane PG2 moved the
     * shipped default to `showcase` — the owner asked for that in as many
     * words — and a literal here would have reddened a column-count pin over a
     * card-style change, which is the wrong test failing.
     */
    expect(str_contains(
        optGet('/shop/?cols=5'),
        'id="grid" data-skin="'.\App\Support\GridSkins::DEFAULT.'" data-cols="5"'
    ))->toBeTrue('?cols=5 does not pin the grid at five');

    // A value that is not on the list is not a pin at all — the automatic
    // answer, not a silent fall back to some other number.
    expect(str_contains(optGet('/shop/?cols=99'), 'data-cols'))
        ->toBeFalse('an unusable ?cols pinned the grid');

    $pin = optCssBlock('#grid[data-cols="5"]', 'kbb-shop.css');
    expect(optCssValue($pin, 'grid-template-columns'))->toBe('repeat(5,minmax(0,1fr))');
});

it('gives every grid on the shop the same pink Add to cart', function () {
    /*
     * The owner's second screenshot showed a near-black Add to cart on the
     * category page against the reference card's pink. That was never a colour
     * decision — it was `.addbtn{background:var(--ink)}` on the /shop card and
     * `.kbb-card-cart{background:#E0567B}` on the skinned one, two cards with
     * two buttons. One card, one button, one colour.
     *
     * Asserted on the CLASS rather than on a colour, because the colour is the
     * owner's (Appearance → Product styles → Cart button): what must be true is
     * that every grid reaches the same rule. Measured in Chromium at
     * rgb(224, 86, 123) on all twenty-one shots in docs/lane-pg-shots.
     *
     * MUTATION: give the tile `class="addbtn"` again and this is red.
     */
    optProduct('opt-button');

    foreach (['/shop/', '/collections/opt-care/', '/brands/opt-house/', '/'] as $uri) {
        $html = optGet($uri);

        expect(str_contains($html, 'class="kbb-card-cart'))
            ->toBeTrue($uri.' does not draw the one tile\'s Add to cart');
        expect(str_contains($html, 'class="addbtn'))
            ->toBeFalse($uri.' still draws the old card\'s button');
    }
});

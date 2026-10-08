<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\HomepageSections;
use App\Services\ProductStyles;
use App\Services\SettingsService;
use App\Support\GridSkins;

/**
 * =============================================================================
 * THE SHOWCASE CARD, AND THE DEFAULT THAT CARRIES IT EVERYWHERE      Lane PG2
 * =============================================================================
 *
 * ── WHAT THE OWNER ASKED FOR, VERBATIM ──────────────────────────────────────
 *
 * "I need to match the product grid design to the attached one, same mean
 *  everything same. and the rating rows will be additional, that's it. apply
 *  this design on the whole website everywhere. exept cart and checkout pages.
 *  keep this design by default from backend. this will apply for desktop and
 *  mobile both. but first give me previews more improved to choose from."
 *
 * ── THE FIVE DEFECTS PINNED HERE, EACH AS IT WOULD LOOK ON THE SHOP ─────────
 *
 *  1. THE DEFAULT IN FIVE PLACES. `grid_skin`'s shipped value is written in
 *     GridSkins, in ProductStyles' schema, in four rows of HomepageSections'
 *     registry and — until this lane — as the literal string 'classic' in three
 *     Blade templates. Moving one of them alone is a shop where the homepage
 *     rails, the wishlist or a curated collection keeps the OLD card while
 *     everything else moves: "everywhere", with one page left out, which is the
 *     exact complaint this work started from.
 *
 *  2. A GRID THIS LANE DID NOT KNOW ABOUT. The brief says to find every
 *     `.kbb-pgrid` site-wide and prove the list is complete rather than
 *     assuming it. A fifth emitter added later, pointed at a hard-coded skin,
 *     would be invisible until somebody looked at that page.
 *
 *  3. THE CARD REACHING THE BASKET AND THE CHECKOUT. He excluded those two
 *     pages by name. They draw no product grid today and this says so, because
 *     `.kbb-card` is ALSO the card-payment box on the checkout — the two share
 *     a class and only `.kbb-tile` tells them apart.
 *
 *  4. A SKIN IN ONE STYLESHEET AND NOT THE OTHER. kbb.css carries a SECOND
 *     COMPLETE COPY of kbb-grid-skins.css (its own comment says so), and only
 *     three storefront pages load the second sheet. A family written into one
 *     file draws on /shop and not on the homepage, or the other way round.
 *
 *  5. A SKIN THAT BREAKS A CONTROL THE OWNER ALREADY HAS. The seven `.pc-no*`
 *     switches under Appearance → Product styles → Card content hide parts of
 *     the card with `display:none` at TWO class weights. Any `display` on those
 *     elements from a skin block — three weights — wins, and "Brand name: off"
 *     silently stops working.
 *
 * ── MUTATION NOTES, ALL RUN ─────────────────────────────────────────────────
 *
 * Named case by case below. Each was made and reverted against this file.
 */

/* ───────────────────────────────── fixtures ──────────────────────────────── */

function dcsCss(string $file): string
{
    return (string) file_get_contents(base_path('resources/css/kbb/'.$file));
}

/** The showcase family block, cut out of a stylesheet by its own first and last rule. */
function dcsFamilyBlock(string $file): string
{
    $css = dcsCss($file);
    $start = strpos($css, '.kbb-pgrid[data-skin^="showcase"]{');

    return $start === false ? '' : substr($css, $start);
}

function dcsProduct(string $slug, array $extra = []): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'dcs-house'], ['name' => 'DCS House']);
    $category = Category::firstOrCreate(['slug' => 'dcs-care'], ['name' => 'DCS Care']);

    $product = Product::create(array_merge([
        'slug' => $slug,
        'name' => 'DCS '.$slug,
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

function dcsGet(string $uri): string
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    return (string) test()->get($uri)->assertOk()->getContent();
}

/* ═══════════ 1 · one default, and every place that states it agrees ═══════ */

it('states the shipped card style in five places and they all say the same thing', function () {
    /*
     * The five are listed in GridSkins::DEFAULT's own docblock, which is what a
     * reader looks at first. This is the instrument behind that list.
     *
     * MUTATION: put ProductStyles::SCHEMA['grid_skin']'s third element back to
     * the literal 'classic', or any one of the four HomepageSections rows back
     * to 'soft' / 'luxe' / 'ribbon', and this is red naming the one that
     * disagreed. RUN.
     */
    expect(GridSkins::exists(GridSkins::DEFAULT))
        ->toBeTrue('the shipped default is not a skin any stylesheet answers to');

    expect(ProductStyles::SCHEMA['grid_skin'][2])
        ->toBe(GridSkins::DEFAULT, 'Appearance → Product styles offers a different default from the resolver');

    foreach (['bundles', 'recommended', 'bestsellers', 'flash'] as $rail) {
        expect(HomepageSections::REGISTRY[$rail][3])
            ->toBe(GridSkins::DEFAULT, 'the homepage rail "'.$rail.'" ships a different card from the rest of the shop');
    }

    /*
     * And the three templates that used to name the OLD default as a literal.
     * They ask the resolver now, so a shop that has never saved `grid_skin` at
     * all — which is every shop until somebody opens the screen — still gets
     * the shipped card rather than the string that happened to be typed here.
     */
    foreach ([
        'partials/home/grid.blade.php',
        'store/wishlist.blade.php',
        'store/collection.blade.php',
    ] as $view) {
        $source = (string) file_get_contents(resource_path('views/'.$view));
        $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        expect(str_contains($code, 'GridSkins::resolve'))
            ->toBeTrue($view.' does not ask the resolver for the card style');
        expect(preg_match("/'classic'/", $code))
            ->toBe(0, $view.' still names a skin as a literal, so it cannot follow the default');
    }
});

/* ═══════════ 2 · every grid on the site, proved rather than assumed ═══════ */

it('has exactly four templates that open a product grid, and each takes its skin from the resolver', function () {
    /*
     * THE SOURCE, not a set of pages: a fifth grid can exist and simply not be
     * on a page a case happens to render. Comments are stripped first — six
     * files in this repository name `.kbb-pgrid` in prose, including the tile's
     * own docblock and this test's, and a scanner that reads prose reports
     * grids that do not exist.
     *
     * MUTATION: add `<div class="kbb-pgrid">` to any storefront template and
     * this is red with that file named. RUN.
     */
    $emitters = [];

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

        if (preg_match('/class="[^"]*\bkbb-pgrid\b/', $code)) {
            $emitters[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    sort($emitters);

    /*
     * THE ADMIN CONSOLE IS EXCLUDED and OneProductTileTest says why: its
     * skinCard() draws the same grid on purpose, as the live preview under
     * Appearance → Product styles. What this case is about is the STOREFRONT.
     */
    expect(array_values(array_filter(
        $emitters,
        static fn (string $f): bool => ! str_starts_with($f, 'resources/views/admin/')
    )))->toBe([
        'resources/views/components/product-grid.blade.php',
        'resources/views/partials/home/grid.blade.php',
        // ▲ MOVED, NOT ADDED (Lane PS; Lane RP; Lane RP2). The product page's
        // related rail left store/product.blade.php for its own partial when it
        // became the "You may also like" carousel, and since Lane RP2 all three
        // blocks at the foot of the page — brand, category, you may also like —
        // draw through this one partial: same tile, same `.rel.kbb-pgrid`,
        // same skin.
        'resources/views/partials/product/recs-block.blade.php',
        'resources/views/store/shop.blade.php',
    ], 'the list of product grids on this site has changed — every one of them has to take its skin from GridSkins::resolve()');

    // And each of the four asks the resolver rather than naming a skin.
    foreach ([
        'components/product-grid.blade.php' => '$skin',
        'partials/home/grid.blade.php' => 'GridSkins::resolve',
        'partials/product/recs-block.blade.php' => 'GridSkins::resolve',
        'store/shop.blade.php' => '$kbbSkin',
    ] as $view => $needle) {
        $code = (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            (string) file_get_contents(resource_path('views/'.$view))
        );

        expect(str_contains($code, $needle))
            ->toBeTrue($view.' no longer takes its skin from '.$needle);
    }
});

it('draws the shipped card on every page that has a product grid', function () {
    /*
     * The rendered proof behind the case above: the shop listing, a category
     * archive, a brand page, the homepage rails, the wishlist and the product
     * page's related rail all print the same `data-skin`.
     *
     * MUTATION: point any one of the four templates at a literal skin and this
     * is red for that page. RUN, against partials/home/grid.blade.php.
     */
    dcsProduct('dcs-everywhere');
    dcsProduct('dcs-related');

    $needle = 'data-skin="'.GridSkins::DEFAULT.'"';

    foreach ([
        '/shop/' => 'the shop listing',
        '/collections/dcs-care/' => 'a category archive',
        '/brands/dcs-house/' => 'a brand page',
        '/product/dcs-everywhere/' => "the product page's related rail",
    ] as $uri => $what) {
        expect(str_contains(dcsGet($uri), $needle))
            ->toBeTrue($what.' ('.$uri.') does not draw the shipped card style');
    }
});

/* ═══════════ 3 · and NOT on the two pages he excluded by name ═════════════ */

it('puts no product tile on the basket or the checkout', function () {
    /*
     * "apply this design on the whole website everywhere. exept cart and
     *  checkout pages."
     *
     * `.kbb-card` ALONE WOULD BE THE WRONG NEEDLE and that is the whole point
     * of this case: partials/checkout/stripe-card.blade.php uses the same class
     * for the card-payment box, and kbb.css — which the checkout loads — styles
     * `.kbb-card` unconditionally. The tile is `.kbb-card.kbb-tile`, which is
     * why the second class exists at all.
     *
     * MUTATION: put an <x-product-card> into store/cart.blade.php and this is
     * red; the payment box on the checkout keeps it green, which is the part
     * that matters.
     */
    dcsProduct('dcs-basket');

    foreach (['/cart/', '/checkout/success/'] as $uri) {
        $html = (string) test()->get($uri)->getContent();

        expect(str_contains($html, 'kbb-card kbb-tile'))
            ->toBeFalse($uri.' draws a product tile, and the owner excluded it by name');
        expect(str_contains($html, 'kbb-pgrid'))
            ->toBeFalse($uri.' opens a product grid, and the owner excluded it by name');
    }
});

/* ═══════════ 4 · the family is in BOTH stylesheets, identically ═══════════ */

it('writes the showcase family into both copies of the skin sheet', function () {
    /*
     * ── THE DEFECT, AND IT IS A REAL ONE IN THIS REPOSITORY ────────────────
     *
     * kbb.css carries a second complete copy of kbb-grid-skins.css — its own
     * comment at that point says so, and records that the duplication is how
     * "four column systems" turned out to be six. Only three storefront pages
     * @vite the separate sheet (home, wishlist, collection); /shop, every
     * category archive, every brand page and the product page's related rail
     * get their skins from the copy inside kbb.css and from nowhere else.
     *
     * So a skin written into one file and not the other is a card that draws on
     * some pages of the shop and not others — which is exactly the "everywhere"
     * this work is about.
     *
     * MUTATION: delete the family block from resources/css/kbb/kbb.css and this
     * is red; /shop then renders the showcase markup with the bare card's
     * styling, which is what the screenshot of it looks like. RUN.
     */
    foreach (['kbb-grid-skins.css', 'kbb.css'] as $file) {
        expect(dcsFamilyBlock($file))
            ->not->toBe('', $file.' carries no showcase family block at all');
    }

    expect(dcsFamilyBlock('kbb.css'))
        ->toBe(dcsFamilyBlock('kbb-grid-skins.css'),
            'the two copies of the showcase family have drifted — the shop would then look different on the pages that load the second sheet');

    /*
     * AND THE COPY IN kbb.css SITS AFTER THE `.kbb-tile` RULES, which is not
     * decoration: `.kbb-tile>.cb>.cp{margin-top:auto}` has the same specificity
     * as the family's own `.cp` rule, so whichever is written last wins. Before
     * the family sat at the end of the file the price line floated half way up
     * the card on /shop and sat on its foot on the homepage — the same skin,
     * two pages, two layouts.
     */
    $css = dcsCss('kbb.css');

    expect(strpos($css, '.kbb-pgrid[data-skin^="showcase"]{'))
        ->toBeGreaterThan((int) strpos($css, '.kbb-tile>.cb>.cp{margin-top:auto}'),
            'the showcase family is written before the .kbb-tile rules it has to win against');

    foreach ([
        'showcase' => 'the faithful treatment',
        'showcase-compact' => 'the tighter treatment',
        'showcase-row' => 'the price-beside-the-button treatment',
        'showcase-airy' => 'the airier treatment',
    ] as $skin => $what) {
        expect(GridSkins::exists($skin))->toBeTrue($what.' is not selectable');
        expect(str_starts_with($skin, 'showcase'))
            ->toBeTrue($what.' does not share the prefix the family block matches on');
    }
});

/* ═══════ 5 · the skin may not switch off a control the owner already has ═══ */

it('never declares display on the seven parts of the card the owner can hide', function () {
    /*
     * Appearance → Product styles → Card content hides parts of the card with
     * `.pc-nobrand .kbb-card-brand{display:none}` and six more like it — two
     * class weights. A skin block is three (`.kbb-pgrid[data-skin=…] .x`), so
     * ANY `display` it declares on one of those elements wins and the switch
     * stops working with no error anywhere.
     *
     * The family therefore changes size, colour, spacing and position on all
     * seven, and `display` on none of them. `.kbb-card-thumb` is the one
     * element it does take `display` on — `display:contents` is the whole
     * mechanism — and that element has no switch.
     *
     * MUTATION: add `display:block` to the family's `.kbb-card-brand` rule and
     * this is red naming it; on the shop, Brand name → off then does nothing.
     * RUN.
     */
    $hideable = [
        'kbb-card-brand' => 'Brand name',
        'kbb-card-cat' => 'Category label',
        'kbb-card-rate' => 'Stars and review count',
        'kbb-card-reg' => 'Was price',
        'kbb-badge-sale' => 'Discount badge',
        'kbb-badge-new' => 'New badge',
        'kbb-card-cart' => 'Add to cart button',
    ];

    foreach (['kbb-grid-skins.css', 'kbb.css'] as $file) {
        $block = (string) preg_replace('#/\*.*?\*/#s', '', dcsFamilyBlock($file));

        // Every rule in the family, as selector => declarations.
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $block, $rules, PREG_SET_ORDER);

        expect($rules)->not->toBe([], $file.': no rules were read out of the family block, so this check is blind');

        foreach ($rules as [, $selector, $declarations]) {
            foreach ($hideable as $class => $control) {
                if (! str_contains($selector, '.'.$class)) {
                    continue;
                }

                expect(preg_match('/(^|;)\s*display\s*:/', $declarations))
                    ->toBe(0, sprintf(
                        '%s: the rule for "%s" declares display, which outweighs the "%s" switch on Appearance → Product styles → Card content',
                        $file,
                        trim($selector),
                        $control
                    ));
            }
        }
    }
});

it('leaves every colour on the card reachable from Appearance → Product styles', function () {
    /*
     * The same argument one level along, for the six colour controls. The
     * family reads the owner's custom properties rather than painting over
     * them: the button is --kbb-cart-bg / --kbb-cart-fg, the stars are
     * --kbb-star, the badges are --kbb-sale / --kbb-new and the price is
     * --kbb-price.
     *
     * ONE COLOUR IS THE SKIN'S OWN AND IT IS NAMED HERE: the SALE price. The
     * owner's card prints the struck original in grey and the marked-down
     * figure in pink, and that is the `.kbb-card-reg + .kbb-card-price`
     * adjacent-sibling rule — it can only match a price that has a struck
     * original in front of it, so a product that is not on sale keeps the
     * colour the Price control sets.
     *
     * MUTATION: change the family's `.kbb-card-cart` background to a literal
     * and this is red; on the shop, Button background then moves nothing.
     */
    $block = (string) preg_replace('#/\*.*?\*/#s', '', dcsFamilyBlock('kbb-grid-skins.css'));

    /*
     * `str_contains()` INSIDE `toBeTrue()` AND NOT `toContain()`, and it is not
     * a style preference: Pest's `toContain(...$needles)` is variadic, so a
     * failure message passed as a second argument becomes a SECOND NEEDLE and
     * the case fails claiming the stylesheet does not contain the sentence
     * explaining what went wrong. Caught here, on this file's first run.
     */
    foreach ([
        'var(--kbb-cart-bg' => 'the button stopped reading the owner\'s button colour',
        'var(--kbb-cart-fg' => 'the button label stopped reading the owner\'s text colour',
        'var(--kbb-ratio' => 'the photograph stopped reading the owner\'s image shape',
        'var(--kbb-price' => 'the price stopped reading the owner\'s price colour',
        // The pink is on the SALE price only, which is what the sibling says.
        '.kbb-card-reg+.kbb-card-price{color:var(--sc-sale)}' => 'the pink is no longer confined to a price that has a struck original in front of it',
    ] as $needle => $why) {
        expect(str_contains($block, $needle))->toBeTrue($why.' — looked for '.var_export($needle, true));
    }
});

it('gives every selectable skin a swatch in the admin picker\'s stylesheet', function () {
    /*
     * ── WHY THIS IS ABOUT admin-skin-preview.css AND NOT ABOUT app.blade.php ─
     *
     * Appearance → Product styles draws a small sample card per skin so the
     * owner can see one before choosing it. Those swatches are styled by
     * `resources/css/kbb/admin-skin-preview.css`, and
     * `resources/views/admin/app.blade.php` carries a GENERATED COPY of that
     * file — which is the file the console actually loads, and which this lane
     * may not edit.
     *
     * So this case holds the half that is in this lane's hands: the SOURCE
     * sheet never falls behind `GridSkins::ALL`. A skin with no swatch rule is
     * an entry in the picker that draws the bare card — it does not break
     * anything and no other test notices, which is exactly why it wants one.
     * The generated copy is named as an integrator edit in
     * docs/PG2-SHOWCASE-CARD.md §8, with the line it goes after.
     *
     * A PREFIX SELECTOR COUNTS, because the showcase family is written as one:
     * `[data-skin^="showcase"]` styles all four of its members and an exact
     * rule for each would be four copies of the same block.
     *
     * MUTATION: add a row to GridSkins::ALL without a matching rule and this is
     * red naming it. RUN — and the first attempt at it, a throwaway
     * 'showcase-wide', stayed GREEN, because the family's own prefix selector
     * covers it. Which is the check working: a fifth showcase treatment really
     * does get a swatch for nothing. A name outside the family, 'lantern',
     * reddens it.
     */
    $css = (string) file_get_contents(base_path('resources/css/kbb/admin-skin-preview.css'));

    preg_match_all('/\[data-skin(\^?)="([a-z0-9-]+)"\]/', $css, $m, PREG_SET_ORDER);

    expect($m)->not->toBe([], 'no skin selectors were read out of admin-skin-preview.css, so this check is blind');

    $unstyled = [];

    foreach (array_keys(GridSkins::ALL) as $skin) {
        $styled = false;

        foreach ($m as [, $prefix, $name]) {
            if ($prefix === '^' ? str_starts_with($skin, $name) : $skin === $name) {
                $styled = true;

                break;
            }
        }

        if (! $styled) {
            $unstyled[] = $skin;
        }
    }

    expect($unstyled)->toBe([],
        'these skins draw the bare card in the admin picker, so the owner would be choosing between swatches that look the same: '
        . implode(', ', $unstyled));
});

/* ═══════════ 6 · the rating row is an ADDITION, not a removal ═════════════ */

it('draws no rating row for an unreviewed product under the new default either', function () {
    /*
     * ▲ The owner: the rating rows "will be additional". His screenshot has no
     * rating row because that product has no reviews, and this shop has drawn
     * nothing at all for an unreviewed product since the round that built the
     * tile — a behaviour he asked for in as many words then. Changing the card
     * style must not quietly bring the five empty stars and the "(0)" back.
     *
     * OneProductTileTest pins this for the tile; this pins it for the card
     * style, because a skin CAN put the row back with one `display` (see the
     * case above) and the two facts would then disagree.
     *
     * MUTATION: drop the `@if ($rc > 0)` from components/product-card.blade.php
     * and this is red for the unreviewed product. RUN.
     */
    dcsProduct('dcs-unrated');
    dcsProduct('dcs-rated', ['rating' => 4.0, 'review_count' => 17]);

    $html = dcsGet('/collections/dcs-care/');

    $tile = static function (string $slug) use ($html): string {
        foreach (array_slice(explode('<div class="kbb-card kbb-tile">', $html), 1) as $one) {
            if (str_contains($one, '/product/'.$slug.'/')) {
                return $one;
            }
        }

        return '';
    };

    expect($tile('dcs-unrated'))->not->toBe('', 'the unreviewed product is not on the page');
    expect(str_contains($tile('dcs-unrated'), 'kbb-card-rate'))
        ->toBeFalse('an unreviewed product drew a rating row, which reads as "rated badly"');
    expect(str_contains($tile('dcs-rated'), 'kbb-card-rate'))
        ->toBeTrue('a reviewed product lost its rating row');

    // And the new default is what drew them.
    expect(str_contains($html, 'data-skin="'.GridSkins::DEFAULT.'"'))->toBeTrue();
});

/* ═══════════ 7 · sized with calc(), measured by nothing ══════════════════ */

it('adds no layout measurement to the shop for the sake of the new card', function () {
    /*
     * Rule 4: this project sizes with calc() and two tests already forbid the
     * element-measuring APIs by name. The showcase family is CSS — a grid, a
     * `display:contents`, a `line-height` that IS the button's height and one
     * `:has()` — so nothing about it needs a script, and this states that the
     * card and the grid partials still carry none.
     *
     * MUTATION: add a getBoundingClientRect() call to the tile and this is red.
     */
    foreach ([
        'components/product-card.blade.php',
        'components/product-grid.blade.php',
        'partials/home/grid.blade.php',
    ] as $view) {
        $source = (string) file_get_contents(resource_path('views/'.$view));

        foreach (['getBoundingClientRect', 'offsetHeight', 'clientHeight', 'getComputedStyle', 'offsetWidth'] as $api) {
            expect(str_contains($source, $api))
                ->toBeFalse($view.' measures layout in JavaScript: '.$api);
        }
    }
});

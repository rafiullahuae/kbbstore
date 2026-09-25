<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * A markdown on a variable product was invisible to the entire shop.
 *
 * ── THE DEFECT, ON THE SHOP ─────────────────────────────────────────────────
 *
 * A variable product's markdown has exactly one place it can live, and it is
 * not the parent row. WooCommerce keeps the money on the VARIATIONS:
 * `products.price` is NULL on the parent, each `product_variants.sale_price`
 * carries the markdown, and the window that opens and closes it is scheduled
 * once on the parent (`product_variants` has no date columns at all — see
 * ProductVariant::effectivePrice() and Import\Entities\VariationImporter::
 * reportSaleWindow()). The parent cannot even carry a markdown of its own:
 * ProductImporter REJECTS a row with a `sale_price` and an empty
 * `regular_price`.
 *
 * Product::isOnSale() was `effectivePrice() < (int) $this->price`, and
 * `(int) null === 0`. Nothing is cheaper than nothing, so it answered FALSE for
 * every variable product in the catalogue whatever its variations charged. The
 * "On sale" facet asked the same question in SQL against the same NULL column,
 * and every comparison against NULL is NULL, so it matched none of them either.
 *
 * The result on the live shop, measured before this change on a parent whose
 * two options are AED 120 and AED 190 and which is marked down to AED 90–140:
 *
 *   - the tile printed "AED 90 – AED 140" with NO badge,
 *   - the product page headline printed the same range with no <s> and no -%,
 *   - /shop?sale=1 did not list it,
 *   - and the /shop price facet and sort filed it correctly at AED 90.
 *
 * So the price moved and not one surface said why. Every surface agreed with
 * every other — the shop was consistent and consistently silent — which is why
 * nothing caught it: the instrument this codebase uses for pricing defects is
 * "do two surfaces disagree", and here they did not.
 *
 * ── WHAT THE COMPARE-AT IS, AND WHY IT IS MIN(regular) ──────────────────────
 *
 * "A parent has no compare-at price" is true of the ROW and false of the
 * PRODUCT. Every surface here collapses a variable product to one number — the
 * from-price, MIN(charged) — so the figure it must be compared against is the
 * from-price of the same product with the sale switched off, which is
 * MIN(regular): literally the number the tile printed the day before the
 * markdown started. Product::compareAtPrice() and
 * VariantPricing::regularLow() are that figure; App\Support\EffectivePrice::
 * regularSql() is the same expression in SQL so the facet cannot drift from
 * the badge.
 *
 * ── MUTATION NOTES, ALL RUN ─────────────────────────────────────────────────
 *
 * 1. Put Product::compareAtPrice() back to `return (int) $this->price;`
 *    (dropping the VariantPricing call). RUN: 8 failed of 15 — every case below
 *    that asserts a compare-at, a badge, a percentage, a strikethrough, facet
 *    membership or a priceValidUntil for a variable product, which is the shop
 *    exactly as it shipped.
 * 2. Put EffectivePrice::whereOnSale()'s right-hand side back to the bare
 *    `$table.'.price'` column, leaving the PHP half fixed. RUN: 3 failed —
 *    'lists it under On sale', 'keeps the facet and the badge saying the same
 *    thing', and the budget case, which fails only because an empty facet never
 *    reaches a tile. The SQL half is therefore asserted separately from the PHP
 *    half rather than one standing in for the other.
 * 3. In VariantPricing::load(), change `MIN(v.price) as reg` to
 *    `MIN(v.sale_price) as reg`. RUN: 7 failed, including 'measures the saving
 *    against the from-price it replaced' with a compare-at of 4000 against the
 *    5000 that was really on the tile.
 * 4. In VariantPricing::load(), cast the aggregate unconditionally
 *    (`(int) $row->reg` in place of the null test). RUN: 1 failed — 'refuses to
 *    invent a sale when no variation carries a regular price', which is the row
 *    that would otherwise be advertised as marked down FROM AED 0.
 * 5. Put any of the three struck-price sites back to `(int) $…->price` —
 *    store/product.blade.php's $kbbWas, partials/quick-view.blade.php's
 *    $kbbQvWas, partials/checkout/browsed-item.blade.php's $kbbBWas. RUN: 1
 *    failed each, 'never prints AED 0…', and the product-page one printed
 *    exactly `AED 90 – AED 140 AED 0 -25%`. That is the regression this change
 *    would have shipped without those three lines: isOnSale() telling the truth
 *    turns on three strikethroughs that all read the NULL parent column.
 *
 * ▲ ONE MUTATION THAT DOES NOT GO RED, recorded because a claim nobody can
 * check is the thing rule 6 is against. Dropping the null test from isOnSale()
 * (`return $this->effectivePrice() < (int) $this->compareAtPrice();`) leaves
 * all 15 green, and that is correct rather than a gap: `(int) null` is 0 and no
 * price in this shop is negative, so the two spellings cannot differ in their
 * answer. The null test is kept because it stops "no compare-at" and "a
 * compare-at of nothing" being the same expression — the exact conflation
 * ownPrice() was extracted from effectivePrice() to undo — and mutation 4 above
 * is what actually holds that distinction in place.
 */

/** A visible variable parent with NO price of its own, which is the real shape. */
function svParent(string $slug, array $extra = []): Product
{
    return Product::create(array_merge([
        'slug' => $slug,
        'name' => 'CUSHION ' . strtoupper($slug),
        'status' => 'publish',
        'is_visible' => true,
        'price' => null,
        'stock_status' => 'instock',
        'type' => 'variable',
    ], $extra));
}

function svVariant(Product $parent, ?int $price, ?int $salePrice = null): ProductVariant
{
    return ProductVariant::create([
        'product_id' => $parent->id,
        'price' => $price,
        'sale_price' => $salePrice,
        'stock_status' => 'instock',
    ]);
}

function svSimple(string $slug, int $price, ?int $salePrice = null): Product
{
    return Product::create([
        'slug' => $slug,
        'name' => 'TONER ' . strtoupper($slug),
        'status' => 'publish',
        'is_visible' => true,
        'price' => $price,
        'sale_price' => $salePrice,
        'stock_status' => 'instock',
        'type' => 'simple',
    ]);
}

/**
 * A variable parent whose options are AED 120 and AED 190, marked down to
 * AED 90 and AED 140 with an open window. Its from-price is 9000 and the
 * from-price it replaced is 12000 — a 25% saving.
 */
function svMarkedDown(string $slug = 'sv-cushion'): Product
{
    $parent = svParent($slug, [
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addWeek(),
    ]);

    svVariant($parent, 12000, 9000);
    svVariant($parent, 19000, 14000);

    return $parent;
}

/** The whole rendered /shop page, with both memos dropped first. */
function svShop(string $query = ''): string
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    return test()->get('/shop/' . $query)->assertOk()->getContent();
}

/** The one `.pc` tile block for $slug, or '' when the page has no such tile. */
function svTile(string $html, string $slug): string
{
    foreach (preg_split('#<div class="pc">#', $html) as $card) {
        if (str_contains($card, '/product/' . $slug . '/')) {
            return $card;
        }
    }

    return '';
}

/** Plain text of one element of the tile for $slug. */
function svCell(string $html, string $slug, string $pattern): string
{
    return preg_match($pattern, svTile($html, $slug), $m)
        ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'))
        : '';
}

/* ───────────────────────────── the model ─────────────────────────────────── */

it('sees a markdown that lives on the variations', function () {
    // THE DEFECT: isOnSale() compared against `products.price`, which is NULL
    // on a variable parent, so `9000 < 0` was false and the shop drew no badge
    // for a product whose price had just dropped by a quarter.
    $parent = svMarkedDown()->fresh();

    expect($parent->effectivePrice())->toBe(9000)
        ->and($parent->compareAtPrice())->toBe(12000)
        ->and($parent->isOnSale())->toBeTrue()
        ->and($parent->discountPercent())->toBe(25);
});

it('measures the saving against the from-price it replaced', function () {
    /*
     * MIN(regular), NOT "the regular price of whichever option is cheapest now",
     * and the two differ here by 40 percentage points.
     *
     * Options at (regular 100, sale 40) and (regular 50, no sale): the tile said
     * "from AED 50" yesterday and says "from AED 40" today, so the saving is
     * AED 10 — 20%. Reading the cheapest option's own regular price gives AED
     * 100 and advertises 60% off, a saving no shopper is getting.
     */
    $parent = svParent('sv-mixed', ['sale_starts_at' => now()->subDay()]);
    svVariant($parent, 10000, 4000);
    svVariant($parent, 5000);

    $parent = $parent->fresh();

    expect($parent->effectivePrice())->toBe(4000)
        ->and($parent->compareAtPrice())->toBe(5000)
        ->and($parent->discountPercent())->toBe(20);
});

it('still invents no sale on a variable product that has none', function () {
    // The pin the previous round left, kept verbatim in intent: deriving a
    // from-price must not make a product look marked down.
    $parent = svParent('sv-plain');
    svVariant($parent, 12000);
    svVariant($parent, 19000);

    $parent = $parent->fresh();

    expect($parent->compareAtPrice())->toBe(12000)
        ->and($parent->isOnSale())->toBeFalse()
        ->and($parent->discountPercent())->toBe(0);
});

it('honours the parent window, which is the only window there is', function () {
    // product_variants has no date columns. A markdown outside the parent's
    // window is not a markdown, and the badge has to agree with the price.
    $shut = svParent('sv-shut', [
        'sale_starts_at' => now()->subMonth(),
        'sale_ends_at' => now()->subDay(),
    ]);
    svVariant($shut, 12000, 9000);

    $early = svParent('sv-early', ['sale_starts_at' => now()->addWeek()]);
    svVariant($early, 12000, 9000);

    expect($shut->fresh()->isOnSale())->toBeFalse()
        ->and($shut->fresh()->effectivePrice())->toBe(12000)
        ->and($early->fresh()->isOnSale())->toBeFalse()
        ->and($early->fresh()->effectivePrice())->toBe(12000);
});

it('refuses to invent a sale when no variation carries a regular price', function () {
    // Variations priced by `sale_price` alone: there is a charged figure and no
    // compare-at anywhere. `(int) null` would make that compare-at AED 0 and
    // advertise a markdown UP from nothing. Null means "none I can vouch for",
    // which is the same direction advertisedSalePrice() takes for an unselected
    // sale window.
    $parent = svParent('sv-noregular', ['sale_starts_at' => now()->subDay()]);
    svVariant($parent, null, 9000);

    $parent = $parent->fresh();

    expect($parent->effectivePrice())->toBe(9000)
        ->and($parent->compareAtPrice())->toBeNull()
        ->and($parent->isOnSale())->toBeFalse()
        ->and($parent->discountPercent())->toBe(0);
});

it('leaves every product that has a price of its own exactly as it was', function () {
    // compareAtPrice() returns `(int) $this->price` unconditionally when the
    // column is there, so a simple product takes the path it always took.
    $sale = svSimple('sv-simple-sale', 20000, 5000);
    $full = svSimple('sv-simple-full', 20000);

    expect($sale->compareAtPrice())->toBe(20000)
        ->and($sale->isOnSale())->toBeTrue()
        ->and($sale->discountPercent())->toBe(75)
        ->and($full->compareAtPrice())->toBe(20000)
        ->and($full->isOnSale())->toBeFalse();
});

it('refuses to answer for a row whose shape it cannot see', function () {
    // Api\ProductController::INDEX_COLUMNS is a real narrowed SELECT on an
    // unauthenticated endpoint. A model without `price` and without `type`
    // must not acquire a compare-at the query never asked about.
    svMarkedDown('sv-narrow');

    $narrow = Product::query()->where('slug', 'sv-narrow')->select(['id', 'slug'])->first();

    expect($narrow->compareAtPrice())->toBeNull()
        ->and($narrow->isOnSale())->toBeFalse();
});

/* ───────────────────────────── the facet ─────────────────────────────────── */

it('lists it under On sale', function () {
    // EffectivePrice::whereOnSale() compared against the bare `price` column,
    // which is NULL here, and every comparison against NULL is NULL — so the
    // facet the owner points at discounted stock contained none of it.
    svMarkedDown();
    svSimple('sv-facet-full', 20000);

    $slugs = test()->get('/shop/?sale=1')->assertOk()
        ->original->getData()['products']->pluck('slug')->all();

    expect($slugs)->toContain('sv-cushion')
        ->and($slugs)->not->toContain('sv-facet-full');
});

it('keeps a closed window out of the On sale facet, exactly as the badge does', function () {
    $shut = svParent('sv-facet-shut', [
        'sale_starts_at' => now()->subMonth(),
        'sale_ends_at' => now()->subDay(),
    ]);
    svVariant($shut, 12000, 9000);

    $slugs = test()->get('/shop/?sale=1')->assertOk()
        ->original->getData()['products']->pluck('slug')->all();

    expect($slugs)->not->toContain('sv-facet-shut');
});

it('keeps the simple products in that facet exactly as they were', function () {
    // regularSql() COALESCEs to `products.price` for anything with a price of
    // its own, so this half of the facet is the expression it always was.
    svSimple('sv-facet-down', 20000, 5000);
    svSimple('sv-facet-plain', 20000);
    svSimple('sv-facet-future', 20000, 5000)->update(['sale_starts_at' => now()->addWeek()]);

    $slugs = test()->get('/shop/?sale=1')->assertOk()
        ->original->getData()['products']->pluck('slug')->all();

    expect($slugs)->toContain('sv-facet-down')
        ->and($slugs)->not->toContain('sv-facet-plain')
        ->and($slugs)->not->toContain('sv-facet-future');
});

/* ─────────────────────────── the shop, rendered ──────────────────────────── */

it('keeps the facet and the badge saying the same thing', function () {
    /*
     * THE ONE INVARIANT THIS LANE IS ABOUT. A product listed under "On sale"
     * whose tile carries no sale badge is the disagreement EffectivePrice's
     * header was written about; a badge on a product the facet excludes is the
     * same defect from the other side. Asserted through the rendered page,
     * because the badge a shopper reads is the one that has to agree.
     */
    svMarkedDown();

    $html = svShop('?sale=1');

    expect(svTile($html, 'sv-cushion'))->not->toBe('', 'the facet dropped the product this case is about');
    expect(svCell($html, 'sv-cushion', '#<span class="lbl"[^>]*>(.*?)</span>#s'))->toBe('-25% OFF');
    // And the price cell still prints the RANGE, which is what it printed
    // before: a badge is added beside it, nothing is replaced.
    expect(svCell($html, 'sv-cushion', '#<div class="cprice">(.*?)</div>#s'))->toBe('AED 90 – AED 140');
});

it('draws no sale badge on a variable product that is not marked down', function () {
    $parent = svParent('sv-tile-plain');
    svVariant($parent, 12000);
    svVariant($parent, 19000);

    $html = svShop();

    expect(svCell($html, 'sv-tile-plain', '#<span class="lbl"[^>]*>(.*?)</span>#s'))->toBe('');
    expect(svCell($html, 'sv-tile-plain', '#<div class="cprice">(.*?)</div>#s'))->toBe('AED 120 – AED 190');
});

it('never prints AED 0 as the price a variable product was marked down from', function () {
    /*
     * THE REGRESSION THIS CHANGE WOULD HAVE SHIPPED. Every struck-through "was"
     * on this shop was `(int) $product->price`, which is 0 on a variable
     * parent. The moment isOnSale() started answering true for one, the product
     * page's <s>, the quick-view modal's <del> and the checkout's "you were
     * looking at" strip would each have quoted a markdown FROM NOTHING beside a
     * real from-price. Product::compareAtPrice() is what all of them read now.
     */
    $parent = svMarkedDown();

    /* 1. the product page's <s>. */
    $page = test()->get('/product/sv-cushion/')->assertOk()->getContent();

    expect($page)->not->toContain('AED 0');

    preg_match('#<div class="bb-price" id="bbPrice">(.*?)</div>#s', $page, $m);
    $headline = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1] ?? ''), ENT_QUOTES, 'UTF-8')));

    // The range, the price it replaced, and the saving — the same three figures
    // the tile carries, in the same words.
    expect($headline)->toContain('AED 90 – AED 140')
        ->and($headline)->toContain('AED 120')
        ->and($headline)->toContain('25%');

    /* 2. the quick-view modal's <del>, which is a JSON route rather than a page. */
    $modal = test()->getJson('/quick-view/' . $parent->id)->assertOk()->json('html');

    preg_match('#<div class="qv-price">(.*?)</div>#s', (string) $modal, $q);
    $qv = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($q[1] ?? ''), ENT_QUOTES, 'UTF-8')));

    expect($qv)->toBe('AED 120 AED 90 -25%');

    /* 3. the checkout's "you were looking at" strip, rendered directly: the
          partial takes one variable and reaching it through the page needs a
          basket and a browsing cookie, neither of which this case is about. */
    $strip = view('partials.checkout.browsed-item', ['bp' => $parent->fresh()])->render();

    preg_match('#<div class="bp">(.*?)</div>#s', $strip, $b);
    $browsed = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($b[1] ?? ''), ENT_QUOTES, 'UTF-8')));

    expect($browsed)->toBe('AED 120AED 90');
});

it('tells Google when the discounted price stops applying', function () {
    /*
     * THE STRUCTURED DATA FOLLOWS isOnSale(), AND IT WAS FOLLOWING IT WRONG.
     *
     * Store\ProductController publishes `sale_ends_at` only when
     * `$product->isOnSale()`, because Seo reads it as `priceValidUntil` and a
     * date already in the past is worse than no date at all — Google treats an
     * elapsed priceValidUntil as an expired offer and drops the price from the
     * rich result. That guard is right, and on a variable product it was always
     * false, so the AggregateOffer for a genuinely marked-down product said
     * nothing about when the markdown ends.
     *
     * The lowPrice/highPrice pair was already correct — it is built from
     * ProductVariant::effectivePrice(), which honours the parent window — so
     * this was the one field in that document the parent-row defect reached.
     *
     * MUTATION: put Product::compareAtPrice() back to `(int) $this->price`.
     * RUN: red here, with no priceValidUntil on the node at all.
     */
    $parent = svMarkedDown();

    $html = test()->get('/product/sv-cushion/')->assertOk()->getContent();

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $blocks);

    $offer = null;

    foreach ($blocks[1] as $raw) {
        $node = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (($node['@type'] ?? null) === 'Product') {
            $offer = $node['offers'] ?? null;
        }
    }

    expect($offer)->toBeArray()
        ->and($offer['@type'])->toBe('AggregateOffer')
        // The range the variations charge, which was already right.
        ->and($offer['lowPrice'])->toBe('90.00')
        ->and($offer['highPrice'])->toBe('140.00')
        // And now the date the markdown ends, which was absent.
        ->and($offer['priceValidUntil'] ?? null)
        ->toBe($parent->sale_ends_at->toDateString());
});

/* ──────────────────────────────── rule 4 ─────────────────────────────────── */

it('costs no extra statement for knowing a variable product is on sale', function () {
    /*
     * RULE 4, MEASURED. compareAtPrice() reads MIN(v.price) off the SAME
     * grouped row VariantPricing::load() already fetched for the range, so a
     * page that prints a badge costs exactly what the page that printed the
     * range alone cost. Deriving it per product would be one statement per tile
     * on /shop, which is the N+1 this area is already pinned against.
     *
     * ▲ COUNTED AS "TIMES THE GROUPED READ RAN", NOT AS A TOTAL, and the first
     * draft of this case got that wrong TWICE — both worth recording, because
     * both failures read like an N+1 and neither was one.
     *
     * The total rose from 4 statements to 6 between one product and twelve:
     * /shop draws its category and brand sidebar from two `having
     * products_count > ?` queries that are skipped entirely while the listing
     * is too small to have a sidebar, so a total measures how many facets the
     * page decided to draw as well as what the tiles cost.
     *
     * Counting every statement that mentions `product_variants` then answered
     * 3, not 1, and that is not a defect either: whereOnSale() puts a
     * correlated subquery over that table into the listing query AND into the
     * `count(*)` beside it, which is two statements the page runs once each
     * however many rows come back. The number this lane is answerable for is
     * how many times VariantPricing::load()'s GROUP BY runs, and that is one,
     * for any number of tiles.
     *
     * ONE WARM-UP RENDER FIRST: Setting::map() memoises in a process-level
     * static as well as the cache, so the first page of a process is dearer
     * than every later one (CLAUDE.md names this trap).
     *
     * MEASURED: the grouped read runs once with one marked-down variable
     * product on the page and once with twelve. (Totals, for the record: 4
     * statements for one, 6 for twelve, the two extra being the sidebar.)
     *
     * MUTATION: in VariantPricing::entry(), reload on every call (`$this->
     * entries = $this->load();` unconditionally). RUN: 1 failed here — the
     * grouped read ran 24 times against 1, twice per tile.
     */
    $groupedReads = function (): int {
        SettingsService::forgetMemo();
        app()->forgetScopedInstances();

        $n = 0;
        DB::listen(function ($event) use (&$n): void {
            // VariantPricing::load()'s SELECT list names this alias and
            // nothing else in the application does.
            if (str_contains($event->sql, 'v.product_id as pid')) {
                $n++;
            }
        });
        test()->get('/shop/?sale=1')->assertOk();

        return $n;
    };

    Product::query()->delete();
    svMarkedDown('sv-cost-1');

    $groupedReads();            // warm-up, discarded
    $one = $groupedReads();

    foreach (range(2, 12) as $i) {
        svMarkedDown('sv-cost-' . $i);
    }

    $many = $groupedReads();

    expect($one)->toBe(1, "one marked-down variable product ran the grouped read {$one} times");
    expect($many)->toBe(
        1,
        "twelve marked-down variable products ran the grouped read {$many} times — "
        .'the range and its compare-at are being derived per row'
    );
});

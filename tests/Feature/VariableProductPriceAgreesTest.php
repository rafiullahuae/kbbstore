<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartService;
use App\Services\SettingsService;
use App\Services\VariantPricing;
use App\Services\VariantRequired;
use App\Support\CollectionSchema;
use App\Support\EffectivePrice;
use Illuminate\Support\Facades\DB;

/**
 * Lane Q5 — the whole shop agrees on what a variable product costs.
 *
 * ── THE DEFECT, AS IT WAS ON THE LIVE SHOP ──────────────────────────────────
 *
 * A WooCommerce variable product keeps its money on its VARIATIONS. The parent
 * row's `products.price` is genuinely NULL and every real figure lives in
 * `product_variants`. Four readers took that column directly and each turned
 * the NULL into a different wrong answer, because PHP and SQL disagree about
 * what a NULL is:
 *
 *   1. Product::effectivePrice() ended `return (int) $this->price`, and
 *      `(int) null === 0`, so the model answered **0 fils**.
 *   2. App\Support\CollectionSchema publishes effectivePrice() into the ItemList
 *      JSON-LD of every /shop, category and brand page — so GOOGLE was handed
 *      `"price": "0.00"` for every variable product, while the product page
 *      beside it published a correct AggregateOffer from the same variations.
 *      Two documents on one site, contradicting each other about money.
 *   3. The price SORT (EffectivePrice::orderBy, /shop?orderby=plow) ordered on
 *      the raw column. NULL sorts FIRST ascending on both SQLite and MySQL, so
 *      "Price: low to high" opened with every variable product in the shop,
 *      ahead of genuinely cheap stock. MEASURED BEFORE THE FIX: a parent whose
 *      options run AED 120–190 sorted ahead of an AED 30 toner.
 *   4. The price FACET (EffectivePrice::whereRange) bucketed on it. Every
 *      comparison against NULL is NULL, which is not true, so a variable
 *      product fell into NO bucket at all. MEASURED BEFORE THE FIX: that same
 *      parent was absent from "Under AED 54", "AED 54 – 150", "AED 150 – 300"
 *      AND "AED 300+" — touching the price filter made it vanish from the shop.
 *
 * And Product::toApi() published `price: null` on the unauthenticated feed
 * while the tile beside it printed a range.
 *
 * ── WHAT THIS FILE PINS ─────────────────────────────────────────────────────
 *
 * Not "the number is no longer zero", which a hardcoded constant would satisfy.
 * The property is AGREEMENT: the tile, the product-page headline, the model,
 * the listing JSON-LD, the sort and the facet all resolve to the SAME figure,
 * derived from the same variations — the low end of the range, which is the
 * "from" price WooCommerce shows, the `lowPrice` Seo::aggregateOffer()
 * publishes, and the only sensible collapse of a range into the one number an
 * ORDER BY or a bucket can hold.
 *
 * ── MUTATION NOTES, EACH ONE ACTUALLY RUN ───────────────────────────────────
 *
 * 1. In App\Support\EffectivePrice, make sql() return `self::ownSql($table)`
 *    alone (dropping the COALESCE) and bindings() return the two-value window
 *    again. RUN: 3 failed in this file — 'files it by what it really costs'
 *    with the variable parent back at the head of the cheapest-first list,
 *    'files it in the bucket its from-price belongs to' with the parent in no
 *    bucket at all, and 'gives the tile, the model, the sort and the facet one
 *    answer' where the SQL half stops matching the PHP half. The model half
 *    stays green, which is the point: this mutation reverts exactly the SQL
 *    half and exactly the SQL cases go red.
 *
 * 2. In App\Models\Product::effectivePrice(), replace the VariantPricing lookup
 *    with `return 0;` — the PHP half reverted. RUN: 6 failed — 'answers the
 *    from-price, not zero', 'honours the parent sale window when deriving',
 *    'stops telling Google the product is free' (red with "0.00", the exact
 *    string the shop published to crawlers), 'quotes the from-price on the
 *    public feed rather than nothing', 'gives the tile, the model, the sort
 *    and the facet one answer', plus 'it now has the model agreeing with the
 *    tile as well' in VariableProductTilePriceTest.
 *
 * 3. In App\Models\Product::ownPrice(), put the three `$this->price === null ?
 *    null :` guards back to a bare `(int) $this->price`. RUN: the same 6 —
 *    because the cast is what conflates "no price" with "a price of zero", so
 *    restoring it makes ownPrice() answer 0 and effectivePrice() never reaches
 *    the derivation at all. Two different one-line reverts, one behaviour.
 */

function q5Parent(string $slug, array $extra = []): Product
{
    return Product::create(array_merge([
        'slug' => $slug,
        'name' => 'Cushion ' . $slug,
        'status' => 'publish',
        'is_visible' => true,
        'price' => null,
        'stock_status' => 'instock',
        'type' => 'variable',
    ], $extra));
}

function q5Variant(Product $parent, ?int $price, ?int $salePrice = null): ProductVariant
{
    return ProductVariant::create([
        'product_id' => $parent->id,
        'price' => $price,
        'sale_price' => $salePrice,
        'stock_status' => 'instock',
    ]);
}

function q5Simple(string $slug, int $price): Product
{
    return Product::create([
        'slug' => $slug,
        'name' => 'Toner ' . $slug,
        'status' => 'publish',
        'is_visible' => true,
        'price' => $price,
        'stock_status' => 'instock',
        'type' => 'simple',
    ]);
}

/** The catalogue every ordering case below reads: 30, [120–190], 500. */
function q5Catalogue(): Product
{
    $parent = q5Parent('q5-cushion');
    q5Variant($parent, 19000);
    q5Variant($parent, 12000);

    q5Simple('q5-cheap', 3000);
    q5Simple('q5-dear', 50000);

    return $parent;
}

function q5Slugs($query): array
{
    return $query->pluck('slug')->all();
}

/* ───────────────────────────── the model ─────────────────────────────────── */

it('answers the from-price, not zero', function () {
    // THE DEFECT: `return (int) $this->price` with a NULL column answered 0
    // fils for every variable product, and that zero reached the listing
    // JSON-LD, the marketing pixels and the homepage routine totals.
    $parent = q5Catalogue();

    expect($parent->fresh()->effectivePrice())->toBe(12000);
});

it('leaves a product with a price of its own untouched', function () {
    // Rule 1: only the rows that were answering 0 may move. A simple product,
    // and a variable parent WooCommerce did put a figure on, take exactly the
    // path they took before — sale window included.
    $simple = q5Simple('q5-plain', 8500);

    expect($simple->fresh()->effectivePrice())->toBe(8500);

    $onSale = Product::create([
        'slug' => 'q5-sale', 'name' => 'Sale', 'status' => 'publish',
        'is_visible' => true, 'price' => 20000, 'sale_price' => 5000,
        'stock_status' => 'instock', 'type' => 'simple',
    ]);

    expect($onSale->fresh()->effectivePrice())->toBe(5000)
        ->and($onSale->fresh()->isOnSale())->toBeTrue();

    // A variable parent that DOES carry its own price keeps it rather than
    // being overruled by its variations.
    $priced = q5Parent('q5-priced', ['price' => 7700]);
    q5Variant($priced, 100);

    expect($priced->fresh()->effectivePrice())->toBe(7700);
});

it('does not invent a sale on a variable product', function () {
    // isOnSale() is `effectivePrice() < (int) price`, and `(int) null` is 0.
    // Deriving 12000 must not make `12000 < 0` true, and must not put a
    // strikethrough or a discount badge on a tile that has no compare-at price.
    $parent = q5Catalogue();

    expect($parent->fresh()->isOnSale())->toBeFalse()
        ->and($parent->fresh()->discountPercent())->toBe(0)
        ->and($parent->fresh()->advertisedSalePrice())->toBeNull();
});

it('honours the parent sale window when deriving', function () {
    // product_variants carries no dates: a variable product's markdown is
    // scheduled once, on its `products` row, for every variation under it. A
    // variant markdown outside that window must not be the from-price.
    $open = q5Parent('q5-window-open', [
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addDay(),
    ]);
    q5Variant($open, 12000, 9000);

    $shut = q5Parent('q5-window-shut', [
        'sale_starts_at' => now()->subMonth(),
        'sale_ends_at' => now()->subDay(),
    ]);
    q5Variant($shut, 12000, 9000);

    /*
     * BOTH ROWS EXIST BEFORE THE FIRST LOOKUP, and that ordering is load-
     * bearing rather than tidiness. VariantPricing holds a per-request memo
     * filled by one grouped query the first time anything asks it, so a row
     * INSERTed after that first ask is not in it — which is correct for a web
     * request (the catalogue does not change halfway through one) and is the
     * Setting::map() trap the working notes describe for anything longer-lived.
     * Writing this case the other way round asserted 12000 and got 0, which is
     * the memo answering honestly about a row that did not exist when it ran.
     */
    expect($open->fresh()->effectivePrice())->toBe(9000)
        ->and($shut->fresh()->effectivePrice())->toBe(12000);
});

/* ───────────────────────────── the sort ──────────────────────────────────── */

it('files it by what it really costs under price: low to high', function () {
    /*
     * THE DEFECT: NULL sorts FIRST ascending on SQLite and MySQL alike, so
     * every variable product in the catalogue opened "Price: low to high" —
     * the cheapest things in the shop, priced at nothing. A shopper sorting by
     * price saw the most expensive stock first.
     */
    q5Catalogue();

    $asc = Product::query()->whereIn('slug', ['q5-cushion', 'q5-cheap', 'q5-dear']);
    EffectivePrice::orderBy($asc, 'asc');

    expect(q5Slugs($asc))->toBe(['q5-cheap', 'q5-cushion', 'q5-dear']);

    $desc = Product::query()->whereIn('slug', ['q5-cushion', 'q5-cheap', 'q5-dear']);
    EffectivePrice::orderBy($desc, 'desc');

    expect(q5Slugs($desc))->toBe(['q5-dear', 'q5-cushion', 'q5-cheap']);
});

/* ───────────────────────────── the facet ─────────────────────────────────── */

it('files it in the bucket its from-price belongs to', function () {
    /*
     * THE DEFECT: every comparison against NULL is NULL, which is not true, so
     * `whereRange()` put a variable product in NO price bucket. Touching the
     * price filter on /shop made every variable product disappear — not
     * mis-filed, absent.
     *
     * The bounds are App\Support\Facets::BUCKETS, in whole AED, as the shop
     * declares them. AED 120 belongs to "AED 54 – 150" and nowhere else.
     */
    q5Catalogue();

    $bucket = function (?int $min, ?int $max): array {
        $q = Product::query()->whereIn('slug', ['q5-cushion', 'q5-cheap', 'q5-dear']);
        EffectivePrice::whereRange($q, $min === null ? null : $min * 100, $max === null ? null : $max * 100);

        return q5Slugs($q->orderBy('slug'));
    };

    expect($bucket(null, 54))->toBe(['q5-cheap'])
        ->and($bucket(54, 150))->toBe(['q5-cushion'])
        ->and($bucket(150, 300))->toBe([])
        ->and($bucket(300, null))->toBe(['q5-dear']);
});

/* ─────────────────────── what Google is told ─────────────────────────────── */

it('stops telling Google the product is free', function () {
    /*
     * THE DEFECT: CollectionSchema::from() publishes effectivePrice() into the
     * ItemList JSON-LD of every listing page, so a crawler reading /shop was
     * told `"price": "0.00"` for every variable product — while the product
     * page one click away published a correct AggregateOffer from the same
     * variations. Structured data that disagrees with the visible page is the
     * one kind Google acts on.
     */
    $parent = q5Catalogue();

    $item = CollectionSchema::from([$parent->fresh()], 'https://extrabeauty.ae')['items'][0];

    expect($item['price'])->toBe('120.00')
        ->and($item['price'])->not->toBe('0.00')
        ->and($item['price_minor'])->toBe(12000);
});

it('quotes the from-price on the public feed rather than nothing', function () {
    // Product::toApi() published `price: null` for a variable parent while the
    // tile beside it printed "AED 120 – AED 190". Two public surfaces, two
    // answers. `?:` not `??`, so a parent with nothing derivable stays null
    // rather than acquiring a published 0.
    $parent = q5Catalogue();

    expect($parent->fresh()->toApi()['price'])->toBe(12000)
        ->and($parent->fresh()->toApi()['sale_price'])->toBeNull();
});

/* ─────────────────────── everything agrees ───────────────────────────────── */

it('gives the tile, the model, the sort and the facet one answer', function () {
    /*
     * THE POINT OF THE WHOLE CHANGE. Each of these used to be computed from a
     * different reading of the same NULL, and they disagreed: the tile said
     * AED 0, the sort said "cheapest in the shop", the facet said "not a
     * product", the JSON-LD said 0.00. They now all resolve through one SQL
     * definition — EffectivePrice::variantChargedSql() — so they cannot drift
     * apart without this case noticing.
     */
    $parent = q5Catalogue();
    $fresh = $parent->fresh();

    $range = app(VariantPricing::class)->range($fresh);

    // What the tile and the product-page headline print.
    expect($range)->toBe([12000, 19000]);

    // What the model, the JSON-LD and the pixels quote.
    expect($fresh->effectivePrice())->toBe($range[0]);

    // What the sort and the facet evaluate, in SQL, on the row itself.
    $sql = DB::table('products')->where('slug', 'q5-cushion')
        ->selectRaw('(' . EffectivePrice::sql() . ') as ep', EffectivePrice::bindings())
        ->value('ep');

    expect((int) $sql)->toBe($range[0]);
});

/* ───────────────────── the conservative fallback ─────────────────────────── */

it('says nothing at all when no variation is priced', function () {
    /*
     * MIN() over an all-NULL set is NULL, and that is deliberate on both sides:
     * un-priced data is not a free product. Such a parent behaves exactly as
     * EVERY variable parent did before this change — 0 from the model, NULL in
     * SQL — which is the conservative direction and keeps the fix from
     * re-introducing the AED 0 through its own machinery.
     */
    $bare = q5Parent('q5-bare');
    q5Variant($bare, null);
    q5Variant($bare, null);

    expect($bare->fresh()->effectivePrice())->toBe(0)
        ->and(app(VariantPricing::class)->range($bare->fresh()))->toBeNull()
        ->and($bare->fresh()->toApi()['price'])->toBeNull();

    $sql = DB::table('products')->where('slug', 'q5-bare')
        ->selectRaw('(' . EffectivePrice::sql() . ') as ep', EffectivePrice::bindings())
        ->value('ep');

    expect($sql)->toBeNull();
});

it('refuses to derive a price from a row whose shape it cannot see', function () {
    /*
     * Half this application hydrates explicit column lists because the
     * endpoints are public — Api\ProductController::INDEX_COLUMNS is the
     * standing example and it does not select `type`. A derived price must not
     * be guessed from a narrowed SELECT: that is the fail-open trap
     * advertisedSalePrice() documents for the sale window, one method along.
     */
    q5Catalogue();

    $narrow = Product::query()->where('slug', 'q5-cushion')
        ->select(['id', 'slug', 'name', 'price', 'sale_price', 'stock_status'])
        ->first();

    expect(app(VariantPricing::class)->range($narrow))->toBeNull()
        ->and($narrow->effectivePrice())->toBe(0)
        ->and($narrow->toApi()['price'])->toBeNull();
});

/* ─────────────────────────── the basket ──────────────────────────────────── */

it('still refuses to put a variable parent in the basket', function () {
    /*
     * The zero used to be CHARGED, not merely displayed: CartService::add()
     * wrote `$variant?->effectivePrice() ?? $product->effectivePrice()` onto
     * cart_items.unit_price and the checkout took the order. That door is shut
     * by requiresVariant(), and deriving a price must not quietly re-open it by
     * making the parent look buyable — a variable product is bought by its
     * VARIATION or not at all, whatever the parent now answers.
     */
    $parent = q5Catalogue();
    $cart = Cart::create([
        'token' => 'q5-cart-token', 'currency' => 'AED',
        'status' => 'active', 'last_activity_at' => now(),
    ]);

    expect(fn () => app(CartService::class)->add($cart, $parent->fresh(), 1))
        ->toThrow(VariantRequired::class);

    expect($parent->fresh()->requiresVariant())->toBeTrue()
        ->and($parent->fresh()->isDirectlyBuyable())->toBeFalse();
});

it('charges the chosen variation, not the parent', function () {
    // The line a shopper can actually create is priced from the VARIANT, which
    // is unchanged by this lane — asserted so that a later change to
    // effectivePrice() cannot start leaking the from-price into a real charge.
    $parent = q5Catalogue();
    $dear = ProductVariant::query()->where('product_id', $parent->id)->where('price', 19000)->first();

    $cart = Cart::create([
        'token' => 'q5-cart-variant', 'currency' => 'AED',
        'status' => 'active', 'last_activity_at' => now(),
    ]);

    $item = app(CartService::class)->add($cart, $parent->fresh(), 1, $dear);

    expect($item->unit_price)->toBe(19000);
});

/* ────────────────────────── rule 4: the cost ─────────────────────────────── */

it('costs the same number of queries for a grid of one as for a grid of many', function () {
    /*
     * Rule 4, measured rather than asserted. effectivePrice() now consults
     * App\Services\VariantPricing, and the naive version of that — a
     * `$this->variants()->min(...)` per product — is an N+1 across a 24-tile
     * grid. VariantPricing is a `scoped` binding holding a per-request memo
     * filled by ONE grouped query, so the count must not move with the number
     * of variable products on the page.
     */
    $brand = Brand::create(['slug' => 'q5-brand', 'name' => 'Q5 Brand']);

    $count = function (int $parents) use ($brand): int {
        // forceDelete(): Product uses SoftDeletes, so an ordinary delete()
        // leaves the row -- and its unique slug -- behind.
        ProductVariant::query()->delete();
        Product::query()->forceDelete();

        for ($i = 0; $i < $parents; $i++) {
            $p = q5Parent('q5-grid-' . $i, ['brand_id' => $brand->id]);
            q5Variant($p, 10000 + $i * 100);
            q5Variant($p, 20000 + $i * 100);
        }

        /*
         * WARM EVERY PROCESS-LIFETIME MEMO FIRST, exactly as
         * StorefrontQueryBudgetTest and the sibling case in
         * VariableProductTilePriceTest do. Measuring cold-then-warm reports a
         * fall that has nothing to do with the catalogue: written without this
         * line the first measurement read 18 and the second 7, which looks
         * precisely like the N+1 it is not.
         */
        test()->get('/shop/')->assertOk();

        SettingsService::forgetMemo();
        app()->forgetScopedInstances();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        test()->get('/shop/')->assertOk();

        return $queries;
    };

    $many = $count(24);
    $one = $count(1);

    expect($many)->toBe($one);
});

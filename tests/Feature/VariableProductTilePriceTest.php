<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\SettingsService;
use App\Services\VariantPricing;
use Illuminate\Support\Facades\DB;

/**
 * The variable-product tile that said AED 0.
 *
 * ── THE DEFECT, ON THE SHOP ─────────────────────────────────────────────────
 *
 * WooCommerce keeps a variable product's money on its VARIATIONS: the parent
 * row's `price` column is NULL and every real figure lives in
 * `product_variants`. Product::effectivePrice() ends `return (int) $this->price`
 * and `(int) null === 0`, so a variable parent answers 0 fils — and every tile
 * that prints it advertised
 *
 *     AED 0
 *
 * on /shop, on the brand pages, in the homepage rails and in Related products,
 * while the product page BESIDE IT published a correct AggregateOffer built
 * from those same variations. The tile and the structured data disagreed and
 * the tile was the wrong one. The price sort filed the product first under
 * "Price: low to high" as the cheapest thing in the shop.
 *
 * ── WHAT THIS FIXES, AND WHAT FINISHED IT ───────────────────────────────────
 *
 * This file covers THE TILE: it stops stating a price it has no basis for and
 * states the range the variations actually carry instead. When it was written
 * that was all that was fixed — `Product::effectivePrice()` still answered 0,
 * so the price SORT and the price FACET were still wrong about these rows, and
 * the last case here asserted that in as many words so nobody could read the
 * lane as having fixed more than it had.
 *
 * Lane Q5 then fixed the rest, by DERIVING rather than backfilling: the same
 * SQL that builds this range now also feeds App\Support\EffectivePrice, so the
 * sort and the facet evaluate it too, and Product::effectivePrice() answers the
 * low end of it. No schema change and no importer change were needed after all
 * — a backfill would additionally have broken this file, because
 * VariantPricing::range() answers only for a parent whose `price` is NULL, so
 * writing a figure into that column would have turned every range on the shop
 * back into a single number. See VariableProductPriceAgreesTest.
 *
 * ── MUTATION NOTE ───────────────────────────────────────────────────────────
 *
 * In resources/views/components/product-card.blade.php, put the `@if` back to
 * `@if ($product->isOnSale())` — i.e. delete the two `$kbbRange` branches — and
 * 'it prints the range a variable product actually charges' goes red with
 * `AED 0`, which is the string the shop published. In
 * App\Services\VariantPricing::load(), drop the `whereRaw(... IS NOT NULL)` and
 * 'it says nothing about a product whose variations are all un-priced' goes red
 * with [0, 0] — the AED 0 re-entering through the fix that removed it. (That
 * one clause changes nothing else: MIN() and MAX() ignore NULLs on their own,
 * so every other case here stays green, which is why it has a case of its own
 * rather than being folded into the tile assertions.)
 */

/** A visible variable parent with NO price of its own, which is the real shape. */
function vptParent(string $slug = 'vpt-cushion', array $extra = []): Product
{
    return Product::create(array_merge([
        'slug' => $slug,
        'name' => 'Water Glow Cushion',
        'status' => 'publish',
        'is_visible' => true,
        'price' => null,
        'stock_status' => 'instock',
        'type' => 'variable',
    ], $extra));
}

function vptVariant(Product $parent, ?int $price, ?int $salePrice = null): ProductVariant
{
    return ProductVariant::create([
        'product_id' => $parent->id,
        'price' => $price,
        'sale_price' => $salePrice,
        'stock_status' => 'instock',
    ]);
}

/** The price cell of the tile for $slug, from the rendered /shop page. */
function vptTile(string $html, string $slug): string
{
    // The card links to /product/{slug}/ and the price sits in the same .pc
    // block. Cut the block out and hand back its .cprice, so an assertion
    // cannot accidentally read a neighbouring tile's money.
    $cards = preg_split('#<div class="pc">#', $html);

    foreach ($cards as $card) {
        if (str_contains($card, '/product/' . $slug . '/')
            && preg_match('#<div class="cprice">(.*?)</div>#s', $card, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
        }
    }

    return '';
}

/** The `.kbb-card-price` cell of the skinned grid's only tile. */
function vptSkinTile(string $html): string
{
    preg_match('#<span class="kbb-card-price">(.*?)</span></div>#s', $html, $m);

    return trim(html_entity_decode(strip_tags($m[1] ?? ''), ENT_QUOTES, 'UTF-8'));
}

function vptShop(): string
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    return test()->get('/shop/')->assertOk()->getContent();
}

/* ─────────────────────────────── the tile ────────────────────────────────── */

it('prints the range a variable product actually charges', function () {
    $parent = vptParent();
    vptVariant($parent, 3500);
    vptVariant($parent, 6000);

    $price = vptTile(vptShop(), 'vpt-cushion');

    // AED 0 is what this cell said before, for every variable product in the
    // catalogue, on every grid on the site.
    expect($price)->not->toContain('AED 0')
        ->and($price)->toContain('35')
        ->and($price)->toContain('60')
        // The en dash is part of the wording, not glue — see
        // InterfaceStrings 'product_card.price_range'.
        ->and($price)->toContain('–');
});

it('prints one price when every option costs the same', function () {
    $parent = vptParent('vpt-balm');
    vptVariant($parent, 4200);
    vptVariant($parent, 4200);

    $price = vptTile(vptShop(), 'vpt-balm');

    // Not "AED 42 – AED 42". Seo::aggregateOffer() declines to publish a
    // lowPrice equal to its highPrice for the same reason: it states a range
    // that is not one.
    expect($price)->toContain('42')
        ->and($price)->not->toContain('–');
});

it('honours the parent sale window a variation has no dates of its own for', function () {
    // ProductVariant::effectivePrice() applies the PARENT's window to every
    // variant, because `product_variants` carries no date columns. A markdown
    // that has not opened yet must not be quoted on the tile — the defect
    // Api\CheckoutController's note calls "money, quietly, in both directions".
    $parent = vptParent('vpt-serum', [
        'sale_starts_at' => now()->addDays(7),
        'sale_ends_at' => now()->addDays(14),
    ]);
    vptVariant($parent, 9000, 4500);
    vptVariant($parent, 12000);

    $price = vptTile(vptShop(), 'vpt-serum');

    expect($price)->toContain('90')
        ->and($price)->toContain('120')
        ->and($price)->not->toContain('45');
});

it('quotes a live variation markdown', function () {
    $parent = vptParent('vpt-mask', [
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addDay(),
    ]);
    vptVariant($parent, 9000, 4500);
    vptVariant($parent, 12000);

    $price = vptTile(vptShop(), 'vpt-mask');

    expect($price)->toContain('45')->and($price)->toContain('120');
});

it('ignores a variation that carries no price at all', function () {
    /*
     * ProductVariant::effectivePrice() answers `$this->price ?? $parent ?? 0`,
     * and the parent here is the very 0 this whole fix exists because of. So an
     * un-priced variation counted at face value would put AED 0 straight back
     * at the bottom of the range. It is un-priced data, not a free product.
     */
    $parent = vptParent('vpt-toner');
    vptVariant($parent, null);
    vptVariant($parent, 5500);
    vptVariant($parent, 7000);

    $price = vptTile(vptShop(), 'vpt-toner');

    expect($price)->toContain('55')
        ->and($price)->toContain('70')
        ->and($price)->not->toContain('AED 0 ');
});

it('says nothing about a product whose variations are all un-priced', function () {
    /*
     * NOT [0, 0]. A parent with nothing priced under it is a product this class
     * knows no price for, and the honest answer is to say so and let the tile
     * render what it always rendered. Answering zero would be the AED 0 coming
     * back in through the door the fix closed.
     *
     * Asserted on the resolver rather than on the tile deliberately: both
     * answers PRINT "AED 0" — one from the range, one from the fallback — so
     * the page cannot tell them apart and only this can.
     */
    $parent = vptParent('vpt-unpriced');
    vptVariant($parent, null);
    vptVariant($parent, null);

    expect((new VariantPricing)->range($parent->fresh()))->toBeNull();
});

it('leaves a variable product that does carry its own price alone', function () {
    // Not every variable row has a NULL parent price, and one that does not is
    // none of this fix's business: it already printed a true figure.
    $parent = vptParent('vpt-priced', ['price' => 8800]);
    vptVariant($parent, 3000);

    $price = vptTile(vptShop(), 'vpt-priced');

    expect($price)->toContain('88')->and($price)->not->toContain('30');
});

it('leaves a simple product byte for byte', function () {
    Product::create([
        'slug' => 'vpt-simple',
        'name' => 'Dokdo Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 8500,
        'stock_status' => 'instock',
        'type' => 'simple',
    ]);

    expect(vptTile(vptShop(), 'vpt-simple'))->toContain('85');
});

/* ─────────────────────────── the skinned grid too ────────────────────────── */

it('prints the range on the skinned grid as well', function () {
    // <x-product-grid> is a second tile with its own markup, used by the brand
    // pages; it printed the same AED 0 from the same call. A fix applied to one
    // template and not the other is the shape of defect this case exists for.
    $brand = Brand::create(['slug' => 'vpt-brand', 'name' => 'Round Lab']);

    $parent = vptParent('vpt-grid', ['brand_id' => $brand->id]);
    vptVariant($parent, 3500);
    vptVariant($parent, 6000);

    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    $html = test()->get('/korean-skincare-brands/vpt-brand/')->assertOk()->getContent();

    expect(vptSkinTile($html))->toBe('AED 35 – AED 60');
});

/* ───────────────────────────── and it is flat ────────────────────────────── */

it('costs one query however many variable tiles are on the page', function () {
    /*
     * A BUDGET CANNOT CATCH AN N+1 ON A SMALL FIXTURE — StorefrontQueryBudget-
     * Test's own header says so, and /shop reached 390 queries for four
     * products without any test noticing. So this measures the SAME page
     * against one variable product and against twelve and asserts the two
     * counts are identical, which is the only evidence that the resolver
     * batches.
     */
    $count = function (int $n): int {
        // forceDelete(): Product uses SoftDeletes, so an ordinary delete()
        // leaves the row — and its unique slug — behind.
        ProductVariant::query()->delete();
        Product::query()->forceDelete();

        for ($i = 0; $i < $n; $i++) {
            $parent = vptParent('vpt-flat-' . $i);
            vptVariant($parent, 3000 + $i * 100);
            vptVariant($parent, 9000 + $i * 100);
        }

        // Warm every process-lifetime memo first, exactly as
        // StorefrontQueryBudgetTest does: measuring cold-then-warm would report
        // a fall that has nothing to do with the catalogue.
        vptShop();

        SettingsService::forgetMemo();
        app()->forgetScopedInstances();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        test()->get('/shop/')->assertOk();

        return $queries;
    };

    $one = $count(1);
    $twelve = $count(12);

    expect($twelve)->toBe($one);
});

it('costs nothing at all on a page with no unpriced variable product', function () {
    // The resolver returns null before touching the database for a simple
    // product, so a catalogue without this shape pays one property read per
    // tile and no query. `$ranges` staying null is that fact, observable.
    Product::create([
        'slug' => 'vpt-none',
        'name' => 'Dokdo Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 8500,
        'stock_status' => 'instock',
        'type' => 'simple',
    ]);

    vptShop();

    $pricing = new VariantPricing;

    expect($pricing->range(Product::query()->where('slug', 'vpt-none')->first()))->toBeNull();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $pricing->range(Product::query()->where('slug', 'vpt-none')->first());

    // The only query is the one fetching the product for this assertion.
    expect($queries)->toBe(1);
});

/* ────────────────────── and the rest of it, since ────────────────────────── */

it('now has the model agreeing with the tile as well', function () {
    /*
     * THIS CASE USED TO ASSERT THE OPPOSITE, and said so: while only the tile
     * was fixed, `Product::effectivePrice()` still answered 0 for a variable
     * parent and the price sort and the price facet were still wrong about it.
     * It carried the instruction that a later lane fixing it properly would
     * turn THIS case red, and that going red was the signal rather than a
     * regression. Lane Q5 did that, so the assertion is inverted rather than
     * deleted — the row it names is the shortest statement of what changed.
     *
     * The rest of the property — the sort, the facet, the listing JSON-LD, the
     * basket and the query cost — is pinned in VariableProductPriceAgreesTest.
     */
    $parent = vptParent('vpt-still-zero');
    vptVariant($parent, 3500);

    expect($parent->fresh()->effectivePrice())->toBe(3500);
});

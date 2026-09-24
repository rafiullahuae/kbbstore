<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Support\EffectivePrice;
use Illuminate\Support\Facades\DB;

/**
 * What a variable product costs, for the tiles that were printing AED 0.
 *
 * ── THE DEFECT, AND EXACTLY HOW MUCH OF IT THIS FIXES ───────────────────────
 *
 * A WooCommerce variable product carries its prices on its VARIATIONS, not on
 * itself: `products.price` is NULL on the parent row and every real figure
 * lives in `product_variants`. Product::effectivePrice() ends
 *
 *     return (int) $this->price;
 *
 * and `(int) null === 0`, so a variable parent answers 0 fils. Every tile on
 * the shop, on a brand page, on the homepage rails and in Related products
 * printed **AED 0** for it, and the price SORT filed it first under
 * "Price: low to high" — the cheapest thing in the shop, priced at nothing.
 * App\Support\Seo got this right already: the product page publishes a real
 * AggregateOffer built from the variants, so the structured data and the tile
 * beside it disagreed, and the tile was the wrong one.
 *
 * ▲ THIS CLASS DOES NOT FIX Product::effectivePrice() AND MUST NOT BE READ AS
 * DOING SO. That method is money — CartService snapshots it onto
 * `cart_items.unit_price` — and correcting it is a decision with two halves
 * this lane does not own: backfilling `products.price` breaks idempotency
 * unless Services\Import\Entities\ProductImporter stops writing NULL over it in
 * the same change, and that file belongs to another lane. So the ZERO IS STILL
 * THERE in effectivePrice(), in the price sort and in the price facet. What is
 * fixed is the one thing that could be fixed without deciding any of that: the
 * tile stops stating a price it has no basis for, and states the range the
 * variations actually carry instead.
 *
 * ── ONE QUERY PER REQUEST, AND ONLY WHEN A TILE ASKS ────────────────────────
 *
 * range() answers from a per-request memo. The memo is filled by ONE grouped
 * query over `product_variants`, taken the first time a tile asks about a
 * product this class has anything to say about — a `variable` parent whose
 * `price` column is NULL. A page with no such tile never asks and never runs
 * it; a page with forty of them runs it once.
 *
 * WHY THE QUERY IS NOT NARROWED TO THE PRODUCTS ON THE PAGE, which is the
 * obvious version. A tile is rendered by resources/views/components/product-
 * card.blade.php and product-grid.blade.php, one product at a time, from five
 * different views and a dozen different controllers; there is no single place
 * that holds the collection, and "remember to preload in every new grid" is a
 * rule that gets forgotten and turns into an N+1 that
 * StorefrontQueryBudgetTest only catches on the pages it happens to walk. So
 * the resolver takes the whole set of variable-and-unpriced parents in one go
 * — one row each, a group-by over a small table — and any grid written after
 * this one is flat without having to know this class exists.
 *
 * THE MEMO IS AN INSTANCE FIELD ON A `scoped` BINDING, NOT A STATIC. Production
 * throws the container away between requests, so scoped means per request; a
 * static would survive into the next request in a queue worker and, more to the
 * point, would survive between requests inside ONE TEST PROCESS and hide this
 * query from StorefrontQueryBudgetTest entirely. Its budgetReset() calls
 * `app()->forgetScopedInstances()`, which is exactly the reset this wants —
 * the same reason CartService and SettingsService are bound that way.
 *
 * ── WHAT COUNTS AS A PRICE ──────────────────────────────────────────────────
 *
 * The SQL mirrors ProductVariant::effectivePrice() condition for condition, the
 * way App\Support\EffectivePrice mirrors Product::effectivePrice(), and borrows
 * that class's bindings so the two cannot disagree about what "now" is:
 * a variant's `sale_price` counts only while the PARENT's sale window is open
 * (variants carry no dates of their own), otherwise its own `price`.
 *
 * A VARIANT PRICED BY NEITHER COLUMN IS EXCLUDED rather than counted as zero.
 * ProductVariant::effectivePrice() answers `$this->price ?? $parent ?? 0` and
 * the parent here is the 0 this whole class exists because of, so counting it
 * would put the AED 0 back at the bottom of the range. Such a variant is
 * un-priced data, not a free product. If NO variant yields a price, range()
 * answers null and the tile renders exactly what it renders today — which is
 * the right fallback: this class may improve a tile, never invent one.
 */
class VariantPricing
{
    /**
     * product_id => [low, high] in fils, for every variable parent with no
     * price of its own. Null until the first tile asks.
     *
     * @var array<int, array{0: int, 1: int}>|null
     */
    private ?array $ranges = null;

    /**
     * The charged-price range of this product's variations, or null.
     *
     * NULL IS THE ANSWER FOR ALMOST EVERY PRODUCT, and it is returned before
     * anything touches the database. A simple product, and a variable one whose
     * parent row does carry a price, are none of this class's business — so a
     * shop with no unpriced variable products pays one property read per tile
     * and not one query.
     *
     * @return array{0: int, 1: int}|null  [low, high] in fils
     */
    public function range(Product $product): ?array
    {
        // `price` read off the attributes, not the accessor: a NULL column and
        // a column that was never SELECTed both answer null through the model,
        // and only the first of them means "this product has no price".
        // Api\ProductController's column list is the standing proof that a
        // narrowed select is a real shape in this codebase.
        $attributes = $product->getAttributes();

        if (($product->type ?? '') !== 'variable'
            || ! array_key_exists('price', $attributes)
            || $attributes['price'] !== null) {
            return null;
        }

        if ($this->ranges === null) {
            $this->ranges = $this->load();
        }

        return $this->ranges[(int) $product->id] ?? null;
    }

    /**
     * Is this product's range a real spread, rather than one repeated figure?
     *
     * A variable product whose options all cost the same is a single price, and
     * printing "AED 60 – AED 60" beside it would be a range in the same sense
     * that an AggregateOffer with lowPrice equal to highPrice is one — which
     * App\Support\Seo::aggregateOffer() already declines to publish, for this
     * reason.
     *
     * @param  array{0: int, 1: int}  $range
     */
    public static function isSpread(array $range): bool
    {
        return $range[0] !== $range[1];
    }

    /**
     * The one query.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function load(): array
    {
        /*
         * ProductVariant::effectivePrice() in SQL. The window columns are the
         * PARENT's, because `product_variants` has none — a variable product's
         * markdown is scheduled once, on its `products` row, for every variant
         * under it.
         *
         * The instant is a BOUND PARAMETER and not NOW(), for both of the
         * reasons App\Support\EffectivePrice gives: SQLite has no NOW() and
         * this suite runs on SQLite while production runs MySQL, and one bound
         * value cannot drift between the two places this expression appears.
         */
        $charged = 'CASE WHEN v.sale_price IS NOT NULL'
            . ' AND (p.sale_starts_at IS NULL OR p.sale_starts_at <= ?)'
            . ' AND (p.sale_ends_at IS NULL OR p.sale_ends_at >= ?)'
            . ' THEN v.sale_price ELSE v.price END';

        $window = EffectivePrice::bindings();

        $rows = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.type', 'variable')
            ->whereNull('p.price')
            /*
             * THE ROW IS DROPPED WHEN NOTHING UNDER IT HAS A PRICE, and this
             * clause does only that — which is worth saying, because it looks
             * like it is doing more.
             *
             * MIN() and MAX() already ignore NULLs, so a parent with one priced
             * variation and one un-priced one answers the same range with or
             * without this line. What it changes is the parent where EVERY
             * variation is un-priced: without it the aggregate answers NULL,
             * `(int) null` is 0, and range() hands the tile [0, 0] — the AED 0
             * this class exists to remove, re-entering through its own fix. With
             * it there is no row at all, range() answers null, and the tile
             * renders what it rendered before. Measured: removing this line
             * leaves every tile case green and only the range() assertion in
             * VariableProductTilePriceTest red.
             */
            ->whereRaw('(' . $charged . ') IS NOT NULL', $window)
            ->groupBy('v.product_id')
            ->selectRaw(
                'v.product_id as pid, MIN(' . $charged . ') as lo, MAX(' . $charged . ') as hi',
                [...$window, ...$window]
            )
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->pid] = [(int) $row->lo, (int) $row->hi];
        }

        return $out;
    }
}

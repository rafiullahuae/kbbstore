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
 * ── WHAT THIS CLASS IS NOW, WHICH IS MORE THAN THE TILE ─────────────────────
 *
 * The paragraph that stood here said this class did NOT fix
 * Product::effectivePrice(), that the zero was still in the sort and the facet,
 * and that only a backfill of `products.price` or an importer change could
 * remove it. That was true when it was written and has not been true since
 * lane Q5: the fix turned out to be DERIVING rather than backfilling, so
 * effectivePrice() now returns range()'s low end, App\Support\EffectivePrice
 * evaluates the same expression in SQL for the price sort and the price facet,
 * and a backfill would in fact have BROKEN this class — range() answers only
 * for a parent whose `price` is NULL, so writing a figure into that column
 * would collapse every range on the shop back to a single number.
 *
 * So this class is the single source for what a variable product costs, and it
 * answers two questions rather than one:
 *
 *   range()      what it costs NOW, low and high — the tile's "AED 90 – 140",
 *                the product-page headline, and (its low end) effectivePrice().
 *   regularLow() what it would cost with no sale running — the compare-at that
 *                Product::compareAtPrice() hands the Sale badge, the struck
 *                price and the "On sale" facet. Added by lane Q6, because a
 *                markdown scheduled on a variable product was real, was charged,
 *                and was announced by nothing at all.
 *
 * Both come off ONE grouped row. Adding the second cost no statement anywhere.
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
     * product_id => [low, high, regularLow] in fils, for every variable parent
     * with no price of its own. Null until the first tile asks.
     *
     * The third figure is the from-price this product WOULD advertise with no
     * sale running -- MIN(v.price) -- which is the compare-at the badge, the
     * strikethrough and the "On sale" facet all need. See regularLow().
     *
     * Null again after any write to `products` or `product_variants`; forget()
     * below is where that is argued.
     *
     * @var array<int, array{0: int, 1: int, 2: int|null}>|null
     */
    private ?array $entries = null;

    /**
     * ── THE MEMO WENT STALE IN A LONG-LIVED PROCESS, AND SILENTLY ───────────
     *
     * $entries is a snapshot of the WHOLE set of variable, un-priced parents,
     * taken once and then trusted. Inside one web request that is exactly
     * right: the catalogue cannot change halfway through a render. Outside one
     * it is not, and the failure is quiet and specific -- a Product row
     * INSERTED after the first lookup is not in the snapshot, range() answers
     * null for it, and Product::effectivePrice() therefore answers **0 fils**,
     * which is the AED 0 this whole class exists to remove, re-entering through
     * its own memo.
     *
     * `scoped` DOES NOT COVER THIS, and that is the part worth writing down.
     * `scoped` means "until someone calls forgetScopedInstances()", and in
     * Laravel 11 exactly one place in the framework calls it: the queue
     * worker's resetScope callback in Illuminate\Queue\QueueServiceProvider. A
     * PHP-FPM request never calls it either -- the process simply exits, which
     * is why the binding looks correct on the storefront. **In `artisan` there
     * is no such seam at all.** A console command, a tinker session or an
     * import that inserted products and then priced them would read one
     * snapshot for its whole life. It is the same trap CLAUDE.md records for
     * Setting::map(), one layer up.
     *
     * ── THE SIGNAL IS TAKEN AT THE WRITE, NOT AT A RESET SEAM ───────────────
     *
     * The snapshot's inputs are two tables, and the only thing that can
     * invalidate it is a WRITE to one of them. So App\Models\Product::booted()
     * and App\Models\ProductVariant::booted() call invalidate() on `saved` and
     * `deleted`, and this instance drops its snapshot. Nothing has to remember
     * to reset anything -- which is the same argument load() makes for reading
     * the whole set rather than the products on the page. It also matters that
     * the seam does not exist yet to be told: nothing in app/Console/Commands
     * and no queued job calls this class today, so the fix has to be in the
     * mechanism rather than in a caller.
     *
     * ▲ NO PROCESS-LEVEL STATIC, DELIBERATELY, AND THIS WAS MEASURED. The first
     * version of this fix was a static generation counter compared against a
     * per-instance $loadedAt. It worked and tests/Feature/
     * StaticMemoIsolationTest failed it, correctly: that file scans app/ for
     * static properties and refuses any that is neither reset nor exempt in
     * Tests\Support\StaticMemos, precisely so a new memo cannot be added
     * without somebody deciding what clears it. Registering a reset for it
     * would have meant putting the counter back to its declared 0 between
     * tests, which is the one direction that can make a stale snapshot look
     * fresh. Clearing the container's own instance has no such edge: the memo
     * is per-instance, an instance that never existed has nothing to clear, and
     * `scoped` already governs how long an instance lives.
     *
     * COST, MEASURED RATHER THAN ASSERTED: nothing on any page. A storefront
     * render writes no product and no variation, so nothing invalidates and
     * load() still runs exactly once per request -- which is what
     * StorefrontQueryBudgetTest, VariableProductTilePriceTest's "costs one
     * query however many variable tiles" and ApiProductTypeNotPublishedTest's
     * "one extra statement for any number of variable products" all continue to
     * measure.
     */
    public function forget(): void
    {
        $this->entries = null;
    }

    /**
     * Drop the snapshot the container is holding, if it is holding one.
     *
     * Called from Product::booted() and ProductVariant::booted(). The
     * `resolved()` guard is what keeps an import cheap: a run that saves ten
     * thousand variations before anything has ever asked for a price does ten
     * thousand array lookups and builds nothing.
     *
     * A BOUNDARY, STATED. This is an Eloquent event, so a mass delete through
     * the query builder (`$product->variants()->delete()`), a mass update
     * (`Product::query()->whereIn(...)->update([...])`) and a raw DB::table()
     * write all fire nothing and do not invalidate. Anything that writes either
     * table that way has to call this itself.
     *
     * ▲ ONE THING NOW DOES, and the note that stood here said nothing did.
     * Admin\CatalogProductsApiController::bulkPrice() writes `products.price`
     * and `products.sale_price` for up to BULK_MAX rows through the query
     * builder, one statement per distinct resulting value, and it calls this
     * afterwards. It is the only such write in the application that touches a
     * column this snapshot is derived from: every other query-builder write to
     * `products` sets `status`, `brand_id`, `category_id`, `position`,
     * `routine_role`, `routine_concerns`, `rating`/`review_count` or `seo`, and
     * load() reads none of them. Nothing at all writes `product_variants` that
     * way. tests/Feature/VariantPriceMemoRawWriteGuardTest.php is what keeps
     * that list honest — it fails, naming the file and line, the day another
     * one appears.
     */
    public static function invalidate(): void
    {
        $container = app();

        if ($container->resolved(self::class)) {
            $container->make(self::class)->forget();
        }
    }

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
        $entry = $this->entry($product);

        return $entry === null ? null : [$entry[0], $entry[1]];
    }

    /**
     * The from-price this product would advertise with NO sale running, or null.
     *
     * ── THE COMPARE-AT PRICE OF A VARIABLE PRODUCT, WHICH THE SHOP HAD NONE OF
     *
     * A markdown on a variable product lives on `product_variants.sale_price`,
     * one per variation, scheduled by the PARENT's window -- ProductVariant::
     * effectivePrice() and Services\Import\Entities\VariationImporter both say
     * so, and ProductImporter REJECTS a row carrying `sale_price` with an empty
     * `regular_price`, so a variable parent with a NULL `price` cannot carry a
     * markdown of its own. The parent row therefore has no compare-at figure in
     * any column, and Product::isOnSale() -- `effectivePrice() < (int) price`,
     * with `(int) null === 0` -- was false for every one of them. A markdown
     * scheduled on a variable product showed no Sale badge, no strikethrough,
     * no percentage and never appeared under "On sale"; the only thing that
     * moved was the price itself, quietly.
     *
     * ── WHY MIN(price) IS THE HONEST COMPARE-AT ─────────────────────────────
     *
     * The tile, the headline and the facet all collapse this product to ONE
     * number, and that number is the from-price: MIN(charged). The figure it
     * has to be compared against is therefore the from-price of the same
     * product with the sale switched off, which is MIN(regular) -- literally
     * what the tile printed the day before the markdown started.
     *
     * It is NOT "the regular price of whichever option is cheapest now", and
     * the difference is real. Options at (regular 100, sale 40) and (regular
     * 50, no sale): the cheapest option now is the first at 40, but the tile
     * said "from AED 50" yesterday, so AED 50 is what a shopper is being
     * offered a saving against. MIN(regular) answers 50; the other rule answers
     * 100 and would advertise a 60% saving nobody is getting.
     *
     * NULL WHEN NO VARIATION CARRIES A REGULAR PRICE AT ALL. MIN() ignores
     * NULLs, so a set of variations priced only by `sale_price` answers NULL
     * here, and Product::isOnSale() reads that as "no compare-at I can vouch
     * for" rather than as zero. Un-priced data is not a free product -- the
     * same fallback load() already takes, and the same direction
     * advertisedSalePrice() takes for an unselected sale window.
     *
     * NO SECOND QUERY. It is MIN(v.price) on the one grouped statement load()
     * already runs, so a page that prints a range and a badge costs exactly
     * what a page that printed the range alone cost.
     */
    public function regularLow(Product $product): ?int
    {
        $entry = $this->entry($product);

        return $entry === null ? null : $entry[2];
    }

    /**
     * The memo row for this product, or null.
     *
     * @return array{0: int, 1: int, 2: int|null}|null
     */
    private function entry(Product $product): ?array
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

        // Null again after any write to either table -- see forget() above.
        if ($this->entries === null) {
            $this->entries = $this->load();
        }

        return $this->entries[(int) $product->id] ?? null;
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
     * @return array<int, array{0: int, 1: int, 2: int|null}>
     */
    private function load(): array
    {
        /*
         * ProductVariant::effectivePrice() in SQL — TAKEN FROM
         * App\Support\EffectivePrice RATHER THAN SPELT OUT HERE.
         *
         * It used to be written out in this method, and that was fine while
         * this class was the only place that needed it. It is not any more:
         * EffectivePrice::sql() now COALESCEs a variable parent's NULL `price`
         * with the cheapest figure its variations charge, so the price SORT and
         * the price FACET evaluate this same expression, inside a correlated
         * MIN instead of a GROUP BY. Two copies of it is how the tile and the
         * sort would come to disagree about what a product costs — which is the
         * disagreement this whole area of the codebase has already paid for
         * twice. One definition, two shapes; see variantChargedSql().
         *
         * The instant is still a BOUND PARAMETER and not NOW(), for both of the
         * reasons EffectivePrice gives: SQLite has no NOW() and this suite runs
         * on SQLite while production runs MySQL, and one bound value cannot
         * drift between the places this expression appears.
         */
        $charged = EffectivePrice::variantChargedSql('v', 'p');

        $window = EffectivePrice::window();

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
            /*
             * `MIN(v.price) as reg` IS THE THIRD FIGURE AND IT TAKES NO WINDOW.
             *
             * regularLow() explains what it is for. What is worth saying HERE
             * is why it carries no bindings while its two neighbours each carry
             * a pair: the window only ever decides whether a variation's
             * `sale_price` counts, and `v.price` is the column a sale is a
             * markdown FROM. It is the same number inside the window and
             * outside it.
             *
             * It is also unaffected by the whereRaw() above, which is why that
             * clause is not repeated inside the aggregate. A variation with a
             * non-NULL `price` always has a non-NULL charged price, because
             * charged falls back to `price`; so every row this MIN could care
             * about has already survived the filter, and every row the filter
             * drops has a NULL `price` that MIN would ignore anyway. Removing
             * the filter changes `reg` on no row -- checked against the
             * un-priced-variation cases in VariableProductTilePriceTest.
             */
            ->selectRaw(
                'v.product_id as pid, MIN(' . $charged . ') as lo, MAX(' . $charged . ') as hi,'
                . ' MIN(v.price) as reg',
                [...$window, ...$window]
            )
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->pid] = [
                (int) $row->lo,
                (int) $row->hi,
                // NULL stays NULL. `(int) null` is 0, and a compare-at of zero
                // is the AED 0 the whole class removes, wearing a strikethrough.
                $row->reg === null ? null : (int) $row->reg,
            ];
        }

        return $out;
    }
}

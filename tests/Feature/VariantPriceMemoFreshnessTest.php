<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\VariantPricing;
use Illuminate\Support\Facades\DB;

/**
 * The derived price that went stale in a long-lived process.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * App\Services\VariantPricing answers from a snapshot of EVERY variable,
 * un-priced parent, taken in one grouped query the first time anything asks and
 * then trusted for the life of the instance. Inside one web request that is
 * exactly right — a catalogue does not change halfway through a render, and the
 * whole point of reading the whole set at once is that no grid has to remember
 * to preload anything.
 *
 * Outside one request it is wrong, and it fails in the worst available
 * direction: a Product row INSERTED after the first lookup is not in the
 * snapshot, range() answers null for it, and Product::effectivePrice()
 * therefore answers **0 fils** — the AED 0 that class exists to remove,
 * re-entering through its own memo. Nothing throws and nothing logs.
 *
 * ── `scoped` DOES NOT COVER IT, AND THAT IS THE PART WORTH KNOWING ──────────
 *
 * AppServiceProvider binds this `scoped`, which means "until someone calls
 * forgetScopedInstances()". In Laravel 11 exactly one place in the framework
 * calls it: the queue worker's resetScope callback in
 * Illuminate\Queue\QueueServiceProvider. A PHP-FPM request never calls it
 * either — the process simply exits, which is why the binding looks correct on
 * the storefront and why nothing on the shop was ever wrong because of it.
 *
 * In `artisan` there is no such seam at all. So the exposure is:
 *
 *   - QUEUED JOBS: safe today, and safe by the framework rather than by luck.
 *     The worker resets scope between jobs. (This application has no app/Jobs
 *     directory; Mail\OrderMail is the one ShouldQueue class and it prices
 *     nothing — order lines carry snapshotted `unit_price` columns.)
 *   - CONSOLE COMMANDS: nothing in app/Console/Commands calls effectivePrice()
 *     or VariantPricing today, and the importer does not price at all —
 *     Import\Entities\ProductImporter and VariationImporter write the `price`
 *     and `sale_price` columns and never read a derived figure back. Checked
 *     rather than assumed, because a wrong price written into an order line or
 *     a feed file is worse than a wrong price on a page about to be re-rendered,
 *     and none of those writers exist yet.
 *   - kbb:page-cost: renders storefront pages in-process, which is the shape
 *     that would have been bitten. It is not, because it forks one child
 *     process per measured page and each child boots one application.
 *
 * So the bug is LATENT, not live: the code that would trip it has not been
 * written. That is the reason to fix the mechanism rather than to add a reset
 * call to a caller — the caller that needs it does not exist to be told.
 *
 * ── THE FIX, AND WHY IT IS NOT A RESET SEAM ─────────────────────────────────
 *
 * The snapshot's inputs are two tables and the only thing that can invalidate
 * it is a WRITE to one of them, so the signal is taken there: Product::booted()
 * and ProductVariant::booted() call VariantPricing::invalidate() on `saved` and
 * `deleted`, which drops the snapshot the container is holding. Nothing has to
 * remember to reset anything, which is the same argument load() already makes
 * for reading the whole set instead of the products on the page — and it is the
 * only shape available here, because the caller that would need to be told to
 * reset has not been written yet.
 *
 * ▲ NOT A PROCESS-LEVEL STATIC, AND THE SUITE IS WHAT DECIDED THAT. The first
 * version was a static generation counter compared against a per-instance
 * $loadedAt. Every case in this file passed and StaticMemoIsolationTest failed,
 * correctly: it scans app/ for static properties and refuses any that is
 * neither reset nor exempt in Tests\Support\StaticMemos, so that a new memo
 * cannot be added without somebody deciding what clears it. Registering a reset
 * would have meant putting the counter back to its declared 0 between tests —
 * the one direction that can make a stale snapshot look fresh. Clearing the
 * container's own instance has no such edge, and leaves this class with no
 * process-level state at all.
 *
 * ── MUTATION NOTES, BOTH RUN ────────────────────────────────────────────────
 *
 * 1. Delete `protected static function booted()` from BOTH App\Models\Product
 *    and App\Models\ProductVariant. RUN: 5 failed — every freshness case here,
 *    with range() answering null and effectivePrice() answering 0 fils for rows
 *    that exist.
 * 2. Delete it from ProductVariant ALONE. RUN: 3 failed. From Product alone:
 *    RUN: 1 failed, and only 'costs one reload and not one per row after a
 *    write'. That asymmetry is worth recording rather than tidying away — every
 *    other case here creates a variation immediately after its parent, so the
 *    variations hook covers for the products one and the products hook looks
 *    redundant. It is not: the case that writes ONLY to `products` is the one
 *    that isolates it, which is why it exists.
 * 3. Make VariantPricing::forget() a no-op (delete the `$this->entries = null;`
 *    line). RUN: 5 failed — the same five, with the memo answering honestly
 *    about rows that did not exist when it ran.
 * 4. Drop the `resolved()` guard from VariantPricing::invalidate() so it always
 *    resolves. RUN: 0 failed, which is the point of recording it: the guard is
 *    a cost control, not a correctness one, and nothing here would notice it
 *    going. What would notice is an import saving ten thousand rows.
 */

function fmParent(string $slug, array $extra = []): Product
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

function fmVariant(Product $parent, ?int $price, ?int $salePrice = null): ProductVariant
{
    return ProductVariant::create([
        'product_id' => $parent->id,
        'price' => $price,
        'sale_price' => $salePrice,
        'stock_status' => 'instock',
    ]);
}

/**
 * The ONE instance a long-lived process would hold.
 *
 * Resolved once and passed around by hand, deliberately: every case below is
 * about what happens WITHOUT a container reset, so calling app() a second time
 * would prove nothing (it returns the same scoped instance) and calling
 * forgetScopedInstances() would prove the opposite of the point.
 */
function fmPricing(): VariantPricing
{
    return app(VariantPricing::class);
}

it('sees a product created after its first answer', function () {
    /*
     * THE DEFECT, in the shape a console command would meet it. The first ask
     * fills the snapshot; the product created afterwards is not in it; range()
     * answers null and effectivePrice() casts that to 0 fils. Before the fix
     * this expectation read 0 and the product was, to the whole application,
     * free.
     */
    $first = fmParent('fm-first');
    fmVariant($first, 12000);

    $pricing = fmPricing();

    expect($pricing->range($first->fresh()))->toBe([12000, 12000]);

    $later = fmParent('fm-later');
    fmVariant($later, 19000);

    expect($pricing->range($later->fresh()))->toBe([19000, 19000])
        ->and($later->fresh()->effectivePrice())->toBe(19000);
});

it('sees a variation added to a product it already answered for', function () {
    // The snapshot is a GROUP BY over product_variants, so a new option under
    // an existing parent moves the answer without the parent row changing at
    // all -- the case a `products`-only invalidation would have missed.
    $parent = fmParent('fm-grow');
    fmVariant($parent, 12000);

    $pricing = fmPricing();

    expect($pricing->range($parent->fresh()))->toBe([12000, 12000]);

    fmVariant($parent, 4000);

    expect($pricing->range($parent->fresh()))->toBe([4000, 12000])
        ->and($parent->fresh()->effectivePrice())->toBe(4000);
});

it('sees a markdown applied after its first answer', function () {
    // A sale opened mid-process: the charged figure moves and so must the
    // compare-at that the badge and the "On sale" facet read.
    $parent = fmParent('fm-markdown', ['sale_starts_at' => now()->subDay()]);
    $variant = fmVariant($parent, 12000);

    $pricing = fmPricing();

    expect($pricing->range($parent->fresh()))->toBe([12000, 12000])
        ->and($pricing->regularLow($parent->fresh()))->toBe(12000)
        ->and($parent->fresh()->isOnSale())->toBeFalse();

    $variant->update(['sale_price' => 9000]);

    expect($pricing->range($parent->fresh()))->toBe([9000, 9000])
        ->and($pricing->regularLow($parent->fresh()))->toBe(12000)
        ->and($parent->fresh()->isOnSale())->toBeTrue();
});

it('forgets a variation that has been deleted', function () {
    /*
     * Without this the snapshot would keep quoting a price for an option that
     * no longer exists — the same staleness from the other direction.
     *
     * ▲ DELETED THROUGH THE MODEL, AND THAT IS THE BOUNDARY OF THE FIX RATHER
     * THAN A CONVENIENCE. The hook is an Eloquent event, so a mass delete
     * through the query builder — `$parent->variants()->delete()`, which is
     * Builder::delete() and fires nothing — does NOT invalidate the snapshot,
     * and neither does a raw DB::table() write. That was measured, not assumed:
     * this case was written with the relation delete first and stayed green
     * against a stale answer. Nothing in the application deletes variations
     * that way today; if something starts to, it has to call
     * VariantPricing::invalidate() itself, and that is written down on
     * Product::booted() as well as here.
     *
     * ▲ AND ONE THING HAS SINCE STARTED, on the `products` side rather than
     * this one: Admin\CatalogProductsApiController::bulkPrice() mass-updates
     * `products.price` and `products.sale_price` through the query builder. It
     * calls invalidate() after the write, and
     * tests/Feature/VariantPriceMemoRawWriteGuardTest.php is what makes the
     * next one impossible to add silently -- it scans app/ and database/ and
     * fails naming the file and the line. A sentence in a docblock could not
     * notice that arrival, which is why that file exists and this paragraph is
     * not the guard.
     */
    $parent = fmParent('fm-gone');
    $cheap = fmVariant($parent, 4000);
    fmVariant($parent, 12000);

    $pricing = fmPricing();

    expect($pricing->range($parent->fresh()))->toBe([4000, 12000]);

    $cheap->delete();

    expect($pricing->range($parent->fresh()))->toBe([12000, 12000]);
});

it('still reads the variations exactly once when nothing has changed', function () {
    /*
     * RULE 4. The freshness fix must not become a query per lookup — the flat
     * cost is what ApiProductTypeNotPublishedTest and StorefrontQueryBudgetTest
     * both pin, and it is the whole reason this class holds a memo at all.
     *
     * Twelve parents, twenty-four lookups, no writes in between: ONE grouped
     * read. MEASURED.
     *
     * MUTATION: make entry() call load() unconditionally. RUN: 1 failed here —
     * 24 grouped reads against 1.
     */
    $parents = [];

    foreach (range(1, 12) as $i) {
        $parent = fmParent('fm-flat-' . $i);
        fmVariant($parent, 1000 * $i);
        fmVariant($parent, 2000 * $i);
        $parents[] = $parent->fresh();
    }

    $pricing = fmPricing();

    $reads = 0;
    DB::listen(function ($event) use (&$reads): void {
        if (str_contains($event->sql, 'v.product_id as pid')) {
            $reads++;
        }
    });

    foreach ($parents as $parent) {
        $pricing->range($parent);
        $pricing->regularLow($parent);
    }

    expect($reads)->toBe(1, "twenty-four lookups ran the grouped read {$reads} times");
});

it('costs one reload and not one per row after a write', function () {
    // The other half of the same budget: a write invalidates ONCE, however many
    // products are then asked about, so an admin screen that saves a product
    // and re-renders a grid pays for one extra statement and not for one per
    // tile.
    $parents = [];

    foreach (range(1, 8) as $i) {
        $parent = fmParent('fm-reload-' . $i);
        fmVariant($parent, 1000 * $i);
        $parents[] = $parent;
    }

    $pricing = fmPricing();
    $pricing->range($parents[0]->fresh());

    $parents[0]->update(['name' => 'RENAMED']);

    $reads = 0;
    DB::listen(function ($event) use (&$reads): void {
        if (str_contains($event->sql, 'v.product_id as pid')) {
            $reads++;
        }
    });

    foreach ($parents as $parent) {
        $pricing->range($parent->fresh());
    }

    expect($reads)->toBe(1, "eight lookups after one write ran the grouped read {$reads} times");
});

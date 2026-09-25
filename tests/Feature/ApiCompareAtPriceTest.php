<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * THE PUBLIC FEED SAID NOTHING ABOUT A VARIABLE PRODUCT'S MARKDOWN.
 *
 * ── THE DEFECT, AND IT IS SILENCE RATHER THAN A LIE ─────────────────────────
 *
 * Product::toApi() publishes `price` and `sale_price`, and on a SIMPLE product
 * the pair carries the whole story: `price` is the regular price and
 * `sale_price` is what is charged while a markdown runs. On a VARIABLE one it
 * cannot. WooCommerce keeps the money on the variations: `products.sale_price`
 * is NULL on the parent — ProductImporter refuses a row carrying one without a
 * regular price — so advertisedSalePrice() answers null for every marked-down
 * variable product in the catalogue, and `price` publishes the CHARGED
 * from-price.
 *
 * So a parent whose options are AED 120 and AED 190, marked down to AED 90 and
 * AED 140, published exactly this:
 *
 *     {"price": 9000, "sale_price": null}
 *
 * Nothing there is untrue and nothing there says a quarter is off. The shop's
 * own tile draws "-25% OFF" beside a struck AED 120 for the same row
 * (VariableProductSaleVisibleTest), so the feed and the storefront disagreed
 * about whether the product is on sale at all — the disagreement this area of
 * the codebase has now been repaired for four times.
 *
 * ── WHY A TWELFTH KEY AND NOT A NEW MEANING FOR `price` ─────────────────────
 *
 * Making the pair mean one thing for both kinds of product is possible and it
 * is a CHOICE OF CONTRACT rather than a fix:
 *
 *   A. `price` becomes the compare-at for variable rows and `sale_price` the
 *      charged from-price. Every consumer that already computes
 *      `sale_price ?? price` — the expression ApiAdvertisedPriceTest pins as
 *      what the checkout will charge — renders the markdown with no change at
 *      all. And every consumer that reads `price` alone silently starts
 *      quoting AED 120 where it quoted AED 90.
 *   B. both keys keep their values and a third one carries the missing figure.
 *      Nothing already published moves; a consumer that wants to draw the
 *      markdown reads one new key.
 *
 * B is what ships. A changes a published number on an unauthenticated feed with
 * consumers this repository cannot see, which is the owner's call and not a
 * lane's — and B does not foreclose it, because under A this key would equal
 * `price` on every row and still be correct. The half of that decision this
 * lane could make without him is the half that moves nothing.
 *
 * ── WHAT A CONSUMER DOES WITH IT ────────────────────────────────────────────
 *
 *     charged = sale_price ?? price       (unchanged)
 *     was     = compare_at_price          (strike it; null means no strike)
 *     percent = 1 - charged / compare_at_price
 *
 * One rule for both kinds of product, and `compare_at_price !== null` is the
 * on-sale test that works for both — which `sale_price !== null` never did.
 *
 * ── MUTATION NOTES, ALL RUN ─────────────────────────────────────────────────
 *
 * 1. Delete the `compare_at_price` key from Product::toApi(). RUN: 8 failed —
 *    the seven cases here that read it (five of them as an ErrorException on
 *    the missing key, which is the shape a consumer would meet) and 'still
 *    publishes exactly the fields Product::toApi() promises' in
 *    ApiProductIndexCostTest, which is the pin advanced for it.
 * 2. Publish `$this->compareAtPrice()` unconditionally, dropping the isOnSale()
 *    test. RUN: 3 failed — 'says nothing when nothing is off', 'says nothing
 *    when the window has shut' and the feed-wide sweep, each publishing a
 *    compare-at equal to a price nothing is discounted from. That is the
 *    strikethrough that reads as a lie, on the endpoint least able to explain
 *    itself.
 * 3. Publish `$this->price` in place of compareAtPrice(). RUN: 2 failed — the
 *    two variable cases, reading null where 12000 is expected: the parent's own
 *    column is NULL, which is the whole reason this key exists.
 * 4. Change VariantPricing::load()'s `MIN(v.price) as reg` to
 *    `MIN(v.sale_price) as reg`. RUN: 2 failed — the same two, at 9000 against
 *    12000: a compare-at equal to the charged price, advertising nothing off a
 *    25% saving.
 * 5. Cast the key — `(int) ($this->isOnSale() ? ... : null)`. RUN: 4 failed,
 *    every case that expects null, each reading 0. Recorded because it is the
 *    shape of change that looks like tidying: `(int) null` is the AED 0 this
 *    whole area of the codebase has been repaired for three times, and it
 *    reaches an unauthenticated feed as `"compare_at_price": 0` — a markdown
 *    from nothing.
 */

/** A variable parent with an open window and no price of its own. */
function apiCaParent(string $slug, array $extra = []): Product
{
    return Product::create(array_merge([
        'slug' => $slug,
        'name' => 'Cushion ' . $slug,
        'status' => 'publish',
        'is_visible' => true,
        'price' => null,
        'stock_status' => 'instock',
        'type' => 'variable',
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addWeek(),
    ], $extra));
}

function apiCaVariant(Product $parent, ?int $price, ?int $salePrice = null): ProductVariant
{
    return ProductVariant::create([
        'product_id' => $parent->id,
        'price' => $price,
        'sale_price' => $salePrice,
        'stock_status' => 'instock',
    ]);
}

/**
 * One row off the INDEX, found by paging.
 *
 * The index answers a bare array ordered by `position` then `id` with a
 * server-side cap of 100, so a product a test creates sits after the seeded
 * catalogue. Paging is what a real consumer does, and it proves the row is
 * published rather than merely not contradicted.
 */
function apiCaRow(Tests\TestCase $t, string $slug): ?array
{
    for ($page = 1; $page <= 20; $page++) {
        $rows = $t->getJson('/api/products?limit=100&page=' . $page)->assertOk()->json();

        if ($rows === []) {
            return null;
        }

        foreach ($rows as $row) {
            if (($row['slug'] ?? null) === $slug) {
                return $row;
            }
        }
    }

    return null;
}

it('measures the saving against the from-price the tile printed', function () {
    /*
     * THE DEFECT. Options at AED 120 and AED 190, marked down to AED 90 and
     * AED 140: the feed said 9000 and nothing else.
     *
     * AED 120 is MIN(regular), the from-price this product advertised the day
     * before the markdown started — not "the regular price of whichever option
     * is cheapest now", which on options at (regular 100, sale 40) and (regular
     * 50, no sale) would advertise 60% off a saving of 20%. The argument is
     * written out on VariantPricing::regularLow(); this case is where the feed
     * is held to it.
     */
    $parent = apiCaParent('api-ca-cushion');
    apiCaVariant($parent, 12000, 9000);
    apiCaVariant($parent, 19000, 14000);

    $row = apiCaRow($this, 'api-ca-cushion');

    expect($row)->not->toBeNull('the variable product is missing from the feed entirely');
    expect($row['compare_at_price'])->toBe(12000);

    // AND THE TWO KEYS THAT WERE ALREADY THERE HAVE NOT MOVED. `price` is the
    // CHARGED from-price, which ApiProductTypeNotPublishedTest pins and this
    // change deliberately did not redefine; `sale_price` stays null because
    // the parent row carries no markdown of its own.
    expect($row['price'])->toBe(9000);
    expect($row['sale_price'])->toBeNull();
});

it('answers the same figures on the detail route', function () {
    // /api/products/{slug} loads the whole row rather than INDEX_COLUMNS, so a
    // key that is right on one door and wrong on the other is a real shape of
    // defect here — Api\ProductController::show() and index() have disagreed
    // before (ApiAdvertisedPriceTest's last case).
    $parent = apiCaParent('api-ca-detail');
    apiCaVariant($parent, 12000, 9000);
    apiCaVariant($parent, 19000, 14000);

    $body = $this->getJson('/api/products/api-ca-detail')->assertOk()->json();

    expect($body['compare_at_price'])->toBe(12000)
        ->and($body['price'])->toBe(9000)
        ->and($body['sale_price'])->toBeNull();
});

it('carries the same key for a simple product, so a consumer needs one rule', function () {
    /*
     * THE POINT OF THE KEY. On a simple product the compare-at is the `price`
     * column, so this duplicates a figure already published — and that
     * duplication is the feature: `compare_at_price` is the struck figure for
     * EVERY kind of product, and a consumer that reads it draws the same badge
     * on both without asking what `type` a row is (which this feed does not
     * publish, and should not).
     */
    $simple = Product::create([
        'slug' => 'api-ca-simple', 'name' => 'Toner', 'status' => 'publish', 'is_visible' => true,
        'price' => 20000, 'sale_price' => 5000, 'stock_status' => 'instock', 'type' => 'simple',
    ]);

    $row = apiCaRow($this, $simple->slug);

    expect($row['compare_at_price'])->toBe(20000)
        ->and($row['price'])->toBe(20000)
        ->and($row['sale_price'])->toBe(5000);

    // The one expression a consumer needs, for both kinds of row.
    expect($row['sale_price'] ?? $row['price'])->toBe(5000);
});

it('says nothing when nothing is off', function () {
    // A compare-at equal to the charged price is not information, it is an
    // invitation to strike through a figure nobody is discounting. null is the
    // same answer advertisedSalePrice() gives for a sale that is not running.
    $parent = apiCaParent('api-ca-plain', ['sale_starts_at' => null, 'sale_ends_at' => null]);
    apiCaVariant($parent, 12000);
    apiCaVariant($parent, 19000);

    $simple = Product::create([
        'slug' => 'api-ca-plain-simple', 'name' => 'Toner', 'status' => 'publish', 'is_visible' => true,
        'price' => 20000, 'stock_status' => 'instock', 'type' => 'simple',
    ]);

    expect(apiCaRow($this, 'api-ca-plain')['compare_at_price'])->toBeNull();
    expect(apiCaRow($this, $simple->slug)['compare_at_price'])->toBeNull();
});

it('says nothing when the window has shut', function () {
    /*
     * THE TRAP THIS ENDPOINT WAS ALREADY BITTEN BY, one key along.
     * `sale_price` used to be published raw, so the feed advertised sales that
     * had ended and sales that had not opened while the checkout charged the
     * real price. A compare-at is the same claim from the other side — "this
     * used to cost more, and it is cheaper now" — so it honours the same
     * window, and `products.sale_starts_at`/`sale_ends_at` are selected by
     * INDEX_COLUMNS precisely so it can.
     */
    $shut = apiCaParent('api-ca-shut', [
        'sale_starts_at' => now()->subMonth(),
        'sale_ends_at' => now()->subDay(),
    ]);
    apiCaVariant($shut, 12000, 9000);

    $early = apiCaParent('api-ca-early', [
        'sale_starts_at' => now()->addWeek(),
        'sale_ends_at' => null,
    ]);
    apiCaVariant($early, 12000, 9000);

    $shutRow = apiCaRow($this, 'api-ca-shut');
    $earlyRow = apiCaRow($this, 'api-ca-early');

    expect($shutRow['compare_at_price'])->toBeNull()
        ->and($shutRow['price'])->toBe(12000)
        ->and($earlyRow['compare_at_price'])->toBeNull()
        ->and($earlyRow['price'])->toBe(12000);
});

it('never publishes a compare-at below what is charged, and never a zero', function () {
    /*
     * THE TWO RULES A STRUCK FIGURE HAS, ASSERTED ACROSS THE WHOLE FEED rather
     * than on one row — because this is the invariant a consumer relies on and
     * a single case only ever proves one product.
     *
     * The zero half is not hypothetical: a variation priced by `sale_price`
     * alone has no regular price anywhere, MIN() over an all-NULL set is NULL,
     * and `(int) null` would publish a markdown FROM AED 0 on an
     * unauthenticated endpoint.
     */
    $parent = apiCaParent('api-ca-rules');
    apiCaVariant($parent, 12000, 9000);
    apiCaVariant($parent, 19000, 14000);

    $noRegular = apiCaParent('api-ca-noregular');
    apiCaVariant($noRegular, null, 9000);

    Product::create([
        'slug' => 'api-ca-rules-simple', 'name' => 'Toner', 'status' => 'publish', 'is_visible' => true,
        'price' => 20000, 'sale_price' => 5000, 'stock_status' => 'instock', 'type' => 'simple',
    ]);

    $seen = 0;

    for ($page = 1; $page <= 20; $page++) {
        $rows = $this->getJson('/api/products?limit=100&page=' . $page)->assertOk()->json();

        if ($rows === []) {
            break;
        }

        foreach ($rows as $row) {
            $compare = $row['compare_at_price'] ?? null;

            if ($compare === null) {
                continue;
            }

            $seen++;
            $charged = $row['sale_price'] ?? $row['price'];

            expect($compare)->toBeGreaterThan(0, "{$row['slug']} publishes a compare-at of {$compare}");
            expect($compare)->toBeGreaterThan(
                (int) $charged,
                "{$row['slug']} publishes a compare-at of {$compare} against a charged price of {$charged}"
            );
        }
    }

    expect($seen)->toBeGreaterThan(0, 'no row on the feed carried a compare-at, so this swept nothing');
    expect(apiCaRow($this, 'api-ca-noregular')['compare_at_price'])->toBeNull();
});

it('refuses to publish a compare-at for a row whose shape it cannot see', function () {
    /*
     * FAIL CLOSED, the rule /api/* is held to. A narrowed SELECT that never
     * fetched `price` must not acquire a compare-at the query did not ask
     * about: compareAtPrice() answers null without it and isOnSale() answers
     * false on a null compare-at, so the projection publishes null rather than
     * a guess. Asserted through toApi() directly, exactly as
     * ApiAdvertisedPriceTest asserts the same rule for the sale window, because
     * the endpoint itself selects the columns and cannot reach this state.
     */
    $parent = apiCaParent('api-ca-narrow');
    apiCaVariant($parent, 12000, 9000);

    $narrow = Product::query()->where('slug', 'api-ca-narrow')
        ->select(['id', 'slug', 'name', 'type'])->first();

    expect($narrow->toApi()['compare_at_price'])->toBeNull();
});

it('costs no extra statement for knowing what a variable product was', function () {
    /*
     * RULE 4, MEASURED. compareAtPrice() and isOnSale() read the SAME grouped
     * row App\Services\VariantPricing already holds for the from-price, so this
     * key is free: deriving it per row would be one statement per product on an
     * unauthenticated, unthrottled endpoint that returns up to a hundred of
     * them — the exact N+1 ApiProductTypeNotPublishedTest's own cost case
     * refuses.
     *
     * ▲ ONE WARM-UP REQUEST FIRST, and ▲ forgetScopedInstances() BEFORE EVERY
     * MEASURED ONE. Setting::map() memoises in a process-level static as well
     * as the cache, so the first request of a process is dearer than every
     * later one; and a test does not reboot the container between getJson()
     * calls, so without the reset the second request answers from the first
     * one's VariantPricing memo. Both traps are documented in
     * ApiProductTypeNotPublishedTest and both were measured there.
     *
     * MEASURED: 1 statement with no variable product on the feed, 2 with one,
     * 2 with twenty-four — identical to the numbers that file records, which
     * is the point: this key added nothing.
     */
    $count = function (): int {
        $this->app->forgetScopedInstances();

        $n = 0;
        DB::listen(function () use (&$n): void { $n++; });
        $this->getJson('/api/products?limit=100&page=1')->assertOk();

        return $n;
    };

    $make = function (int $i): void {
        $parent = apiCaParent('api-ca-flat-' . $i);
        apiCaVariant($parent, 12000 + $i, 9000 + $i);
        apiCaVariant($parent, 19000 + $i, 14000 + $i);
    };

    Product::query()->delete();

    $count();                   // warm-up, discarded
    $none = $count();

    $make(1);
    $one = $count();

    foreach (range(2, 24) as $i) {
        $make($i);
    }

    $many = $count();

    expect($many)->toBe(
        $one,
        "twenty-four marked-down variable products cost {$many} statements where one costs {$one} — "
        .'the compare-at is being derived per row, on an endpoint anyone may call for free'
    );

    expect($many - $none)->toBe(
        1,
        'variable products add ' . ($many - $none) . ' statements to this endpoint; the grouped '
        .'read is one statement and the compare-at comes off the same row'
    );
});

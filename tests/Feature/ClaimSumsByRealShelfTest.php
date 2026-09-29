<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\StockClaim;
use App\Services\StockUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * TWO LINES THAT SHARE A SHELF WERE CHECKED AGAINST IT SEPARATELY
 * =============================================================================
 *
 * ── THE DEFECT ─────────────────────────────────────────────────────────────
 *
 * `StockClaim::perShelf()` keyed demand on the VARIANT id whenever a line
 * carried one:
 *
 *     $key = $variantId !== null ? 'variant:' . $variantId : 'product:' . $productId;
 *
 * and `claimOne()` decided the shelf AFTERWARDS, by a different rule:
 *
 *     if ($variant !== null && $variant->manage_stock) { …variant's shelf… }
 *     if ($product->manage_stock)                      { …parent's shelf… }
 *
 * A variant that does NOT count its own stock comes off its PARENT's shelf —
 * which is how a variable product with one shared stock figure behaves, and
 * how `VariationImporter` leaves every variant this shop imported. So a basket
 * holding that variant AND the parent product as a plain line asked for one
 * jar twice, and the two asks were checked one at a time against a shelf they
 * both came off.
 *
 * ── WHAT THAT LOOKED LIKE ON THE SHOP ──────────────────────────────────────
 *
 * It cannot oversell — the decrement repeats its condition in its own WHERE —
 * so this is a WRONG SENTENCE, not a wrong number, and the wrong sentence is
 * the one that matters to a shopper who wants to fix their basket:
 *
 *   the honest answer  "Only 1 of Hydrating Serum is left. Please REDUCE THE
 *                       QUANTITY in your basket to continue."
 *   what they got      "Hydrating Serum is sold out. Please REMOVE IT from
 *                       your basket to continue."
 *
 * The first claim emptied the shelf and set `stock_status` to `outofstock`;
 * the second then failed `claimOne()`'s own status check and reported the
 * product as gone. A shopper told to remove a line removes it — and the shop
 * loses the sale of the unit it actually had.
 *
 * ── AND THE HALF THAT MUST NOT MOVE ────────────────────────────────────────
 *
 * Summing by the real shelf must not lose the PER-LINE availability check. A
 * variant can be marked sold out by hand while its parent is in stock, so
 * merging two lines onto one shelf and checking only one of them would sell a
 * variant the owner has delisted. The decrement is summed; the status question
 * is asked once per distinct product-and-variant pair. The last two cases are
 * that.
 */
function shelfProduct(string $name, int $stock, bool $manage = true): Product
{
    return Product::create([
        'slug' => 'shelf-' . Str::slug($name) . '-' . Str::random(6),
        'name' => $name,
        'type' => 'variable',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9000,
        'stock_status' => 'instock',
        'manage_stock' => $manage,
        'stock' => $stock,
    ]);
}

/** A variant that shares its parent's stock figure — `manage_stock` off. */
function shelfVariant(Product $product, bool $manage = false, ?int $stock = null): ProductVariant
{
    return ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'SH-' . Str::random(6),
        'price' => 9000,
        'stock_status' => 'instock',
        'manage_stock' => $manage,
        'stock' => $stock,
        'position' => 0,
    ]);
}

/** @param list<array{0:Product,1:?ProductVariant,2:int,3:string}> $lines */
function shelfClaim(array $lines): ?string
{
    $payload = [];

    foreach ($lines as [$product, $variant, $quantity, $label]) {
        $payload[] = [
            'product_id' => (int) $product->id,
            'variant_id' => $variant?->id === null ? null : (int) $variant->id,
            'quantity' => $quantity,
            'label' => $label,
        ];
    }

    try {
        DB::transaction(fn () => app(StockClaim::class)->claim($payload));
    } catch (StockUnavailable $e) {
        return $e->getMessage();
    }

    return null;
}

it('tells a shopper to reduce the quantity, not to remove a product the shop has', function () {
    /*
     * ▲ THE DEFECT ITSELF. One jar on the shelf; a variant line and a plain
     * line that both come off it, asking for one each.
     *
     * Before the fix this answered "Hydrating Serum is sold out. Please remove
     * it from your basket to continue." — the first claim emptied the shelf,
     * the second met the status the first had just written. The shop had a jar
     * and told the shopper it had none.
     *
     * MUTATION NOTE, AND IT IS NOT A ONE-LINER, WHICH IS ITSELF THE FINDING.
     * Restore StockClaim to its pre-fix shape — the old claim(), claimOne() and
     * one-argument perShelf(), which is
     * `git show <base>:app/Services/StockClaim.php` — and this is red exactly
     * as the shop was: "Hydrating Serum is sold out. Please remove it from your
     * basket to continue." RUN.
     *
     * ▲ Putting back ONLY the old key (`'variant:' . $variantId` before the
     * shelf is resolved) is GREEN here, and that is worth knowing rather than
     * hiding. TWO things had to be wrong together to produce that sentence: the
     * split demand, and the fact that claimOne() RE-READ each row as it reached
     * it, so the second claim saw the zero and the `outofstock` the first had
     * just written. The rows are locked and read once up front now, so `have`
     * is the figure the basket was measured against and the sentence comes out
     * honest even if the demand is split. The split still shows up in the
     * ledger and in the statement count — the two cases below — and those DO
     * have a one-line mutation, run and recorded on each.
     */
    $serum = shelfProduct('Hydrating Serum', 1);
    $fifty = shelfVariant($serum);

    $message = shelfClaim([
        [$serum, $fifty, 1, 'Hydrating Serum — 50 ml'],
        [$serum, null, 1, 'Hydrating Serum'],
    ]);

    expect($message)->not->toBeNull('Two units off a shelf of one must still be refused.');

    expect(str_contains((string) $message, 'Only 1 of'))->toBeTrue(
        'The shelf holds one, so the sentence is about the quantity: ' . $message
    );

    expect(str_contains((string) $message, 'reduce the quantity'))->toBeTrue(
        'And it must tell the shopper what to actually do: ' . $message
    );

    expect((int) $serum->fresh()->stock)->toBe(1, 'A refused claim writes nothing.')
        ->and((string) $serum->fresh()->stock_status)->toBe('instock', 'And delists nothing.');
});

it('takes the shared shelf down once for the total', function () {
    /*
     * The same basket against a shelf that CAN cover it. Two units wanted, two
     * on the shelf: one decrement of two, and the shelf is emptied and marked
     * once rather than twice.
     *
     * MUTATION NOTE. Key on `'variant:' . $variantId` whenever a line carries
     * one — the old key, before the shelf is resolved — and this is red: the
     * shelf still reaches 0, because the arithmetic was never wrong, but
     * `order_stock_claims` carries TWO rows for one shelf instead of one. RUN.
     */
    $serum = shelfProduct('Hydrating Serum', 2);
    $fifty = shelfVariant($serum);

    $order = DB::table('orders')->insertGetId([
        'order_number' => 'SHELF-' . Str::random(6),
        'email' => 'shelf@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 18000, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 18000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::transaction(function () use ($serum, $fifty, $order) {
        app(StockClaim::class)->claim([
            ['product_id' => $serum->id, 'variant_id' => $fifty->id, 'quantity' => 1, 'label' => 'Serum — 50 ml'],
            ['product_id' => $serum->id, 'variant_id' => null, 'quantity' => 1, 'label' => 'Serum'],
        ], (int) $order);
    });

    expect((int) $serum->fresh()->stock)->toBe(0)
        ->and((string) $serum->fresh()->stock_status)->toBe('outofstock');

    $rows = DB::table('order_stock_claims')->where('order_id', $order)->get();

    expect($rows)->toHaveCount(1, 'One shelf, one ledger row — two is the split this fixes.')
        ->and((int) $rows->first()->quantity)->toBe(2);
});

it('still keeps a variant that counts its own stock on its own shelf', function () {
    /*
     * The other direction, and the reason the rule is `manage_stock` rather
     * than "a variant is always its own shelf" or "never". A variant that
     * counts its own stock has a shelf of its own and must not be merged into
     * its parent's.
     *
     * MUTATION NOTE. Drop the `$variant->manage_stock` test from the shelf
     * decision so every variant line falls to the parent and this is red: the
     * parent's 5 goes down by 2 and the variant's own 3 never moves. RUN.
     */
    $serum = shelfProduct('Hydrating Serum', 5);
    $counted = shelfVariant($serum, manage: true, stock: 3);

    DB::transaction(function () use ($serum, $counted) {
        app(StockClaim::class)->claim([
            ['product_id' => $serum->id, 'variant_id' => $counted->id, 'quantity' => 2, 'label' => 'Serum — 50 ml'],
            ['product_id' => $serum->id, 'variant_id' => null, 'quantity' => 1, 'label' => 'Serum'],
        ]);
    });

    expect((int) $counted->fresh()->stock)->toBe(1, 'The variant counts its own stock.')
        ->and((int) $serum->fresh()->stock)->toBe(4, 'And the parent keeps its own figure.');
});

it('still refuses a variant the owner has marked sold out by hand', function () {
    /*
     * ▲ THE HALF THAT SUMMING BY SHELF COULD HAVE LOST, AND THE REASON THE
     *   STATUS QUESTION IS ASKED PER LINE AND NOT PER SHELF.
     *
     * A variant may be delisted while its parent is still selling. Merging its
     * line onto the parent's shelf and then asking the status question ONCE —
     * of whichever line happened to arrive first — would sell a variant the
     * owner has taken off the shop by hand, which is the flip he actually uses
     * in Store → Products.
     *
     * MUTATION NOTE. Ask the status question once per SHELF instead of once
     * per product-and-variant pair and this is red: the claim succeeds and the
     * delisted 50 ml is sold. RUN.
     */
    $serum = shelfProduct('Hydrating Serum', 5);
    $gone = shelfVariant($serum);
    $gone->update(['stock_status' => 'outofstock']);

    $message = shelfClaim([
        [$serum, null, 1, 'Hydrating Serum'],
        [$serum, $gone, 1, 'Hydrating Serum — 50 ml'],
    ]);

    expect($message)->not->toBeNull('A delisted variant must be refused even beside a healthy parent.');
    expect(str_contains((string) $message, '50 ml'))->toBeTrue(
        'And the refusal names the line that is gone, not the one that is fine: ' . $message
    );
    expect((int) $serum->fresh()->stock)->toBe(5, 'A refused claim writes nothing.');
});

it('costs one statement for the locks however many lines share a shelf', function () {
    /*
     * ── THE COST, MEASURED RATHER THAN ASSERTED ────────────────────────────
     *
     * Resolving the shelf BEFORE the sum means knowing each variant's
     * `manage_stock` before anything is grouped, and the naive way to learn it
     * is a lookup per line — an N+1 inside the transaction that places the
     * order, which is the worst place in this application to put one.
     *
     * So the rows are locked in ONE statement per table, up front, in id
     * order. That is fewer statements than the loop it replaces, not more: the
     * old code ran one `lockForUpdate` per line for the product and another for
     * the variant. A six-line basket cost twelve; it costs two.
     *
     * Locking in id order is also the safer shape against deadlocks — two
     * transactions that take the same rows now take them in the same sequence,
     * which N separate statements in basket order did not guarantee.
     *
     * MUTATION NOTE. Key on the old `'variant:' . $variantId` before the shelf
     * is resolved and this is red: six lines that share one shelf become six
     * separate decrements and six ledger writes where there should be one of
     * each. RUN.
     */
    $make = function (int $lines): array {
        $serum = shelfProduct('Serum ' . Str::random(4), 50);
        $payload = [];

        for ($i = 0; $i < $lines; $i++) {
            $variant = shelfVariant($serum);
            $payload[] = ['product_id' => $serum->id, 'variant_id' => $variant->id, 'quantity' => 1, 'label' => 'L' . $i];
        }

        return $payload;
    };

    $small = $make(2);
    $large = $make(6);

    $queries = 0;
    DB::listen(function () use (&$queries) { $queries++; });

    /*
     * A warm-up first, and it is not padding. StockSetRule::mode() reads a
     * setting and SettingsService memoises it in a process-level static, so the
     * FIRST claim in a process pays for that read and every later one does not.
     * Measured without this: the two-line basket reported 5 statements and the
     * six-line one 4 — the smaller basket looking more expensive, which is the
     * tell that the difference is a one-off and not a per-line cost.
     */
    $measureWarm = $make(1);
    DB::transaction(fn () => app(StockClaim::class)->claim($measureWarm));

    $measure = function (array $payload) use (&$queries): int {
        $queries = 0;
        DB::transaction(fn () => app(StockClaim::class)->claim($payload));

        return $queries;
    };

    $two = $measure($small);
    $six = $measure($large);

    expect($six)->toBe(
        $two,
        "A six-line basket ran {$six} statements and a two-line one {$two}. "
        . 'A difference is a lookup per line, inside the placing transaction.'
    );
});

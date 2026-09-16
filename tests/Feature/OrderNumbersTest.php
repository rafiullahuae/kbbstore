<?php

declare(strict_types=1);

/**
 * The order-number allocator's rules, one at a time and without a race.
 *
 * OrderNumberRaceTest proves the thing this was built for — two simultaneous
 * placements both succeeding — but it is MySQL-only, takes forty seconds, and
 * a race is a blunt instrument for pinning a rule. These are the deterministic
 * half: each one states a single rule, runs on both engines, and fails for one
 * reason.
 *
 * The division of labour matters, because a guard that is only checked by the
 * race test is a guard that stops being checked the moment somebody adds a
 * retry that papers over it. That is not hypothetical: removing withTrashed()
 * from the allocator leaves the BACK-OFFICE race arm green, because
 * ManualOrderBuilder::create() retries and the sequence has moved past the
 * clash by the second attempt. The rule is pinned here instead, where a retry
 * cannot hide it.
 */

use App\Models\Order;
use App\Services\Orders\OrderNumbers;
use Illuminate\Support\Facades\DB;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/** An order holding a specific number, optionally trashed. */
function orderNumbered(string $number, bool $trashed = false): Order
{
    $order = Order::create([
        'order_number' => $number,
        'email' => 'shopper@example.ae',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 8900, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'tax_total' => 0, 'total' => 8900,
        'payment_method' => 'cod',
    ]);

    if ($trashed) {
        $order->delete();
    }

    return $order;
}

/** Point the sequence at a known number. */
function sequenceAt(int $next): void
{
    DB::table(OrderNumbers::TABLE)->updateOrInsert(
        ['id' => OrderNumbers::ROW_ID],
        ['next_number' => $next],
    );
}

function sequenceValue(): int
{
    return (int) DB::table(OrderNumbers::TABLE)
        ->where('id', OrderNumbers::ROW_ID)
        ->value('next_number');
}

it('hands out the sequence value and advances past it', function () {
    sequenceAt(70000);

    expect(app(OrderNumbers::class)->allocate())->toBe('70000');
    expect(sequenceValue())->toBe(70001);

    // A second caller gets the next one, never the same one twice.
    expect(app(OrderNumbers::class)->allocate())->toBe('70001');
});

it('never hands out a number a live order already holds', function () {
    orderNumbered('70000');
    sequenceAt(70000);

    expect(app(OrderNumbers::class)->allocate())->toBe('70001');
});

/**
 * The guard that cost the shop every checkout it had.
 *
 * A soft-deleted order keeps its row, and `orders.order_number` is UNIQUE
 * across the whole table — `deleted_at` is just a column, invisible to the
 * index. The checkout's old allocator queried through the default scope, could
 * not see the trashed row, and handed its number out on every single request:
 * one cancelled order at the top of the range and nobody in the shop could
 * check out, with no concurrency involved at all.
 *
 * Delete `withTrashed()` from OrderNumbers::taken() and this goes red.
 */
it('never hands out a number a SOFT-DELETED order already holds', function () {
    orderNumbered('70000', trashed: true);
    sequenceAt(70000);

    $number = app(OrderNumbers::class)->allocate();

    expect($number)->toBe('70001');

    // And the number really is still spoken for, which is the whole reason.
    expect(Order::withTrashed()->where('order_number', '70000')->exists())->toBeTrue();
    expect(Order::where('order_number', '70000')->exists())->toBeFalse();
});

/**
 * Imported WooCommerce orders keep their numbers — CLAUDE.md is explicit — so
 * the allocator has to step over a whole imported block rather than reissue
 * any of it.
 */
it('steps over a block of imported numbers without reissuing one', function () {
    foreach (range(70000, 70009) as $n) {
        orderNumbered((string) $n);
    }

    sequenceAt(70000);

    expect(app(OrderNumbers::class)->allocate())->toBe('70010');
});

/**
 * The lexical-maximum regression.
 *
 * `order_number` is a VARCHAR, so SQL's MAX() compares it as TEXT: with '9999'
 * and '50002' both present, MAX() answers '9999' and `(int)` makes that 9999 —
 * which is how the old allocator could propose a number 40,000 below the top
 * of the table. seedValue() reads the numbers out and compares them as
 * integers instead.
 */
it('seeds above the highest number even when the lexical maximum is lower', function () {
    orderNumbered('9999');
    orderNumbered('50002');

    // The trap itself, so the test documents what it is defending against.
    expect((string) Order::withTrashed()->max('order_number'))->toBe('9999');

    expect(OrderNumbers::seedValue())->toBe(50003);
});

it('seeds above soft-deleted orders too', function () {
    orderNumbered('50002', trashed: true);

    expect(OrderNumbers::seedValue())->toBe(50003);
});

it('ignores order numbers that are not plain digits when seeding', function () {
    orderNumbered('WC-ORDER-8');
    orderNumbered('70000');

    // 'WC-ORDER-8' must neither raise nor be cast to some number.
    expect(OrderNumbers::seedValue())->toBe(70001);
});

it('starts a store with no orders at the documented floor', function () {
    Order::query()->forceDelete();

    expect(OrderNumbers::seedValue())->toBe(OrderNumbers::FIRST_NUMBER);
});

/**
 * The sequence row is data, and data goes missing — a partial restore, a
 * truncate in a test. Losing it must not stop the shop taking orders, and must
 * not restart numbering underneath what the table already holds.
 */
it('rebuilds the sequence row if it has gone missing', function () {
    orderNumbered('70000');

    DB::table(OrderNumbers::TABLE)->delete();

    expect(app(OrderNumbers::class)->allocate())->toBe('70001');
    expect(sequenceValue())->toBe(70002);
});

/**
 * The self-healing jump.
 *
 * An import run AFTER the sequence was created writes numbers the sequence
 * knows nothing about. Walking over them one lookup at a time would spend the
 * step budget and refuse to place the order, so a collision makes the
 * allocator jump above everything the table holds in one go.
 */
it('jumps above a block written after the sequence was created', function () {
    sequenceAt(70000);

    foreach (range(70000, 70400) as $n) {
        orderNumbered((string) $n);
    }

    expect(app(OrderNumbers::class)->allocate())->toBe('70401');
});

/**
 * New numbers CONTINUE from whatever is already there.
 *
 * The contract the old allocator carried in its docblock, and the one
 * CheckoutPlacementTest pins from the storefront end: an imported WooCommerce
 * order at 48231 must not be followed by an order numbered 10001. Uniqueness
 * alone would allow that — nothing would break, and the shop's order list
 * would quietly stop being in order.
 *
 * Delete the resync() call at the top of allocate() and this goes red.
 */
it('continues above an imported block even when the sequence is behind it', function () {
    orderNumbered('48231');

    // A sequence that knows nothing about the import, which is the state after
    // an import runs against a store whose sequence was created earlier.
    sequenceAt(10001);

    expect((int) app(OrderNumbers::class)->allocate())->toBeGreaterThan(48231);
});

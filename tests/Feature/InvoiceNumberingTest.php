<?php

declare(strict_types=1);

/**
 * `orders.invoice_number`, the column nothing filled.
 *
 * It has been a real UNIQUE column since Phase 0 and every order in the store
 * has had NULL in it. An invoice number is a financial reference, so getting
 * this wrong has two distinct shapes and both are pinned below:
 *
 *   THE SAME NUMBER ON TWO ORDERS. Two admins press Print on two orders in the
 *   same second, both read the same MAX, both compute the same candidate. No
 *   amount of checking in PHP closes that window — the window is between the
 *   SELECT and the UPDATE. The UNIQUE index closes it, and the tests here drive
 *   that race deliberately rather than describing it.
 *
 *   A SECOND NUMBER ON ONE ORDER. An order invoiced twice is one debt with two
 *   documents against it. `WHERE invoice_number IS NULL` in the claim closes
 *   that one, and allocate() is asserted idempotent.
 *
 * AND THE IMPORT. docs/IMPORT-READINESS.md D6: WooCommerce invoice numbers may
 * be imported and the sequence is expected to continue from the maximum. A
 * scheme derived from `orders.id` would collide with them on the first new
 * order — which is exactly the bug that took down checkout when
 * nextOrderNumber() was `10000 + max(id)`.
 */

use App\Models\Order;
use App\Services\Invoices\InvoiceNumbers;
use App\Services\SettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function invOrder(array $attributes = []): Order
{
    static $n = 0;
    $n++;

    return Order::create(array_merge([
        'order_number' => 'INV-N-' . $n . '-' . uniqid(),
        'email' => 'inv-number-' . $n . '@example.test',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 10000,
        'total' => 10000,
    ], $attributes));
}

function invNumbers(): InvoiceNumbers
{
    return app(InvoiceNumbers::class);
}

/* ------------------------------------------------------------- the sequence */

it('starts at the configured starting number on a store with no invoices', function () {
    expect(invNumbers()->allocate(invOrder()))->toBe(InvoiceNumbers::DEFAULT_START);
});

it('lets the owner set where the sequence begins', function () {
    app(SettingsService::class)->set(InvoiceNumbers::START_KEY, 7000);

    expect(invNumbers()->allocate(invOrder()))->toBe(7000)
        ->and(invNumbers()->allocate(invOrder()))->toBe(7001);
});

it('issues consecutive numbers and never repeats one', function () {
    $issued = [];

    for ($i = 0; $i < 6; $i++) {
        $issued[] = invNumbers()->allocate(invOrder());
    }

    expect($issued)->toBe([1000, 1001, 1002, 1003, 1004, 1005])
        ->and(array_unique($issued))->toHaveCount(6);
});

it('stamps invoiced_at in the same write as the number', function () {
    $order = invOrder();

    invNumbers()->allocate($order);
    $order->refresh();

    // A row with a number and no date, or a date and no number, is a state
    // nothing else in the app knows how to read.
    expect($order->invoice_number)->toBe(1000)
        ->and($order->invoiced_at)->not->toBeNull();
});

/* ---------------------------------------------------------------- the import */

it('continues from an imported WooCommerce number rather than from max(id)', function () {
    // What the import leaves behind: one order carrying its historical
    // WebToffee number, sitting on a low primary key.
    $imported = invOrder(['invoice_number' => 50231, 'invoiced_at' => '2024-03-02 10:00:00']);

    $fresh = invOrder();

    expect(invNumbers()->allocate($fresh))->toBe(50232)
        // And the imported order is untouched: it already has a number, so the
        // sequence is read, never rewritten.
        ->and($imported->fresh()->invoice_number)->toBe(50231);
});

it('does not hand out a number a partially imported run has already used', function () {
    // D6's warning made concrete: SOME historical numbers came across and some
    // did not, so the sequence has a hole in the middle. Walking forward from
    // the maximum steps over the hole rather than into it.
    invOrder(['invoice_number' => 4000]);
    invOrder(['invoice_number' => 4003]);

    expect(invNumbers()->allocate(invOrder()))->toBe(4004);
});

it('counts a trashed order as holding its number', function () {
    // A soft-deleted row still occupies the unique index. Skipping it would
    // hand the same number out twice and the second write would simply fail.
    $trashed = invOrder(['invoice_number' => 8800]);
    $trashed->delete();

    expect($trashed->trashed())->toBeTrue()
        ->and(invNumbers()->allocate(invOrder()))->toBe(8801);
});

it('treats the starting setting as a floor and never as an override', function () {
    invOrder(['invoice_number' => 9000]);

    // Lowered after the store has already issued higher numbers — by a typo, or
    // by an owner who did not realise. Re-issuing 1000 would be a duplicate
    // invoice number in a filed set of accounts.
    app(SettingsService::class)->set(InvoiceNumbers::START_KEY, 10);

    expect(invNumbers()->allocate(invOrder()))->toBe(9001);
});

/* ------------------------------------------------------------- exactly once */

it('gives one order one number however many times it is asked', function () {
    $order = invOrder();

    $first = invNumbers()->allocate($order);

    for ($i = 0; $i < 5; $i++) {
        expect(invNumbers()->allocate($order))->toBe($first);
    }

    // And the sequence advanced exactly once: the next order gets the next
    // number, not the sixth one after it.
    expect(invNumbers()->allocate(invOrder()))->toBe($first + 1);
});

it('never re-invoices an order that already carries an imported number', function () {
    $order = invOrder(['invoice_number' => 777]);

    expect(invNumbers()->allocate($order))->toBe(777)
        ->and($order->fresh()->invoice_number)->toBe(777);
});

/* -------------------------------------------------------------- the race */

it('lets the database refuse a duplicate number rather than trusting a check', function () {
    // The guarantee everything else rests on, asserted against the engine
    // rather than against the code that hopes it is there. This test runs on
    // SQLite in one suite and on real MySQL in the other, so the unique index
    // is proven present on both.
    invOrder(['invoice_number' => 3100]);
    $second = invOrder();

    expect(fn () => DB::table('orders')
        ->where('id', $second->id)
        ->update(['invoice_number' => 3100]))
        ->toThrow(QueryException::class);
});

it('cannot give two orders the same number when both claim it at once', function () {
    /*
     * The real race, driven by hand.
     *
     * Two admin requests arriving in the same moment both read MAX(invoice_number)
     * before either has written, so both compute the SAME candidate. That is
     * not a hypothetical interleaving to mock — it is simply what
     * nextCandidate() returns to both of them, so this test calls it twice and
     * lets the two claims fight over the answer.
     *
     * Exactly one wins. The loser is refused by the UNIQUE index, not by any
     * check in PHP, and allocate() picks the refusal up and moves on.
     */
    $a = invOrder();
    $b = invOrder();

    $numbers = invNumbers();

    $candidateA = $numbers->nextCandidate();
    $candidateB = $numbers->nextCandidate();

    expect($candidateB)->toBe($candidateA, 'both requests must really be racing for one number');

    expect($numbers->claim($a, $candidateA))->toBeTrue()
        ->and($numbers->claim($b, $candidateB))->toBeFalse();

    // The loser is not left without an invoice: the full allocation retries.
    expect($numbers->allocate($b))->toBe($candidateA + 1);

    $a->refresh();
    $b->refresh();

    expect($a->invoice_number)->toBe($candidateA)
        ->and($b->invoice_number)->toBe($candidateA + 1)
        ->and($a->invoice_number)->not->toBe($b->invoice_number);
});

it('cannot give one order two numbers when two claims race for it', function () {
    // The other half of the race: two requests for the SAME order. The second
    // claim is refused by `WHERE invoice_number IS NULL`, not by the index, and
    // allocate() answers with the number that was actually written.
    $order = invOrder();
    $numbers = invNumbers();

    expect($numbers->claim($order, 2200))->toBeTrue()
        ->and($numbers->claim($order, 2201))->toBeFalse();

    expect($order->fresh()->invoice_number)->toBe(2200)
        ->and($numbers->allocate($order->fresh()))->toBe(2200);

    // 2201 was never consumed — a refused claim writes nothing at all.
    expect(Order::withTrashed()->where('invoice_number', 2201)->exists())->toBeFalse();
});

it('walks past a stretch of taken numbers instead of failing on the first collision', function () {
    // The many-tabs case: several numbers above the maximum are already gone by
    // the time this caller writes. It steps over all of them in one allocate().
    invOrder(['invoice_number' => 5000]);
    invOrder(['invoice_number' => 5001]);
    invOrder(['invoice_number' => 5002]);
    invOrder(['invoice_number' => 5003]);

    expect(invNumbers()->allocate(invOrder()))->toBe(5004);
});

/* ------------------------------------------------------------ how it reads */

it('prints the number zero padded to five digits and leaves longer ones alone', function () {
    expect(InvoiceNumbers::format(1000))->toBe('01000')
        ->and(InvoiceNumbers::format(7))->toBe('00007')
        ->and(InvoiceNumbers::format(50231))->toBe('50231')
        ->and(InvoiceNumbers::format(123456))->toBe('123456');
});

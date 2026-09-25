<?php

declare(strict_types=1);

/**
 * The order confirmation costs the same whether the basket held one line or ten
 * — measured, at 1, 2, 5 and 10 — Lane Q11.
 *
 * ── WHY SLOPE AND NOT A TOTAL ───────────────────────────────────────────────
 *
 * A budget that caps a total passes for a year while the total grows one query
 * per row underneath it, and then fails on the day somebody's basket is large
 * enough. Two rounds on this repo have been bitten by exactly that. So this file
 * measures the same render at four sizes and asserts the DIFFERENCE is zero. A
 * cost that grows with the lines cannot hide inside a headroom.
 *
 * It matters more here than on a page. This runs while a customer is waiting for
 * a confirmation, and it runs inside a queued job where nobody is watching the
 * clock.
 *
 * ── WHAT THE BRIEF SAID, AND WHAT IS ACTUALLY TRUE ──────────────────────────
 *
 * docs/q10-component-load-contract.md's suite-wide census reported
 * `Order::$items` read lazily 14 times at OrderEmailPresenter:460, and
 * `OrderItem::$product` 6 times, and named the pair as "probably the same
 * one-line shape" as the live N+1 it had just found on the cart picker.
 *
 * MEASURED FIRST, AND IT IS NOT AN N+1. A lazy `hasMany` read is ONE query
 * returning every row; it is not one query per row. The census counts relation
 * READS, so 14 reads is 14 orders each asking once, not 14 lines. Driven at four
 * sizes, before anything was changed:
 *
 *     lines on the order      1     2     5    10   slope
 *     present() statements    3     3     3     3    0.00
 *     whole send statements   6     6     6     6    0.00
 *
 * And `OrderItem::$product` is not read by an order email at all — no email
 * template touches the catalogue; every word and number on the receipt is a
 * snapshot on `order_items`. The six reads the census found are elsewhere.
 *
 * ── SO WHAT DID CHANGE ──────────────────────────────────────────────────────
 *
 * `OrderEmailPresenter::present()` and `::ledgerWidth()` now say
 * `$order->loadMissing('items')` out loud. That is not a fix for a cost — it is
 * the identical single query — it is the read made explicit, so the presenter no
 * longer depends on its five callers having remembered and so it survives a
 * `preventLazyLoading()` run. The case below that records lazy reads printed
 * `Order::$items => 2` before it and `(none)` after.
 *
 * ── THE THREE MEASUREMENT TRAPS, ALL HANDLED HERE ───────────────────────────
 *
 *   1  `Setting::map()` memoises in a process-level static, so the FIRST render
 *      of a process is dearer than every one after it. A warm-up render is made
 *      and discarded before any number is taken.
 *   2  A test does not reboot the container between requests, so a `scoped()`
 *      binding — MailSettings is one, see MailServiceProvider — answers the
 *      second measurement out of the first one's memo.
 *      `app()->forgetScopedInstances()` runs before each.
 *   3  `DB::listen()` registers a listener that is never removed, so four
 *      measurements leave four listeners counting, including the fixture writes
 *      made BETWEEN them. `DB::enableQueryLog()` / `flushQueryLog()` is per
 *      connection and can be emptied, so it is what this file uses. There is no
 *      DB::listen() anywhere in it.
 *
 * And the fourth, which cost another lane its first fixture: EVERY ROW STATES
 * WHAT THE RENDER ACTUALLY PRODUCED before any count is compared. A render that
 * produced nothing is flat and cheap and proves nothing.
 *
 * MUTATION NOTES at the foot: nine run, with what each printed, including the
 * two that came back GREEN.
 */

use App\Mail\OrderConfirmation;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailSettings;
use App\Services\Mail\OrderEmailPresenter;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** The four sizes. 1 and 2 bracket the single-row hydrate() blind spot; 10 is a real basket. */
const Q11_SIZES = [1, 2, 5, 10];

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    // The log transport, so the measurement is of this application and not of a
    // socket. It changes no query: ServerMailTransport and LogTransport both
    // write the same one row to mail_deliveries.
    app(MailSettings::class)->save(['mail_transport' => MailSettings::TRANSPORT_LOG]);
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(MailConfigurator::class)->refresh();
});

afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

/**
 * An order of exactly $lines lines, EACH POINTING AT ITS OWN PRODUCT.
 *
 * Its own product per line, deliberately: a lazy `$item->product` read on an
 * order whose lines all name the same product would be answered once out of the
 * identity map and the N+1 would measure as flat. One product each is the only
 * fixture on which that cost is visible.
 */
function q11Order(int $lines): Order
{
    $order = Order::create([
        'order_number' => 'KBB-Q11-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 1000 * $lines,
        'shipping_total' => 2000,
        'total' => 1000 * $lines + 2000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ]);

    for ($i = 1; $i <= $lines; $i++) {
        $product = Product::create([
            'name' => "Q11 Product {$i} " . uniqid(),
            'slug' => 'q11-' . $i . '-' . uniqid(),
            'price' => 1000,
            'status' => 'publish',
            'type' => 'simple',
            'stock_status' => 'instock',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'name' => "Q11 Line {$i}",
            'quantity' => 1,
            'unit_price' => 1000,
            'total' => 1000,
            'sku' => 'Q11SKU' . $i,
        ]);
    }

    return $order;
}

/**
 * Count the statements one call makes, and hand back what it produced.
 *
 * enableQueryLog/flushQueryLog rather than DB::listen(), for trap 3 above.
 *
 * @return array{0:int, 1:mixed}
 */
function q11Count(callable $fn): array
{
    app()->forgetScopedInstances();
    SettingsService::forgetMemo();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $produced = $fn();

    $statements = count(DB::getQueryLog());
    DB::flushQueryLog();
    DB::disableQueryLog();

    return [$statements, $produced];
}

/** The order, re-read so its lines are NOT already in memory. */
function q11Fresh(int $id): Order
{
    return Order::findOrFail($id);
}

/* ═══════════════════════════════════════════════════════════════════════════
   1 · THE SLOPE
   ═════════════════════════════════════════════════════════════════════════ */

it('presents an order for email at a cost that does not grow with its lines', function () {
    // Trap 1: the first render of a process fills Setting::map()'s static and is
    // dearer than every one after it. Discarded.
    (new OrderEmailPresenter)->present(q11Fresh(q11Order(2)->id));

    $cost = [];

    foreach (Q11_SIZES as $n) {
        $order = q11Order($n);

        [$statements, $presented] = q11Count(
            fn () => (new OrderEmailPresenter)->present(q11Fresh($order->id))
        );

        /*
         * WHAT THE RENDER ACTUALLY PRODUCED, asserted BEFORE the count is used
         * for anything. A presenter that returned an empty item list would be
         * flat at every size and this file would call that a pass.
         */
        expect($presented['items'])->toHaveCount($n, "the presenter produced {$n} lines' worth of nothing");
        expect($presented['items'][$n - 1]['name'])->toBe("Q11 Line {$n}");

        $cost[$n] = $statements;
    }

    // Flat, at every size, against the smallest.
    foreach (Q11_SIZES as $n) {
        expect($cost[$n])->toBe(
            $cost[1],
            "presenting {$n} lines cost {$cost[$n]} statements against {$cost[1]} for one — "
            . 'the order email has grown a query per line, which is paid while a customer waits'
        );
    }

    // And a per-line slope of zero, stated as the number rather than implied.
    expect(($cost[10] - $cost[1]) / 9)->toEqual(0.0);

    /*
     * A TOTAL AS WELL AS THE SLOPE, and the reason is a mutation that came back
     * green. Replacing `$order->items` with `$order->items()->get()` adds one
     * statement to EVERY render — a constant, so the slope stays 0.00 and a
     * slope-only guard sees nothing. Slope catches what grows with the basket;
     * this catches what is simply added. Both, or the pair has a hole.
     *
     * Three: the lines, the settings row, and the order itself (which this
     * fixture fetches inside the window). Raise it deliberately, with the
     * reason, or find another way.
     */
    expect($cost[1])->toBe(3, 'the presenter has taken on another query');
});

it('sends the confirmation at a cost that does not grow with its lines', function () {
    /*
     * The WHOLE send, not the presenter: the Mailable is constructed, both
     * templates are rendered, the transport is built and mail_deliveries is
     * written. That is what the queued job really does and what the customer
     * really waits for.
     */
    Mail::mailer(MailConfigurator::MAILER)->to('warm@example.com')
        ->send(new OrderConfirmation(q11Fresh(q11Order(2)->id)));

    $cost = [];

    foreach (Q11_SIZES as $n) {
        $order = q11Order($n);

        [$statements, $html] = q11Count(function () use ($order) {
            $fresh = q11Fresh($order->id);
            Mail::mailer(MailConfigurator::MAILER)->to('buyer@example.com')->send(new OrderConfirmation($fresh));

            // What went out, so the row below states what it measured.
            return (new OrderConfirmation($fresh))->render();
        });

        // Every line really is on the message. A template that silently rendered
        // no lines would be flat and cheap.
        foreach (range(1, $n) as $i) {
            expect($html)->toContain("Q11 Line {$i}");
        }

        $cost[$n] = $statements;
    }

    foreach (Q11_SIZES as $n) {
        expect($cost[$n])->toBe(
            $cost[1],
            "sending a {$n}-line confirmation cost {$cost[$n]} statements against {$cost[1]} for one"
        );
    }

    expect(($cost[10] - $cost[1]) / 9)->toEqual(0.0);

    /*
     * The total, for the same reason as above. Six: the order, its lines, the
     * settings row, the mail credential, and the two writes to mail_deliveries
     * (the insert before the transport is handed the message, the update with
     * its verdict).
     */
    expect($cost[1])->toBe(6, 'sending an order confirmation has taken on another query');
});

/* ═══════════════════════════════════════════════════════════════════════════
   2 · AND NOT ONE LAZY READ, WHICH IS THE THING THE CENSUS COUNTED
   ═════════════════════════════════════════════════════════════════════════ */

it('reads no relation lazily while building or sending an order email', function () {
    /*
     * The mechanism docs/q10-component-load-contract.md priced: the exception
     * replaced by a recorder, so nothing fails and everything is counted.
     *
     * BEFORE `loadMissing('items')` was added to the presenter this printed
     *     App\Models\Order::$items => 2
     * — once for present() and once for the send. One query each, not one per
     * line, which is why the slope above was already zero and why this is a
     * tidying rather than a repair. It is worth pinning all the same: a
     * `$item->product?->image` added to the template for a thumbnail would show
     * up here as `App\Models\OrderItem::$product => 6` AND on the slope above.
     */
    Mail::mailer(MailConfigurator::MAILER)->to('warm@example.com')
        ->send(new OrderConfirmation(q11Fresh(q11Order(2)->id)));

    $order = q11Order(6);
    $sibling = q11Order(2);

    /*
     * ▲ TWO ROWS, AND THE CASE ASSERTS IT.
     *
     * Builder::hydrate() copies Model::preventsLazyLoading() onto the rows it
     * builds ONLY when the result has more than one — so an order fetched with
     * findOrFail() reports NOTHING however badly the template is written. That
     * is mutation 5 below and it came back green. The flag is read off the
     * fixture and asserted before the recorder is trusted.
     */
    $fetch = fn () => Order::whereIn('id', [$order->id, $sibling->id])->get();

    $seen = [];

    Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) use (&$seen): void {
        $key = get_class($model) . '::$' . $relation;
        $seen[$key] = ($seen[$key] ?? 0) + 1;
    });

    Model::preventLazyLoading(true);

    try {
        $rows = $fetch();
        $subject = $rows->firstWhere('id', $order->id);

        expect($subject->preventsLazyLoading)->toBeTrue(
            'the fixture does not carry the flag, so this case cannot see a lazy read at all'
        );

        $presented = (new OrderEmailPresenter)->present($subject);

        $rows2 = $fetch();
        $html = (new OrderConfirmation($rows2->firstWhere('id', $order->id)))->render();
    } finally {
        Model::preventLazyLoading(false);
        Model::handleLazyLoadingViolationUsing(null);
    }

    // What was rendered, before what it cost.
    expect($presented['items'])->toHaveCount(6);
    expect($html)->toContain('Q11 Line 6');

    expect($seen)->toBe([], 'the order email read a relation off a row nobody eager-loaded: ' . json_encode($seen));
});

it('asks the catalogue nothing at all while sending an order email', function () {
    /*
     * Stated as its own assertion because it is the thing that would change if
     * somebody put product thumbnails in the receipt, which is a plausible and
     * reasonable request. Every word and number on an order email is a snapshot
     * on `order_items` — name, brand, sku, quantity, price, and since Lane CN
     * the localised name too — so the email survives a product being renamed,
     * unpublished or deleted, and asks `products` nothing.
     */
    Mail::mailer(MailConfigurator::MAILER)->to('warm@example.com')
        ->send(new OrderConfirmation(q11Fresh(q11Order(2)->id)));

    $order = q11Order(8);

    app()->forgetScopedInstances();
    SettingsService::forgetMemo();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $fresh = q11Fresh($order->id);
    Mail::mailer(MailConfigurator::MAILER)->to('buyer@example.com')->send(new OrderConfirmation($fresh));
    $html = (new OrderConfirmation($fresh))->render();

    $queries = array_column(DB::getQueryLog(), 'query');
    DB::flushQueryLog();
    DB::disableQueryLog();

    expect($html)->toContain('Q11 Line 8');   // it really rendered eight lines

    $catalogue = array_values(array_filter(
        $queries,
        static fn (string $q) => str_contains($q, '"products"') || str_contains($q, '`products`')
    ));

    expect($catalogue)->toBe([], 'an order email queried the catalogue: ' . json_encode($catalogue));
});

/* ═══════════════════════════════════════════════════════════════════════════
   MUTATIONS — nine, every one applied, run and reverted. What each PRINTED is
   quoted, including the three that came back GREEN, which are the three that
   changed how this file is written.
   ═══════════════════════════════════════════════════════════════════════════

    1  `$order->loadMissing('items')` removed from present() AND ledgerWidth()
       — this lane's own change, undone.
       RUN: 1 failed, 3 passed:
           "the order email read a relation off a row nobody eager-loaded:
            {"App\\Models\\Order::$items":2}"
       ▲ AND THE SLOPE CASES STAYED GREEN, which is the measurement this whole
       task turns on. The lazy read is ONE query per render, not one per line,
       so removing the eager load costs nothing you can see at 1, 2, 5 or 10
       lines. The brief's "loads its own lines one at a time" is a mis-reading of
       a census that counts relation reads, and this mutation is the proof.

    2  `'thumb' => (string) ($item->product?->image ?? '')` added to
       OrderEmailPresenter::items() — the plausible future change, a product
       thumbnail on the receipt.
       RUN: 4 failed. The slope case prints
           "presenting 2 lines cost 5 statements against 4 for one — the order
            email has grown a query per line, which is paid while a customer
            waits"
       — slope 1.00 per line — the send case the same at 8 against 7, the lazy
       case names OrderItem::$product, and the catalogue case names the query.
       This is the defect the file exists to catch, and it catches it four ways.

    3  The lazy case fetches with findOrFail() (one row) and mutation 1 is
       applied with it, so a real lazy read is present.
       RUN: 1 failed —
           "the fixture does not carry the flag, so this case cannot see a lazy
            read at all / Failed asserting that false is true."

    3b The same, with the flag assertion DELETED.
       RUN: 4 passed — GREEN, against a presenter that is provably reading a
       relation lazily. Builder::hydrate() copies preventsLazyLoading() onto its
       rows only when the result has more than one, so a one-row fetch reports
       nothing however the code is written. 3 and 3b together are why the
       fixture fetches two orders and asserts the flag before it trusts the
       recorder. Same blind spot docs/q10-component-load-contract.md records as
       its own mutation 16.

    4  The "what the render produced" floor removed from the slope case, and
       present() changed to return `'items' => []`.
       RUN: that case alone — 1 passed, GREEN, at 3 statements for every size.
       A render that produces nothing is flat and cheap and proves nothing. With
       the floor in place the same mutation is red at
           "Failed asserting that actual size 0 matches expected size 6."

    5  `foreach ($order->items ...)` in items() changed to
       `foreach ($order->items()->get() ...)` — one extra statement on EVERY
       render, a constant rather than a slope.
       RUN: first attempt, before this file had totals: 4 passed — GREEN. The
       slope is still 0.00 because the extra query is paid at every size. THIS
       IS WHY THE TOTALS WERE ADDED. With them:
           "the presenter has taken on another query / Failed asserting that 4
            is identical to 3"  and  "…that 8 is identical to 6."
       Slope catches what grows with the basket; the total catches what is
       simply added. A file with only one of them has a hole.

    6  The warm-up render removed from both slope cases.
       RUN: 2 failed —
           "presenting 2 lines cost 3 statements against 5 for one"
           "sending a 2-line confirmation cost 6 statements against 9 for one"
       Trap 1 made to happen: the FIRST render of the process is 2 statements
       dearer for present() and 3 dearer for a send, so without the warm-up the
       n=1 row is the expensive one and every later size reads as an
       improvement. A measurement that reports a saving where nothing changed.

    7  `app()->forgetScopedInstances()` and `SettingsService::forgetMemo()`
       removed from q11Count().
       RUN: 2 failed on the totals —
           "the presenter has taken on another query / Failed asserting that 2
            is identical to 3"  and  "…that 4 is identical to 6."
       Trap 2: the settings read is answered out of the previous measurement's
       memo, so every number is one lower than the truth and the file would pin
       a budget the application does not actually meet.

    8  q11Count() rewritten to use DB::listen() with a fresh closure per
       measurement instead of enableQueryLog()/flushQueryLog().
       RUN: 4 passed — GREEN, and the reason is worth writing down rather than
       filing as luck. A listener registered inside the window and read at the
       end of it counts only its own window correctly; what leaks is the
       LISTENER, not the count, because each dead closure goes on incrementing a
       variable nobody reads. The shape that really misreports is a listener
       registered ONCE with a running total read repeatedly — then the fixture
       writes between measurements land in it. enableQueryLog()/flushQueryLog()
       is used here because it cannot be written the second way by accident, not
       because this particular spelling of DB::listen() was measured wrong.

    9  The catalogue case's `expect($html)->toContain('Q11 Line 8')` removed and
       present() returned `'items' => []`.
       RUN: that case alone — GREEN. An email that renders no lines asks the
       catalogue nothing, trivially. The floor is what makes the case an
       assertion about a receipt rather than about an empty string.
   ═════════════════════════════════════════════════════════════════════════ */

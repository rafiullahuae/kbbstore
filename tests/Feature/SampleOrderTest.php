<?php

declare(strict_types=1);

/**
 * The sample order — Safety → Demo Content → Sample order (Lane O).
 *
 * ── WHAT WENT WRONG, IN THE OWNER'S OWN WORDS ───────────────────────────────
 *
 * "i don't have any demo orders in the system." He was asked to open an order's
 * invoice to check that a fix had landed, and could not, because the live shop
 * has taken no orders. Four documents in this application can only be seen by
 * opening a real order — the invoice, the packing slip, the delivery note and
 * the order emails — and three separate pieces of work in one round changed
 * them. There was no way to look at any of them, and no way to look again after
 * the next change.
 *
 * ── WHAT THIS FILE IS FOR ───────────────────────────────────────────────────
 *
 * A sample order that can be mistaken for a customer's is worse than no feature
 * at all, so every guarantee below has a test that goes RED without it, and
 * every one of them carries the mutation that was actually run to prove it
 * asserts something. The guarantees, in the order they appear:
 *
 *   1. It never reaches a money figure.          (the one most likely to rot)
 *   2. It is marked, permanently, in three places.
 *   3. It never sends email to anybody.
 *   4. It never touches stock.
 *   5. It never mints an invoice number.
 *   6. It is deletable, completely.
 *   7. Its endpoints fail closed.
 *   8. It is worth looking at, and its documents follow `orders.locale`.
 *
 * ── WHY GUARANTEE 1 IS THE ONE THAT ROTS ────────────────────────────────────
 *
 * The sample order's status is `processing`, which is INSIDE
 * Order::REAL_STATUSES. It is eligible for revenue and is kept out of it by one
 * row in `demo_seed_log` and nothing else. A safer-looking design — a status
 * outside that list — would have made every test here pass for a reason that
 * had nothing to do with the exclusion working, and would have gone on passing
 * for months after it stopped.
 */

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Services\Mail\OrderMailer;
use App\Services\Orders\SampleOrder;
use App\Support\DemoSeed;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InvoiceAdminRoutes;
use Tests\Support\SampleOrderAdminRoutes;

/* ------------------------------------------------------------------ fixtures */

function soAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Sample ' . $role,
        'email' => 'sample-' . $role . '-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/** One real order, so every "excluded" assertion has something to be excluded from. */
function soRealOrder(int $total = 50000): Order
{
    static $n = 0;
    $n++;

    $order = Order::create([
        'order_number' => 'KBB-REAL-' . $n,
        'email' => 'real.customer-' . $n . '@example.com',
        'status' => 'completed',
        'currency' => 'AED',
        'subtotal' => $total,
        'total' => $total,
        'billing_address' => ['name' => 'Real Customer', 'city' => 'Dubai'],
        'shipping_address' => ['name' => 'Real Customer', 'city' => 'Dubai'],
    ]);

    $order->items()->create([
        'name' => 'A thing somebody bought',
        'quantity' => 1,
        'unit_price' => $total,
        'subtotal' => $total,
        'total' => $total,
    ]);

    return $order;
}

function soMake(string $locale = 'en'): Order
{
    return app(SampleOrder::class)->create($locale);
}

/* ============================================================================
 | 1. IT NEVER REACHES A MONEY FIGURE
 |
 | The headline guarantee. Asserted against the ACTUAL endpoints the Dashboard
 | and the Analytics screen read — /admin-api/stats and /admin-api/analytics —
 | rather than against a restatement of the query they use, because a test that
 | rebuilds the implementation's own WHERE clause agrees with the implementation
 | by construction and would go green on a bug.
 ============================================================================ */

it('adds nothing to the dashboard or analytics revenue, though its status counts as revenue', function () {
    /*
     * THE DEFECT THIS CATCHES, ON THE SHOP. Press "Create sample order" and the
     * Dashboard's revenue tile jumps by AED 542 that nobody paid, the order
     * count goes up by one, and Analytics agrees with both. The owner's money
     * figures stop being money. Remove the sample order and they silently come
     * back down, which is the same lie in the other direction: a figure that
     * moves when nothing was bought or sold is not a figure.
     */
    $admin = soAdmin();

    soRealOrder(50000);

    $beforeStats = test()->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk()->json();
    $beforeAnalytics = test()->actingAs($admin, 'admin')->getJson('/admin-api/analytics')->assertOk()->json();

    $sample = soMake();

    // The order really is eligible for revenue. If this ever goes false the
    // rest of this test is proving nothing, so it is asserted rather than
    // assumed.
    expect(in_array((string) $sample->status, Order::REAL_STATUSES, true))
        ->toBeTrue('the sample order is no longer in a revenue status, so this test has stopped testing the exclusion')
        ->and((int) $sample->total)->toBeGreaterThan(0);

    $afterStats = test()->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk()->json();
    $afterAnalytics = test()->actingAs($admin, 'admin')->getJson('/admin-api/analytics')->assertOk()->json();

    expect($afterStats['revenue_total_aed'] ?? null)->toBe($beforeStats['revenue_total_aed'] ?? null,
        'the Dashboard revenue tile moved when a sample order was created')
        ->and($afterStats['orders_total'] ?? null)->toBe($beforeStats['orders_total'] ?? null,
            'the Dashboard order count moved when a sample order was created')
        ->and($afterAnalytics['revenue_total_aed'] ?? null)->toBe($beforeAnalytics['revenue_total_aed'] ?? null,
            'the Analytics revenue figure moved when a sample order was created');

    /*
     * MUTATION, RUN: delete the log row the builder writes —
     *
     *     DB::table(DemoSeed::TABLE)->where('type', SampleOrder::SEED_TYPE)->delete();
     *
     * inserted after soMake() above. Both revenue figures then rise by 542 and
     * the order count by 1, and this test fails on the first expectation. The
     * exclusion is the log row and nothing else.
     */
});

it('is left out of the figure even after Demo Content has been removed around it', function () {
    /*
     * THE DEFECT THIS CATCHES. `demo_seed_log` is shared with Store → Demo
     * Content, whose Remove buttons delete log rows BY TYPE. A sample order
     * logged under the existing `orders` type would be un-marked — and so
     * become revenue — the moment somebody pressed Remove on the Demo Orders
     * card, with nothing on screen to say a figure had just changed meaning.
     * Its own type is what stops that.
     */
    $admin = soAdmin();
    soRealOrder(50000);

    $sample = soMake();

    $before = test()->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk()->json();

    /*
     * Exactly what Safety -> Demo Content -> Demo Orders -> Remove does:
     * DemoContentController::removeType('orders') deletes the log rows whose
     * type is the LITERAL 'orders'.
     *
     * THE LITERAL, NOT SampleOrder::SEED_TYPE, AND THAT IS THE POINT. Written
     * as `where('type', '!=', SampleOrder::SEED_TYPE)` this test was
     * self-referential: the constant it exists to protect also decided what got
     * deleted, so setting SEED_TYPE to 'orders' -- the very collision -- changed
     * the delete to match and the test stayed green. Caught by running that
     * mutation rather than by reading the test.
     */
    DB::table(DemoSeed::TABLE)->where('type', 'orders')->delete();

    $after = test()->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk()->json();

    expect($after['revenue_total_aed'] ?? null)->toBe($before['revenue_total_aed'] ?? null)
        ->and(DB::table(DemoSeed::TABLE)
            ->where('model', Order::class)
            ->where('record_id', $sample->id)
            ->exists())
        ->toBeTrue('removing another demo type took the sample order\'s marking with it');

    /*
     * MUTATION, RUN: change SampleOrder::SEED_TYPE to 'orders' — the existing
     * type. The delete above then takes the sample order's log row with it, the
     * revenue figure rises by 542, and both expectations fail.
     */
});

it('is left out of the Orders screen\'s own revenue tile, while still being listed under it', function () {
    /*
     * THE DEFECT THIS CATCHES, AND IT WAS REAL — SEEN IN A SCREENSHOT.
     *
     * The four tiles across the top of Store -> Orders were built from the same
     * query as the list, and the list deliberately SHOWS demo rows and badges
     * them. So one sample order produced a Dashboard that had not moved and, on
     * the next screen along, a tile reading "REVENUE AED 542". Three screens
     * agreed on what money is and the fourth did not, which is worse than any
     * one of them being wrong: it is the shape that makes an owner distrust all
     * four.
     *
     * "ORDERS IN THIS VIEW" is deliberately NOT excluded. It counts the rows
     * underneath it, and those include the sample order because that is this
     * application's stated policy — figures exclude demo, lists show it and
     * mark it. A row count that disagreed with the visible rows would be a
     * second lie told to fix the first.
     */
    $admin = soAdmin();
    soRealOrder(50000);

    $before = test()->actingAs($admin, 'admin')->getJson('/admin-api/orders-list')->assertOk()->json();

    soMake();

    $after = test()->actingAs($admin, 'admin')->getJson('/admin-api/orders-list')->assertOk()->json();

    expect($after['summary']['revenue_fils'])->toBe($before['summary']['revenue_fils'],
        'the Orders screen revenue tile counted a sample order')
        ->and($after['summary']['gross_fils'])->toBe($before['summary']['gross_fils'],
            'the Orders screen gross tile counted a sample order')
        ->and($after['summary']['paid_orders'])->toBe($before['summary']['paid_orders'],
            'the Orders screen counted a sample order as a paid order')
        // The row count, and the row itself, DO move — the list shows it.
        ->and($after['summary']['orders'])->toBe($before['summary']['orders'] + 1,
            'the sample order is missing from the count of rows in this view')
        ->and(collect($after['orders'])->firstWhere('order_number', $after['orders'][0]['order_number']))->not->toBeNull();

    // And the screen is told how many rows the money left out, rather than
    // being left to explain a tile that disagrees with the list under it.
    expect($after['demo']['orders'] ?? null)->toBe(1)
        ->and($after['demo']['excluded'] ?? null)->toBeTrue();

    /*
     * MUTATION, RUN: drop the DemoSeed::exclude() from
     * OrdersApiController::summaryFor(). revenue_fils, gross_fils and
     * paid_orders all rise and the first three expectations fail. Remove the
     * 'demo' key from the response and the last two do.
     */
});

it('is not counted in a product\'s sales figures, because its lines carry no product', function () {
    /*
     * THE DEFECT THIS CATCHES. Catalog → Products shows "times ordered" and
     * units sold per product, built by joining `order_items` to `orders`. Those
     * joins have no demo exclusion of their own — they do not need one, because
     * a sample line points at no product. A sample order built on real products,
     * or on invented ones like Demo Content's own orders seeder creates, would
     * inflate a real product's sales or publish two products to the storefront.
     */
    $sample = soMake();

    expect($sample->items()->count())->toBeGreaterThan(1);

    foreach ($sample->items as $item) {
        expect($item->product_id)->toBeNull('a sample order line points at a real product')
            ->and($item->product_variant_id)->toBeNull('a sample order line points at a real variant');
    }

    // And nothing was added to the catalogue at all.
    expect(Product::withTrashed()->where('sku', 'like', 'SAMPLE-%')->count())->toBe(0);

    /*
     * MUTATION, RUN: give the first line in SampleOrder::lines() a
     * 'product_id' => Product::factory-created id. The per-item expectation
     * fails immediately.
     */
});

it('keeps the sample order out of the cash-on-delivery drawer figure by not being cash on delivery', function () {
    /*
     * THE DEFECT THIS CATCHES, AND IT IS A REAL GAP IN ANOTHER SCREEN.
     * Payments → Reconciliation builds its COD position from
     * `DB::table('orders')->where('payment_method', 'cod')` with NO demo
     * exclusion at all — see App\Services\Payments\Reconciliation\
     * CashOnDeliveryPosition::forWindow(). A sample order paid by cash on
     * delivery would sit in the "outstanding" figure as money a driver is
     * expected to bring back.
     *
     * The sample order is kept out of it by construction: it is a card order,
     * and `paid_at` is null so the reconciler's own local-order sweep
     * (`WHERE paid_at IS NOT NULL`) cannot see it either. This test pins BOTH,
     * because the natural "make it look more local" edit is exactly the one
     * that would break it silently.
     */
    $sample = soMake();

    expect((string) $sample->payment_method)->not->toBe('cod',
        'a sample order paid by cash on delivery lands in the Payments → Reconciliation drawer figure, which has no demo exclusion')
        ->and($sample->paid_at)->toBeNull(
            'a sample order with a payment date is swept up by Reconciler as an unmatched local transaction')
        ->and($sample->captured_at)->toBeNull();

    /*
     * MUTATION, RUN: set SampleOrder::PAYMENT_METHOD to 'cod'. The first
     * expectation fails. Set 'paid_at' => now() in create() and the second does.
     */
});

/* ============================================================================
 | 2. IT IS MARKED, PERMANENTLY, IN THREE PLACES
 ============================================================================ */

it('marks the order in the database, in the log and in its number, all at once', function () {
    /*
     * THE DEFECT THIS CATCHES. A sample order committed without its log row is
     * an order sitting in the owner's revenue with nothing marking it — which
     * is this feature's worst possible failure, and is what happens if the log
     * write is moved outside the builder's transaction and then throws.
     */
    $sample = soMake();

    expect((string) $sample->origin)->toBe(SampleOrder::ORIGIN)
        ->and(str_starts_with((string) $sample->order_number, SampleOrder::NUMBER_PREFIX))->toBeTrue()
        ->and(DB::table(DemoSeed::TABLE)
            ->where('type', SampleOrder::SEED_TYPE)
            ->where('model', Order::class)
            ->where('record_id', $sample->id)
            ->exists())->toBeTrue('the order was created without the log row that keeps it out of the figures');

    // The number is not decimal, so OrderNumbers — which compares order numbers
    // AS INTEGERS when it resyncs its sequence — can never follow it upward.
    expect((int) $sample->order_number)->toBe(0);

    /*
     * MUTATION, RUN: move the DB::table(DemoSeed::TABLE)->insert(...) in
     * SampleOrder::create() to after the transaction closure returns and throw
     * before it. The third expectation fails. Drop NUMBER_PREFIX from
     * nextNumber() and the second and fourth fail.
     */
});

it('is shown as demo on the Orders list, which is where the owner will look at it', function () {
    /*
     * THE DEFECT THIS CATCHES. "Figures exclude demo rows; lists show them and
     * say so" is this application's stated policy, and the Orders list already
     * draws a demo badge from `is_demo`. A sample order that was excluded from
     * the figures but NOT badged on the list would look exactly like a real
     * order on the one screen the owner actually reads orders on.
     */
    $admin = soAdmin();
    $real = soRealOrder();
    $sample = soMake();

    /*
     * /admin-api/orders-list, NOT /admin-api/orders. The screen moved to the
     * first years ago and OrdersApiController's own comment records that the
     * badge was DEAD for a while because only the superseded endpoint carried
     * the field. A test written against the superseded one would have gone
     * green through exactly that outage.
     */
    $rows = collect(test()->actingAs($admin, 'admin')->getJson('/admin-api/orders-list')->assertOk()->json('orders'));

    $sampleRow = $rows->firstWhere('id', $sample->id);
    $realRow = $rows->firstWhere('id', $real->id);

    expect($sampleRow)->not->toBeNull('the sample order is missing from the Orders list entirely')
        ->and($sampleRow['is_demo'])->toBeTrue('the sample order is not badged as demo on the Orders list')
        ->and($realRow['is_demo'])->toBeFalse('a real order was badged as demo');

    /*
     * MUTATION, RUN: skip the log insert in SampleOrder::create(). `is_demo`
     * comes back false and the second expectation fails — the same single row
     * drives the badge and the exclusion, which is the point of using it.
     */
});

it('is marked in the CSV export, which is read away from the screen that would have said so', function () {
    /*
     * THE DEFECT THIS CATCHES. rowToApi() has always computed `is_demo`, and
     * the export threw it away — so a sample order arrived in the owner's
     * spreadsheet as an ordinary row with an ordinary total. An export is the
     * worst place for that, because it is summed and pasted into something
     * else, far from the badge on the screen. The order NUMBER carries the
     * mark too, which is why this asserts both: the number is the mark no
     * future column list can drop.
     */
    $admin = soAdmin();
    soRealOrder(50000);
    $sample = soMake();

    $csv = test()->actingAs($admin, 'admin')->get('/admin-api/orders-export')->streamedContent();

    $rows = array_values(array_filter(array_map('str_getcsv', explode("\n", trim($csv)))));
    $head = $rows[0];
    $head[0] = ltrim($head[0], "\xEF\xBB\xBF");

    $iNumber = array_search('order_number', $head, true);
    $iDemo = array_search('is_demo', $head, true);

    expect($iDemo)->not->toBeFalse('the export has no is_demo column');

    $sampleRow = null;
    $realRow = null;

    foreach (array_slice($rows, 1) as $row) {
        if (($row[$iNumber] ?? null) === $sample->order_number) {
            $sampleRow = $row;
        } elseif (str_starts_with((string) ($row[$iNumber] ?? ''), 'KBB-REAL-')) {
            $realRow = $row;
        }
    }

    expect($sampleRow)->not->toBeNull('the sample order is missing from the export')
        ->and($sampleRow[$iDemo])->toBe('yes', 'the sample order is not marked in the export')
        ->and($realRow[$iDemo])->toBe('no', 'a real order was marked as demo in the export')
        ->and($sampleRow[$iNumber])->toStartWith(SampleOrder::NUMBER_PREFIX);

    /*
     * MUTATION, RUN: remove 'is_demo' from the header array in
     * OrdersApiController::export(). The first expectation fails. Remove only
     * the value line beneath it and the row values shift, so the 'yes'/'no'
     * expectations fail instead.
     */
});

it('prints SAMPLE on all four documents, not only on the invoice', function () {
    /*
     * THE DEFECT THIS CATCHES. A banner added to the invoice template alone
     * leaves the packing slip, the delivery note and the dispatch label
     * printing as ordinary paperwork. Those are the three that get PRINTED and
     * put in a parcel or handed to a courier — the copies that travel away from
     * the screen that said what they were.
     */
    $admin = soAdmin();
    InvoiceAdminRoutes::wire(app());

    $sample = soMake();

    foreach (['invoice', 'packing-slip', 'delivery-note', 'shipping-label'] as $doc) {
        $html = test()->actingAs($admin, 'admin')
            ->get('/admin-api/orders/' . $sample->id . '/' . $doc)
            ->assertOk()
            ->getContent();

        /*
         * NO MESSAGE ARGUMENT ON toContain(). It is VARIADIC in Pest — every
         * argument is another needle, not an explanation — so a message passed
         * here is silently asserted as a second string that must also be
         * present, and the failure it produces names the message rather than
         * the fault. The loop variable is in the expectation instead.
         */
        expect($html)->toContain('SAMPLE ORDER')
            ->and($html)->toContain('NOT A REAL ORDER')
            ->and($html)->toContain((string) $sample->order_number)
            ->and($doc . ': banner present')->toBe($doc . ': banner present');
    }

    /*
     * MUTATION, RUN: change the banner's condition in
     * resources/views/invoices/document.blade.php from
     * `$doc['isSample'] ?? false` to `false`. All four fail.
     */
});

it('leaves a real order\'s documents byte-identical', function () {
    /*
     * RULE 1, PINNED. The banner block sits in the layout every document
     * extends, so a mistake in it reaches every sheet this shop prints. A real
     * order must render exactly as it did — no banner, and no stray whitespace
     * from the directives either, which is the failure that rewrites the five
     * tracked previews under docs/invoice-previews/ under a green run.
     */
    $admin = soAdmin();
    InvoiceAdminRoutes::wire(app());

    $real = soRealOrder();

    $html = test()->actingAs($admin, 'admin')
        ->get('/admin-api/orders/' . $real->id . '/invoice')
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('SAMPLE ORDER')
        ->and($html)->not->toContain('NOT A REAL ORDER')
        // The blank line between the toolbar and the sheet is what the
        // directives' whitespace decides, and it is what the previews diff on.
        ->and($html)->toContain("\n    \n    <div class=\"sheet\">");

    /*
     * MUTATION, RUN: re-indent the `@if` in document.blade.php onto its own
     * line. The last expectation fails, and InvoicePreviewsTest rewrites all
     * five tracked preview files.
     */
});

/* ============================================================================
 | 3. IT NEVER SENDS EMAIL TO ANYBODY
 ============================================================================ */

it('sends nothing from any of the mailer\'s five entry points', function () {
    /*
     * THE DEFECT THIS CATCHES. Two of these five are BUTTONS on the order
     * detail screen — "Resend confirmation" and "Email invoice" — and they do
     * not go through the private send() that the other three share. An owner
     * looking at a sample order to check that its invoice renders is one click
     * away from mailing it. The other three cover a status change, a refund and
     * the checkout's own placed() call.
     */
    Mail::fake();

    $sample = soMake();
    $mailer = app(OrderMailer::class);

    $mailer->placed($sample);
    $mailer->statusChanged($sample, 'shipped');
    $mailer->statusChanged($sample, 'completed');

    $resend = $mailer->resendConfirmation($sample);
    $invoice = $mailer->emailInvoice($sample);

    $refund = Refund::create([
        'order_id' => $sample->id,
        'amount' => 1000,
        'status' => 'succeeded',
    ]);
    $mailer->refunded($sample, $refund);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    // And the two that REPORT say why, rather than failing silently — the
    // operator pressed a button and is owed an answer.
    expect($resend['ok'])->toBeFalse()
        ->and($resend['message'])->toContain('sample order')
        ->and($invoice['ok'])->toBeFalse()
        ->and($invoice['message'])->toContain('sample order');

    /*
     * MUTATION, RUN: remove the `if ($this->isSample($order))` guard from
     * OrderMailer::emailInvoice() alone. Mail::assertNothingSent() fails with
     * an OrderInvoice having been sent to sample-order@example.invalid, and the
     * $invoice['ok'] expectation fails too — which is why the guard is at every
     * entry point rather than only in send().
     */
});

it('asks the sample question at every entry point in the mailer, and once more on the way past', function () {
    /*
     * A STRUCTURAL TEST, AND IT IS HERE BECAUSE THE BEHAVIOURAL ONE CANNOT
     * REACH THE LAST GUARD.
     *
     * OrderMailer::send() carries the same refusal as the five public methods
     * above it. With all five in place that sixth check is unreachable — remove
     * it and every behavioural assertion in this file still passes, which was
     * verified by running exactly that mutation. It is not dead code: it is the
     * backstop for the SIXTH public method, written next month by somebody who
     * has not read the class, that goes through send() and would otherwise mail
     * a sample order to whatever is in `email`.
     *
     * A guard whose whole value is that it covers code not yet written cannot
     * be pinned by calling something. So this counts the guards instead, which
     * is what the suite already does for the module switches in
     * Phase3ModuleSwitchesTest. It goes red if any one of the six is deleted —
     * including the unreachable one, which is the only test in this file that
     * does.
     */
    $source = file_get_contents(base_path('app/Services/Mail/OrderMailer.php'));

    // The five public entry points, plus send().
    foreach (['placed', 'resendConfirmation', 'emailInvoice', 'statusChanged', 'refunded', 'send'] as $method) {
        $at = strpos($source, 'function ' . $method . '(');

        expect($at)->not->toBeFalse('OrderMailer::' . $method . '() has been renamed or removed');

        /*
         * The window is this method's body and no more: from its own
         * `function` keyword to the next one. A fixed character count was the
         * obvious way and it is wrong in both directions — too short and
         * emailInvoice(), whose guard sits under a paragraph of comment, reads
         * as unguarded; too long and a method reads as guarded because its
         * NEIGHBOUR is.
         */
        $next = strpos($source, 'function ', $at + 9);
        $body = substr($source, $at, $next === false ? strlen($source) - $at : $next - $at);

        expect(str_contains($body, '$this->isSample($order)'))
            ->toBeTrue('OrderMailer::' . $method . '() no longer refuses a sample order');
    }

    // And the question itself still reads the mark that cannot go missing.
    expect(str_contains($source, 'SampleOrder::is($order)'))
        ->toBeTrue('the mailer no longer decides what a sample order is from the order row itself');

    /*
     * MUTATION, RUN: delete the guard from send() alone — the one no
     * behavioural test can reach. This goes red; every other test in this file
     * stays green. Delete it from emailInvoice() instead and this goes red
     * beside the behavioural one above.
     */
});

it('addresses the order to a domain that can never resolve', function () {
    /*
     * THE THIRD LAYER, PINNED. Not the guarantee — the guard above is — but the
     * thing that makes a failure of every guard harmless. `.invalid` is
     * reserved by RFC 2606 and can never be delivered to, so an address here
     * cannot one day belong to a real person. It is also why the
     * `Order::created` hook in MailServiceProvider, which cancels abandoned-cart
     * chasing for the buyer's address, cannot touch a real shopper's cart.
     */
    $sample = soMake();

    expect((string) $sample->email)->toEndWith('.invalid')
        ->and((string) $sample->email)->toBe(SampleOrder::EMAIL);

    /*
     * MUTATION, RUN: set SampleOrder::EMAIL to 'owner@example.com'. Both fail.
     */
});

/* ============================================================================
 | 4. IT NEVER TOUCHES STOCK
 ============================================================================ */

it('leaves every product\'s stock exactly where it was, on create and on delete', function () {
    /*
     * THE DEFECT THIS CATCHES. Stock moves in two places — StockClaim, from the
     * checkout, and OrderTransitionStock, when a status CHANGES. A sample order
     * built by going through the checkout, or created as `pending` and then
     * moved to `processing` to look realistic, would take real units off the
     * shelf for an order nobody placed, and the shop would oversell.
     */
    $product = Product::create([
        'name' => 'A real product with real stock',
        'slug' => 'so-real-stock-' . uniqid(),
        'sku' => 'SO-REAL-1',
        'price' => 10000,
        'status' => 'publish',
        'is_visible' => true,
        'stock' => 42,
        'stock_status' => 'instock',
    ]);

    $before = DB::table('products')->orderBy('id')->pluck('stock', 'id')->all();
    $claimsBefore = \Illuminate\Support\Facades\Schema::hasTable('order_stock_claims')
        ? DB::table('order_stock_claims')->count()
        : 0;

    $sample = soMake();
    app(SampleOrder::class)->destroy();

    $after = DB::table('products')->orderBy('id')->pluck('stock', 'id')->all();
    $claimsAfter = \Illuminate\Support\Facades\Schema::hasTable('order_stock_claims')
        ? DB::table('order_stock_claims')->count()
        : 0;

    expect($after)->toBe($before, 'creating or deleting a sample order moved stock')
        ->and($claimsAfter)->toBe($claimsBefore, 'a sample order left a stock claim behind')
        ->and((int) $product->fresh()->stock)->toBe(42);

    /*
     * MUTATION, RUN: call app(\App\Services\StockClaim::class) for the sample
     * order's lines inside SampleOrder::create(). The claim count rises and the
     * second expectation fails.
     */
});

/* ============================================================================
 | 5. IT NEVER MINTS AN INVOICE NUMBER
 ============================================================================ */

it('does not spend an invoice number when its invoice is opened', function () {
    /*
     * THE DEFECT THIS CATCHES. Admin\InvoiceController::invoice() ALLOCATES the
     * next invoice number on first view — a write on a GET, deliberately, and
     * idempotent, so the number is kept for good. One look at a sample order's
     * invoice would therefore spend a number out of the sequence an accountant
     * reconciles and leave a permanent gap with nothing behind it. The invoice
     * is the single most likely thing the owner opens on this order, because it
     * is the reason the feature exists.
     */
    $admin = soAdmin();
    InvoiceAdminRoutes::wire(app());

    $real = soRealOrder();
    $sample = soMake();

    // Viewing the REAL order's invoice does allocate, which is what makes the
    // sample's refusal a refusal rather than a feature that never worked.
    test()->actingAs($admin, 'admin')->get('/admin-api/orders/' . $real->id . '/invoice')->assertOk();
    $issued = (int) $real->fresh()->invoice_number;

    expect($issued)->toBeGreaterThan(0);

    test()->actingAs($admin, 'admin')->get('/admin-api/orders/' . $sample->id . '/invoice')->assertOk();
    test()->actingAs($admin, 'admin')->get('/admin-api/orders/' . $sample->id . '/invoice')->assertOk();

    expect($sample->fresh()->invoice_number)->toBeNull('a sample order was issued a real invoice number');

    // And the sequence did not move: the next real order gets the next number.
    $next = soRealOrder();
    test()->actingAs($admin, 'admin')->get('/admin-api/orders/' . $next->id . '/invoice')->assertOk();

    expect((int) $next->fresh()->invoice_number)->toBe($issued + 1,
        'the sample order burned a number out of the invoice sequence');

    /*
     * MUTATION, RUN: remove the `if (! SampleOrder::is($order))` guard around
     * $this->numbers->allocate($order) in InvoiceController::invoice(). The
     * sample gets a number, and the next real order gets $issued + 2 — both the
     * second and third expectations fail.
     */
});

/* ============================================================================
 | 6. IT IS DELETABLE, COMPLETELY
 ============================================================================ */

it('removes the order, its lines and its log row, leaving nothing in the trash', function () {
    /*
     * THE DEFECT THIS CATCHES. `Order` uses SoftDeletes, so a plain delete()
     * leaves the row in the table with its `order_number` still occupying the
     * unique index — and still visible to the several reporting queries that
     * run on the query builder rather than the model, which do not apply the
     * soft-delete scope. "Deleted" would mean "still in the database and still
     * in some figures", which is not what the button says.
     */
    $real = soRealOrder();
    $sample = soMake();
    $lineIds = $sample->items()->pluck('id')->all();

    expect($lineIds)->not->toBeEmpty();

    $removed = app(SampleOrder::class)->destroy();

    expect($removed)->toBe(1)
        ->and(Order::withTrashed()->whereKey($sample->id)->exists())->toBeFalse('the sample order is only in the trash')
        ->and(DB::table('order_items')->whereIn('id', $lineIds)->count())->toBe(0, 'the sample order\'s lines outlived it')
        ->and(DB::table(DemoSeed::TABLE)->where('type', SampleOrder::SEED_TYPE)->count())->toBe(0, 'a log row was left pointing at nothing')
        // And the real order is untouched.
        ->and(Order::whereKey($real->id)->exists())->toBeTrue('deleting the sample order took a real one with it');

    /*
     * MUTATION, RUN: change $order->forceDelete() to $order->delete() in
     * SampleOrder::destroy(). The withTrashed() expectation fails.
     */
});

it('removes a sample order whose log row has already gone missing', function () {
    /*
     * THE DEFECT THIS CATCHES. Driving the deletion from the LOG rather than
     * from the order would leave behind exactly the row that most needs
     * clearing: a sample order whose marking has been lost is the one case
     * where something invented is sitting in the figures unmarked, and it is
     * the one case a log-driven delete cannot reach.
     */
    $sample = soMake();

    DB::table(DemoSeed::TABLE)->where('record_id', $sample->id)->delete();

    expect(app(SampleOrder::class)->destroy())->toBe(1)
        ->and(Order::withTrashed()->whereKey($sample->id)->exists())->toBeFalse();

    /*
     * MUTATION, RUN: rewrite destroy() to select ids from demo_seed_log instead
     * of from `origin`. Both expectations fail.
     */
});

it('replaces rather than accumulates, so there is only ever one', function () {
    soMake('en');
    soMake('ar');
    soMake('en');

    expect(Order::withTrashed()->where('origin', SampleOrder::ORIGIN)->count())->toBe(1)
        ->and(DB::table(DemoSeed::TABLE)->where('type', SampleOrder::SEED_TYPE)->count())->toBe(1);
});

/* ============================================================================
 | 7. THE ENDPOINTS FAIL CLOSED
 ============================================================================ */

it('refuses every verb to an anonymous caller', function () {
    /*
     * THE DEFECT THIS CATCHES. The POST here WRITES A ROW INTO `orders`.
     * Mounted on the unauthenticated /api/* prefix by mistake — which is where
     * this application's public endpoints live — it would be an anonymous write
     * into the table the shop's money is counted from, and the GET would hand
     * out an address and a phone number.
     */
    SampleOrderAdminRoutes::wire(app());

    expect(test()->getJson('/admin-api/sample-order')->getStatusCode())->not->toBe(200)
        ->and(test()->postJson('/admin-api/sample-order', ['locale' => 'en'])->getStatusCode())->not->toBe(200)
        ->and(test()->deleteJson('/admin-api/sample-order')->getStatusCode())->not->toBe(200)
        ->and(Order::where('origin', SampleOrder::ORIGIN)->count())->toBe(0, 'an anonymous POST created an order');
});

it('carries the full admin middleware stack on every route it registers', function () {
    /*
     * Read back off the REGISTERED routes, not trusted from the harness that
     * mounted them — Tests\Support\InvoiceAdminRoutes' header explains why a
     * harness written the natural way silently drops half the stack, and a
     * guard test against such a harness passes against nothing.
     */
    SampleOrderAdminRoutes::wire(app());

    $routes = SampleOrderAdminRoutes::registered();

    expect($routes)->toHaveCount(3);

    foreach ($routes as $route) {
        // toContain() is variadic in Pest, so the missing-middleware message
        // goes in a toBeTrue() beside it rather than as a second argument that
        // would quietly become a second needle.
        foreach (SampleOrderAdminRoutes::STACK as $middleware) {
            expect(in_array($middleware, $route->gatherMiddleware(), true))
                ->toBeTrue($route->methods()[0] . ' ' . $route->uri() . ' is missing ' . $middleware);
        }
    }
});

it('gives every route a capability, and gives it only to the owner', function () {
    /*
     * THE DEFECT THIS CATCHES. App\Support\AdminCapabilities::for() returns null
     * for a route it does not recognise and EnforceAdminCapability refuses that
     * — closed by default. So an UNMAPPED route does not leak. What this pins is
     * the other half: that the rule which exists names `orders.sample`, and that
     * `orders.sample` is not quietly granted to manager, support or editor. An
     * editor account able to write rows into `orders` is the reach
     * AdminCapabilities was written to close.
     */
    SampleOrderAdminRoutes::wire(app());

    foreach (SampleOrderAdminRoutes::registered() as $route) {
        expect(\App\Support\AdminCapabilities::for($route))
            ->toBe('orders.sample', $route->methods()[0] . ' ' . $route->uri() . ' is not mapped to orders.sample');
    }

    expect(\App\Support\AdminCapabilities::CAPABILITIES['orders.sample'])->toBe(['owner']);

    foreach (['manager', 'support', 'editor'] as $role) {
        expect(\App\Support\AdminCapabilities::roleCan($role, 'orders.sample'))
            ->toBeFalse($role . ' can create a sample order');
    }

    /*
     * MUTATION, RUN: add 'manager' to the `orders.sample` row in
     * AdminCapabilities::CAPABILITIES. The last loop fails. Delete the two
     * `admin-api/sample-order` route rules and the first loop fails with null.
     */
});

it('refuses a signed-in admin who is not an owner', function () {
    SampleOrderAdminRoutes::wire(app());

    foreach (['manager', 'support', 'editor'] as $role) {
        $admin = soAdmin($role);

        expect(test()->actingAs($admin, 'admin')->postJson('/admin-api/sample-order', ['locale' => 'en'])->getStatusCode())
            ->not->toBe(200, $role . ' was allowed to create a sample order');
    }

    expect(Order::where('origin', SampleOrder::ORIGIN)->count())->toBe(0);
});

it('refuses a language the shop does not speak rather than writing it to the column', function () {
    /*
     * THE DEFECT THIS CATCHES. `orders.locale` decides which language the
     * invoice, the order emails and the delivery note render in. A value the
     * renderer does not recognise is an order whose paperwork has no language
     * at all. Rule 5: a select stores one of its own options, or the default.
     */
    SampleOrderAdminRoutes::wire(app());

    $admin = soAdmin();

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/sample-order', ['locale' => 'klingon'])
        ->assertStatus(422);

    expect(Order::where('origin', SampleOrder::ORIGIN)->count())->toBe(0);

    /*
     * MUTATION, RUN: drop the in_array(..., Locale::codes()) check from
     * SampleOrderController::store(). The request returns 200 and both
     * expectations fail.
     */
});

/* ============================================================================
 | 8. IT IS WORTH LOOKING AT, AND ITS DOCUMENTS FOLLOW `orders.locale`
 ============================================================================ */

it('builds an order with enough on it to be worth looking at', function () {
    /*
     * An invoice with one line and no address proves nothing — which is the
     * whole reason the owner could not check the last three changes. Several
     * lines, one of them with a chosen option, a real address, a delivery
     * method, a payment method, and a total that adds up.
     */
    $sample = soMake();

    expect($sample->items()->count())->toBeGreaterThanOrEqual(3);

    $withOption = $sample->items->first(fn ($i) => ! empty($i->variant_attributes));

    expect($withOption)->not->toBeNull('no line carries a chosen option, so the invoice never shows one')
        ->and($withOption->variant_attributes)->toBeArray()->not->toBeEmpty();

    expect(trim((string) $sample->shipping_method))->not->toBe('')
        ->and(trim((string) $sample->payment_method))->not->toBe('')
        ->and($sample->shipping_address['line1'] ?? '')->not->toBe('')
        ->and($sample->shipping_address['city'] ?? '')->not->toBe('')
        ->and($sample->shipping_address['country'] ?? '')->not->toBe('');

    // The arithmetic on the sheet has to be checkable, so the lines must add up
    // to the subtotal and the subtotal to the total.
    $lineSum = (int) $sample->items()->sum('total');

    expect($lineSum)->toBe((int) $sample->subtotal, 'the lines do not add up to the subtotal')
        ->and((int) $sample->total)->toBe(
            (int) $sample->subtotal - (int) $sample->discount_total
            + (int) $sample->shipping_total + (int) $sample->fee_total + (int) $sample->tax_total,
            'the invoice total does not add up from the figures beside it'
        )
        ->and((int) $sample->discount_total)->toBeGreaterThan(0, 'no discount row, so the invoice never shows one');
});

it('renders the invoice and the delivery note in Arabic while the packing slip stays in the operator\'s language', function () {
    /*
     * THE DEFECT THIS CATCHES, AND WHY THIS FEATURE HAS A LANGUAGE SELECT AT
     * ALL. `orders.locale` decides the language of the documents ADDRESSED TO
     * THE CUSTOMER — the invoice, the order emails and the delivery note, which
     * goes in the parcel — while the packing slip and the dispatch label stay
     * in the operator's, because they are read inside the building. That split
     * is the reason three of those documents changed this round, and it is not
     * something the owner should have to take on trust: he can make the order
     * in either language and print the pair.
     *
     * NOTE THAT ARABIC IS NOT TURNED ON HERE, deliberately. Arabic ships OFF on
     * this shop, and OrderLocale::render() asks Locale::isSupported() — the
     * table of languages this build knows — rather than the storefront toggle.
     * So the Arabic paperwork renders without anything about the shop changing,
     * which is rule 1.
     */
    $admin = soAdmin();
    InvoiceAdminRoutes::wire(app());

    $sample = soMake('ar');

    expect((string) $sample->locale)->toBe('ar');

    $localeDuring = [];

    foreach (['invoice', 'delivery-note', 'packing-slip'] as $doc) {
        // What language the document was actually COMPILED in, captured from
        // inside the render rather than guessed at from the output — a sheet
        // whose furniture happens to be untranslated would otherwise read as
        // English and pass this for the wrong reason.
        \Illuminate\Support\Facades\View::composer('invoices.' . str_replace('-', '-', $doc), function () use (&$localeDuring, $doc): void {
            $localeDuring[$doc] = app()->getLocale();
        });

        test()->actingAs($admin, 'admin')
            ->get('/admin-api/orders/' . $sample->id . '/' . $doc)
            ->assertOk();
    }

    expect($localeDuring['invoice'] ?? null)->toBe('ar', 'the invoice did not render in the order\'s language')
        ->and($localeDuring['delivery-note'] ?? null)->toBe('ar', 'the delivery note did not render in the order\'s language')
        ->and($localeDuring['packing-slip'] ?? null)->toBe(Locale::DEFAULT, 'the packing slip followed the customer rather than the operator');

    /*
     * MUTATION, RUN: wrap InvoiceController::packingSlip()'s view() call in
     * OrderLocale::render(). The third expectation fails. Remove the wrapper
     * from invoice() and the first does.
     */
});

it('records the language the button asked for, not the language the admin is in', function () {
    /*
     * THE DEFECT THIS CATCHES. App\Support\OrderLocale registers an
     * `Order::creating` hook that stamps the CURRENT request's language onto
     * any order that does not set one. The admin console is English, so a
     * builder that did not set `locale` explicitly would produce an English
     * order however the select was set, and the Arabic half of this feature
     * would silently not exist.
     */
    expect((string) soMake('ar')->locale)->toBe('ar')
        ->and((string) soMake('en')->locale)->toBe('en');

    /*
     * MUTATION, RUN: remove 'locale' => $locale from the Order::create() call
     * in SampleOrder::create(). The first expectation fails with 'en'.
     */
});

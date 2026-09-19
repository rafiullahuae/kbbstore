<?php

/*
 * REFUNDS AND ORDER NOTES: the two tables a clean full-volume import left at
 * zero, and what filling them changes.
 *
 * ============================================================================
 * WHAT IS BEING PROVED, AND WHY IT IS PROVED THIS WAY
 * ============================================================================
 *
 * docs/FV-IMPORT-AT-VOLUME.md §11 names `refunds` first among the empty tables
 * and states the cost in one sentence: "A partial refund imports as an order at
 * its full total." That is not a gap in the history, it is an OVERSTATEMENT —
 * of revenue, of a customer's lifetime value, and of how much of the order is
 * still refundable through a live payment provider.
 *
 * So the assertions below are figures, to the fil, before and after, taken from
 * the same readers the owner's screens are built from:
 *
 *   PaymentRefunder::refundedFils() / capturedFils()   the order screen, and
 *                                                      the ceiling on the
 *                                                      Refund button
 *   /admin-api/customers/list                          lifetime value
 *
 * Nothing is stubbed. tests/Fixtures/kbb-export is the export Lane GE's plugin
 * really wrote, run through App\Services\Import\ImportRunner — the class
 * `kbb:import` and Store -> Import both call — with no double in the path.
 * These tests deliberately share that fixture with GeWpExporterTest rather than
 * inventing one: an importer proved against its own fixture is proved against
 * nothing.
 */

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Refund;
use App\Services\Import\Entities\RefundImporter;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;
use App\Services\Mail\OrderMailer;
use App\Services\Payments\PaymentRefunder;
use App\Support\Money;
use Illuminate\Support\Facades\Mail;

/** The export Lane GE's plugin wrote. Shared with GeWpExporterTest on purpose. */
function giExportDir(): string
{
    return base_path('tests/Fixtures/kbb-export');
}

function giManifest(): array
{
    return json_decode((string) file_get_contents(giExportDir().'/manifest.json'), true);
}

/**
 * The import, run the way docs/IMPORT-RUNBOOK.md says to run this export.
 *
 * `--timezone` out of the manifest, never defaulted: DateParser refuses to
 * guess it, and every date in refunds.csv and order_notes.csv is written in
 * site time.
 */
function giImport(array $overrides = []): ImportReport
{
    $options = new ImportOptions(...array_merge([
        'directory' => giExportDir(),
        'sourceTimezone' => giManifest()['source']['timezone'],
        // The demo catalogue every install carries holds `cosrx` and
        // `beauty-of-joseon`, which are real brands this shop really sells.
        'adoptBySlug' => true,
        'runKey' => 'gi-'.bin2hex(random_bytes(4)),
    ], $overrides));

    return (new ImportRunner)->run($options);
}

/** Everything except the two entities this lane adds. */
function giWithoutRefundsAndNotes(): array
{
    return array_values(array_diff(ImportRunner::entityNames(), ['refunds', 'order-notes']));
}

/** Every rejection reason the run produced, entity by entity. */
function giRejections(ImportReport $report): array
{
    $out = [];

    foreach (ImportRunner::entityNames() as $entity) {
        foreach ($report->for($entity)->rejections() as $rejection) {
            $out[] = $entity.' line '.$rejection['line'].' ('.$rejection['id'].'): '.$rejection['reason'];
        }
    }

    return $out;
}

function giOrder(int $wcOrderId): Order
{
    return Order::query()->where('wc_order_id', $wcOrderId)->firstOrFail();
}

function giAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Import Owner',
        'email' => 'gi-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** A one-file export, fed to the real runner. */
function giRefundsOnly(string $csv): ImportReport
{
    $dir = sys_get_temp_dir().'/kbb-gi-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    file_put_contents($dir.'/refunds.csv', $csv);

    $report = (new ImportRunner)->run(new ImportOptions(
        directory: $dir,
        only: ['refunds'],
        sourceTimezone: 'Asia/Dubai',
        runKey: 'gi-refunds-'.bin2hex(random_bytes(4)),
    ));

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);

    return $report;
}

/* ========================================================================
 | THE HEADLINE: a partially refunded order stops reading as full revenue
 |========================================================================*/

it('stops a partially refunded order reading as full revenue, to the fil', function () {
    /*
     * KBB-1001 (wc order 10233) is Layla's, `completed`, AED 358.50, and it is
     * the only order she has. `completed` is in Order::REAL_STATUSES, so the
     * whole 358.50 is her lifetime value on Store -> Customers — which is the
     * figure the owner uses to decide who his best customers are.
     *
     * Then AED 100.00 of it goes back. WooCommerce leaves the order
     * `completed`, because a partial refund does not undo the sale, and this
     * shop does the same (PaymentRefunder moves an order to `refunded` only
     * once everything captured has gone back). So the order keeps counting —
     * and with an empty `refunds` table it keeps counting at its FULL total.
     */
    $this->actingAs(giAdmin(), 'admin');

    giImport();

    $layla = Customer::query()->where('email', 'buyer@example.test')->firstOrFail();

    $spend = function () use ($layla): int {
        $rows = $this->getJson('/admin-api/customers/list?per_page=100')
            ->assertOk()
            ->json('customers');

        foreach ($rows as $row) {
            if ((int) $row['id'] === (int) $layla->id) {
                return (int) $row['spend_fils'];
            }
        }

        throw new RuntimeException('Layla is not on the customers screen at all');
    };

    // BEFORE — the whole order, because nothing has been handed back.
    expect($spend())->toBe(35850)
        ->and(Money::amount($spend(), 2))->toBe('358.50');

    $report = giRefundsOnly(
        "refund_id,order_id,date_created,amount,reason,refunded_by,currency,total\n"
        ."10299,10233,2019-03-20 10:00:00,100.00,One item returned,1,AED,-100.00\n"
    );

    expect(giRejections($report))->toBe([]);

    // AFTER — 358.50 minus 100.00, exactly, with no float anywhere on the path.
    expect($spend())->toBe(25850)
        ->and(Money::amount($spend(), 2))->toBe('258.50');

    // And the order itself has not moved status: the customer kept the goods.
    expect(giOrder(10233)->status)->toBe('completed');
});

it('stops offering to refund money WooCommerce already gave back', function () {
    /*
     * THE SECOND HALF OF THE SAME DEFECT, AND THE ONE THAT MOVES REAL MONEY.
     *
     * KBB-1003 (wc order 10235) was paid AED 199.00 by card and WooCommerce
     * refunded AED 99.50 of it. PaymentRefunder's ceiling is
     * capturedFils() - refundedFils(), and refundedFils() is
     * SUM(refunds.amount) over PaymentRefunder::COUNTED. With the table empty
     * that subtrahend is zero, so Store -> Orders offered the whole AED 199.00
     * as still refundable — and the Refund button would have sent a SECOND
     * 99.50 through Stripe against a charge that no longer holds it.
     */
    $refunder = app(PaymentRefunder::class);

    giImport(['only' => giWithoutRefundsAndNotes()]);

    $order = giOrder(10235);

    // BEFORE. No payments row is imported, so capturedFils() falls back to the
    // order total — which PaymentRefunder's own header describes as the soft
    // ceiling for exactly this case, an imported order with no provider record.
    expect($refunder->capturedFils($order))->toBe(19900)
        ->and($refunder->refundedFils($order))->toBe(0)
        ->and($refunder->capturedFils($order) - $refunder->refundedFils($order))->toBe(19900);

    giImport(['only' => ['refunds']]);

    $order->refresh();

    // AFTER. Half the order has already gone back, so half is what is left.
    expect($refunder->refundedFils($order))->toBe(9950)
        ->and(Money::amount($refunder->refundedFils($order), 2))->toBe('99.50')
        ->and($refunder->capturedFils($order) - $refunder->refundedFils($order))->toBe(9950);
});

it('shows the imported refund and the imported note on the order screen', function () {
    $this->actingAs(giAdmin(), 'admin');

    giImport();

    $refunded = giOrder(10235);

    $detail = $this->getJson('/admin-api/orders/'.$refunded->id.'/detail')->assertOk()->json();

    expect($detail['refunded_total_aed'])->toBe(99.5)
        ->and($detail['refundable_aed'])->toBe(99.5)
        ->and($detail['settlement']['refundable_fils'])->toBe(9950)
        ->and($detail['refunds'])->toHaveCount(1)
        ->and($detail['refunds'][0]['amount_aed'])->toBe(99.5)
        ->and($detail['refunds'][0]['status'])->toBe('succeeded')
        ->and($detail['refunds'][0]['reason'])->toBe('One bottle came back')
        // Woo names the refunder by WordPress user id; the column is printed
        // beside the money, where a bare "1" is not a person.
        ->and($detail['refunds'][0]['refunded_by'])->toBe('WordPress user 1');

    $noted = giOrder(10233);

    $detail = $this->getJson('/admin-api/orders/'.$noted->id.'/detail')->assertOk()->json();

    $contents = array_column($detail['notes'], 'content');

    expect(in_array('Order status changed from Processing to Completed.', $contents, true))->toBeTrue(
        'the imported order note is not on the order screen: '.implode(' | ', $contents)
    );

    foreach ($detail['notes'] as $note) {
        if ($note['content'] === 'Order status changed from Processing to Completed.') {
            // Internal, not a customer note — the default that does not publish
            // a remark the shop made to itself.
            expect($note['is_customer_note'])->toBeFalse()
                ->and($note['author'])->toBe('WooCommerce')
                // 2019, not today. Order::notes() is ordered by this column.
                ->and($note['created_at'])->toStartWith('2019-03-06');
        }
    }
});

/* ========================================================================
 | Imported vs. performed here
 |========================================================================*/

it('dates an imported refund when it happened, not when it was imported', function () {
    /*
     * FOUND BY MUTATION. Removing `created_at`/`updated_at` from the write left
     * every test green: Eloquent stamps them, the second pass still reports
     * `unchanged` (the attribute array no longer carries them, so nothing is
     * dirty), and nothing looked at the column. The whole refund history would
     * have landed under the day of the import — which is the same defect
     * OrderImporter's header opens with, and the reconcile window in
     * Store -> Payments is built from exactly this column.
     */
    giImport();

    $refund = Refund::query()->where('wc_refund_id', 10236)->firstOrFail();

    expect($refund->created_at->toDateString())->toBe('2023-09-05')
        // updated_at too, and for its own reason: an updated_at of now() makes
        // "recently modified" meaningless the moment the import finishes.
        ->and($refund->updated_at->toDateString())->toBe('2023-09-05')
        // Read in the site's zone, out of the manifest, and stored UTC. 10:00
        // Asia/Dubai is 06:00Z.
        ->and($refund->created_at->utc()->format('H:i'))->toBe('06:00');
});

it('names the refund breakdown it cannot keep, with its value', function () {
    /*
     * ALSO FOUND BY MUTATION. Dropping the discard entry left everything green,
     * because nothing asked for it. `refunded_items` is the only thing in
     * refunds.csv that does not reach the database, and a loss nobody is told
     * about is the exact failure the discard channel exists to prevent.
     */
    $report = giImport();

    $discards = $report->for('refunds')->discards();
    $named = implode(' ', array_keys($discards));

    expect(str_contains($named, 'which lines of the order a refund covered'))->toBeTrue(
        'the refund breakdown was dropped in silence: '.$named
    );

    $samples = [];

    foreach ($discards as $group) {
        foreach ($group['samples'] as $sample) {
            $samples[] = $sample['before'];
        }
    }

    // The value, so the owner approves a fact and not a column heading.
    expect(in_array('5506:-1:-99.50', $samples, true))->toBeTrue(
        'the discard named the column and not what was in it: '.implode(' | ', $samples)
    );
});

it('tells an imported refund apart from one this shop performed, and keeps both counting', function () {
    giImport();

    $imported = Refund::query()->whereNotNull('wc_refund_id')->firstOrFail();

    /*
     * `provider` is NOT the order's gateway. Reconciler::stepLocalRefunds()
     * selects `refunds WHERE provider = <gateway>` and reports every row the
     * provider did not list as REFUND_NOT_CONFIRMED. refunds.csv carries the
     * WooCommerce refund POST id, which is not and never will be a Stripe
     * reference, so filing these under `stripe` would raise a permanent false
     * finding on every imported refund — a reconciliation that never balances.
     */
    expect(giOrder(10235)->payment_method)->toBe('stripe')
        ->and($imported->provider)->toBe(RefundImporter::PROVIDER)
        ->and($imported->provider)->not->toBe('stripe')
        // No provider reference, because we do not have the provider's id for
        // it; no idempotency key, because that column is a unique lock on a
        // call to a payment provider and an import makes no call.
        ->and($imported->provider_ref)->toBeNull()
        ->and($imported->idempotency_key)->toBeNull()
        ->and($imported->wc_refund_id)->toBe(10236)
        // And it COUNTS. Only `pending` and `succeeded` are in COUNTED, and
        // COUNTED is what every revenue figure in this application nets out.
        ->and($imported->status)->toBe('succeeded')
        ->and(in_array($imported->status, PaymentRefunder::COUNTED, true))->toBeTrue();

    /*
     * A refund this shop performs is the other kind. KBB-1001 is cash on
     * delivery, which has no refund API — PaymentRefunder records it anyway,
     * because a recorded ledger entry is what a manual bank transfer needs —
     * so this runs without a configured gateway and still exercises the real
     * path end to end.
     */
    $outcome = app(PaymentRefunder::class)->refund(giOrder(10233), 5000, 'Goodwill', 'Owner');

    expect($outcome->ok)->toBeTrue($outcome->message);

    $performed = Refund::query()->whereNull('wc_refund_id')->firstOrFail();

    expect($performed->provider)->toBe('cod')
        ->and($performed->provider)->not->toBe(RefundImporter::PROVIDER)
        // A key, because this one really did go to a provider path and a second
        // click must be refused by the unique index rather than by a check.
        ->and($performed->idempotency_key)->not->toBeNull()
        ->and($performed->status)->toBe('succeeded');

    // Both kinds hold money against their order's ceiling, which is the whole
    // reason they share a table.
    expect(app(PaymentRefunder::class)->refundedFils(giOrder(10235)))->toBe(9950)
        ->and(app(PaymentRefunder::class)->refundedFils(giOrder(10233)))->toBe(5000);
});

it('refuses a second refund of money WooCommerce already returned', function () {
    /*
     * The imported refund is not decoration on a screen: it is the thing that
     * makes the ceiling right. AED 199.00 captured, AED 99.50 already back,
     * so AED 150.00 is more than is left and the refunder says so instead of
     * calling Stripe.
     */
    giImport();

    $outcome = app(PaymentRefunder::class)->refund(giOrder(10235), 15000, 'Second bottle', 'Owner');

    expect($outcome->ok)->toBeFalse('the shop refunded money it had already given back')
        ->and($outcome->code)->toBe('over_captured')
        ->and($outcome->message)->toContain('99.50');

    // Nothing was written beyond the imported row.
    expect(Refund::query()->count())->toBe(1);
});

it('never emails a customer about a refund WooCommerce already told them about', function () {
    /*
     * OrderMailObserver mails on `Refund::created` when the row arrives already
     * `succeeded`, which is the shape of every row RefundImporter writes. Left
     * alone, importing a five-year refund history tells several hundred real
     * people, today, that their money is on its way back.
     *
     * The guard is on the ROW (`wc_refund_id`), not on the import process, so
     * a delta re-run and a row touched by hand are equally silent. Asserted
     * both ways round here, because a guard that also silenced a real refund
     * would be a worse defect than the one it fixes.
     */
    Mail::fake();

    giImport();

    Mail::assertNotSent(\App\Mail\OrderRefunded::class);

    /*
     * AND THE GUARD IS NARROW. The same order, a refund performed HERE —
     * written the way PaymentRefunder writes one, pending and then settled —
     * still mails. A guard that also silenced a real refund would be a worse
     * defect than the one it fixes, so it is asserted both ways round.
     */
    $order = giOrder(10235);

    $refund = $order->refunds()->create([
        'amount' => 1000,
        'status' => 'pending',
        'provider' => 'cod',
        'refunded_by' => 'Admin',
    ]);

    Mail::assertNotSent(\App\Mail\OrderRefunded::class);

    $refund->forceFill(['status' => 'succeeded'])->save();

    Mail::assertSent(\App\Mail\OrderRefunded::class);
})->skip(
    fn (): bool => ! method_exists(OrderMailer::class, 'refunded'),
    'this build has no refund email to suppress',
);

/* ========================================================================
 | The money guards
 |========================================================================*/

it('refuses a refund whose two money conventions disagree, rather than picking one', function () {
    /*
     * Woo holds `_refund_amount` positive and the refund order's `total`
     * negative, and they are the same money. docs/GE-WP-EXPORTER.md emits both
     * precisely so an importer does not have to guess — "an importer guessing
     * which it has applies a refund twice or backwards". When they disagree,
     * picking one is a coin flip on real money, so the row is refused and both
     * figures are named.
     */
    giImport(['only' => giWithoutRefundsAndNotes()]);

    $report = giRefundsOnly(
        "refund_id,order_id,date_created,amount,total\n"
        ."10801,10233,2019-03-20 10:00:00,25.00,-30.00\n"
    );

    $rejections = $report->for('refunds')->rejections();

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toContain('25.00')
        ->and($rejections[0]['reason'])->toContain('30.00')
        ->and($rejections[0]['id'])->toBe('refund_id=10801')
        ->and(Refund::query()->count())->toBe(0);
});

it('takes the negative convention on its own and lands it positive', function () {
    giImport(['only' => giWithoutRefundsAndNotes()]);

    // `total` alone — the refund order's own figure, negative, which is what a
    // hand-rolled SELECT against wp_posts gives you.
    $report = giRefundsOnly(
        "refund_id,order_id,date_created,total\n"
        ."10802,10233,2019-03-20 10:00:00,-12.34\n"
    );

    expect(giRejections($report))->toBe([]);

    $refund = Refund::query()->where('wc_refund_id', 10802)->firstOrFail();

    // Positive in the column, because `refunds.amount` is a magnitude and every
    // reader subtracts it. A negative would ADD to revenue.
    expect($refund->amount)->toBe(1234)
        ->and(app(PaymentRefunder::class)->refundedFils(giOrder(10233)))->toBe(1234);
});

it('refuses a refund of nothing', function () {
    giImport(['only' => giWithoutRefundsAndNotes()]);

    $report = giRefundsOnly(
        "refund_id,order_id,date_created,amount\n"
        ."10803,10233,2019-03-20 10:00:00,0.00\n"
        ."10804,10233,2019-03-20 10:00:00,\n"
    );

    expect($report->for('refunds')->rejections())->toHaveCount(2)
        ->and(Refund::query()->count())->toBe(0);
});

it('keeps the fils on a refund that does not divide into whole dirhams', function () {
    giImport(['only' => giWithoutRefundsAndNotes()]);

    giRefundsOnly(
        "refund_id,order_id,date_created,amount\n"
        ."10805,10233,2019-03-20 10:00:00,33.33\n"
        ."10806,10233,2019-03-20 10:00:00,0.07\n"
    );

    // 3333 + 7, by integer arithmetic on the decimal string. The previous dead
    // importer in this repository assigned the decimal straight into an integer
    // column and AED 99.50 landed as 99 fils.
    expect(app(PaymentRefunder::class)->refundedFils(giOrder(10233)))->toBe(3340)
        ->and(Money::amount(3340, 2))->toBe('33.40');
});

it('refuses a refund of an order that is not in this database', function () {
    giImport(['only' => giWithoutRefundsAndNotes()]);

    $report = giRefundsOnly(
        "refund_id,order_id,date_created,amount\n"
        ."10807,99999,2019-03-20 10:00:00,10.00\n"
    );

    $rejections = $report->for('refunds')->rejections();

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toContain('99999')
        ->and(Refund::query()->count())->toBe(0);
});

it('names a refund bigger than the order was ever charged instead of clamping it', function () {
    /*
     * NOT clamped and NOT refused. WooCommerce's record is the evidence that
     * money moved; `orders.total` is a column an operator can edit, and
     * PaymentRefunder::capturedFils()'s header sets out what trusting it as a
     * ceiling has already cost this shop in both directions.
     */
    giImport(['only' => giWithoutRefundsAndNotes()]);

    $report = giRefundsOnly(
        "refund_id,order_id,date_created,amount\n"
        ."10808,10233,2019-03-20 10:00:00,500.00\n"
    );

    expect(giRejections($report))->toBe([])
        ->and((int) Refund::query()->where('wc_refund_id', 10808)->value('amount'))->toBe(50000);

    $notes = implode(' ', array_keys($report->for('refunds')->notes()));

    expect(str_contains($notes, 'past what the order itself says it was charged'))->toBeTrue(
        'an over-total refund was imported and nothing in the report said so: '.$notes
    );

    // And the screen does not go negative on it.
    $refunder = app(PaymentRefunder::class);
    $order = giOrder(10233);

    expect(max(0, $refunder->capturedFils($order) - $refunder->refundedFils($order)))->toBe(0);
});

/* ========================================================================
 | Order notes
 |========================================================================*/

it('keeps a customer note a customer note and an internal note internal', function () {
    giImport(['only' => giWithoutRefundsAndNotes()]);

    $dir = sys_get_temp_dir().'/kbb-gi-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    file_put_contents($dir.'/order_notes.csv',
        "note_id,order_id,date_created,author,content,is_customer_note\n"
        ."8301,10233,2019-03-06 09:05:00,WooCommerce,Chargeback reference 99812 — do not tell the buyer,no\n"
        ."8302,10233,2019-03-07 09:05:00,Shop,Your parcel is with the courier.,yes\n"
    );

    (new ImportRunner)->run(new ImportOptions(
        directory: $dir,
        only: ['order-notes'],
        sourceTimezone: 'Asia/Dubai',
        runKey: 'gi-notes-'.bin2hex(random_bytes(4)),
    ));

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);

    $internal = OrderNote::query()->where('source_comment_id', 8301)->firstOrFail();
    $customer = OrderNote::query()->where('source_comment_id', 8302)->firstOrFail();

    expect($internal->is_customer_note)->toBeFalse()
        ->and($customer->is_customer_note)->toBeTrue();
});

it('defaults a note with no is_customer_note column to internal', function () {
    /*
     * THE SAFE DIRECTION ON A COLUMN WHOSE OTHER VALUE IS "SHOW THIS TO THE
     * BUYER". A WooCommerce internal note routinely carries a courier's phone
     * number, a chargeback reference or a remark about the customer; defaulting
     * the wrong way publishes it.
     */
    giImport(['only' => giWithoutRefundsAndNotes()]);

    $dir = sys_get_temp_dir().'/kbb-gi-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    file_put_contents($dir.'/order_notes.csv',
        "note_id,order_id,date,author,note\n"
        ."8401,10233,2019-03-06 09:05:00,admin,Called the customer\n"
    );

    (new ImportRunner)->run(new ImportOptions(
        directory: $dir,
        only: ['order-notes'],
        sourceTimezone: 'Asia/Dubai',
        runKey: 'gi-notes-'.bin2hex(random_bytes(4)),
    ));

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);

    expect(OrderNote::query()->where('source_comment_id', 8401)->value('is_customer_note'))
        ->toBeIn([false, 0, '0']);
});

it('refuses an empty note rather than putting a blank line in the history', function () {
    /*
     * FOUND BY MUTATION, AND THE MUTATION SAID SOMETHING WORTH KEEPING.
     *
     * No fixture carries an empty note, so removing the guard changed nothing
     * anybody was looking at. Putting a test on it was not enough either: the
     * row is refused EITHER WAY, because `order_notes.content` is NOT NULL and
     * ImportRunner catches a QueryException per row and rejects it with the
     * driver's own words. So this guard is not what keeps the bad row out — the
     * column is. What it buys is a reason the owner can act on, in the same
     * sentence on both engines, instead of "NOT NULL constraint failed:
     * order_notes.content" on SQLite and "Column 'content' cannot be null" on
     * MySQL.
     *
     * Which is why this asserts the IMPORTER'S OWN WORDING and not merely that
     * two rows were refused. An assertion that the database's message also
     * satisfies is an assertion about the database.
     */
    giImport(['only' => giWithoutRefundsAndNotes()]);

    $dir = sys_get_temp_dir().'/kbb-gi-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    file_put_contents($dir.'/order_notes.csv',
        "note_id,order_id,date_created,author,content\n"
        ."8501,10233,2019-03-06 09:05:00,admin,\n"
        ."8502,10233,2019-03-06 09:06:00,admin,   \n"
    );

    $report = (new ImportRunner)->run(new ImportOptions(
        directory: $dir,
        only: ['order-notes'],
        sourceTimezone: 'Asia/Dubai',
        runKey: 'gi-notes-'.bin2hex(random_bytes(4)),
    ));

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);

    $rejections = $report->for('order-notes')->rejections();

    expect($rejections)->toHaveCount(2)
        ->and($rejections[0]['reason'])->toContain('a blank line in an order')
        ->and($rejections[1]['reason'])->toContain('a blank line in an order')
        ->and($rejections[0]['id'])->toBe('note_id=8501')
        ->and(OrderNote::query()->whereNotNull('source_comment_id')->count())->toBe(0);
});

it('does not keep the note author email, and says so', function () {
    $report = giImport();

    $discards = $report->for('order-notes')->discards();
    $named = implode(' ', array_keys($discards));

    expect(str_contains($named, 'email address'))->toBeTrue(
        'the author email was dropped in silence: '.$named
    );

    // The value is in the sample, so the owner approves a fact and not a column
    // heading. And it really is not in the database.
    $samples = [];

    foreach ($discards as $group) {
        foreach ($group['samples'] as $sample) {
            $samples[] = $sample['before'];
        }
    }

    expect(in_array('woocommerce@kbeautybliss.com', $samples, true))->toBeTrue();
    expect(\Illuminate\Support\Facades\Schema::hasColumn('order_notes', 'author_email'))->toBeFalse();
});

/* ========================================================================
 | The contracts every entity has to satisfy
 |========================================================================*/

it('verifies both new entities by counting the database, not its own report', function () {
    $report = giImport();
    $manifest = giManifest();

    expect($report->for('refunds')->inDatabase)->toBe($manifest['counts']['refunds'])
        ->and(Refund::query()->whereNotNull('wc_refund_id')->count())->toBe($manifest['counts']['refunds'])
        ->and($report->for('order-notes')->inDatabase)->toBe($manifest['counts']['order_notes'])
        ->and(OrderNote::query()->whereNotNull('source_comment_id')->count())->toBe($manifest['counts']['order_notes']);

    // Every row read was accounted for — the check that catches a mapping which
    // returns early and increments nothing.
    foreach (['refunds', 'order-notes'] as $entity) {
        expect($report->for($entity)->unaccountedCount())->toBe(0, $entity.' lost a row silently')
            ->and($report->for($entity)->rejectedCount())->toBe(0);
    }
});

it('is idempotent: a second pass changes nothing', function () {
    giImport();

    $report = giImport();

    foreach (['refunds', 'order-notes'] as $entity) {
        $e = $report->for($entity);

        expect($e->created)->toBe(0, $entity.' created a second copy on a re-run')
            ->and($e->updated)->toBe(0, $entity.' rewrote rows nothing had changed')
            ->and($e->unchanged)->toBeGreaterThan(0, $entity.' reported nothing at all');
    }

    expect(Refund::query()->count())->toBe(1)
        ->and(OrderNote::query()->whereNotNull('source_comment_id')->count())->toBe(1);
});

it('is registered on the runner and on the screen that drives it, in the same order', function () {
    /*
     * ImportWorkspace::ENTITIES and ImportRunner::entities() are two
     * hand-maintained lists. Registering `seo` on one and not the other made
     * every upload on Store -> Import 500. AdminImportScreenTest pins the whole
     * walk; this pins the two names this lane adds, so a merge that drops one
     * of them fails here with the reason written on it.
     */
    $runner = ImportRunner::entityNames();

    expect(in_array('refunds', $runner, true))->toBeTrue('refunds is not registered on ImportRunner')
        ->and(in_array('order-notes', $runner, true))->toBeTrue('order-notes is not registered on ImportRunner')
        ->and(\App\Services\ImportConsole\ImportWorkspace::isEntity('refunds'))->toBeTrue()
        ->and(\App\Services\ImportConsole\ImportWorkspace::isEntity('order-notes'))->toBeTrue()
        ->and(\App\Services\ImportConsole\ImportWorkspace::entities())->toBe($runner);

    // After orders, because both attach to one and their order_id is NOT NULL.
    expect(array_search('refunds', $runner, true))->toBeGreaterThan(array_search('orders', $runner, true))
        ->and(array_search('order-notes', $runner, true))->toBeGreaterThan(array_search('orders', $runner, true));
});

it('adds no migration and no column, because the schema already had both keys', function () {
    /*
     * This project has been wrong about "nothing exists" five times this month.
     * `refunds.wc_refund_id` and `order_notes.source_comment_id` have been in
     * the Phase 0 schema — nullable and UNIQUE — from the start, and
     * 2026_09_22_000000_add_import_external_ids names both in its header as
     * external ids that were ALREADY present. Pinned, because the unique index
     * is the only thing that stops a delta pass doubling every refund in the
     * store, and it is exactly the kind of constraint a repair migration
     * re-adds without.
     */
    foreach ([['refunds', 'wc_refund_id'], ['order_notes', 'source_comment_id']] as [$table, $column]) {
        expect(\Illuminate\Support\Facades\Schema::hasColumn($table, $column))->toBeTrue(
            $table.'.'.$column.' is gone; the import cannot be re-run without it'
        );

        $unique = false;

        foreach (\Illuminate\Support\Facades\Schema::getIndexes($table) as $index) {
            if (($index['unique'] ?? false) === true
                && array_map('strtolower', array_map('strval', (array) ($index['columns'] ?? []))) === [$column]) {
                $unique = true;
            }
        }

        expect($unique)->toBeTrue($table.'.'.$column.' is not unique — a second pass would double every row');
    }
});

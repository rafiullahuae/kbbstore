<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| A WooCommerce import must not email anybody about an order from 2023
|------------------------------------------------------------------------------
|
| Nothing suppressed model events during an import. ImportContext::apply() calls
| $model->save(), an existing order's save fires Eloquent's `updated` event,
| App\Services\Mail\OrderMailObserver listens to it, and OrderMailer::statusChanged()
| then asks OrderStatusMailPolicy — whose standing rule for `shipped` and
| `cancelled` is ON by default, because that is what the shop does when a real
| operator moves a real order.
|
| So a delta pass carrying the statuses a Woo store has moved to since the last
| export told real customers, at their real addresses, that an order they placed
| years ago had just shipped or just been cancelled. One email per changed row,
| the biggest re-syncs being the loudest.
|
| WHAT IT IS NOT. A first import mails nothing: a new row fires `created`, and
| the observer listens to `updated`. Nor does a dry run: the whole preview
| happens inside a transaction that is always rolled back, and the observer
| sends through DB::afterCommit(). Both of those are asserted below, because a
| fix that covered them and nothing else would look identical from outside.
|
| THE FIX IS NOT THE STATUS FUNNEL. App\Services\Orders\OrderStatus is where
| every other status write goes, and the importer is deliberately NOT routed
| through it — that would write thousands of history notes and hand back coupon
| uses WooCommerce has already accounted for. So the suppression is taken where
| the mail decision is actually taken, in OrderStatusMailPolicy, which is the one
| gate both the observer and the bulk path already pass through.
|
| AND IT IS NARROW, AND IT IS COUNTED. It suppresses order-status mail to the
| customer and nothing else, only while the orders entity is running, and every
| suppressed message is counted into the import report as a note — because an
| import that silently stopped sending everything would be its own hazard, and
| the owner should be told that thirty of his customers were not written to
| rather than discover it.
|
| Pest note: `toContain` reads a second argument as another needle rather than as
| a message, so explanations are written `expect(str_contains(...))->toBeTrue()`.
*/

use App\Mail\OrderStatusChanged;
use App\Models\Order;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;
use App\Services\Mail\OrderStatusMailPolicy;
use Illuminate\Support\Facades\Mail;

/** A private copy of the nasty fixture, so one file can be rewritten. */
function importMailFixtureCopy(): string
{
    $dir = sys_get_temp_dir() . '/kbb-import-mail-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    foreach (glob(base_path('tests/Fixtures/woo') . '/*.csv') ?: [] as $file) {
        copy($file, $dir . '/' . basename($file));
    }

    return $dir;
}

function importMailRun(string $dir, array $overrides = []): ImportReport
{
    return (new ImportRunner)->run(new ImportOptions(...array_merge([
        'directory' => $dir,
        'adoptBySlug' => true,
    ], $overrides)));
}

/**
 * Re-export the fixture with an order moved to a status the shop emails about.
 *
 * `wc-cancelled` is one of exactly two statuses App\Mail\OrderStatusChanged has
 * wording for, and its module toggle ships ON — so this is the real shape of the
 * hazard rather than a contrived one.
 */
function importMailRewriteStatus(string $dir, string $from, string $to): void
{
    $path = $dir . '/orders.csv';
    $csv = (string) file_get_contents($path);

    expect(str_contains($csv, $from))->toBeTrue("The fixture no longer contains {$from}.");

    file_put_contents($path, str_replace($from, $to, $csv));
}

it('sends nothing when a re-import moves an old order to a status the shop emails about', function () {
    $dir = importMailFixtureCopy();

    // Pass one: every order is created, which fires `created` and not `updated`.
    importMailRun($dir);

    $order = Order::query()->where('wc_order_id', 10233)->firstOrFail();

    expect($order->status)->toBe('completed')
        ->and($order->email)->toBe('buyer@example.test')
        // Placed in 2019. This is the customer the hazard is about.
        ->and($order->created_at->year)->toBe(2019);

    importMailRewriteStatus($dir, '10233,KBB-1001,wc-completed', '10233,KBB-1001,wc-cancelled');

    Mail::fake();

    importMailRun($dir);

    // The status really did move — the import did its job...
    expect(Order::query()->where('wc_order_id', 10233)->value('status'))->toBe('cancelled');

    // ...and nobody was told about it years after the fact.
    Mail::assertNothingSent();
});

it('counts every message it held back into the import report', function () {
    $dir = importMailFixtureCopy();

    importMailRun($dir);
    importMailRewriteStatus($dir, '10233,KBB-1001,wc-completed', '10233,KBB-1001,wc-cancelled');

    Mail::fake();

    $report = importMailRun($dir);

    $notes = $report->for('orders')->notes();
    $held = '';

    foreach ($notes as $note => $count) {
        if (str_contains($note, 'not emailed')) {
            $held = $note;
        }
    }

    expect($held)->not->toBe('', 'The report says nothing about the emails the import held back: '
        . implode(' | ', array_keys($notes)));

    expect($notes[$held])->toBe(1, 'One status change was suppressed, so the note should be counted once.');
});

it('leaves a first import silent, because a created row was never going to mail', function () {
    $dir = importMailFixtureCopy();

    Mail::fake();

    importMailRun($dir);

    Mail::assertNothingSent();
});

it('leaves a dry run silent', function () {
    $dir = importMailFixtureCopy();

    importMailRun($dir);
    importMailRewriteStatus($dir, '10233,KBB-1001,wc-completed', '10233,KBB-1001,wc-cancelled');

    Mail::fake();

    importMailRun($dir, ['dryRun' => true]);

    Mail::assertNothingSent();
});

/* --------------------------------------------- the suppression is not sticky */

it('hands the shop back its ordinary mail behaviour the moment the import ends', function () {
    $dir = importMailFixtureCopy();

    importMailRun($dir);
    importMailRewriteStatus($dir, '10233,KBB-1001,wc-completed', '10233,KBB-1001,wc-cancelled');

    Mail::fake();

    importMailRun($dir);

    // An operator cancelling an order by hand, in the same process, right
    // afterwards. A suppression that outlived the run would silence this too,
    // and nothing on any screen would say why.
    $other = Order::create([
        'order_number' => 'AFTER-' . uniqid(),
        'email' => 'layla@example.ae',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 1000, 'total' => 1000,
    ]);

    $other->status = 'cancelled';
    $other->save();

    Mail::assertSent(OrderStatusChanged::class, 1);
});

it('suppresses only while it is asked to, and counts what it suppressed', function () {
    // The policy's own contract, away from the importer — so a later lane that
    // wants the same lever can see what it is buying.
    $policy = app(OrderStatusMailPolicy::class);

    $order = Order::create([
        'order_number' => 'POLICY-' . uniqid(),
        'email' => 'layla@example.ae',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 1000, 'total' => 1000,
    ]);

    expect($policy->shouldNotify($order, 'cancelled'))->toBeTrue();

    $returned = $policy->whileSuppressed('a test', function () use ($policy, $order) {
        expect($policy->shouldNotify($order, 'cancelled'))->toBeFalse();
        expect($policy->shouldNotify($order, 'shipped'))->toBeFalse();

        return 'the closure ran';
    });

    expect($returned)->toBe('the closure ran')
        ->and($policy->suppressedCount())->toBe(2)
        ->and($policy->shouldNotify($order, 'cancelled'))->toBeTrue();
});

it('lifts the suppression even when the work inside it throws', function () {
    $policy = app(OrderStatusMailPolicy::class);

    $order = Order::create([
        'order_number' => 'THROW-' . uniqid(),
        'email' => 'layla@example.ae',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 1000, 'total' => 1000,
    ]);

    try {
        $policy->whileSuppressed('a test', function () {
            throw new \RuntimeException('the import fell over');
        });
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toBe('the import fell over');
    }

    expect($policy->shouldNotify($order, 'cancelled'))
        ->toBeTrue('A failed import left the shop permanently unable to email anyone.');
});

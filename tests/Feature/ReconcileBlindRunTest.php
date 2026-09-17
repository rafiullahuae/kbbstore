<?php

declare(strict_types=1);

/**
 * Lane EW — a run that could not look must not be able to read clean.
 *
 * ---------------------------------------------------------------------------
 * WHAT WAS WRONG
 * ---------------------------------------------------------------------------
 *
 * PaymentReconciliationController::acknowledge() let an operator acknowledge a
 * `payments_source_unavailable` / `refunds_source_unavailable` finding, and
 * Reconciler::counts() excludes acknowledged rows. So the screen read ZERO
 * outstanding on a run that had read NOTHING from the provider — while
 * `run.status` said `complete`, because every phase was finished, including
 * the ones finished by failure.
 *
 * Acknowledging a discrepancy means "I looked, it's fine". Acknowledging "I
 * could not look" does not make the looking happen. A window nobody had
 * reconciled sat in the list looking reconciled, permanently, and the check it
 * was missing is the one the whole class is for.
 *
 * ---------------------------------------------------------------------------
 * HOW IT IS CLOSED, AND WHY THAT WAY
 * ---------------------------------------------------------------------------
 *
 * THE ACK IS REFUSED on those two kinds. A run-level flag was the alternative
 * and it needs a screen to read it; this repository cannot ship a view change
 * in this lane. The refusal needs nothing — with the row un-ackable, counts()
 * can never reach zero on a blind run, on the screen exactly as it stands.
 *
 * It is not a dead end, which is what would have made it the wrong call: the
 * adversarial lane's rearmUnavailable() re-runs exactly those phases the next
 * time the run is opened, so the operator's way out is the one that actually
 * reconciles the window rather than the one that hides the question. That is
 * pinned below too, because the refusal is only defensible if the exit exists.
 *
 * The flag is there as well (Reconciler::blindness()), computed WITHOUT
 * reference to acknowledged_at, so the screen can say WHY. The count is what
 * stops the owner believing a window has been answered; the flag is what tells
 * him which provider he is blind to.
 */

use App\Models\AdminUser;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Reconciliation\ReconcileWindow;
use App\Services\Payments\Reconciliation\Reconciler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    Http::preventStrayRequests();

    // The routes ship UNMOUNTED — CLAUDE.md forbids editing web.php — so this
    // registers routes/payments-reconcile.php into exactly the group its own
    // header tells the integrator to mount it in. One middleware() call, not
    // two: RouteRegistrar::middleware() REPLACES rather than appends.
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/payments-reconcile.php'));
});

function rbStripe(): void
{
    $row = PaymentProvider::create([
        'id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 3,
    ]);

    $row->config = ['secret_key' => 'sk_test_BLINDCANARY000001'];
    $row->save();

    app(GatewayCredentials::class)->forget();
}

function rbAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Blind Owner',
        'email' => 'blind-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);
}

/** A run against a provider that answers nothing at all. */
function rbBlindRun(?ReconcileWindow $window = null): int
{
    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['code' => 'api_key_expired']], 401)]);

    $reconciler = app(Reconciler::class);
    $runId = $reconciler->open($window ?? ReconcileWindow::lastDays(7), ['stripe'], 'tester');

    for ($i = 0; $i < 200; $i++) {
        if (($reconciler->step($runId)['done'] ?? false) === true) {
            break;
        }
    }

    return $runId;
}

/** @return array<int, object> */
function rbFindings(int $runId, ?string $kind = null): array
{
    $q = DB::table(Reconciler::FINDINGS)->where('run_id', $runId);

    if ($kind !== null) {
        $q->where('kind', $kind);
    }

    return $q->orderBy('id')->get()->all();
}

/*
|------------------------------------------------------------------------------
| 1. The bug itself
|------------------------------------------------------------------------------
*/

it('refuses to acknowledge a notice that says the provider could not be read', function () {
    rbStripe();

    $runId = rbBlindRun();

    $notice = rbFindings($runId, Reconciler::PAYMENTS_SOURCE_UNAVAILABLE)[0];

    $response = test()->actingAs(rbAdmin(), 'admin')
        ->postJson('/admin-api/payments/reconcile/' . $runId . '/findings/' . $notice->id . '/ack', [
            'note' => 'Stripe was down, never mind.',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('error', 'cannot_acknowledge')
        ->assertJsonPath('kind', Reconciler::PAYMENTS_SOURCE_UNAVAILABLE);

    // And nothing was hidden.
    expect(DB::table(Reconciler::FINDINGS)->where('id', $notice->id)->value('acknowledged_at'))->toBeNull();
});

it('cannot be talked down to zero outstanding on a run that read nothing', function () {
    /*
     * The money consequence, stated as the screen states it. Before this, the
     * two notices could be acknowledged one after the other and the summary
     * line went to zero — over a window in which not one transaction had been
     * compared with anything.
     */
    rbStripe();

    $runId = rbBlindRun();

    $admin = rbAdmin();

    foreach (rbFindings($runId) as $finding) {
        test()->actingAs($admin, 'admin')
            ->postJson('/admin-api/payments/reconcile/' . $runId . '/findings/' . $finding->id . '/ack');
    }

    $counts = app(Reconciler::class)->counts($runId);

    expect($counts)->not->toBe([], 'The screen read zero outstanding on a run that checked nothing.')
        ->and($counts[Reconciler::PAYMENTS_SOURCE_UNAVAILABLE] ?? 0)->toBe(1)
        ->and($counts[Reconciler::REFUNDS_SOURCE_UNAVAILABLE] ?? 0)->toBe(1);
});

it('still accepts an acknowledgement of a real discrepancy', function () {
    /*
     * The refusal has to be narrow. Acknowledgement exists because a second run
     * over a window re-reports everything the owner has already decided about,
     * and a report that shouts about settled business stops being read. Taking
     * that away would be a worse bug than the one being fixed.
     */
    rbStripe();

    $runId = rbBlindRun();

    // A discrepancy of the ordinary kind, written into the same run.
    $id = DB::table(Reconciler::FINDINGS)->insertGetId([
        'run_id' => $runId,
        'provider' => 'stripe',
        'kind' => Reconciler::MONEY_NOT_RECORDED,
        'severity' => 'alarm',
        'summary' => 'Stripe holds 250.00 this shop has no record of.',
        'detail' => '[]',
        'fingerprint' => 'rb-' . uniqid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    test()->actingAs(rbAdmin(), 'admin')
        ->postJson('/admin-api/payments/reconcile/' . $runId . '/findings/' . $id . '/ack', [
            'note' => 'Refunded at Stripe by hand.',
        ])
        ->assertOk();

    expect(DB::table(Reconciler::FINDINGS)->where('id', $id)->value('acknowledged_by'))->toBe('Blind Owner')
        ->and(app(Reconciler::class)->counts($runId)[Reconciler::MONEY_NOT_RECORDED] ?? 0)->toBe(0);
});

/*
|------------------------------------------------------------------------------
| 2. The run says of itself that it is blind
|------------------------------------------------------------------------------
*/

it('reports the run as blind, and names which half of which provider', function () {
    rbStripe();

    $runId = rbBlindRun();

    $blindness = app(Reconciler::class)->blindness($runId);

    expect($blindness['blind'])->toBeTrue()
        ->and($blindness['providers'])->toHaveCount(1)
        ->and($blindness['providers'][0]['provider'])->toBe('stripe')
        ->and($blindness['providers'][0]['payments'])->toBeTrue()
        ->and($blindness['providers'][0]['refunds'])->toBeTrue();

    // On the report the screen draws, beside the counts that say what was
    // found — a different question from whether anything was looked at.
    test()->actingAs(rbAdmin(), 'admin')
        ->getJson('/admin-api/payments/reconcile/' . $runId . '/findings')
        ->assertOk()
        ->assertJsonPath('blindness.blind', true)
        ->assertJsonPath('blindness.providers.0.provider', 'stripe');

    // On the status payload the screen polls, beside `complete`.
    $status = app(Reconciler::class)->status($runId);

    expect($status['blindness']['blind'])->toBeTrue()
        // `complete` is unchanged and stays unchanged on purpose: it means
        // "nothing left for the browser to ask for", which is what stops the
        // screen polling for ever. It has never meant "answered".
        ->and($status['complete'])->toBeTrue();
});

it('computes blindness without reference to acknowledgement', function () {
    /*
     * The property that makes the flag worth having. There is no acknowledging
     * your way out of not having looked, so this must not be reachable by the
     * ack path even if a future screen finds a way to set the column.
     */
    rbStripe();

    $runId = rbBlindRun();

    // Straight at the column, past the endpoint that now refuses.
    DB::table(Reconciler::FINDINGS)
        ->where('run_id', $runId)
        ->whereIn('kind', Reconciler::COULD_NOT_LOOK)
        ->update(['acknowledged_at' => now(), 'acknowledged_by' => 'someone']);

    expect(app(Reconciler::class)->blindness($runId)['blind'])
        ->toBeTrue('A stamped column made the run look as though it had read the provider.');
});

it('says a run that read the provider is not blind', function () {
    // The flag has to be able to be false, or it says nothing.
    rbStripe();

    Http::fake(['api.stripe.com/*' => Http::response(['data' => [], 'has_more' => false], 200)]);

    $reconciler = app(Reconciler::class);
    $runId = $reconciler->open(ReconcileWindow::lastDays(7), ['stripe'], 'tester');

    for ($i = 0; $i < 200; $i++) {
        if (($reconciler->step($runId)['done'] ?? false) === true) {
            break;
        }
    }

    expect($reconciler->blindness($runId))->toBe(['blind' => false, 'providers' => []])
        ->and($reconciler->counts($runId))->toBe([]);
});

/*
|------------------------------------------------------------------------------
| 3. The way out is the one that actually reconciles the window
|------------------------------------------------------------------------------
*/

it('clears itself when the run is opened again and the provider answers', function () {
    /*
     * The refusal is only defensible because this exists. The owner's route is
     * not "tick it away" — it is "fix the key and press Run over the same
     * dates", which re-walks exactly the phases the outage skipped.
     */
    rbStripe();

    /*
     * One stub for the whole test, switched by a flag. Http::fake() APPENDS
     * its stubs and the first match wins, so a second call would not replace
     * the outage — the retry would "fail" again and this case would pass for
     * the wrong reason.
     */
    $down = true;

    // `use (&$down)`, and an ordinary closure rather than `fn`: an arrow
    // function captures by VALUE at the point it is written, so the flag would
    // never flip and the retry would fail again — passing this case for the
    // exact reason it is meant to rule out.
    Http::fake(function () use (&$down) {
        return $down
            ? Http::response(['error' => ['code' => 'api_key_expired']], 401)
            : Http::response(['data' => [], 'has_more' => false], 200);
    });

    $window = ReconcileWindow::lastDays(7);

    $reconciler = app(Reconciler::class);
    $runId = $reconciler->open($window, ['stripe'], 'tester');

    for ($i = 0; $i < 200; $i++) {
        if (($reconciler->step($runId)['done'] ?? false) === true) {
            break;
        }
    }

    expect($reconciler->blindness($runId)['blind'])->toBeTrue();

    // The key is fixed and Stripe answers.
    $down = false;

    $again = $reconciler->open($window, ['stripe'], 'tester');

    expect($again)->toBe($runId, 'A run is keyed on its window; pressing Run again continues it.');

    for ($i = 0; $i < 200; $i++) {
        if (($reconciler->step($runId)['done'] ?? false) === true) {
            break;
        }
    }

    expect($reconciler->blindness($runId))->toBe(['blind' => false, 'providers' => []])
        ->and($reconciler->counts($runId))->toBe([]);
});

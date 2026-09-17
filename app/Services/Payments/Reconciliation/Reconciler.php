<?php

declare(strict_types=1);

namespace App\Services\Payments\Reconciliation;

use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\PaymentRefunder;
use Illuminate\Support\Facades\DB;

/**
 * Checking the provider's books against ours.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * Everything else in App\Services\Payments is a conversation this shop starts
 * or is pushed into. We create a session; the provider redirects the shopper;
 * the provider posts a webhook; we capture; we refund. Every one of those is
 * fine until one of them does not happen, and then there is nothing.
 *
 * The concrete shape of "nothing": a Stripe payment completes, the webhook
 * delivery fails five times against a signing secret the owner rotated a day
 * early, Stripe gives up, and this database never learns that a customer paid.
 * `paid_at` is null, the order sits in `pending`, the warehouse never sees it,
 * and the customer's card has been charged. There is no error anywhere. The
 * only artefact is a row in Stripe's dashboard that nothing here has ever
 * compared against anything.
 *
 * That was discoverable, before this, by opening the provider's dashboard and
 * reading it against the orders list by hand.
 *
 * ---------------------------------------------------------------------------
 * IT REPORTS. IT DOES NOT REPAIR.
 * ---------------------------------------------------------------------------
 * Nothing in this class writes to `orders`, `payments`, `refunds` or
 * `payment_events`. It writes findings and nothing else, and that is a
 * decision rather than an unfinished feature.
 *
 * An automatic repair here would be a function that reads a list from a remote
 * server and, on the strength of it, marks orders paid. That is the same shape
 * as the defect closed in 2.60.199 — a late callback reviving an order the
 * shop had already cancelled — only worse, because it would act on a whole
 * page at a time and because the input is a list rather than a signed
 * delivery. The stock has gone back on the shelf and been sold; the coupon has
 * been handed back. "The provider says this was paid" is a reason for a human
 * to look, not a reason for software to ship goods.
 *
 * So what the owner gets is the order number, the provider's reference and the
 * amount — everything needed to open one order, look at it, and use the
 * capture or refund button that already exists and already records what it
 * did. One order, explicitly pressed.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT WILL NOT CONCLUDE FROM SILENCE
 * ---------------------------------------------------------------------------
 * Read RemotePage's class comment; it is the other half of this one. In short:
 * "we have money the provider does not" is derived from ABSENCE, and a 401, a
 * 429 and a wrong path all look like absence. Whenever a provider's list
 * cannot be read in full, the conclusions that depend on having read it in
 * full are SKIPPED and the run says so out loud. A reconciliation that
 * reported every payment in the window as unconfirmed because a key had been
 * rotated would not be noisy, it would be the report that convinces an owner
 * he has been defrauded — produced by a typo.
 *
 * ---------------------------------------------------------------------------
 * RESUMABLE, BECAUSE THIS HOST KILLS LONG REQUESTS
 * ---------------------------------------------------------------------------
 * No shell, no queue worker, and PHP-FPM gives up somewhere nobody can query.
 * So a run is many short steps driven from the browser, exactly like the CSV
 * importer next door, and for the same reason.
 *
 * The checkpoint rule is App\Services\Import\Checkpoint's rule, and it is the
 * property everything else rests on: **the cursor advances inside the same
 * transaction as the findings it describes**. A cursor committed separately
 * could point past a page whose findings were rolled back, and those findings
 * would then never be produced again — a reconciliation with a hole in it,
 * which is worse than no reconciliation, because it reports "all clear".
 *
 * Unlike the importer there is no fingerprint refusal on resume. The provider
 * is not a file: its books legitimately grow while the run walks them. What
 * protects us instead is that every finding and every sighting is written with
 * a unique key, so a page re-fetched after an interruption writes nothing
 * twice. Running the whole thing again over the same window continues the same
 * run (the run key is derived from the window and the providers, not random)
 * and produces the same report.
 */
final class Reconciler
{
    /* ------------------------------------------------------------- tables */

    public const RUNS = 'reconciliation_runs';

    public const CHECKPOINTS = 'reconciliation_checkpoints';

    public const SIGHTINGS = 'reconciliation_sightings';

    public const FINDINGS = 'reconciliation_findings';

    /* ------------------------------------------------------------- phases */

    public const PHASE_REMOTE_PAYMENTS = 'remote_payments';

    public const PHASE_REMOTE_REFUNDS = 'remote_refunds';

    public const PHASE_LOCAL_PAYMENTS = 'local_payments';

    public const PHASE_LOCAL_REFUNDS = 'local_refunds';

    public const PHASE_ORDERS = 'orders';

    /** The provider slot the order-side phase runs under. It has no provider. */
    public const LOCAL = 'local';

    /* ----------------------------------------------------- finding kinds */

    /** Q1. The customer paid and this shop does not know. Nothing ships. */
    public const MONEY_NOT_RECORDED = 'money_not_recorded';

    /** Q2. We believe we hold money the provider will not confirm. */
    public const MONEY_NOT_CONFIRMED = 'money_not_confirmed';

    /** Q3. Both sides have it and the figures differ. */
    public const AMOUNT_DISAGREEMENT = 'amount_disagreement';

    /** Q4. A refund on one side with no counterpart on the other. */
    public const REFUND_NOT_RECORDED = 'refund_not_recorded';

    public const REFUND_NOT_CONFIRMED = 'refund_not_confirmed';

    /** Q5, and its reverse. */
    public const ORDER_PAID_NO_PAYMENT = 'order_paid_no_payment';

    public const PAYMENT_NO_PAID_ORDER = 'payment_no_paid_order';

    /**
     * Money the provider is holding that will evaporate if nobody takes it.
     *
     * Not one of the five questions, and found for free on the way past: an
     * authorisation is in the provider's list with a state of its own, and
     * Tabby and Tamara both auto-void one that is never captured. Reporting it
     * as "money the provider has that we have no record of" would be wrong —
     * it is not money yet — so it gets its own, quieter row.
     */
    public const UNCAPTURED = 'authorised_never_captured';

    /** The provider's books could not be read. See the class comment. */
    public const PAYMENTS_SOURCE_UNAVAILABLE = 'payments_source_unavailable';

    public const REFUNDS_SOURCE_UNAVAILABLE = 'refunds_source_unavailable';

    /* ------------------------------------------------------------ budgets */

    /**
     * Remote pages per step.
     *
     * Two, not ten. Each is an HTTPS round trip to somebody else's rate-limited
     * API with a twenty-second timeout, so three slow pages is already a minute
     * and this host's real request ceiling is unknown and unknowable from
     * inside PHP. The browser makes the next call; there is no prize for
     * getting more done inside one of them.
     */
    public const REMOTE_PAGES_PER_STEP = 2;

    /** Rows asked of a provider per page. All three cap at or above this. */
    public const REMOTE_PAGE_SIZE = 100;

    /** Local rows per step. No network, so this can be generous. */
    public const LOCAL_BATCH = 250;

    /**
     * How long a provider's reference may be before it is truncated.
     *
     * This number is not free. It must equal the width of
     * `reconciliation_sightings.remote_key`, because those two together with
     * `provider` and `kind` form a unique index and an InnoDB index is capped
     * at 767 bytes on the older MySQL this host could still be running — 191
     * utf8mb4 characters across ALL of it, not per column. The migration's own
     * comment does the arithmetic.
     *
     * Truncating here rather than letting the database do it is the difference
     * between a key that is consistently shortened on both the write and the
     * lookup — which still matches — and one that is silently cut on MySQL,
     * rejected on strict MySQL, and left whole on SQLite. Three behaviours for
     * one value is how a guard passes on one engine and matches nothing on the
     * other, which has shipped on this project before.
     *
     * 120 is three times the longest id any of these three providers issues.
     */
    public const KEY_LENGTH = 120;

    /**
     * `payments.status` values that assert "a customer's money is ours".
     *
     * Only these. A `capture` row written by PaymentLedger is keyed on the
     * provider's CAPTURE id, which is not the id the provider's payment list
     * returns, so counting one as a claim would report every captured Tabby
     * order as unconfirmed. A `capture_failed` row is evidence money did NOT
     * move. `paid` is written by PaymentConfirmer in the same transaction that
     * sets `paid_at`, and is the only status that means what this phase needs.
     */
    public const CLAIMS_MONEY = ['paid'];

    /**
     * `payments.status` values that mean "the provider has money this order is
     * not getting credit for".
     *
     * Each is a real, recorded event with money sitting at the far end:
     * `late_confirmation` is a verified payment for an order that had already
     * been cancelled, and the two mismatch statuses are payments this shop
     * refused to apply because the figure was not the one it asked for. All
     * three need a human at the provider's end, and all three are invisible
     * from the orders list.
     */
    public const HELD_ELSEWHERE = ['late_confirmation', 'amount_mismatch', 'currency_mismatch'];

    public function __construct(
        private GatewayRegistry $registry,
        private Redaction $redaction,
    ) {}

    /* ===================================================================== */
    /*  Opening and describing a run                                         */
    /* ===================================================================== */

    /**
     * Find or start the run for this window and this set of providers.
     *
     * The key is derived rather than random, which is what makes pressing
     * "Run" a second time continue rather than duplicate. `$restart` is the
     * deliberate escape hatch — it throws the findings away and walks the
     * window again, which is what the owner wants after he has fixed
     * something and wants a clean answer rather than yesterday's plus today's.
     *
     * @param  array<int, string>  $providers
     */
    public function open(ReconcileWindow $window, array $providers, ?string $startedBy = null, bool $restart = false): int
    {
        $providers = $this->normaliseProviders($providers);
        $key = sha1($window->key() . '|' . implode(',', $providers));

        $existing = DB::table(self::RUNS)->where('run_key', $key)->first();

        if ($existing !== null && ! $restart) {
            $this->rearmUnavailable((int) $existing->id);

            return (int) $existing->id;
        }

        if ($existing !== null) {
            $id = (int) $existing->id;

            // Everything the previous pass concluded, including the
            // acknowledgements on it. A restart means "forget what you thought
            // and look again"; keeping half of it would produce a report that
            // is neither the old answer nor the new one.
            DB::transaction(function () use ($id, $window, $providers) {
                DB::table(self::FINDINGS)->where('run_id', $id)->delete();
                DB::table(self::SIGHTINGS)->where('run_id', $id)->delete();
                DB::table(self::CHECKPOINTS)->where('run_id', $id)->delete();

                DB::table(self::RUNS)->where('id', $id)->update([
                    'window_from' => $window->from->toDateString(),
                    'window_to' => $window->to->toDateString(),
                    'providers' => implode(',', $providers),
                    'status' => 'running',
                    'remote_seen' => 0,
                    'local_checked' => 0,
                    'findings_count' => 0,
                    'started_at' => now(),
                    'finished_at' => null,
                    'updated_at' => now(),
                ]);
            });

            return $id;
        }

        return (int) DB::table(self::RUNS)->insertGetId([
            'run_key' => $key,
            'window_from' => $window->from->toDateString(),
            'window_to' => $window->to->toDateString(),
            'providers' => implode(',', $providers),
            'status' => 'running',
            'started_by' => $startedBy !== null && trim($startedBy) !== '' ? substr(trim($startedBy), 0, 190) : null,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Give a provider that was unreachable last time another chance.
     *
     * WHY PRESSING RUN AGAIN USED TO DO NOTHING
     *
     * A run is keyed on the window and the providers, so pressing Run over the
     * same dates CONTINUES the run rather than starting a second one. That is
     * the resumability this screen is built on, and it is right.
     *
     * But a remote phase that could not read the provider's list is closed by
     * advance(..., finished: true) — "finished" meaning "will not be retried
     * inside this run", not "succeeded" — and the local phases that depend on
     * it are then closed too, skipped on purpose, because a conclusion drawn
     * from a list nobody could read is the false alarm this class exists to
     * avoid. Every phase being finished is also exactly what nextPhase() reads
     * as "there is nothing left to do".
     *
     * So the window a provider was down for became PERMANENTLY unanswerable:
     * the owner fixes the key, presses Run over the same dates, not one HTTP
     * request is made, and he is shown the same two "could not be read"
     * notices as before. Nothing says the second press did nothing. The check
     * he is missing is the one this whole class is for — money the provider
     * holds that this database does not, or the reverse — and it sits in a
     * window he believes he has reconciled. `restart` would have done it, but
     * a correctness property that depends on the owner guessing which button
     * means "actually look this time" is not a property.
     *
     * So: opening a run that carries a source-unavailable finding re-arms
     * exactly the phases that outage skipped, and nothing else. The phases
     * that DID complete keep their cursors and their findings.
     *
     * Re-walking is safe by construction, which is why this is cheap: findings
     * are fingerprinted and inserted with insertOrIgnore, so a page seen twice
     * writes nothing twice. The sightings for the re-armed half ARE dropped
     * first, though — a sighting left over from a partial page the retry no
     * longer sees would answer wasSeen() "yes" for a transaction nobody saw
     * this time, which is the same silence-for-agreement mistake one layer
     * down.
     */
    private function rearmUnavailable(int $runId): void
    {
        $halves = [
            self::PAYMENTS_SOURCE_UNAVAILABLE => [
                'phases' => [self::PHASE_REMOTE_PAYMENTS, self::PHASE_LOCAL_PAYMENTS],
                'sighting_kind' => RemoteTxn::PAYMENT,
            ],
            self::REFUNDS_SOURCE_UNAVAILABLE => [
                'phases' => [self::PHASE_REMOTE_REFUNDS, self::PHASE_LOCAL_REFUNDS],
                'sighting_kind' => RemoteTxn::REFUND,
            ],
        ];

        DB::transaction(function () use ($halves, $runId) {
            $rearmed = false;

            foreach ($halves as $kind => $half) {
                $providers = DB::table(self::FINDINGS)
                    ->where('run_id', $runId)
                    ->where('kind', $kind)
                    ->distinct()
                    ->pluck('provider')
                    ->all();

                foreach ($providers as $provider) {
                    $provider = (string) $provider;

                    DB::table(self::FINDINGS)
                        ->where('run_id', $runId)
                        ->where('kind', $kind)
                        ->where('provider', $provider)
                        ->delete();

                    DB::table(self::SIGHTINGS)
                        ->where('run_id', $runId)
                        ->where('provider', $provider)
                        ->where('kind', $half['sighting_kind'])
                        ->delete();

                    DB::table(self::CHECKPOINTS)
                        ->where('run_id', $runId)
                        ->where('provider', $provider)
                        ->whereIn('phase', $half['phases'])
                        ->update(['cursor' => null, 'finished_at' => null, 'updated_at' => now()]);

                    $rearmed = true;
                }
            }

            if (! $rearmed) {
                return;
            }

            // The run is open again, and its headline count is recomputed from
            // the rows rather than decremented — the notices just deleted were
            // counted into it when they were written.
            DB::table(self::RUNS)->where('id', $runId)->update([
                'status' => 'running',
                'finished_at' => null,
                'findings_count' => DB::table(self::FINDINGS)->where('run_id', $runId)->count(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Which gateways this build can actually ask.
     *
     * Cash on delivery is absent by construction rather than by exclusion: it
     * does not implement ListsTransactions, because there is no provider
     * holding a set of books for cash handed to a courier. See
     * CashOnDeliveryPosition.
     *
     * @return array<int, string>
     */
    public function reconcilable(): array
    {
        return $this->registry->all()
            ->filter(fn ($g) => $g instanceof ListsTransactions)
            ->map(fn ($g) => $g->id())
            ->values()
            ->all();
    }

    /** @param array<int, string> $providers */
    private function normaliseProviders(array $providers): array
    {
        $known = $this->reconcilable();

        $chosen = array_values(array_unique(array_filter(
            array_map(fn ($p) => strtolower(trim((string) $p)), $providers),
            fn ($p) => in_array($p, $known, true),
        )));

        sort($chosen);

        return $chosen !== [] ? $chosen : $known;
    }

    /* ===================================================================== */
    /*  One bounded slice of work                                            */
    /* ===================================================================== */

    /**
     * Advance the run by one slice and report what is left.
     *
     * Exactly one phase per call, deliberately. A step that chased the work
     * across phase boundaries would sometimes make two remote calls and
     * sometimes make two hundred local queries, and the browser would have no
     * way to size the next one. One phase, one predictable shape.
     *
     * @return array<string, mixed>
     */
    public function step(int $runId): array
    {
        $run = DB::table(self::RUNS)->where('id', $runId)->first();

        if ($run === null) {
            return ['done' => true, 'error' => 'That reconciliation run no longer exists.'];
        }

        $window = ReconcileWindow::between((string) $run->window_from, (string) $run->window_to);
        $providers = array_values(array_filter(explode(',', (string) $run->providers)));

        $next = $this->nextPhase($runId, $providers);

        if ($next === null) {
            DB::table(self::RUNS)->where('id', $runId)->update([
                'status' => 'complete',
                'finished_at' => $run->finished_at ?? now(),
                'updated_at' => now(),
            ]);

            return ['done' => true] + $this->status($runId);
        }

        [$provider, $phase] = $next;

        match ($phase) {
            self::PHASE_REMOTE_PAYMENTS => $this->stepRemote($runId, $provider, $window, RemoteTxn::PAYMENT),
            self::PHASE_REMOTE_REFUNDS => $this->stepRemote($runId, $provider, $window, RemoteTxn::REFUND),
            self::PHASE_LOCAL_PAYMENTS => $this->stepLocalPayments($runId, $provider, $window),
            self::PHASE_LOCAL_REFUNDS => $this->stepLocalRefunds($runId, $provider, $window),
            self::PHASE_ORDERS => $this->stepOrders($runId, $window),
            default => null,
        };

        return ['done' => false, 'phase' => $phase, 'provider' => $provider] + $this->status($runId);
    }

    /**
     * The next unfinished (provider, phase), in the only order that works.
     *
     * Remote before local, per provider, because the local phases are answered
     * out of the sightings the remote phases record — asking "did the provider
     * have this one" before the provider's list has been read would answer no
     * for every row. The order phase runs last because it is the only one that
     * needs no provider at all and is therefore the only one still worth doing
     * when every provider is unreachable.
     *
     * @param  array<int, string>  $providers
     * @return array{0: string, 1: string}|null
     */
    private function nextPhase(int $runId, array $providers): ?array
    {
        foreach ($providers as $provider) {
            foreach ([self::PHASE_REMOTE_PAYMENTS, self::PHASE_REMOTE_REFUNDS] as $phase) {
                if (! $this->phaseFinished($runId, $provider, $phase)) {
                    return [$provider, $phase];
                }
            }
        }

        foreach ($providers as $provider) {
            if (! $this->phaseFinished($runId, $provider, self::PHASE_LOCAL_PAYMENTS)) {
                return [$provider, self::PHASE_LOCAL_PAYMENTS];
            }

            if (! $this->phaseFinished($runId, $provider, self::PHASE_LOCAL_REFUNDS)) {
                return [$provider, self::PHASE_LOCAL_REFUNDS];
            }
        }

        return $this->phaseFinished($runId, self::LOCAL, self::PHASE_ORDERS)
            ? null
            : [self::LOCAL, self::PHASE_ORDERS];
    }

    private function phaseFinished(int $runId, string $provider, string $phase): bool
    {
        return DB::table(self::CHECKPOINTS)
            ->where('run_id', $runId)
            ->where('provider', $provider)
            ->where('phase', $phase)
            ->whereNotNull('finished_at')
            ->exists();
    }

    /** The checkpoint row for a phase, created on first sight. */
    private function checkpoint(int $runId, string $provider, string $phase): object
    {
        $row = DB::table(self::CHECKPOINTS)
            ->where('run_id', $runId)->where('provider', $provider)->where('phase', $phase)
            ->first();

        if ($row !== null) {
            return $row;
        }

        DB::table(self::CHECKPOINTS)->insert([
            'run_id' => $runId,
            'provider' => $provider,
            'phase' => $phase,
            'cursor' => null,
            'processed' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table(self::CHECKPOINTS)
            ->where('run_id', $runId)->where('provider', $provider)->where('phase', $phase)
            ->first();
    }

    /**
     * Advance a checkpoint.
     *
     * MUST be called from inside the transaction that wrote the findings this
     * cursor describes. Import\Checkpoint's class comment is the long version
     * of why, and it applies here word for word.
     */
    private function advance(int $checkpointId, ?string $cursor, int $processed, bool $finished): void
    {
        DB::table(self::CHECKPOINTS)->where('id', $checkpointId)->update([
            'cursor' => $cursor,
            'processed' => DB::raw('processed + ' . max(0, $processed)),
            'finished_at' => $finished ? now() : null,
            'updated_at' => now(),
        ]);
    }

    /* ===================================================================== */
    /*  The remote phases — reading the provider's books                     */
    /* ===================================================================== */

    private function stepRemote(int $runId, string $provider, ReconcileWindow $window, string $kind): void
    {
        $gateway = $this->registry->find($provider);

        if (! $gateway instanceof ListsTransactions) {
            // A provider row for a gateway this build cannot list. Finish the
            // phase rather than spin on it, and say so.
            $cp = $this->checkpoint($runId, $provider, $this->remotePhase($kind));

            DB::transaction(function () use ($runId, $provider, $kind, $cp) {
                $this->recordSourceUnavailable($runId, $provider, $kind, 'This build cannot list transactions for that gateway.');
                $this->advance((int) $cp->id, null, 0, true);
            });

            return;
        }

        $phase = $this->remotePhase($kind);
        $cp = $this->checkpoint($runId, $provider, $phase);
        $cursor = $cp->cursor !== null ? (string) $cp->cursor : null;

        for ($page = 0; $page < self::REMOTE_PAGES_PER_STEP; $page++) {
            $result = $kind === RemoteTxn::PAYMENT
                ? $gateway->listRemotePayments($window, $cursor, self::REMOTE_PAGE_SIZE)
                : $gateway->listRemoteRefunds($window, $cursor, self::REMOTE_PAGE_SIZE);

            if (! $result->ok) {
                /*
                 * The phase is finished, in the sense that it will not be
                 * retried inside this run — but it did NOT succeed, and the
                 * difference is the whole point. The finding written here is
                 * what the local phases look for before they are willing to
                 * conclude anything from the provider's silence.
                 */
                $error = $this->redactFor($provider, (string) ($result->error ?? 'unreachable'));

                DB::transaction(function () use ($runId, $provider, $kind, $cp, $cursor, $error, $result) {
                    $this->recordSourceUnavailable($runId, $provider, $kind, $error, $result->httpStatus);
                    $this->advance((int) $cp->id, $cursor, 0, true);
                });

                return;
            }

            $previous = $cursor;
            $cursor = $result->cursor;

            /*
             * A provider that hands back the cursor it was given would page
             * forever, and "forever" on a browser-driven run means a progress
             * bar that never moves and a rate limit hit a thousand times. The
             * page is still processed — it is real data — and then the phase is
             * closed. This has never been observed against these three APIs; it
             * costs one comparison to make impossible.
             */
            $stuck = $previous !== null && $cursor !== null && $cursor === $previous;

            /*
             * One transaction per page: the sightings, the findings and the
             * cursor commit together or not at all. An interrupted step loses
             * at most the page it was in the middle of, and re-fetches it.
             */
            DB::transaction(function () use ($runId, $result, $kind, $cp, $cursor, $stuck) {
                $found = 0;

                foreach ($result->items as $txn) {
                    $this->recordSighting($runId, $txn);

                    $found += $kind === RemoteTxn::PAYMENT
                        ? $this->judgeRemotePayment($runId, $txn)
                        : $this->judgeRemoteRefund($runId, $txn);
                }

                $this->advance((int) $cp->id, $cursor, count($result->items), $stuck || ! $result->hasMore());
                $this->bump($runId, remoteSeen: count($result->items), findings: $found);
            });

            if ($stuck || ! $result->hasMore()) {
                return;
            }
        }
    }

    private function remotePhase(string $kind): string
    {
        return $kind === RemoteTxn::PAYMENT ? self::PHASE_REMOTE_PAYMENTS : self::PHASE_REMOTE_REFUNDS;
    }

    /**
     * Every id this transaction could be known by gets a sighting row, not
     * just its own.
     *
     * Stripe is why. A charge's id is `ch_…`, but `payments.provider_ref`
     * holds the PaymentIntent (`pi_…`) because that is what the webhook
     * carries, and `orders.transaction_id` may still hold the Checkout session
     * (`cs_…`) if the webhook never arrived. The local phase asks "is this
     * reference among the things we saw", and it can only get a true answer if
     * all three were written down.
     */
    private function recordSighting(int $runId, RemoteTxn $txn): void
    {
        $rows = [];

        foreach ($txn->keys() as $key) {
            $rows[] = [
                'run_id' => $runId,
                'provider' => $txn->provider,
                'kind' => $txn->kind,
                'remote_key' => substr($key, 0, self::KEY_LENGTH),
                'amount' => $txn->amountFils,
                'currency' => $txn->currency !== '' ? substr($txn->currency, 0, 3) : null,
                'state' => $txn->state,
            ];
        }

        if ($rows !== []) {
            DB::table(self::SIGHTINGS)->insertOrIgnore($rows);
        }
    }

    /** @return int findings written */
    private function judgeRemotePayment(int $runId, RemoteTxn $txn): int
    {
        $local = $this->localPaymentFor($txn);
        $order = $this->orderFor($txn, $local);

        // ------------------------------------------------ the provider says dead
        if ($txn->state === RemoteTxn::DEAD) {
            // Only interesting if we think otherwise. A declined payment we
            // also treated as declined is not news.
            if ($local !== null && in_array((string) $local->status, self::CLAIMS_MONEY, true)) {
                return $this->record($runId, [
                    'provider' => $txn->provider,
                    'kind' => self::MONEY_NOT_CONFIRMED,
                    'severity' => 'alarm',
                    'order_id' => $order?->id,
                    'order_number' => $order?->order_number,
                    'remote_ref' => $txn->remoteId,
                    'local_ref' => $local->provider_ref,
                    'amount_remote' => $txn->amountFils,
                    'amount_local' => (int) $local->amount,
                    'currency' => $txn->currency,
                    'summary' => sprintf(
                        'This shop has it recorded as paid, but %s reports it as %s — so the money did not move.',
                        $txn->provider,
                        $txn->rawState !== '' ? $txn->rawState : 'not paid',
                    ),
                    'detail' => ['remote_state' => $txn->rawState, 'local_status' => (string) $local->status],
                ]);
            }

            return 0;
        }

        // ---------------------------------------- authorised, nobody captured it
        if ($txn->state === RemoteTxn::AUTHORISED) {
            if ($order !== null && $order->captured_at !== null) {
                return 0;
            }

            return $this->record($runId, [
                'provider' => $txn->provider,
                'kind' => self::UNCAPTURED,
                'severity' => 'warn',
                'order_id' => $order?->id,
                'order_number' => $order?->order_number ?? $txn->reference,
                'remote_ref' => $txn->remoteId,
                'local_ref' => $local?->provider_ref,
                'amount_remote' => $txn->amountFils,
                'amount_local' => $local !== null ? (int) $local->amount : null,
                'currency' => $txn->currency,
                'summary' => sprintf(
                    '%s is holding this authorisation and nobody has captured it. An authorisation that is never '
                    . 'captured is released back to the customer, and the goods will have shipped.',
                    $txn->provider,
                ),
                'detail' => ['remote_state' => $txn->rawState],
            ]);
        }

        // ------------------------------------------------- settled money, then
        if ($local === null) {
            /*
             * Q1, and the dangerous one. The customer has paid and this shop
             * has no record of it, so nothing has been picked, packed or sent.
             */
            return $this->record($runId, [
                'provider' => $txn->provider,
                'kind' => self::MONEY_NOT_RECORDED,
                'severity' => 'alarm',
                'order_id' => $order?->id,
                'order_number' => $order?->order_number ?? $txn->reference,
                'remote_ref' => $txn->remoteId,
                'local_ref' => null,
                'amount_remote' => $txn->amountFils,
                'amount_local' => null,
                'currency' => $txn->currency,
                'summary' => $order === null
                    ? sprintf('%s has taken this money and there is no order here it belongs to.', $txn->provider)
                    : sprintf(
                        '%s has taken this money and no payment is recorded against order %s. '
                        . 'If the order is still unpaid, nothing has shipped.',
                        $txn->provider,
                        (string) $order->order_number,
                    ),
                'detail' => ['remote_state' => $txn->rawState, 'reference' => $txn->reference],
            ]);
        }

        // ------------------------------------------------------ Q3, both sides
        return $this->judgeAmounts($runId, $txn, (int) $local->amount, (string) ($local->currency ?? ''), (string) $local->provider_ref, $order);
    }

    /** @return int findings written */
    private function judgeRemoteRefund(int $runId, RemoteTxn $txn): int
    {
        if (! $txn->isMoney()) {
            return 0;
        }

        $local = DB::table('refunds')
            ->where('provider', $txn->provider)
            ->whereIn('provider_ref', $txn->keys())
            ->whereIn('status', PaymentRefunder::COUNTED)
            ->first();

        $order = $local !== null
            ? $this->orderById((int) $local->order_id)
            : ($this->orderByNumber($txn->reference) ?? $this->orderBehindParent($txn));

        if ($local === null) {
            return $this->record($runId, [
                'provider' => $txn->provider,
                'kind' => self::REFUND_NOT_RECORDED,
                'severity' => 'warn',
                'order_id' => $order?->id,
                'order_number' => $order?->order_number ?? $txn->reference,
                'remote_ref' => $txn->remoteId,
                'local_ref' => null,
                'amount_remote' => $txn->amountFils,
                'amount_local' => null,
                'currency' => $txn->currency,
                'summary' => sprintf(
                    '%s has given this money back to the customer and there is no refund recorded here for it. '
                    . 'Every total on this shop\'s side is overstated by that amount.',
                    $txn->provider,
                ),
                'detail' => ['remote_state' => $txn->rawState, 'reference' => $txn->reference],
            ]);
        }

        // An empty local currency, deliberately: `refunds` has no currency
        // column. Comparing against a value that does not exist would report a
        // currency disagreement on every refund in the window.
        return $this->judgeAmounts($runId, $txn, (int) $local->amount, '', (string) $local->provider_ref, $order);
    }

    /**
     * Q3 — both sides have it, do the figures agree?
     *
     * Currency first, for PaymentConfirmer's reason: a 100 SAR payment against
     * a 100 AED order matches on the number and is not the same money. The
     * local currency is only compared when we have one; `refunds` has no
     * currency column, and comparing against a value that does not exist
     * would report a disagreement on every refund in the window.
     */
    private function judgeAmounts(int $runId, RemoteTxn $txn, int $localFils, string $localCurrency, string $localRef, ?object $order): int
    {
        $currencyDiffers = $localCurrency !== ''
            && strtoupper(trim($localCurrency)) !== strtoupper(trim($txn->currency));

        if (! $currencyDiffers && $localFils === $txn->amountFils) {
            return 0;
        }

        return $this->record($runId, [
            'provider' => $txn->provider,
            'kind' => self::AMOUNT_DISAGREEMENT,
            'severity' => 'alarm',
            'order_id' => $order?->id,
            'order_number' => $order?->order_number ?? $txn->reference,
            'remote_ref' => $txn->remoteId,
            'local_ref' => $localRef,
            'amount_remote' => $txn->amountFils,
            'amount_local' => $localFils,
            'currency' => $txn->currency,
            'summary' => $currencyDiffers
                ? sprintf(
                    'Both sides have this %s, and they disagree about the currency: %s here, %s at %s.',
                    $txn->kind, strtoupper($localCurrency), strtoupper($txn->currency), $txn->provider,
                )
                : sprintf(
                    'Both sides have this %s, and they disagree about the amount: %s here, %s at %s.',
                    $txn->kind,
                    \App\Support\Money::amount($localFils, 2),
                    \App\Support\Money::amount($txn->amountFils, 2),
                    $txn->provider,
                ),
            'detail' => ['txn_kind' => $txn->kind, 'remote_state' => $txn->rawState, 'local_currency' => $localCurrency],
        ]);
    }

    /* ===================================================================== */
    /*  The local phases — our books, against what we saw                    */
    /* ===================================================================== */

    /**
     * Q2 — a `payments` row the provider never showed us.
     *
     * SKIPPED ENTIRELY when the provider's payment list could not be read. See
     * the class comment: this conclusion is drawn from absence, and a rotated
     * key is indistinguishable from an empty list.
     */
    private function stepLocalPayments(int $runId, string $provider, ReconcileWindow $window): void
    {
        $cp = $this->checkpoint($runId, $provider, self::PHASE_LOCAL_PAYMENTS);

        if ($this->sourceWasUnavailable($runId, $provider, self::PAYMENTS_SOURCE_UNAVAILABLE)) {
            DB::transaction(fn () => $this->advance((int) $cp->id, null, 0, true));

            return;
        }

        /*
         * `payments.id` is a UUID, not an auto-increment, so the cursor is the
         * last id as a STRING and the ordering is lexicographic. That is fine —
         * it need only be total and stable, not chronological — but it is the
         * reason nothing here casts a cursor to int. An (int) on a UUID is 0,
         * and a cursor that is always 0 re-presents the first batch forever.
         */
        $after = (string) ($cp->cursor ?? '');

        /*
         * THE STRICT WINDOW HERE, NOT THE GRACE ONE, and the difference is a
         * false alarm at every midnight.
         *
         * The grace exists so that a transaction straddling a boundary still
         * MATCHES — and matching does not use dates at all (localPaymentFor()
         * looks up by provider reference). This phase concludes from ABSENCE,
         * and it can only honestly do that for rows the provider's own query
         * actually covered. The provider was asked for the strict window; a
         * local payment recorded twelve hours past the end of it was never in
         * scope for that question, so "the provider did not list it" says
         * nothing about it.
         *
         * Widening the remote query instead would make the run report
         * transactions outside the dates the owner typed. Narrowing here costs
         * nothing: a payment in the margin is simply checked by the next day's
         * run, which is the window it belongs to.
         */
        $rows = DB::table('payments')
            ->where('provider', $provider)
            ->whereIn('status', self::CLAIMS_MONEY)
            ->whereBetween('created_at', [$window->from, $window->to])
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::LOCAL_BATCH)
            ->get();

        DB::transaction(function () use ($runId, $provider, $rows, $cp) {
            $found = 0;
            $last = (string) ($cp->cursor ?? '');

            foreach ($rows as $row) {
                $last = (string) $row->id;
                $ref = trim((string) ($row->provider_ref ?? ''));

                if ($ref === '' || $this->wasSeen($runId, $provider, RemoteTxn::PAYMENT, $ref)) {
                    continue;
                }

                $order = $row->order_id !== null ? $this->orderById((int) $row->order_id) : null;

                $found += $this->record($runId, [
                    'provider' => $provider,
                    'kind' => self::MONEY_NOT_CONFIRMED,
                    'severity' => 'alarm',
                    'order_id' => $order?->id,
                    'order_number' => $order?->order_number,
                    'remote_ref' => null,
                    'local_ref' => $ref,
                    'amount_remote' => null,
                    'amount_local' => (int) $row->amount,
                    'currency' => (string) ($row->currency ?? ''),
                    'summary' => sprintf(
                        'This shop has it recorded as paid, and %s did not list it for this period at all.',
                        $provider,
                    ),
                    'detail' => ['local_status' => (string) $row->status],
                ]);
            }

            $this->advance((int) $cp->id, $last, $rows->count(), $rows->count() < self::LOCAL_BATCH);
            $this->bump($runId, localChecked: $rows->count(), findings: $found);
        });
    }

    /** Q4, the other half — a refund we recorded that the provider never showed us. */
    private function stepLocalRefunds(int $runId, string $provider, ReconcileWindow $window): void
    {
        $cp = $this->checkpoint($runId, $provider, self::PHASE_LOCAL_REFUNDS);

        if ($this->sourceWasUnavailable($runId, $provider, self::REFUNDS_SOURCE_UNAVAILABLE)) {
            DB::transaction(fn () => $this->advance((int) $cp->id, null, 0, true));

            return;
        }

        $after = (int) ($cp->cursor ?? 0);

        // The strict window, for the reason spelled out in stepLocalPayments:
        // this phase concludes from absence, and absence only means anything
        // inside the period the provider was actually asked about.
        $rows = DB::table('refunds')
            ->where('provider', $provider)
            ->whereIn('status', PaymentRefunder::COUNTED)
            ->whereBetween('created_at', [$window->from, $window->to])
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::LOCAL_BATCH)
            ->get();

        DB::transaction(function () use ($runId, $provider, $rows, $cp) {
            $found = 0;
            $last = (int) ($cp->cursor ?? 0);

            foreach ($rows as $row) {
                $last = (int) $row->id;
                $ref = trim((string) ($row->provider_ref ?? ''));

                /*
                 * A refund with no provider reference is one somebody logged by
                 * hand — the old admin endpoint did exactly that, and every row
                 * predating the capture/refund migration has none. There is
                 * nothing at the provider for it to match, and reporting it
                 * would be reporting the shop's own bookkeeping as a
                 * discrepancy.
                 */
                if ($ref === '' || $this->wasSeen($runId, $provider, RemoteTxn::REFUND, $ref)) {
                    continue;
                }

                $order = $this->orderById((int) $row->order_id);

                $found += $this->record($runId, [
                    'provider' => $provider,
                    'kind' => self::REFUND_NOT_CONFIRMED,
                    'severity' => 'warn',
                    'order_id' => $order?->id,
                    'order_number' => $order?->order_number,
                    'remote_ref' => null,
                    'local_ref' => $ref,
                    'amount_remote' => null,
                    'amount_local' => (int) $row->amount,
                    'currency' => null,
                    'summary' => sprintf(
                        'This shop has recorded a refund that %s did not list for this period. '
                        . 'The customer may not have been paid back.',
                        $provider,
                    ),
                    'detail' => ['local_status' => (string) $row->status],
                ]);
            }

            $this->advance((int) $cp->id, (string) $last, $rows->count(), $rows->count() < self::LOCAL_BATCH);
            $this->bump($runId, localChecked: $rows->count(), findings: $found);
        });
    }

    /**
     * Q5 and its reverse — the two halves of our own books disagreeing with
     * each other.
     *
     * No provider is involved, which is why this phase is worth running even
     * when every gateway is unreachable, and why it runs last: it is the one
     * answer this screen can always give.
     *
     * Cash on delivery cannot appear here in either direction and it is not
     * filtered out, it simply cannot arise: CashOnDelivery never sets
     * `paid_at` (its own class comment says why), and the `capture` row
     * PaymentLedger writes for a collected COD order is not one of
     * CLAIMS_MONEY.
     */
    private function stepOrders(int $runId, ReconcileWindow $window): void
    {
        $cp = $this->checkpoint($runId, self::LOCAL, self::PHASE_ORDERS);

        /*
         * Two sub-passes in one phase, driven off one cursor, which is why the
         * cursor carries its own pass name: `orders:1234` then `payments:<uuid>`.
         * The two tables are keyed differently — `orders.id` is an
         * auto-increment and `payments.id` is a UUID — so a bare number could
         * not have addressed both, and a numeric trick to tell the passes apart
         * would have broken the moment it met the UUID.
         */
        [$pass, $after] = $this->splitCursor($cp->cursor !== null ? (string) $cp->cursor : '');

        if ($pass === 'orders') {
            $this->ordersPaidWithoutPayment($runId, $window, $cp, (int) $after);

            return;
        }

        $this->paymentsWithoutPaidOrder($runId, $window, $cp, $after);
    }

    /** @return array{0: string, 1: string} */
    private function splitCursor(string $cursor): array
    {
        if (! str_contains($cursor, ':')) {
            return ['orders', '0'];
        }

        [$pass, $after] = explode(':', $cursor, 2);

        return [$pass === 'payments' ? 'payments' : 'orders', $after];
    }

    private function ordersPaidWithoutPayment(int $runId, ReconcileWindow $window, object $cp, int $after): void
    {
        /*
         * TRASHED ORDERS ARE INCLUDED, and that is deliberate.
         *
         * This is a query builder rather than the Order model, so the soft
         * delete scope does not apply — which for once is the behaviour we
         * want. An order in the trash that is marked paid and has no payment
         * behind it is money nobody can account for, and putting it in the
         * trash is how it stopped being visible from the orders list in the
         * first place. Excluding it would hide precisely the rows most likely
         * to be wrong.
         */
        $rows = DB::table('orders')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$window->localFrom(), $window->localTo()])
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::LOCAL_BATCH)
            ->get(['id', 'order_number', 'total', 'currency', 'payment_method', 'status', 'paid_at']);

        DB::transaction(function () use ($runId, $rows, $cp) {
            $found = 0;
            $last = 0;

            foreach ($rows as $order) {
                $last = (int) $order->id;

                $has = DB::table('payments')
                    ->where('order_id', $order->id)
                    ->whereIn('status', array_merge(self::CLAIMS_MONEY, self::HELD_ELSEWHERE))
                    ->exists();

                if ($has) {
                    continue;
                }

                $found += $this->record($runId, [
                    'provider' => (string) ($order->payment_method ?: self::LOCAL),
                    'kind' => self::ORDER_PAID_NO_PAYMENT,
                    'severity' => 'alarm',
                    'order_id' => (int) $order->id,
                    'order_number' => (string) $order->order_number,
                    'remote_ref' => null,
                    'local_ref' => null,
                    'amount_remote' => null,
                    'amount_local' => (int) $order->total,
                    'currency' => (string) ($order->currency ?? ''),
                    'summary' => 'This order is marked paid and there is no payment record behind it at all, '
                        . 'so there is nothing to check against any provider.',
                    'detail' => ['order_status' => (string) $order->status, 'payment_method' => (string) $order->payment_method],
                ]);
            }

            $complete = $rows->count() < self::LOCAL_BATCH;

            // Finished the first pass: hand the cursor to the second one.
            $this->advance(
                (int) $cp->id,
                $complete ? 'payments:' : 'orders:' . $last,
                $rows->count(),
                false,
            );
            $this->bump($runId, localChecked: $rows->count(), findings: $found);
        });
    }

    /**
     * The grace window IS right here, unlike in the two phases above.
     *
     * Nothing in this pass asks whether a provider saw something. It compares
     * two of our own tables against each other, so widening the net only ever
     * catches more true disagreements — and a payment written minutes after
     * midnight against an order paid minutes before it is exactly the pair
     * worth catching.
     */
    private function paymentsWithoutPaidOrder(int $runId, ReconcileWindow $window, object $cp, string $after): void
    {
        $rows = DB::table('payments')
            ->whereIn('status', array_merge(self::CLAIMS_MONEY, self::HELD_ELSEWHERE))
            ->whereBetween('created_at', [$window->localFrom(), $window->localTo()])
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::LOCAL_BATCH)
            ->get();

        DB::transaction(function () use ($runId, $rows, $cp, $after) {
            $found = 0;
            $last = $after;

            foreach ($rows as $payment) {
                $last = (string) $payment->id;

                $order = $payment->order_id !== null ? $this->orderById((int) $payment->order_id) : null;

                if ($order !== null && $order->paid_at !== null && in_array((string) $payment->status, self::CLAIMS_MONEY, true)) {
                    continue;
                }

                $status = (string) $payment->status;

                $found += $this->record($runId, [
                    'provider' => (string) ($payment->provider ?: self::LOCAL),
                    'kind' => self::PAYMENT_NO_PAID_ORDER,
                    'severity' => 'alarm',
                    'order_id' => $order?->id,
                    'order_number' => $order?->order_number,
                    'remote_ref' => (string) ($payment->provider_ref ?? ''),
                    'local_ref' => (string) ($payment->provider_ref ?? ''),
                    'amount_remote' => null,
                    'amount_local' => (int) $payment->amount,
                    'currency' => (string) ($payment->currency ?? ''),
                    'summary' => match ($status) {
                        'late_confirmation' => 'The provider took this money for an order that had already been '
                            . 'cancelled or refunded here. It has to be given back at the provider\'s end; nothing in '
                            . 'this shop will do it.',
                        'amount_mismatch', 'currency_mismatch' => 'A payment arrived for an amount this shop did not '
                            . 'ask for, so it was refused and the order was not marked paid. The money is still at the '
                            . 'provider.',
                        default => 'There is a payment recorded and the order it belongs to is not marked paid.',
                    },
                    'detail' => [
                        'local_status' => $status,
                        'order_status' => $order !== null ? (string) $order->status : null,
                    ],
                ]);
            }

            $complete = $rows->count() < self::LOCAL_BATCH;

            $this->advance((int) $cp->id, 'payments:' . $last, $rows->count(), $complete);
            $this->bump($runId, localChecked: $rows->count(), findings: $found);
        });
    }

    /* ===================================================================== */
    /*  Lookups                                                              */
    /* ===================================================================== */

    /**
     * Our own row for a remote transaction, preferring the one that claims
     * money.
     *
     * Two queries rather than one ordered by a CASE expression. A remote id can
     * legitimately have more than one `payments` row against it — a
     * `capture_failed` written by PaymentLedger and then a `paid` written by
     * PaymentConfirmer — and picking whichever the database happened to return
     * first would report a settled payment as unrecorded about half the time.
     * Two plain queries say which one we want in a way that reads the same on
     * SQLite and MySQL; an ORDER BY CASE would have been a third dialect to be
     * right in.
     */
    private function localPaymentFor(RemoteTxn $txn): ?object
    {
        $base = fn () => DB::table('payments')
            ->where('provider', $txn->provider)
            ->whereIn('provider_ref', $txn->keys());

        return $base()->whereIn('status', self::CLAIMS_MONEY)->first()
            ?? $base()->first();
    }

    private function orderFor(RemoteTxn $txn, ?object $payment): ?object
    {
        if ($payment !== null && $payment->order_id !== null) {
            $order = $this->orderById((int) $payment->order_id);

            if ($order !== null) {
                return $order;
            }
        }

        $byNumber = $this->orderByNumber($txn->reference);

        if ($byNumber !== null) {
            return $byNumber;
        }

        // Last resort: the provider's own id, which start() wrote to the order
        // before any webhook existed. This is what finds the order behind a
        // payment whose webhook never arrived — which is exactly the case Q1
        // is about, so it is the lookup that matters most here.
        return DB::table('orders')
            ->whereIn('transaction_id', $txn->keys())
            ->first(['id', 'order_number', 'total', 'currency', 'status', 'paid_at', 'captured_at']);
    }

    /**
     * The order behind the PAYMENT a refund reverses.
     *
     * A Stripe refund carries no order reference at all, so this is the only
     * way a "the provider refunded money we have not recorded" finding can name
     * an order rather than reading "order —" and leaving the owner with nothing
     * to open. It goes through `payments`, which is where the provider's
     * payment id actually lives on our side.
     */
    private function orderBehindParent(RemoteTxn $txn): ?object
    {
        $parents = $txn->parents();

        if ($parents === []) {
            return null;
        }

        $payment = DB::table('payments')
            ->where('provider', $txn->provider)
            ->whereIn('provider_ref', $parents)
            ->whereNotNull('order_id')
            ->first();

        return $payment !== null ? $this->orderById((int) $payment->order_id) : null;
    }

    private function orderById(int $id): ?object
    {
        return DB::table('orders')->where('id', $id)
            ->first(['id', 'order_number', 'total', 'currency', 'status', 'paid_at', 'captured_at']);
    }

    private function orderByNumber(?string $number): ?object
    {
        if ($number === null || trim($number) === '') {
            return null;
        }

        return DB::table('orders')->where('order_number', trim($number))
            ->first(['id', 'order_number', 'total', 'currency', 'status', 'paid_at', 'captured_at']);
    }

    private function wasSeen(int $runId, string $provider, string $kind, string $key): bool
    {
        return DB::table(self::SIGHTINGS)
            ->where('run_id', $runId)
            ->where('provider', $provider)
            ->where('kind', $kind)
            ->where('remote_key', substr($key, 0, self::KEY_LENGTH))
            ->exists();
    }

    private function sourceWasUnavailable(int $runId, string $provider, string $kind): bool
    {
        return DB::table(self::FINDINGS)
            ->where('run_id', $runId)
            ->where('provider', $provider)
            ->where('kind', $kind)
            ->exists();
    }

    /* ===================================================================== */
    /*  Writing findings                                                     */
    /* ===================================================================== */

    /**
     * Write one finding, once.
     *
     * The fingerprint is derived from what the finding IS, not from when it was
     * made, and the unique index on (run_id, fingerprint) turns that into the
     * write-side half of idempotency: a page re-fetched after an interrupted
     * step produces the same fingerprints and inserts nothing. insertOrIgnore
     * rather than a read-then-write, because two browser tabs stepping the same
     * run would race a check and not a constraint.
     *
     * @param  array<string, mixed>  $f
     * @return int 1 if this finding was new
     */
    private function record(int $runId, array $f): int
    {
        $provider = (string) $f['provider'];

        $fingerprint = sha1(implode('|', [
            $provider,
            (string) $f['kind'],
            (string) ($f['remote_ref'] ?? ''),
            (string) ($f['local_ref'] ?? ''),
            (string) ($f['order_id'] ?? ''),
            (string) ($f['amount_remote'] ?? ''),
            (string) ($f['amount_local'] ?? ''),
        ]));

        $detail = is_array($f['detail'] ?? null) ? $f['detail'] : [];

        return DB::table(self::FINDINGS)->insertOrIgnore([[
            'run_id' => $runId,
            'provider' => substr($provider, 0, 40),
            'kind' => (string) $f['kind'],
            'severity' => (string) ($f['severity'] ?? 'warn'),
            'order_id' => $f['order_id'] ?? null,
            'order_number' => $f['order_number'] ?? null,
            'remote_ref' => ($f['remote_ref'] ?? null) !== null ? substr((string) $f['remote_ref'], 0, self::KEY_LENGTH) : null,
            'local_ref' => ($f['local_ref'] ?? null) !== null ? substr((string) $f['local_ref'], 0, self::KEY_LENGTH) : null,
            'amount_remote' => $f['amount_remote'] ?? null,
            'amount_local' => $f['amount_local'] ?? null,
            'currency' => ($f['currency'] ?? '') !== '' ? substr(strtoupper((string) $f['currency']), 0, 8) : null,
            'summary' => $this->redactFor($provider, (string) $f['summary']),
            // Redacted even though nothing here is built from a provider's
            // response body. The rule is "no credential reaches a finding",
            // and a rule that is applied only where a leak is currently
            // believed impossible is a rule that stops being true the first
            // time somebody adds a field.
            'detail' => json_encode($this->redactArrayFor($provider, $detail)),
            'fingerprint' => $fingerprint,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
    }

    private function recordSourceUnavailable(int $runId, string $provider, string $kind, string $error, ?int $httpStatus = null): void
    {
        $isPayments = $kind === RemoteTxn::PAYMENT;

        $this->record($runId, [
            'provider' => $provider,
            'kind' => $isPayments ? self::PAYMENTS_SOURCE_UNAVAILABLE : self::REFUNDS_SOURCE_UNAVAILABLE,
            // A warning, not an alarm: no money is known to be wrong. What IS
            // wrong is that this run cannot answer half the questions, and the
            // screen has to say so rather than show a reassuring empty list.
            'severity' => 'warn',
            'order_id' => null,
            'order_number' => null,
            'remote_ref' => null,
            'local_ref' => null,
            'amount_remote' => null,
            'amount_local' => null,
            'currency' => null,
            'summary' => sprintf(
                '%s\'s %s could not be read (%s). Nothing was concluded from that silence: the checks that need '
                . 'the whole of %s\'s side were skipped for this run rather than answered from a partial list.',
                $provider,
                $isPayments ? 'list of payments' : 'list of refunds',
                $error,
                $provider,
            ),
            'detail' => ['error' => $error, 'http_status' => $httpStatus],
        ]);
    }

    private function redactFor(string $provider, string $text): string
    {
        return $this->redaction->scrub($provider, $this->configKeys($provider), $text);
    }

    private function redactArrayFor(string $provider, array $data): array
    {
        return $this->redaction->scrubArray($provider, $this->configKeys($provider), $data);
    }

    /** @return array<int, string> */
    private function configKeys(string $provider): array
    {
        $gateway = $this->registry->find($provider);

        return $gateway === null ? [] : array_keys($gateway->configSchema());
    }

    private function bump(int $runId, int $remoteSeen = 0, int $localChecked = 0, int $findings = 0): void
    {
        $update = ['updated_at' => now()];

        if ($remoteSeen > 0) {
            $update['remote_seen'] = DB::raw('remote_seen + ' . $remoteSeen);
        }

        if ($localChecked > 0) {
            $update['local_checked'] = DB::raw('local_checked + ' . $localChecked);
        }

        if ($findings > 0) {
            $update['findings_count'] = DB::raw('findings_count + ' . $findings);
        }

        DB::table(self::RUNS)->where('id', $runId)->update($update);
    }

    /* ===================================================================== */
    /*  Reporting                                                            */
    /* ===================================================================== */

    /** @return array<string, mixed> */
    public function status(int $runId): array
    {
        $run = DB::table(self::RUNS)->where('id', $runId)->first();

        if ($run === null) {
            return ['run' => null];
        }

        $providers = array_values(array_filter(explode(',', (string) $run->providers)));

        $phases = [];

        foreach ($providers as $provider) {
            foreach ([self::PHASE_REMOTE_PAYMENTS, self::PHASE_REMOTE_REFUNDS, self::PHASE_LOCAL_PAYMENTS, self::PHASE_LOCAL_REFUNDS] as $phase) {
                $phases[] = $this->phaseState($runId, $provider, $phase);
            }
        }

        $phases[] = $this->phaseState($runId, self::LOCAL, self::PHASE_ORDERS);

        $done = count(array_filter($phases, fn ($p) => $p['finished']));

        return [
            'run' => [
                'id' => (int) $run->id,
                'window_from' => (string) $run->window_from,
                'window_to' => (string) $run->window_to,
                'providers' => $providers,
                'status' => (string) $run->status,
                'remote_seen' => (int) $run->remote_seen,
                'local_checked' => (int) $run->local_checked,
                'findings' => (int) $run->findings_count,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
            ],
            'phases' => $phases,
            'phases_done' => $done,
            'phases_total' => count($phases),
            'complete' => $done === count($phases),
            'counts' => $this->counts($runId),
        ];
    }

    /** @return array<string, mixed> */
    private function phaseState(int $runId, string $provider, string $phase): array
    {
        $row = DB::table(self::CHECKPOINTS)
            ->where('run_id', $runId)->where('provider', $provider)->where('phase', $phase)
            ->first();

        return [
            'provider' => $provider,
            'phase' => $phase,
            'processed' => $row !== null ? (int) $row->processed : 0,
            'finished' => $row !== null && $row->finished_at !== null,
        ];
    }

    /** Findings by kind, for the summary line. @return array<string, int> */
    public function counts(int $runId): array
    {
        return DB::table(self::FINDINGS)
            ->where('run_id', $runId)
            ->whereNull('acknowledged_at')
            ->select('kind', DB::raw('count(*) as n'))
            ->groupBy('kind')
            ->pluck('n', 'kind')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}

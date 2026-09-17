<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\Reconciliation\CashOnDeliveryPosition;
use App\Services\Payments\Reconciliation\ListsTransactions;
use App\Services\Payments\Reconciliation\ReconcileWindow;
use App\Services\Payments\Reconciliation\Reconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Store → Ecommerce → Payments → Reconcile.
 *
 * WHAT THIS IS THE HANDLE FOR. `php artisan payments:reconcile` exists and is
 * the thing CI runs, and it is useless to the one person who needs it: the
 * owner of this store has no shell. The host is shared hosting and every change
 * reaches it as a zip applied through the admin panel. The same argument
 * ImportApiController's class comment makes, for the same host, about a
 * different long job.
 *
 * WHY step() IS ITS OWN ENDPOINT. Shared PHP-FPM kills long requests and there
 * is no queue worker on this host, so a reconciliation cannot be one request
 * and cannot be a background job. It is many short requests driven from the
 * browser, each continuing from the checkpoint the last one committed. Two
 * things this controller adds, both lifted from the importer because they were
 * right there:
 *
 *   ignore_user_abort(true) — a tab closed mid-step still finishes the page it
 *   was in and commits it, rather than abandoning remote rows that were paid
 *   for with a rate-limited API call.
 *
 *   a modest set_time_limit — enough for one slice, nowhere near enough to be
 *   the thing that hangs a worker.
 *
 * WHAT IT WILL NOT DO. There is no repair endpoint here, and its absence is the
 * design. Nothing in this controller writes to `orders`, `payments`, `refunds`
 * or `payment_events`. The nearest thing to an action is `acknowledge`, which
 * records that a human has read a finding and moves not one fil. See
 * Reconciler's class comment for the argument; the short version is that
 * marking an order paid from a remote list is the same shape as the defect
 * 2.60.199 closed, and the correct repair is the capture or refund button that
 * already exists on the order screen, pressed one order at a time, which
 * already records what it did.
 *
 * GUARD. Every route here is mounted inside the existing `admin-api` group in
 * routes/web.php, which carries `auth:admin` and NoStoreAdminApi, under the
 * `payments.manage` capability. That is not caution: the findings name order
 * numbers, provider references and amounts — a map of this shop's money and
 * where it is weak — and `/api/*` in this application is unauthenticated by
 * design. See routes/payments-reconcile.php.
 */
class PaymentReconciliationController extends Controller
{
    /** Per step. The browser adapts within this from what it measures. */
    private const STEP_SECONDS = 110;

    /** Findings returned in one page of the report. */
    private const PAGE = 50;

    public function __construct(
        private Reconciler $reconciler,
        private GatewayRegistry $registry,
        private CashOnDeliveryPosition $cod,
    ) {}

    /**
     * Everything the screen draws itself from before a run exists.
     *
     * One call, so the screen is never half-fresh: which gateways can be asked,
     * which cannot and why, and the most recent run if there is one.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'gateways' => $this->gateways(),
            'max_days' => ReconcileWindow::MAX_DAYS,
            'recent' => $this->recentRuns(),
        ]);
    }

    /**
     * Open (or resume) a run.
     *
     * Resuming is the default and pressing the button twice is safe: the run
     * key is derived from the window and the chosen gateways, so the second
     * press continues the first rather than starting a second run beside it.
     * `restart` is the deliberate escape hatch for "I have fixed things, look
     * again from the top".
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:32'],
            'to' => ['required', 'string', 'max:32'],
            'providers' => ['sometimes', 'array', 'max:8'],
            'providers.*' => ['string', 'max:40'],
            'restart' => ['sometimes', 'boolean'],
        ]);

        try {
            $window = ReconcileWindow::between((string) $data['from'], (string) $data['to']);
        } catch (\InvalidArgumentException $e) {
            // The message is written for the owner, not for a developer, so it
            // is handed straight through rather than replaced with "invalid".
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $runId = $this->reconciler->open(
            $window,
            $data['providers'] ?? [],
            auth('admin')->user()?->name,
            (bool) ($data['restart'] ?? false),
        );

        return response()->json(['run_id' => $runId] + $this->reconciler->status($runId));
    }

    /**
     * One bounded slice.
     *
     * The browser calls this until `done` comes back true. Each call does one
     * phase's worth of work — at most two remote pages, or at most one batch of
     * local rows — and commits its checkpoint with the findings it produced.
     */
    public function step(Request $request): JsonResponse
    {
        $data = $request->validate(['run_id' => ['required', 'integer', 'min:1']]);

        @ignore_user_abort(true);
        @set_time_limit(self::STEP_SECONDS);

        return response()->json($this->reconciler->step((int) $data['run_id']));
    }

    /** Progress, without doing any work. Safe to poll. */
    public function status(int $run): JsonResponse
    {
        $state = $this->reconciler->status($run);

        if (($state['run'] ?? null) === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json($state);
    }

    /**
     * The report.
     *
     * Acknowledged findings are excluded unless asked for, so a second run over
     * the same window shows what is new rather than what has already been dealt
     * with.
     */
    public function findings(Request $request, int $run): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['sometimes', 'string', 'max:48'],
            'provider' => ['sometimes', 'string', 'max:40'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'include_acknowledged' => ['sometimes', 'boolean'],
        ]);

        if (! DB::table(Reconciler::RUNS)->where('id', $run)->exists()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $query = DB::table(Reconciler::FINDINGS)->where('run_id', $run);

        if (isset($data['kind'])) {
            $query->where('kind', $data['kind']);
        }

        if (isset($data['provider'])) {
            $query->where('provider', $data['provider']);
        }

        if (! ($data['include_acknowledged'] ?? false)) {
            $query->whereNull('acknowledged_at');
        }

        $page = (int) ($data['page'] ?? 1);
        $total = (int) $query->count();

        $rows = $query
            // Alarms first, then by id so the order is total and stable —
            // paging a list whose order is not total silently drops rows
            // between pages.
            ->orderByRaw($this->severityOrder())
            ->orderBy('id')
            ->forPage($page, self::PAGE)
            ->get();

        return response()->json([
            'run' => $this->reconciler->status($run)['run'] ?? null,
            'total' => $total,
            'page' => $page,
            'per_page' => self::PAGE,
            'counts' => $this->reconciler->counts($run),
            'findings' => $rows->map(fn ($f) => [
                'id' => (int) $f->id,
                'provider' => (string) $f->provider,
                'kind' => (string) $f->kind,
                'severity' => (string) $f->severity,
                'order_id' => $f->order_id !== null ? (int) $f->order_id : null,
                'order_number' => $f->order_number,
                'remote_ref' => $f->remote_ref,
                'local_ref' => $f->local_ref,
                // Fils, as integers, the way every other money value crosses
                // this boundary. Formatting is the screen's job.
                'amount_remote' => $f->amount_remote !== null ? (int) $f->amount_remote : null,
                'amount_local' => $f->amount_local !== null ? (int) $f->amount_local : null,
                'currency' => $f->currency,
                'summary' => (string) $f->summary,
                'detail' => json_decode((string) ($f->detail ?? '[]'), true) ?: [],
                'acknowledged_at' => $f->acknowledged_at,
                'acknowledged_by' => $f->acknowledged_by,
                'acknowledged_note' => $f->acknowledged_note,
            ])->all(),
        ]);
    }

    /**
     * Record that a human has read a finding.
     *
     * THIS IS NOT A REPAIR. It moves no money, marks no order paid, writes to
     * no table but `reconciliation_findings`, and it is named for what it does.
     * The reason it exists at all is that without it the second run over a
     * window re-reports everything the owner has already looked at and decided
     * about, and a report that shouts about settled business is a report that
     * stops being read.
     *
     * Who and when are recorded because an acknowledgement hides a money
     * discrepancy from the default view, and anything that hides a money
     * discrepancy has to say who hid it.
     */
    public function acknowledge(Request $request, int $run, int $finding): JsonResponse
    {
        $data = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']]);

        $row = DB::table(Reconciler::FINDINGS)->where('run_id', $run)->where('id', $finding)->first();

        if ($row === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        DB::table(Reconciler::FINDINGS)->where('id', $finding)->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => substr((string) (auth('admin')->user()?->name ?? 'admin'), 0, 190),
            'acknowledged_note' => isset($data['note']) ? substr(trim((string) $data['note']), 0, 500) : null,
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'counts' => $this->reconciler->counts($run)]);
    }

    /**
     * Cash on delivery, which is a different report and says so.
     *
     * Its own endpoint rather than a section of the findings list, because it
     * is not a finding: there is no second set of books for it to disagree
     * with. See CashOnDeliveryPosition.
     */
    public function cashOnDelivery(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:32'],
            'to' => ['required', 'string', 'max:32'],
        ]);

        try {
            $window = ReconcileWindow::between((string) $data['from'], (string) $data['to']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->cod->forWindow($window));
    }

    /* ------------------------------------------------------------- helpers */

    /**
     * A total, stable ordering that reads the same on SQLite and MySQL.
     *
     * Written out rather than relying on the alphabet: `alarm` happens to sort
     * before `note` before `warn`, which is the right order by luck and would
     * stop being right the first time a severity was renamed.
     */
    private function severityOrder(): string
    {
        return "CASE severity WHEN 'alarm' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END";
    }

    /** @return array<int, array<string, mixed>> */
    private function gateways(): array
    {
        return $this->registry->all()->map(function ($gateway) {
            $lists = $gateway instanceof ListsTransactions;

            return [
                'id' => $gateway->id(),
                'title' => $gateway->title(),
                'configured' => $gateway->configured(),
                'reconcilable' => $lists,
                'source' => $lists ? $gateway->remoteSourceLabel() : null,
                'why_not' => $lists
                    ? null
                    : 'There is no provider holding a second set of books for this method, so there is nothing to '
                        . 'check it against. The cash-on-delivery figures are a separate report.',
            ];
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function recentRuns(): array
    {
        return DB::table(Reconciler::RUNS)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'window_from' => (string) $r->window_from,
                'window_to' => (string) $r->window_to,
                'providers' => array_values(array_filter(explode(',', (string) $r->providers))),
                'status' => (string) $r->status,
                'findings' => (int) $r->findings_count,
                'started_at' => $r->started_at,
                'finished_at' => $r->finished_at,
            ])
            ->all();
    }
}

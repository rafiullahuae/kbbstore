<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\Quiqup\QuiqupClient;
use App\Services\Quiqup\QuiqupSettings;
use App\Services\Quiqup\QuiqupStates;
use App\Services\SecurityModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Store -> Quiqup: Connection, Pickup & defaults, Statuses, Activity
 * (QUIQUP-PLAN §3.4). Capabilities in AdminCapabilities::RULES:
 * GET settings / activity = quiqup.view, every write and the test =
 * quiqup.settings. Secrets leave this controller as {filled, last4} only.
 */
final class QuiqupSettingsController extends Controller
{
    public function __construct(private QuiqupSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['ok' => true] + $this->settings->publicView() + [
            'shipping_methods' => $this->recentShippingMethods(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $in = $request->only(['secrets_env', 'api_key', 'webhook_secret', 'regenerate_url', 'options']);

        try {
            $changed = $this->settings->save($in);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        if ($changed !== []) {
            try {
                app(SecurityModule::class)->record('quiqup.settings', 'Store → Quiqup: ' . implode(', ', $changed), [
                    'subject' => 'quiqup', 'severity' => 'notice',
                ]);
            } catch (\Throwable) {
                // The audit line is a courtesy; the save has happened.
            }
        }

        return response()->json(['ok' => true, 'changed' => $changed] + $this->settings->publicView() + [
            'shipping_methods' => $this->recentShippingMethods(),
        ]);
    }

    /** "Test connection": GET /orders for today, one page of one (§3.4). */
    public function test(Request $request, QuiqupClient $client): JsonResponse
    {
        $env = in_array($request->input('env'), QuiqupSettings::ENVS, true) ? (string) $request->input('env') : $this->settings->env();

        if (! $this->settings->configured($env)) {
            return response()->json(['ok' => false, 'message' => 'Add your ' . ($env === 'live' ? 'live' : 'staging') . ' API key first.']);
        }

        $t = microtime(true);
        $r = $client->ping($env);
        $secs = round(microtime(true) - $t, 1);

        if ($r->ok()) {
            // One of the Go-Live checks: this key answered a real call.
            $row = $this->settings->row($env);
            $row->verified_at = now();
            $row->save();
        }

        return response()->json($r->ok()
            ? ['ok' => true, 'message' => 'OK · ' . ($env === 'live' ? 'live' : 'staging') . ' · ' . $secs . ' s']
            : ['ok' => false, 'message' => $r->message()]);
    }

    /**
     * Off -> Internal testing -> Live, and back. Live needs `confirm` and a
     * passing checklist (QuiqupSettings::checklist()); every change is an
     * audit line: who, when, from -> to.
     */
    public function mode(Request $request): JsonResponse
    {
        $by = (string) (auth('admin')->user()?->name ?? 'admin');

        // "Internal testing books on": the same capability (quiqup.settings),
        // the same confirm-then-audit shape, no new route.
        if ($request->has('testing_env')) {
            return $this->testingEnv($request, $by);
        }

        try {
            $r = $this->settings->setMode((string) $request->input('mode'), $request->boolean('confirm'), $by);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage(), 'checklist' => $this->settings->checklist()], 422);
        }

        if ($r['from'] !== $r['to']) {
            try {
                app(SecurityModule::class)->record('quiqup.mode', 'Store → Quiqup mode: ' . QuiqupSettings::MODE_LABELS[$r['from']] . ' → ' . QuiqupSettings::MODE_LABELS[$r['to']], [
                    'subject' => 'quiqup', 'severity' => $r['to'] === 'live' ? 'alert' : 'notice',
                    'before' => $r['from'], 'after' => $r['to'],
                ]);
            } catch (\Throwable) {
                // The mode log in options keeps the change either way.
            }
        }

        return response()->json(['ok' => true, 'from' => $r['from'], 'to' => $r['to']] + $this->settings->publicView() + [
            'shipping_methods' => $this->recentShippingMethods(),
        ]);
    }

    /**
     * Staging (default) or a real courier for Internal testing's test orders.
     * Live needs `confirm` and the live key verified, the live webhook secret
     * and the pickup address; every change is an audit line.
     */
    private function testingEnv(Request $request, string $by): JsonResponse
    {
        try {
            $r = $this->settings->setTestingEnv($request->input('testing_env'), $request->boolean('confirm'), $by);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage(), 'testing_live_missing' => $this->settings->liveMissing()], 422);
        }

        if ($r['from'] !== $r['to']) {
            $L = QuiqupSettings::TESTING_ENV_LABELS;
            try {
                app(SecurityModule::class)->record('quiqup.testing_env', 'Store → Quiqup: Internal testing books on ' . $L[$r['from']] . ' → ' . $L[$r['to']], [
                    'subject' => 'quiqup', 'severity' => $r['to'] === 'live' ? 'alert' : 'notice',
                    'before' => $r['from'], 'after' => $r['to'],
                ]);
            } catch (\Throwable) {
                // The mode log in options keeps the change either way.
            }
        }

        return response()->json(['ok' => true, 'from' => $r['from'], 'to' => $r['to']] + $this->settings->publicView() + [
            'shipping_methods' => $this->recentShippingMethods(),
        ]);
    }

    /** Flag one order as the owner's test order (Internal testing), or unflag it. */
    public function testOrder(Request $request): JsonResponse
    {
        $id = (int) $request->input('order_id');
        $order = \App\Models\Order::query()->find($id, ['id', 'order_number']);

        if ($order === null) {
            return response()->json(['ok' => false, 'message' => 'No such order.'], 404);
        }

        $on = $request->boolean('on');
        $this->settings->setTestOrder($id, $on);

        \App\Models\OrderNote::create([
            'order_id' => $id, 'author' => (string) (auth('admin')->user()?->name ?? 'admin'), 'is_customer_note' => false,
            'content' => $on ? 'Marked as a Quiqup test order (Internal testing).' : 'No longer a Quiqup test order.',
        ]);

        return response()->json(['ok' => true, 'order_id' => $id, 'test' => $on]);
    }

    /** In-flight shipments, failures, webhook and poll health (§6.3). */
    public function activity(): JsonResponse
    {
        $env = $this->settings->env();
        $row = $this->settings->row($env);

        $inflight = Shipment::query()
            ->whereIn('job', ['queued', 'creating', 'created'])
            ->where(fn ($q) => $q->whereNull('state')->orWhereNotIn('state', QuiqupStates::END))
            ->count();

        $recent = Shipment::query()
            ->leftJoin('orders', 'orders.id', '=', 'shipments.order_id')
            ->where(fn ($q) => $q->whereIn('shipments.job', ['queued', 'creating', 'failed'])
                ->orWhereIn('shipments.state', ['delivery_failed', 'returned_to_origin', 'return_to_origin', 'on_hold', 'collection_failed'])
                ->orWhere(fn ($q2) => $q2->where('shipments.state', 'pending')->where('shipments.updated_at', '<', now()->subMinutes(15))))
            ->orderByDesc('shipments.updated_at')
            ->orderByDesc('shipments.id')
            ->limit(50)
            ->get(['shipments.id', 'shipments.order_id', 'shipments.env', 'shipments.qq_id', 'shipments.job', 'shipments.state', 'shipments.state_at',
                'shipments.attempts', 'shipments.last_reason', 'shipments.last_error', 'shipments.tracking_url', 'shipments.updated_at', 'orders.order_number']);

        $lastHook = $row->last_webhook_at;
        $stale = $inflight > 0 && ($lastHook === null || $lastHook->lt(now()->subHours(6)));

        return response()->json([
            'ok' => true,
            'env' => $env,
            'configured' => $this->settings->configured($env),
            'inflight' => $inflight,
            'last_webhook_at' => $lastHook?->toIso8601String(),
            'last_webhook_state' => $row->last_webhook_state,
            'last_poll_at' => $row->last_poll_at?->toIso8601String(),
            'last_poll_error' => $row->last_poll_error,
            'webhook_stale' => $stale,
            'unknown_today' => (int) Cache::get('quiqup:webhook-unknown:' . now()->format('Ymd'), 0),
            'unapplied' => DB::table('shipment_events')->whereNull('applied_at')->count(),
            'rows' => $recent->map(fn ($s) => [
                'order_id' => (int) $s->order_id,
                'order_number' => (string) $s->order_number,
                'env' => $s->env,
                'qq_id' => $s->qq_id,
                'job' => $s->job,
                'state' => $s->state,
                'state_label' => QuiqupStates::adminLabel($s->state),
                'state_at' => $s->state_at?->toIso8601String(),
                'attempts' => (int) $s->attempts,
                'reason' => $s->last_reason,
                'error' => $s->last_error,
                'tracking_url' => $s->tracking_url,
                'stuck_pending' => $s->state === 'pending' && $s->updated_at !== null && $s->updated_at->lt(now()->subMinutes(15)),
            ])->values(),
        ]);
    }

    /**
     * Shipping method titles seen in the last 90 days, so "service per
     * delivery method" offers the shop's real ones. One grouped query.
     *
     * @return list<string>
     */
    private function recentShippingMethods(): array
    {
        return DB::table('orders')
            ->whereNotNull('shipping_method')
            ->where('created_at', '>=', now()->subDays(90))
            ->groupBy('shipping_method')
            ->select('shipping_method', DB::raw('COUNT(*) as n'))
            ->get()
            // A handful of distinct titles: ordered and cut in PHP, with the
            // title as the tie-break, so the list is stable.
            ->sortBy([['n', 'desc'], ['shipping_method', 'asc']])
            ->take(20)
            ->map(fn ($r) => mb_substr((string) $r->shipping_method, 0, 100))
            ->filter(fn ($m) => $m !== '')
            ->values()
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CartTracking\CartTrackingReport;
use App\Services\CartTracking\CartTrackingSettings;
use App\Services\CartTracking\HostingNetworks;
use App\Services\Security\IpBlockList;
use App\Services\SecurityModule;
use App\Support\ExportProbe;
use App\Support\IpRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Growth & Marketing → Cart Tracking.                              (Lane CT)
 *
 * Mounted under /admin-api (web + auth:admin + NoStoreAdminApi) by
 * routes/cart-tracking-admin.php. Two capabilities, both owner + manager, both
 * failing closed for every other role in AdminCapabilities::RULES:
 *
 *   carttracking.view    every GET: the carts, one cart, the product
 *                        rankings, the block list, the settings, the CSV
 *   carttracking.block   every write: block, unblock, bulk actions (block
 *                        addresses / ranges, delete carts), save settings
 *
 * Every input is validated against its own allowlist or bounds before it
 * reaches a query; every row returned is built key by key in
 * CartTrackingReport / IpBlockList::row(). IP addresses are personal data and
 * leave the server only through these two capabilities — nothing here is on
 * /api/*.
 */
class CartTrackingApiController extends Controller
{
    public function __construct(
        private CartTrackingReport $report,
        private IpBlockList $blocks,
        private CartTrackingSettings $settings,
    ) {}

    /** GET cart-tracking — the Carts tab. */
    public function index(Request $request): JsonResponse
    {
        $p = $this->filters($request);

        return response()->json($this->report->carts($p) + [
            'periods' => CartTrackingReport::PERIODS,
            'threshold' => (int) $this->settings->get('bot_threshold'),
            'health' => $this->report->addressHealth(),
            'you' => IpRange::normalise($request->ip()),
        ]);
    }

    /** GET cart-tracking/carts/{id} — one cart's history. */
    public function show(Request $request, int $id): JsonResponse
    {
        $cart = $this->report->cart($id);

        if ($cart === null) {
            return response()->json(['error' => 'not_found', 'message' => 'That cart no longer exists.'], 404);
        }

        return response()->json($cart + ['you' => IpRange::normalise($request->ip())]);
    }

    /** GET cart-tracking/products?kind=added|removed&period=today|7d|30d|all */
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:added,removed'],
            'period' => ['nullable', 'string', 'in:'.implode(',', CartTrackingReport::PRODUCT_PERIODS)],
        ]);

        return response()->json($this->report->products($data['kind'], (string) ($data['period'] ?? '7d')));
    }

    /** GET cart-tracking/blocks */
    public function blocks(Request $request): JsonResponse
    {
        return response()->json([
            'rows' => $this->report->blocks(),
            'you' => IpRange::normalise($request->ip()),
            'scope' => (string) $this->settings->get('block_scope'),
            'health' => $this->report->addressHealth(),
        ]);
    }

    /** POST cart-tracking/blocks — block one address or range. */
    public function block(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target' => ['required', 'string', 'max:49'],
            'mode' => ['nullable', 'string', 'in:single,range'],
            'reason' => ['nullable', 'string', 'max:190'],
            'days' => ['nullable', 'integer', 'in:0,1,7,30,90,365'],
            'cart_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $target = trim($data['target']);

        // "The whole range" from a single address: its /24 (IPv6 /64).
        if (($data['mode'] ?? 'single') === 'range' && ! str_contains($target, '/')) {
            $target = IpRange::rangeOf($target) ?? $target;
        }

        $result = $this->blocks->block($target, $this->who($request) + [
            'reason' => $data['reason'] ?? null,
            'days' => (int) ($data['days'] ?? 0),
            'cart_id' => $data['cart_id'] ?? null,
            'source' => isset($data['cart_id']) ? 'cart' : 'manual',
        ]);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /** POST cart-tracking/blocks/{id}/unblock */
    public function unblock(int $id): JsonResponse
    {
        $result = $this->blocks->unblock($id);

        return response()->json($result, $result['ok'] ? 200 : 404);
    }

    /**
     * POST cart-tracking/bulk — {action, ids[]} or {action, all:1, …filters}.
     *
     *   block_ip     block each selected cart's address
     *   block_range  block each selected cart's /24 (IPv6 /64)
     *   delete       delete the selected carts and their history
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:block_ip,block_range,delete'],
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['integer', 'min:1'],
            'all' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        $ids = ! empty($data['all'])
            ? $this->report->matchingIds($this->filters($request), 5000)
            : array_values(array_unique(array_map('intval', $data['ids'] ?? [])));

        if ($ids === []) {
            return response()->json(['ok' => false, 'message' => 'Select at least one cart.'], 422);
        }

        if ($data['action'] === 'delete') {
            return response()->json($this->deleteCarts($ids));
        }

        $range = $data['action'] === 'block_range';
        $targets = DB::table('carts')->whereIn('id', $ids)->whereNotNull('ct_ip')
            ->get(['id', 'ct_ip', 'ct_net'])
            ->mapWithKeys(fn ($c) => [(string) ($range ? $c->ct_net : $c->ct_ip) => (int) $c->id])
            ->all();

        $done = 0;
        $already = 0;
        $refused = [];
        $who = $this->who($request);

        foreach ($targets as $target => $cartId) {
            if ($target === '') {
                continue;
            }

            $r = $this->blocks->block($target, $who + [
                'reason' => $data['reason'] ?? 'Bulk block from Cart Tracking',
                'cart_id' => $cartId,
                'source' => 'bulk',
            ]);

            if (! $r['ok']) {
                $refused[] = $r['message'];
            } elseif (! empty($r['existing'])) {
                $already++;
            } else {
                $done++;
            }
        }

        $message = sprintf('%d %s blocked', $done, $range ? ($done === 1 ? 'range' : 'ranges') : ($done === 1 ? 'address' : 'addresses'))
            .($already ? sprintf(', %d already blocked', $already) : '')
            .($refused ? sprintf(', %d refused', count($refused)) : '').'.';

        return response()->json([
            'ok' => true,
            'blocked' => $done,
            'already' => $already,
            'refused' => array_values(array_unique(array_slice($refused, 0, 10))),
            'message' => $message,
        ]);
    }

    /** GET cart-tracking/export — the current filter (or ?ids=1,2,3) as CSV. */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        // The console's download gate asks first (?probe=1): answered before a
        // query runs, so a dead session is told on the screen rather than
        // replacing the console with a login page.
        if ($probe = ExportProbe::answer($request)) {
            return $probe;
        }

        $p = $this->filters($request);
        $only = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('ids', '')))));
        $only = array_slice($only, 0, 5000);
        $threshold = (int) $this->settings->get('bot_threshold');

        $filename = 'cart-tracking-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($p, $only, $threshold) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Cart', 'Status', 'First seen (UTC)', 'Last update (UTC)', 'IP', 'Range', 'Country',
                'Bot', 'Bot score', 'Value', 'Adds', 'Removes', 'Order ID', 'Order number', 'Order status', 'User agent']);

            $ids = $only !== [] ? $only : $this->report->matchingIds($p, 100000);

            foreach (array_chunk($ids, 1000) as $chunk) {
                $orders = [];
                $rows = DB::table('carts')->whereIn('id', $chunk)->orderByDesc('id')
                    ->get(['id', 'status', 'ct_first_at', 'ct_last_at', 'ct_ip', 'ct_net', 'ct_country', 'ct_bot_score',
                        'ct_value', 'ct_added', 'ct_removed', 'ct_order_id', 'ct_ua']);

                $oids = $rows->pluck('ct_order_id')->filter()->unique()->values()->all();

                if ($oids !== []) {
                    $orders = DB::table('orders')->whereIn('id', $oids)->get(['id', 'order_number', 'status'])->keyBy('id')->all();
                }

                foreach ($rows as $r) {
                    $o = $r->ct_order_id !== null ? ($orders[$r->ct_order_id] ?? null) : null;

                    fputcsv($out, array_map([self::class, 'cell'], [
                        $r->id, $r->status, $r->ct_first_at, $r->ct_last_at, $r->ct_ip, $r->ct_net, $r->ct_country,
                        (int) $r->ct_bot_score >= $threshold ? 'Yes' : 'No', $r->ct_bot_score,
                        number_format(((int) $r->ct_value) / 100, 2, '.', ''), $r->ct_added, $r->ct_removed,
                        $r->ct_order_id, $o?->order_number, $o?->status, $r->ct_ua,
                    ]));
                }
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** GET cart-tracking/settings */
    public function settings(): JsonResponse
    {
        return response()->json([
            'values' => $this->settings->all(),
            'fields' => CartTrackingSettings::fields(),
            'hosting' => HostingNetworks::about(),
        ]);
    }

    /** POST cart-tracking/settings */
    public function saveSettings(Request $request): JsonResponse
    {
        $values = $request->validate([
            'values' => ['required', 'array'],
        ])['values'];

        $this->settings->save(array_intersect_key($values, CartTrackingSettings::SCHEMA));
        IpBlockList::rebuild();

        return response()->json(['ok' => true, 'values' => $this->settings->all(), 'message' => 'Settings saved.']);
    }

    /* ─────────────────────────────────────────────────────────────── */

    /** The Carts tab's filters, each held to its allowlist. */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'period' => ['nullable', 'string', 'in:'.implode(',', array_keys(CartTrackingReport::PERIODS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:80'],
            'bot' => ['nullable', 'string', 'in:yes,no'],
            'bought' => ['nullable', 'string', 'in:yes,no'],
            'country' => ['nullable', 'string', 'regex:/^([A-Za-z]{2}|--)$/'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', array_keys(CartTrackingReport::SORTS))],
            'dir' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        return [
            'period' => (string) ($data['period'] ?? '7d'),
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'q' => (string) ($data['q'] ?? ''),
            'bot' => (string) ($data['bot'] ?? ''),
            'bought' => (string) ($data['bought'] ?? ''),
            'country' => (string) ($data['country'] ?? ''),
            'sort' => (string) ($data['sort'] ?? 'last'),
            'dir' => (string) ($data['dir'] ?? 'desc'),
            'page' => (int) ($data['page'] ?? 1),
        ];
    }

    /** Who is asking — for the admin's-own-address refusal and the audit trail. */
    private function who(Request $request): array
    {
        $admin = Auth::guard('admin')->user();

        return [
            'admin_ip' => $request->ip(),
            'admin_id' => $admin?->getKey(),
            'admin_name' => $admin?->name ?: $admin?->email,
        ];
    }

    /** @param list<int> $ids */
    private function deleteCarts(array $ids): array
    {
        $deleted = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::transaction(function () use ($chunk, &$deleted) {
                // Explicit rather than trusting ON DELETE CASCADE, which SQLite
                // only honours with foreign keys switched on.
                DB::table('cart_events')->whereIn('cart_id', $chunk)->delete();
                DB::table('cart_items')->whereIn('cart_id', $chunk)->delete();
                DB::table('cart_recoveries')->whereIn('cart_id', $chunk)->delete();
                $deleted += DB::table('carts')->whereIn('id', $chunk)->delete();
            });
        }

        app(SecurityModule::class)->record('carttracking.deleted', sprintf('Deleted %d cart%s from Cart Tracking', $deleted, $deleted === 1 ? '' : 's'), [
            'severity' => 'notice',
        ]);

        return ['ok' => true, 'deleted' => $deleted, 'message' => sprintf('%d cart%s deleted.', $deleted, $deleted === 1 ? '' : 's')];
    }

    /** A CSV cell a spreadsheet will not run as a formula. */
    private static function cell(mixed $v): string
    {
        $s = (string) ($v ?? '');

        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$s : $s;
    }
}

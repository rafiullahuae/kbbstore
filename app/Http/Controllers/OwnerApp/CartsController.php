<?php

declare(strict_types=1);

namespace App\Http\Controllers\OwnerApp;

use App\Http\Controllers\Controller;
use App\Services\CartTracking\CartTrackingReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner app's Cart tracking screen (Lane QK10). The owner, 9 October:
 * "also give this cart tracking access to the owner app too so i can check
 * that from the mobile too."
 *
 * ONE read, the console's own: CartTrackingReport::carts() -- the query, the
 * paging (50 a page), the batched look-ups and the 60-second summary cache
 * the console's Carts tab uses. No new data model and no second query path.
 *
 * Behind the app's session (OwnerAppSession) and the console's capability,
 * carttracking.view, checked here: no member, or a role without it, gets the
 * app's 403 and nothing else (fails closed).
 *
 * LESS than the console, never more. Each row is rebuilt key by key from the
 * report's row: the visitor's IP address and network, the bot score and its
 * reasons, product URLs and the removed-line detail stay on the console. The
 * phone is read-only -- blocking and deleting (carttracking.block) are not
 * offered here.
 */
final class CartsController extends Controller
{
    use Concerns;

    /** The periods the phone offers (a subset of CartTrackingReport::PERIODS). */
    public const PERIODS = ['today', '7d', '30d', 'all'];

    public function index(Request $request, CartTrackingReport $report): JsonResponse
    {
        if ($no = $this->refuse($request, 'carttracking.view')) {
            return $no;
        }

        // Every input is one of its own options or the default -- never a 422
        // the phone would have to explain.
        $period = in_array($request->query('period'), self::PERIODS, true) ? (string) $request->query('period') : '7d';
        $bought = in_array($request->query('bought'), ['yes', 'no'], true) ? (string) $request->query('bought') : '';
        $bot = $request->query('bot') === 'no' ? 'no' : '';
        $page = max(1, min(100000, (int) $request->query('page', 1)));

        $d = $report->carts([
            'period' => $period, 'bought' => $bought, 'bot' => $bot,
            'sort' => 'last', 'dir' => 'desc', 'page' => $page,
        ]);

        $s = $d['summary'];

        return response()->json([
            'rows' => array_map(self::row(...), $d['rows']),
            'total' => (int) $d['total'],
            'page' => (int) $d['page'],
            'pages' => (int) $d['pages'],
            'summary' => [
                'carts' => (int) $s['carts'],
                'bought' => (int) $s['bought'],
                'conversion' => (float) $s['conversion'],
                'bots' => (int) $s['bots'],
                'open_value' => (string) $s['open_value'],
                'bought_value' => (string) $s['bought_value'],
            ],
        ]);
    }

    /** The allowlist: what a phone row shows, and nothing else. */
    public const ROW_KEYS = ['id', 'first', 'last', 'value', 'value_label', 'items', 'removed', 'country', 'bot', 'customer', 'email', 'order'];

    private static function row(array $r): array
    {
        $c = $r['customer'] ?? null;
        $o = $r['order'] ?? null;

        return [
            'id' => (int) $r['id'],
            'first' => $r['first'],
            'last' => $r['last'],
            'value' => (int) $r['value'],
            'value_label' => (string) $r['value_label'],
            'items' => array_map(fn (array $l) => [
                'id' => $l['id'] === null ? null : (int) $l['id'],
                'name' => (string) $l['name'],
                'qty' => (int) $l['qty'],
            ], $r['products'] ?? []),
            'removed' => (int) ($r['removed_count'] ?? 0),
            'country' => $r['country_name'],
            'bot' => (bool) $r['bot'],
            'customer' => $c === null ? null : ['id' => (int) $c['id'], 'name' => $c['name']],
            'email' => $r['email'] ?? null,
            'order' => $o === null ? null : [
                'id' => (int) $o['id'],
                'number' => (string) $o['number'],
                'status' => (string) $o['status'],
                'total' => (string) $o['total'],
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\BoardLayout;
use App\Services\Analytics\Report;
use App\Services\Analytics\Rollup;
use App\Services\Analytics\Tracker;
use App\Services\SettingsService;
use App\Support\IpRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Analytics, the board's three reads and one write.                  (Lane AN)
 *
 *   GET  /admin-api/site-analytics?range=today|yesterday|7d|30d|90d|custom&from&to
 *   GET  /admin-api/site-analytics/live?w=5|10|15|25&since=&osince=
 *   GET  /admin-api/site-analytics/settings
 *   POST /admin-api/site-analytics/settings        {tracking, exclude}
 *
 * analytics.view for the reads, analytics.manage for the write, both mapped
 * in AdminCapabilities and enforced before this runs (fails closed). The owner
 * app reaches summary() and live() through App\Http\Controllers\OwnerApp\
 * AnalyticsController with its own session and the same capability.
 */
final class SiteAnalyticsApiController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        return response()->json(self::summaryPayload($request));
    }

    public function live(Request $request): JsonResponse
    {
        return response()->json(self::livePayload($request));
    }

    /** @return array<string, mixed> */
    public static function summaryPayload(Request $request): array
    {
        // The lazy half of the rollup: a late or missing scheduler is covered
        // the moment somebody opens the board.
        if (Rollup::stale(120)) {
            Rollup::runDue(true);
        }

        [$from, $to, $key] = Report::range((string) $request->query('range', 'today'), $request->query('from'), $request->query('to'));

        return ['ok' => true, 'range' => $key, 'layout' => BoardLayout::get(self::adminId($request))] + Report::summary($from, $to);
    }

    /** @return array<string, mixed> */
    public static function livePayload(Request $request): array
    {
        $out = ['ok' => true] + Report::live(
            Report::window($request->query('w')),
            max(0, (int) $request->query('since', 0)),
            max(0, (int) $request->query('osince', 0)),
        );

        // TODAY MOVES WITH THE LIVE CARD (Lane AN2). The owner saw the
        // funnel and the lists lag the live card: they are today's summary,
        // which only the minute rollup rebuilds, and without cron only the
        // board's first open did. So while the board shows Today, every poll
        // brings today's summary too, rebuilt here if it is over a minute old
        // (Rollup::freshToday, under a lock). Other ranges are not touched.
        if ($request->query('today') === '1') {
            Rollup::freshToday(60);
            [$from, $to] = Report::range('today');
            $out['today'] = ['ok' => true, 'range' => 'today'] + Report::summary($from, $to);
        }

        $out['cron'] = [
            'alive' => Rollup::cronAlive(),
            'line' => '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
            'where' => 'Cloudways → your application → Cron Job Management → Add New Cron Job',
        ];

        return $out;
    }

    /*
     * The board's layout for THIS admin (Lane AN2): block order and hidden
     * blocks, allowlisted by BoardLayout. analytics.view, because it is the
     * viewer's own arrangement and changes nothing anybody else sees.
     */
    public function layout(Request $request): JsonResponse
    {
        return response()->json(['ok' => true] + BoardLayout::get(self::adminId($request)));
    }

    public function saveLayout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order' => ['present', 'array', 'max:60'],
            'order.*' => ['string', 'max:24'],
            'hidden' => ['nullable', 'array', 'max:60'],
            'hidden.*' => ['string', 'max:24'],
        ]);
        $id = self::adminId($request);
        abort_if($id === null, 403);

        return response()->json(['ok' => true] + BoardLayout::put($id, $data['order'], $data['hidden'] ?? []));
    }

    public function resetLayout(Request $request): JsonResponse
    {
        $id = self::adminId($request);
        abort_if($id === null, 403);

        return response()->json(['ok' => true] + BoardLayout::reset($id));
    }

    /** The signed-in admin: the console's guard, or the owner app's member. */
    public static function adminId(Request $request): ?int
    {
        $a = $request->attributes->get('oa.admin') ?? auth('admin')->user();

        return $a instanceof \App\Models\AdminUser ? (int) $a->getKey() : null;
    }

    public function settings(SettingsService $settings): JsonResponse
    {
        return response()->json(['ok' => true] + self::settingsShape($settings));
    }

    public function saveSettings(Request $request, SettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'tracking' => ['required', 'boolean'],
            'exclude' => ['nullable', 'string', 'max:2000'],
        ]);

        $kept = [];
        $bad = [];
        foreach (preg_split('/[\s,]+/', (string) ($data['exclude'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
            $ok = str_contains($item, '/') ? IpRange::parse($item) !== null : IpRange::normalise($item) !== null;
            $ok ? $kept[] = $item : $bad[] = $item;
        }

        if ($bad !== []) {
            return response()->json(['ok' => false, 'message' => 'Not an address: '.implode(', ', array_slice($bad, 0, 5))], 422);
        }

        $settings->set(Tracker::SETTING_ON, (bool) $data['tracking']);
        $settings->set(Tracker::SETTING_EXCLUDE, implode("\n", array_slice(array_unique($kept), 0, 50)));

        return response()->json(['ok' => true] + self::settingsShape($settings));
    }

    /** @return array<string, mixed> */
    private static function settingsShape(SettingsService $settings): array
    {
        return [
            'tracking' => (bool) $settings->get(Tracker::SETTING_ON, true),
            'exclude' => (string) $settings->get(Tracker::SETTING_EXCLUDE, ''),
            'your_ip' => (string) request()->ip(),
            'keep_hours' => Rollup::KEEP_HOURS,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

        return ['ok' => true, 'range' => $key] + Report::summary($from, $to);
    }

    /** @return array<string, mixed> */
    public static function livePayload(Request $request): array
    {
        return ['ok' => true] + Report::live(
            Report::window($request->query('w')),
            max(0, (int) $request->query('since', 0)),
            max(0, (int) $request->query('osince', 0)),
        );
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

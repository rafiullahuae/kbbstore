<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Marketing\CampaignInsights;
use App\Services\Marketing\OpenPixel;
use App\Services\SettingsService;
use App\Support\AdminRoles;
use App\Support\StoreTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Growth & Marketing → Marketing Emails → Reports → a campaign → Recipients,
 * its CSV, and the Open tracking switch.                            (Lane ER)
 *
 * Mounted by routes/marketing-report-admin.php inside the admin-api group.
 * Capabilities (AdminCapabilities): the list is marketing.email.view, the CSV
 * marketing.export (a list of addresses leaving the building), the switch
 * marketing.email.manage. The KPI tiles ride Lane MK's own GET /reports/{id}.
 */
final class MktReportController extends Controller
{
    public function __construct(private CampaignInsights $insights)
    {
    }

    public function recipients(Request $request, int $id): JsonResponse
    {
        if (! DB::table('mkt_campaigns')->where('id', $id)->exists()) {
            return response()->json(['error' => 'No such campaign.'], 404);
        }

        return response()->json($this->insights->recipients(
            $id,
            (string) $request->query('q', ''),
            (string) $request->query('filter', 'all'),
            max(1, (int) $request->query('page', 1)),
        ) + ['can_export' => AdminRoles::can($request->user('admin'), 'marketing.export')]);
    }

    public function export(int $id): StreamedResponse|JsonResponse
    {
        if (! DB::table('mkt_campaigns')->where('id', $id)->exists()) {
            return response()->json(['error' => 'No such campaign.'], 404);
        }

        $name = 'campaign-' . $id . '-recipients-' . StoreTime::today()->format('Y-m-d') . '.csv';

        return response()->stream(function () use ($id) {
            $out = fopen('php://output', 'w');
            // A cell that starts like a formula is quoted (CSV injection), as MktGroupsController does.
            $cell = static fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'" . $v : (string) $v;
            $yes = static fn (bool $b) => $b ? 'yes' : 'no';
            fputcsv($out, ['email', 'status', 'bounced', 'opened (estimate)', 'open kind', 'first opened', 'opens', 'clicked', 'clicks', 'first click', 'orders within 7 days', 'revenue', 'unsubscribed']);

            $this->insights->eachRecipient($id, function (array $rows) use ($out, $cell, $yes) {
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $cell($r['email']), $r['status'], $r['bounce'] ?? '', $yes($r['opened']), $r['open_kind'] ?? '',
                        $r['first_open_at'] ?? '', $r['opens'], $yes($r['clicked']), $r['clicks'], $r['first_click_at'] ?? '',
                        $r['orders'], $cell($r['revenue']), $yes($r['unsubscribed']),
                    ]);
                }
            });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    public function openTracking(Request $request, SettingsService $settings): JsonResponse
    {
        $on = filter_var($request->input('on'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($on === null) {
            return response()->json(['error' => 'Say on or off.'], 422);
        }

        $settings->set(OpenPixel::SETTING, $on ? '1' : '0');

        return response()->json(['ok' => true, 'open_tracking' => OpenPixel::enabled()]);
    }
}

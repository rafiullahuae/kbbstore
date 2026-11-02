<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payments\CodRefusals;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Store → Payments → Cash on delivery → Refused deliveries.      (Lane CODR)
 *
 *   GET  /admin-api/cod-refusals              the switch and one page of rows   codrefusals.view
 *   POST /admin-api/cod-refusals/settings     {enabled}                         codrefusals.manage
 *   POST /admin-api/cod-refusals/{id}/allow   "Allow COD again"                 codrefusals.manage
 *
 * Every row is a customer's email and phone, so it lives in the guarded
 * admin-api group and nowhere public. The list is two queries whatever its
 * length (a count and one page), with the columns named, never a model.
 */
final class CodRefusalsApiController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private CodRefusals $refusals, private SettingsService $settings) {}

    public function index(Request $request): JsonResponse
    {
        // Wildcards are dropped rather than escaped: LIKE's escape character
        // differs between SQLite and MySQL, and nobody searches for a '%'.
        $q = trim(str_replace(['%', '_', '\\'], '', mb_substr((string) $request->query('q', ''), 0, 120)));
        $page = max(1, min(10000, (int) $request->query('page', 1)));

        $base = DB::table('cod_refusals')->whereNull('lifted_at');

        if ($q !== '') {
            $like = '%' . mb_strtolower($q) . '%';
            $digits = CodRefusals::phone($q);
            $base->where(function ($w) use ($like, $digits, $q) {
                $w->where('email', 'like', $like)
                    ->orWhere('order_number', 'like', '%' . ltrim($q, '#') . '%');
                if ($digits !== null) {
                    $w->orWhere('phone', $digits);
                }
            });
        }

        $total = (clone $base)->count();
        $rows = $base->orderByDesc('refused_at')->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)
            ->get(['id', 'email', 'phone', 'order_id', 'order_number', 'refused_at', 'source'])
            ->map(static fn ($r) => [
                'id' => (int) $r->id,
                'email' => $r->email,
                'phone' => $r->phone,
                'order_id' => (int) $r->order_id,
                'order_number' => (string) $r->order_number,
                'refused_at' => $r->refused_at,
                'source' => $r->source === 'history' ? 'history' : 'refused',
            ])->all();

        return response()->json([
            'enabled' => $this->refusals->enabled(),
            'history_enabled' => $this->refusals->historyEnabled(),
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        // Either switch, or both, in one save (2.60.636 adds the second).
        $data = $request->validate([
            'enabled' => ['required_without:history_enabled', 'boolean'],
            'history_enabled' => ['required_without:enabled', 'boolean'],
        ]);

        if (array_key_exists('enabled', $data)) {
            $this->settings->set(CodRefusals::SETTING, $data['enabled'] ? '1' : '0');
        }
        if (array_key_exists('history_enabled', $data)) {
            $this->settings->set(CodRefusals::HISTORY_SETTING, $data['history_enabled'] ? '1' : '0');
        }

        return response()->json([
            'ok' => true,
            'enabled' => $this->refusals->enabled(),
            'history_enabled' => $this->refusals->historyEnabled(),
        ]);
    }

    public function allow(Request $request, int $id): JsonResponse
    {
        $admin = $request->user('admin');
        $by = trim((string) ($admin?->name ?: $admin?->email ?: 'admin'));

        $ok = $this->refusals->allow($id, $by);

        return response()->json(['ok' => $ok], $ok ? 200 : 404);
    }
}

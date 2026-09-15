<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCountry;
use App\Services\ExtendedDelivery;
use App\Services\Import\Money as ImportMoney;
use App\Services\SettingsService;
use App\Services\ShippingService;
use App\Support\Countries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Store → Delivery & Shipping → Extended. */
class ExtendedDeliveryApiController extends Controller
{
    public function __construct(
        private SettingsService $settings,
        private ExtendedDelivery $extended,
        private ShippingService $shipping,
    ) {}

    /**
     * If the delivery_countries table is missing — a migration that has not
     * run yet, or failed silently on a shared host with restricted DDL
     * privileges — this screen used to 500 with no way to tell why from the
     * browser. It now degrades the same way Newsletter does when its table is
     * absent: empty rows, on always false, and a flag the front end shows as
     * an explanation rather than a generic server error.
     */
    public function show(): JsonResponse
    {
        $ready = Schema::hasTable('delivery_countries');

        // Zone countries are answered on the Zones tab; offering them here too
        // would be a second place deciding the same country's rate. Excluded
        // from both the pick list and any stale rows a country might already
        // have (e.g. from before a zone was extended to cover it).
        $zoneCodes = array_keys($this->shipping->coveredCountries());

        $rows = [];

        if ($ready) {
            try {
                $rows = DeliveryCountry::orderBy('position')->get()
                    ->reject(fn (DeliveryCountry $c) => in_array($c->code, $zoneCodes, true))
                    ->map(fn (DeliveryCountry $c) => [
                        'code' => $c->code,
                        'enabled' => (bool) $c->enabled,
                        'charge' => (int) $c->charge,
                        'free_from' => $c->free_from === null ? null : (int) $c->free_from,
                        'eta' => $c->eta ?? '',
                    ])->values()->all();
            } catch (\Throwable $e) {
                Log::error('extended-delivery: failed to read delivery_countries', ['message' => $e->getMessage()]);
                $ready = false;
            }
        }

        return response()->json([
            'on' => $ready && $this->extended->enabled(),
            'detect' => $this->extended->detectEnabled(),
            'show_all' => $this->extended->showsUnserved(),
            'names' => array_diff_key(Countries::NAMES, array_flip($zoneCodes)),
            'regions' => Countries::REGIONS,
            'currency' => (string) $this->settings->get('currency_symbol', 'د.إ'),
            'rows' => $rows,
            'ready' => $ready,
            'zoneCount' => count($zoneCodes),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        if (! Schema::hasTable('delivery_countries')) {
            return response()->json([
                'ok' => false,
                'error' => 'Extended delivery is not set up on this server yet — the delivery_countries table is missing. Re-apply the update, or check storage/logs/laravel.log for a migration error.',
            ], 500);
        }

        $data = $request->validate([
            'on' => ['required', 'boolean'],
            'detect' => ['required', 'boolean'],
            'show_all' => ['required', 'boolean'],
            'rows' => ['present', 'array'],
            'rows.*.code' => ['required', 'string', 'size:2'],
            'rows.*.enabled' => ['required', 'boolean'],
            // Fils in `integer` columns — signed 32-bit. `integer|min:0` is not
            // a ceiling, and without one MySQL answers the write with ERROR
            // 1264 while SQLite stores the value, so the suite could not see it.
            'rows.*.charge' => ['required', 'integer', 'min:0', 'max:' . ImportMoney::MAX_FILS],
            'rows.*.free_from' => ['nullable', 'integer', 'min:0', 'max:' . ImportMoney::MAX_FILS],
            'rows.*.eta' => ['nullable', 'string', 'max:40'],
        ]);

        $codes = array_map('strtoupper', array_column($data['rows'], 'code'));
        $unknown = array_diff($codes, array_keys(Countries::NAMES));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown country: ' . implode(', ', $unknown)], 422);
        }

        $zoneCodes = array_keys($this->shipping->coveredCountries());
        $clash = array_intersect($codes, $zoneCodes);

        if ($clash !== []) {
            return response()->json([
                'ok' => false,
                'error' => implode(', ', $clash) . ' already delivers via a zone. Edit it under the Zones tab instead.',
            ], 422);
        }

        $this->settings->set(ExtendedDelivery::SETTING_ON, $data['on']);
        $this->settings->set(ExtendedDelivery::SETTING_DETECT, $data['detect']);
        $this->settings->set(ExtendedDelivery::SETTING_SHOW_ALL, $data['show_all']);

        $keep = [];

        foreach (array_values($data['rows']) as $i => $row) {
            $code = strtoupper($row['code']);
            $keep[] = $code;

            DeliveryCountry::updateOrCreate(['code' => $code], [
                'enabled' => $row['enabled'],
                'charge' => $row['charge'],
                'free_from' => $row['free_from'],
                'eta' => $row['eta'] ?: null,
                'position' => $i,
            ]);
        }

        DeliveryCountry::whereNotIn('code', $keep ?: ['--'])->delete();

        return response()->json(['ok' => true]);
    }
}

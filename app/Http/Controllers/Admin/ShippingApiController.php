<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Store → Delivery & Shipping.
 *
 * The zones, their locations and their methods already existed — seeded with
 * AED 20 flat and free over AED 199 for the UAE, and AED 150 / free over 1,600
 * for the Gulf. What was missing was any way to change them without a database
 * client. This edits the existing rows; it does not introduce a parallel set of
 * settings, which is what the free-shipping threshold has been read from all
 * along.
 */
class ShippingApiController extends Controller
{
    public function show(): JsonResponse
    {
        try {
            $zones = ShippingZone::with(['locations', 'methods' => fn ($q) => $q->orderBy('position')])
                ->orderBy('position')
                ->get()
                ->map(fn (ShippingZone $z) => [
                    'id' => $z->id,
                    'name' => $z->name,
                    'locations' => $z->locations->pluck('code')->all(),
                    'methods' => $z->methods->map(fn (ShippingMethod $m) => [
                        'id' => $m->id,
                        'type' => $m->type,
                        'title' => $m->title,
                        'enabled' => (bool) $m->enabled,
                        'cost' => (int) $m->cost,
                        'min_amount' => $m->min_amount === null ? null : (int) $m->min_amount,
                    ])->all(),
                ])
                ->all();
        } catch (\Throwable $e) {
            // A screen that reads database rows should say so specifically
            // rather than surface a bare 500 with no way to act on it from
            // the browser — the exact fault this replaces.
            Log::error('shipping: failed to load zones', ['message' => $e->getMessage()]);

            return response()->json([
                'zones' => [],
                'currency' => 'د.إ',
                'error' => 'Could not read the shipping zones. Check storage/logs/laravel.log for "shipping: failed to load zones".',
            ], 500);
        }

        return response()->json([
            'zones' => $zones,
            'currency' => (string) app(\App\Services\SettingsService::class)->get('currency_symbol', 'د.إ'),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'methods' => ['required', 'array'],
            'methods.*.id' => ['required', 'integer'],
            'methods.*.title' => ['required', 'string', 'max:60'],
            'methods.*.enabled' => ['required', 'boolean'],
            'methods.*.cost' => ['required', 'integer', 'min:0'],
            'methods.*.min_amount' => ['nullable', 'integer', 'min:0'],
        ]);

        foreach ($data['methods'] as $row) {
            $method = ShippingMethod::find($row['id']);

            if (! $method) {
                continue;
            }

            $method->title = $row['title'];
            $method->enabled = $row['enabled'];

            // A free-shipping method costs nothing by definition, and a flat
            // rate has no threshold. Enforced here so a bad value cannot reach
            // ratesFor(), which trusts what it reads.
            if ($method->type === 'free_shipping') {
                $method->cost = 0;
                $method->min_amount = $row['min_amount'] ?? 0;
            } else {
                $method->cost = $row['cost'];
                $method->min_amount = null;
            }

            $method->save();
        }

        return response()->json(['ok' => true]);
    }
}

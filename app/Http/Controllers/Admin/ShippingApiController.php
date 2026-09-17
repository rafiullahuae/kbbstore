<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Services\Import\Money;
use App\Support\WholeDirhams;
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
            /*
             * allMethods(), not methods().
             *
             * methods() is the STOREFRONT's relation and carries
             * `where('enabled', true)` so a switched-off rate can never be
             * offered or charged. Reading it here meant a method disappeared
             * from this screen the instant it was unticked and saved — and
             * save() below only updates ids the browser posts back, which are
             * the ids this method returned. Turning a delivery method off was
             * therefore permanent from the admin's side. See the note on
             * ShippingZone::allMethods().
             */
            $zones = ShippingZone::with(['locations', 'allMethods'])
                ->orderBy('position')
                ->get()
                ->map(fn (ShippingZone $z) => [
                    'id' => $z->id,
                    'name' => $z->name,
                    'locations' => $z->locations->pluck('code')->all(),
                    'methods' => $z->allMethods->map(fn (ShippingMethod $m) => [
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
        /*
         * `cost` and `min_amount` are fils in `integer` columns — signed 32-bit,
         * so Money::MAX_FILS is the real ceiling. `integer|min:0` on its own is
         * not a bound: Laravel's `integer` rule takes 99,999,999,999 without
         * complaint, and the two engines then disagree about what to do with
         * it. MySQL in strict mode raises ERROR 1264 and this screen 500s on
         * the live host; SQLite stores it, which is why the suite stayed green.
         * Bounded here so both engines behave the same and the operator gets a
         * sentence instead of a server error.
         */
        $data = $request->validate([
            'methods' => ['required', 'array'],
            'methods.*.id' => ['required', 'integer'],
            'methods.*.title' => ['required', 'string', 'max:60'],
            'methods.*.enabled' => ['required', 'boolean'],
            'methods.*.cost' => ['required', 'integer', 'min:0', 'max:' . Money::MAX_FILS],
            'methods.*.min_amount' => ['nullable', 'integer', 'min:0', 'max:' . Money::MAX_FILS],
        ], [
            'methods.*.cost.max' => 'A delivery charge cannot be more than AED 21,474,836.47.',
            'methods.*.min_amount.max' => 'A free-delivery threshold cannot be more than AED 21,474,836.47.',
        ]);

        /*
         * WHOLE DIRHAMS, CHECKED BEFORE ANYTHING IS WRITTEN — Lane FA.
         *
         * A delivery charge and a free-delivery threshold are both money the
         * owner types, so both are REFUSED rather than adjusted. See
         * App\Support\WholeDirhams for the rule.
         *
         * The threshold matters as much as the charge and for a different
         * reason: it is compared against a subtotal that is now always a whole
         * dirham, so a threshold of 19,950 fils is one no basket can ever land
         * exactly on, and the shop's own "AED 0 away from free delivery"
         * arithmetic is computed from the difference. A threshold on the same
         * grid as the baskets it measures is what makes that line able to
         * reach zero.
         *
         * A SEPARATE PASS, ahead of the write loop, deliberately. The loop
         * below saves each method as it goes, so refusing inside it would
         * leave the earlier rows of the form applied and the later ones not —
         * a screen showing a mixture of saved and unsaved rates with a single
         * error message over the top. This is the same "validate everything
         * first, write nothing until it all passes" rule
         * AdminController::updateSettings() states at length.
         *
         * Each figure is compared against what the method already holds, so a
         * zone carrying a legacy 1,250-fil rate can still be renamed or
         * switched off. The audit command is where those are dealt with.
         */
        foreach ($data['methods'] as $row) {
            $method = ShippingMethod::find($row['id']);

            if (! $method) {
                continue;
            }

            $checks = $method->type === 'free_shipping'
                ? ['min_amount' => ['Free-delivery threshold', $row['min_amount'] ?? 0, $method->min_amount]]
                : ['cost' => ['Delivery charge', $row['cost'], $method->cost]];

            foreach ($checks as $field => [$label, $value, $stored]) {
                $value = (int) $value;

                if ($value !== ($stored === null ? null : (int) $stored)
                    && ! WholeDirhams::isWhole($value)) {
                    return response()->json([
                        'message' => WholeDirhams::message($label . ' on “' . $method->title . '”', $value),
                        'errors' => ['methods.' . $field => ['Whole ' . WholeDirhams::plural() . ' only.']],
                    ], 422);
                }
            }
        }

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

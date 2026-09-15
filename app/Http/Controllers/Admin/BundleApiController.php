<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BundleService;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read and write the quantity-bundle tiers. */
class BundleApiController extends Controller
{
    public function __construct(
        private SettingsService $settings,
        private BundleService $bundles,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'enabled' => $this->bundles->enabled(),
            'tiers' => $this->bundles->tiers(),
            'defaults' => BundleService::DEFAULT_TIERS,
            // A worked example, so the effect of a change is visible before saving.
            'preview' => $this->preview(5500),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'tiers' => ['required', 'array', 'min:1', 'max:6'],
            'tiers.*.qty' => ['required', 'integer', 'between:1,99'],
            'tiers.*.discount' => ['required', 'numeric', 'between:0,90'],
            'tiers.*.label' => ['required', 'string', 'max:60'],
            'tiers.*.tag' => ['nullable', 'string', 'max:40'],
        ]);

        // Duplicate quantities would render two rows that do the same thing.
        $seen = [];

        foreach ($data['tiers'] as $t) {
            if (in_array($t['qty'], $seen, true)) {
                return response()->json(['ok' => false, 'error' => 'Each tier needs a different quantity.'], 422);
            }

            $seen[] = $t['qty'];
        }

        $this->settings->set('bundles_enabled', $data['enabled']);
        $this->settings->set('bundle_tiers', array_values($data['tiers']));

        return response()->json(['ok' => true, 'preview' => $this->preview(5500)]);
    }

    /**
     * What the tiers look like on a AED 55 product.
     *
     * FORMATTED OUT OF THE INTEGER, not through `number_format($x / 100, 2)`.
     *
     * To be precise about which half of that was actually broken, because the
     * two get conflated: the FLOAT was not. `$fils / 100` and number_format()
     * agree with exact integer arithmetic on every value a 32-bit money column
     * can hold — checked across the whole range, not argued from principle —
     * because a value with two decimal places is never a tie at two decimal
     * places, and the double's error is ~1e-13 relative, nowhere near half a
     * fil. So this was not a rounding bug waiting to happen.
     *
     * The hard-coded `/ 100` and `2` were the bug. Minor units are hundredths
     * only for a two-decimal currency; Money::minorExponent() is a setting and
     * CurrencyDisplayTest already drives it to JPY (0) and KWD (3). On a KWD
     * store 5500 minor units is 5.500, and this printed 55.00 — out by a factor
     * of ten on the one screen whose entire job is to show the operator what a
     * discount will do before they save it.
     *
     * Money::amount() takes the integer and the exponent the store is actually
     * configured with, and constructs no float at all. Passing 2 explicitly
     * keeps this preview at two decimals whatever the storefront's display
     * setting is, so the output is byte-for-byte unchanged on AED.
     */
    private function preview(int $unit): array
    {
        $rows = [];

        foreach ($this->bundles->tiers() as $t) {
            $qty = $t['qty'];
            $total = $this->bundles->totalFor($unit, $qty);
            $was = $unit * $qty;

            $rows[] = [
                'label' => $t['label'],
                'qty' => $qty,
                'total' => Money::amount($total, 2),
                'was' => Money::amount($was, 2),
                'saved' => Money::amount($was - $total, 2),
            ];
        }

        return $rows;
    }
}

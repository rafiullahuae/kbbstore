<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BundleService;
use App\Services\SettingsService;
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

    /** What the tiers look like on a AED 55 product. */
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
                'total' => number_format($total / 100, 2),
                'was' => number_format($was / 100, 2),
                'saved' => number_format(($was - $total) / 100, 2),
            ];
        }

        return $rows;
    }
}

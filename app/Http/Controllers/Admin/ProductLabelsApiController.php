<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
use App\Services\ProductLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Catalogue → Product Labels. */
class ProductLabelsApiController extends Controller
{
    public function __construct(private ProductLabels $labels) {}

    public function show(): JsonResponse
    {
        // One schema, drawn by one renderer. This loop used to be copied
        // into nine controllers that had to agree by hand.
        $tabs = ModuleSchema::tabs(
            ProductLabels::SCHEMA,
            ProductLabels::TABS,
            $this->labels->all(),
            ProductLabels::POLICY,
        );

        return response()->json([
            'tabs' => $tabs,
            // The screen dims itself when the module is off, rather than letting
            // someone set badges that cannot appear.
            'module_on' => app(\App\Services\SettingsService::class)->moduleEnabled('product_labels', false),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(ProductLabels::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $this->labels->save($data['settings']);

        return response()->json(['ok' => true]);
    }
}

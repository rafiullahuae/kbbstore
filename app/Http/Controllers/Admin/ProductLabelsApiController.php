<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProductLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Catalogue → Product Labels. */
class ProductLabelsApiController extends Controller
{
    public function __construct(private ProductLabels $labels) {}

    public function show(): JsonResponse
    {
        $values = $this->labels->all();
        $fields = [];

        foreach (ProductLabels::SCHEMA as $key => $def) {
            [$type, $label, $default, $help] = array_pad($def, 4, '');

            $fields[$key] = [
                'key' => $key, 'type' => $type, 'label' => $label, 'help' => $help,
                'default' => $default, 'value' => $values[$key], 'options' => $def[4] ?? null,
            ];
        }

        $tabs = [];

        foreach (ProductLabels::TABS as $key => [$label, $description, $keys]) {
            $tabs[] = [
                'key' => $key, 'label' => $label, 'description' => $description,
                'fields' => array_values(array_filter(array_map(fn ($k) => $fields[$k] ?? null, $keys))),
            ];
        }

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

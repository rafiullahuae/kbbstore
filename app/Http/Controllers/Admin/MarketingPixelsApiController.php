<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MarketingPixels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Growth & Marketing → Marketing Pixels. */
class MarketingPixelsApiController extends Controller
{
    public function __construct(private MarketingPixels $pixels) {}

    public function show(): JsonResponse
    {
        $values = $this->pixels->all();
        $fields = [];

        foreach (MarketingPixels::SCHEMA as $key => $def) {
            [$type, $label, $default, $help] = array_pad($def, 4, '');

            $fields[$key] = [
                'key' => $key, 'type' => $type, 'label' => $label, 'help' => $help,
                'default' => $default, 'value' => $values[$key],
            ];
        }

        $tabs = [];

        foreach (MarketingPixels::TABS as $key => [$label, $description, $keys]) {
            $tabs[] = [
                'key' => $key, 'label' => $label, 'description' => $description,
                'fields' => array_values(array_filter(array_map(fn ($k) => $fields[$k] ?? null, $keys))),
            ];
        }

        return response()->json([
            'tabs' => $tabs,
            'module_on' => $this->pixels->enabled(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(MarketingPixels::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $this->pixels->save($data['settings']);

        return response()->json(['ok' => true]);
    }
}

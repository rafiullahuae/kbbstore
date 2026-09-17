<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MarketingPixels;
use App\Services\ModuleSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Growth & Marketing → Marketing Pixels. */
class MarketingPixelsApiController extends Controller
{
    public function __construct(private MarketingPixels $pixels) {}

    public function show(): JsonResponse
    {
        // Built by ModuleSchema, which is where this loop now lives once instead
        // of identically in two controllers.
        $tabs = ModuleSchema::tabs(MarketingPixels::SCHEMA, MarketingPixels::TABS, $this->pixels->all());

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

        $rejected = $this->pixels->save($data['settings']);

        if ($rejected !== []) {
            return response()->json([
                'ok' => false,
                'error' => '“' . implode('”, “', $rejected) . '” is not a valid value.',
            ], 422);
        }

        return response()->json(['ok' => true]);
    }
}

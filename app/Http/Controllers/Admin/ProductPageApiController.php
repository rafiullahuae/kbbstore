<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProductSections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Product page modules: visibility per device. */
class ProductPageApiController extends Controller
{
    public function __construct(private ProductSections $sections) {}

    public function show(): JsonResponse
    {
        return response()->json(['sections' => array_values($this->sections->all())]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.key' => ['required', 'string', 'max:40'],
            'sections.*.desktop' => ['required', 'boolean'],
            'sections.*.mobile' => ['required', 'boolean'],
        ]);

        $payload = [];

        foreach ($data['sections'] as $row) {
            if (! isset(ProductSections::REGISTRY[$row['key']])) {
                return response()->json(['ok' => false, 'error' => "Unknown module: {$row['key']}."], 422);
            }

            $payload[$row['key']] = ['desktop' => $row['desktop'], 'mobile' => $row['mobile']];
        }

        $this->sections->save($payload);

        return response()->json([
            'ok' => true,
            'saved' => count($payload),
            'sections' => array_values($this->sections->all()),
        ]);
    }
}

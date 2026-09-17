<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Services\ProductStyles;
use App\Support\GridSkins;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Appearance → Product styles, plus the data the shortcode builder needs. */
class ProductStylesApiController extends Controller
{
    public function __construct(private ProductStyles $styles) {}

    public function show(): JsonResponse
    {
        $values = $this->styles->all();
        $fields = [];

        foreach (ProductStyles::SCHEMA as $key => $def) {
            [$type, $label, $default, $help] = array_pad($def, 4, '');

            $fields[$key] = [
                'key' => $key, 'type' => $type, 'label' => $label, 'help' => $help,
                'default' => $default, 'value' => $values[$key], 'options' => $def[4] ?? null,
            ];
        }

        $tabs = [];

        foreach (ProductStyles::TABS as $key => [$label, $description, $keys]) {
            $tabs[] = [
                'key' => $key, 'label' => $label, 'description' => $description,
                'fields' => array_values(array_filter(array_map(fn ($k) => $fields[$k] ?? null, $keys))),
            ];
        }

        return response()->json([
            'tabs' => $tabs,
            'skins' => collect(GridSkins::ALL)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            // The builder offers real categories and brands rather than asking
            // someone to remember a slug. Both end on `id` because both are
            // truncated at 200 and neither name column is unique.
            'categories' => Cache::remember('kbb.admin.cats', 600, fn () => Category::query()
                ->select('id', 'name', 'slug')->orderBy('name')->orderBy('id')->limit(200)->get()),
            'brands' => Cache::remember('kbb.admin.brands', 600, fn () => Brand::query()
                ->select('id', 'name', 'slug')->orderBy('name')->orderBy('id')->limit(200)->get()),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(ProductStyles::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $this->styles->save($data['settings']);

        \App\Support\Shortcodes::flush();
        Cache::forget('kbb.home.rails');

        return response()->json(['ok' => true, 'saved' => count($data['settings']), 'values' => $this->styles->all()]);
    }
}

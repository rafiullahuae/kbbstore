<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
use App\Services\HeaderSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Appearance → Header. */
class HeaderApiController extends Controller
{
    public function __construct(private HeaderSettings $header) {}

    public function show(): JsonResponse
    {
        // One schema, drawn by one renderer. This loop used to be copied
        // into nine controllers that had to agree by hand.
        $tabs = ModuleSchema::tabs(
            HeaderSettings::SCHEMA,
            HeaderSettings::TABS,
            $this->header->all(),
            HeaderSettings::POLICY,
        );

        // Real brands and categories, so a word can be picked rather than typed.
        $suggestions = Cache::remember('kbb.admin.trending', 600, fn () => array_values(array_unique(array_merge(
            \App\Models\Brand::query()->orderByDesc('id')->limit(30)->pluck('name')->all(),
            // `id` after `name`: this is a LIMIT, and category names are not
            // unique across the tree.
            \App\Models\Category::query()->orderBy('name')->orderBy('id')->limit(30)->pluck('name')->all(),
        ))));

        return response()->json(['tabs' => $tabs, 'suggestions' => $suggestions]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(HeaderSettings::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $this->header->save($data['settings']);

        // The header sits inside every cached page.
        foreach (['kbb.nav.primary', 'kbb.count.products', 'kbb.home.rails'] as $key) {
            Cache::forget($key);
        }

        return response()->json(['ok' => true, 'saved' => count($data['settings']), 'values' => $this->header->all()]);
    }
}

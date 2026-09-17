<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        $values = $this->header->all();
        $fields = [];

        foreach (HeaderSettings::SCHEMA as $key => $def) {
            [$type, $label, $default, $help] = array_pad($def, 4, '');

            $fields[$key] = [
                'key' => $key, 'type' => $type, 'label' => $label, 'help' => $help,
                'default' => $default, 'value' => $values[$key], 'options' => $def[4] ?? null,
            ];
        }

        $tabs = [];

        foreach (HeaderSettings::TABS as $key => [$label, $description, $keys]) {
            $tabs[] = [
                'key' => $key, 'label' => $label, 'description' => $description,
                'fields' => array_values(array_filter(array_map(fn ($k) => $fields[$k] ?? null, $keys))),
            ];
        }

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

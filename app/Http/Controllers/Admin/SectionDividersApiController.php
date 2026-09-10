<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\HomepageSections;
use App\Services\SectionDividers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Appearance → Section dividers. */
class SectionDividersApiController extends Controller
{
    public function __construct(private SectionDividers $dividers) {}

    public function show(): JsonResponse
    {
        $values = $this->dividers->all();
        $fields = [];

        foreach (SectionDividers::SCHEMA as $key => $def) {
            [$type, $label, $default, $help] = array_pad($def, 4, '');

            $fields[$key] = [
                'key' => $key, 'type' => $type, 'label' => $label, 'help' => $help,
                'default' => $default, 'value' => $values[$key], 'options' => $def[4] ?? null,
            ];
        }

        $tabs = [];

        foreach (SectionDividers::TABS as $key => [$label, $description, $keys]) {
            $tabs[] = [
                'key' => $key, 'label' => $label, 'description' => $description,
                'fields' => array_values(array_filter(array_map(fn ($k) => $fields[$k] ?? null, $keys))),
            ];
        }

        // The section list for the picker, in the order they appear on the page.
        $sections = [];

        foreach (HomepageSections::REGISTRY as $key => [$label]) {
            $sections[] = ['key' => $key, 'label' => $label];
        }

        return response()->json([
            'tabs' => $tabs,
            'sections' => $sections,
            // What a random setting resolved to for this request, so the screen
            // can say which one is showing rather than leaving it a mystery.
            'resolved' => $this->dividers->style(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(SectionDividers::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $this->dividers->save($data['settings']);

        return response()->json(['ok' => true]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
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
        // One schema, drawn by one renderer. This loop used to be copied
        // into nine controllers that had to agree by hand.
        $tabs = ModuleSchema::tabs(
            SectionDividers::SCHEMA,
            SectionDividers::TABS,
            $this->dividers->all(),
            SectionDividers::POLICY, SectionDividers::overrides(),
        );

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

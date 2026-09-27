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

        /*
         * ── ONE FIELD IS INERT WHILE ANOTHER SCREEN'S SWITCH IS ON ──────────
         *
         * `max_width` is read by HeaderSettings::maxWidthCss() ONLY when
         * Appearance → Site layout → Page width → "Header follows the site
         * width" is off, and that switch ships ON. Dragging it on the shipped
         * shop therefore does nothing at all, which is what the owner reported:
         * "we have this option, but header remains still same width".
         *
         * Marked here rather than in SCHEMA because it is not a property of the
         * field — it is a property of the shop right now, and it changes the
         * moment the other screen is saved. The schema states what the field IS;
         * this states whether it is currently doing anything.
         *
         * The reason travels with the flag rather than being written into the
         * console, so the screen stays a renderer and the two cannot drift.
         */
        $follows = (bool) app(\App\Services\SiteLayout::class)->get('header_follows');

        foreach ($tabs as &$tab) {
            foreach ($tab['fields'] as &$field) {
                if (($field['key'] ?? '') !== 'max_width') {
                    continue;
                }

                $field['inert'] = $follows;
                $field['inert_why'] = $follows
                    ? 'Not in use — the header is following the site width. Turn that off on Appearance → Site layout → Page width to set the header\'s own width here.'
                    : '';
            }

            unset($field);
        }

        unset($tab);

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

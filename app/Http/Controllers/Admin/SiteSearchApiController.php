<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\HeaderSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store → Site Search.
 *
 * The search field's own presence lived under Appearance → Header, next to
 * things like bar height and logo colour — reasonable when it was three
 * settings, not once it has its own extended-matching logic and its own
 * colours. This screen owns everything search now; Header no longer has a
 * Search tab. Storage is unchanged — still the same HeaderSettings-backed
 * fields, just edited from here.
 */
class SiteSearchApiController extends Controller
{
    /** tab key => [label, description, field keys] */
    private const TABS = [
        'search' => ['Search', 'What triggers a suggestion, and what the panel shows.',
            ['search_show', 'search_text', 'search_min_chars', 'search_panel', 'search_results_max',
                'search_limit_categories', 'search_limit_brands', 'search_row_size', 'search_group_rule',
                'search_row_rule', 'search_native_clear', 'search_brands_phone', 'search_recent_count',
                'trending_show', 'trending_words', 'trending_limit', 'trending_limit_mobile', 'trending_hide']],
        'extended' => ['Extended Search Results', 'Recognise a brand name in the query and match accordingly.',
            ['search_extended_enabled', 'search_extended_strict_brand',
                'search_extended_partial_brand_match', 'search_extended_broaden_others']],
        'styles' => ['Search styles & colors', 'Colours and shape for the search panel.',
            ['search_radius', 'search_style_accent', 'search_style_accent_deep',
                'search_style_chip_bg', 'search_style_chip_text', 'search_style_radius']],
    ];

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

        foreach (self::TABS as $key => [$label, $description, $keys]) {
            $tabs[] = [
                'key' => $key, 'label' => $label, 'description' => $description,
                'fields' => array_values(array_filter(array_map(fn ($k) => $fields[$k] ?? null, $keys))),
            ];
        }

        return response()->json(['tabs' => $tabs]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(HeaderSettings::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $this->header->save($data['settings']);

        return response()->json(['ok' => true, 'saved' => count($data['settings']), 'values' => $this->header->all()]);
    }
}

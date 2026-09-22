<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\HeaderSettings;
use App\Services\MobileHeader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Appearance → Mobile Header. */
class MobileHeaderApiController extends Controller
{
    public function __construct(private MobileHeader $header, private HeaderSettings $site) {}

    public function show(): JsonResponse
    {
        $values = $this->header->all();
        $fields = [];

        foreach (MobileHeader::SCHEMA as $key => $def) {
            [$type, $label, $default, $help] = array_pad($def, 4, '');

            $fields[$key] = [
                'key' => $key, 'type' => $type, 'label' => $label, 'help' => $help,
                'default' => $default, 'value' => $values[$key], 'options' => $def[4] ?? null,
            ];
        }

        $tabs = [];

        foreach (MobileHeader::TABS as $key => [$label, $description, $keys]) {
            $tabs[] = [
                'key' => $key, 'label' => $label, 'description' => $description,
                'fields' => array_values(array_filter(array_map(fn ($k) => $fields[$k] ?? null, $keys))),
            ];
        }

        /*
         * THE PREVIEW HAS TO KNOW WHAT THE STOREFRONT ACTUALLY DRAWS.
         *
         * "Trending words" is a size control HERE, but whether the row exists
         * at all is HeaderSettings' `trending_show`, which is OFF by default —
         * its own help text says the chips appear in the search panel instead.
         * Drawing a trending row in the preview unconditionally would show
         * every shop a row most of them do not have, which is the same class
         * of bug as a slider that moves nothing: the screen would be lying,
         * just in the other direction.
         *
         * So the flag travels with the payload and the preview draws the row
         * only when the storefront would. One boolean, read-only, on an
         * admin-authenticated route that already returns every header setting.
         */
        return response()->json([
            'tabs' => $tabs,
            'context' => ['trending' => (bool) $this->site->get('trending_show')],
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(MobileHeader::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $this->header->save($data['settings']);

        return response()->json(['ok' => true]);
    }
}

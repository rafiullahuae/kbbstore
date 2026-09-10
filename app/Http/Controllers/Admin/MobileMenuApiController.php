<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MobileMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Appearance → Mobile menu. */
class MobileMenuApiController extends Controller
{
    public function __construct(private MobileMenu $menu) {}

    public function show(): JsonResponse
    {
        $values = $this->menu->all();
        $fields = [];

        foreach (MobileMenu::SCHEMA as $key => $def) {
            [$type, $label, $default, $help] = array_pad($def, 4, '');

            $fields[] = [
                'key' => $key,
                'type' => $type,
                'label' => $label,
                'help' => $help,
                'default' => $default,
                'value' => $values[$key],
                'options' => $def[4] ?? null,
            ];
        }

        return response()->json([
            'fields' => $fields,
            'groups' => [
                ['key' => 'panel', 'label' => 'Panel', 'description' => 'Size and motion of the sheet.',
                 'fields' => ['icon_style', 'height', 'radius', 'slide_speed', 'scrim', 'show_grab', 'show_close']],
                ['key' => 'top', 'label' => 'Top of the sheet', 'description' => 'What sits above the menu itself.',
                 'fields' => ['show_search', 'search_text', 'show_heading', 'heading_text']],
                ['key' => 'rows', 'label' => 'Rows', 'description' => 'Density and layout of the items.',
                 'fields' => ['density', 'child_columns', 'show_counts', 'single_open']],
                ['key' => 'open', 'label' => 'Open section', 'description' => 'How an expanded parent is marked.',
                 'fields' => ['card_style', 'rule_position', 'rule_width', 'rule_colour', 'card_bg', 'parent_bg', 'parent_colour']],
                ['key' => 'foot', 'label' => 'Foot of the sheet', 'description' => 'Support and account links.',
                 'fields' => ['show_support', 'support_text', 'show_account', 'account_label']],
            ],
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(MobileMenu::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $this->menu->save($data['settings']);

        // The sheet renders inside cached pages, so the nav cache must go too.
        Cache::forget('kbb.nav.primary');
        Cache::forget('kbb.nav.mobile');

        return response()->json(['ok' => true, 'saved' => count($data['settings']), 'values' => $this->menu->all()]);
    }
}

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

        /*
         * THE GROUPS ARE MobileMenu::TABS NOW, not a second list written here.
         *
         * They were inline in this method, which is what kept this module out
         * of ModuleFrameworkGuardTest: with the only copy of the grouping in a
         * controller there was nothing to check the schema against, so a
         * setting stored with no control to write it — the exact defect that
         * guard caught on cart_panel's `accent` — would have gone unnoticed
         * here. The constant is checked against SCHEMA on every run.
         *
         * THE PAYLOAD IS UNCHANGED, deliberately. This screen answers `fields`
         * + `groups`, where a group names its fields by KEY, rather than the
         * `tabs` shape ModuleSchema::tabs() builds — that is a different
         * contract with a different renderer in the console, and swapping it
         * would move a screen this change is not about. So the constant is read
         * here in the shape this endpoint has always sent.
         */
        $groups = [];

        foreach (MobileMenu::TABS as $key => [$label, $description, $keys]) {
            $groups[] = ['key' => $key, 'label' => $label, 'description' => $description, 'fields' => $keys];
        }

        return response()->json(['fields' => $fields, 'groups' => $groups]);
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

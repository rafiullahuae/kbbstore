<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AccountPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Appearance → Login / Register panel. */
class AccountPanelApiController extends Controller
{
    public function __construct(private AccountPanel $panel) {}

    public function show(): JsonResponse
    {
        $values = $this->panel->all();
        $fields = [];

        foreach (AccountPanel::SCHEMA as $key => $def) {
            [$type, $label, $default, $help] = array_pad($def, 4, '');

            $fields[$key] = [
                'key' => $key, 'type' => $type, 'label' => $label, 'help' => $help,
                'default' => $default, 'value' => $values[$key], 'options' => $def[4] ?? null,
            ];
        }

        $tabs = [];

        foreach (AccountPanel::TABS as $key => [$label, $description, $keys]) {
            $tabs[] = [
                'key' => $key, 'label' => $label, 'description' => $description,
                'fields' => array_values(array_filter(array_map(fn ($k) => $fields[$k] ?? null, $keys))),
            ];
        }

        // The preview needs the real stacks, so it cannot drift from the storefront.
        $fonts = [];

        foreach (AccountPanel::FONTS as $key => [$name, $stack, $weight]) {
            $fonts[$key] = ['name' => $name, 'stack' => $stack, 'weight' => $weight];
        }

        return response()->json(['tabs' => $tabs, 'fonts' => $fonts]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(AccountPanel::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $this->panel->save($data['settings']);
        Cache::forget('kbb.nav.primary');

        return response()->json(['ok' => true, 'saved' => count($data['settings']), 'values' => $this->panel->all()]);
    }
}

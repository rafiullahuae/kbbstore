<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
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
        // One schema, drawn by one renderer. This loop used to be copied
        // into nine controllers that had to agree by hand.
        $tabs = ModuleSchema::tabs(
            AccountPanel::SCHEMA,
            AccountPanel::TABS,
            $this->panel->all(),
            AccountPanel::POLICY,
        );

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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
use App\Services\CartPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Appearance → Cart panel. */
class CartPanelApiController extends Controller
{
    public function __construct(private CartPanel $panel) {}

    public function show(): JsonResponse
    {
        // One schema, drawn by one renderer. This loop used to be copied
        // into nine controllers that had to agree by hand.
        $tabs = ModuleSchema::tabs(
            CartPanel::SCHEMA,
            CartPanel::TABS,
            $this->panel->all(),
            CartPanel::POLICY,
        );

        return response()->json(['tabs' => $tabs]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(CartPanel::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $this->panel->save($data['settings']);

        return response()->json(['ok' => true]);
    }
}

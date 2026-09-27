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

        /*
         * `touch` and the two breakpoints are sent rather than repeated in the
         * screen's JavaScript, because they are facts about the SHOP and a second
         * copy of a fact is a copy that goes stale. The screen turns the help
         * text under a `touch` slider warm below 44 and prints the two widths on
         * the previews' rulers.
         *
         * NOTHING HERE IS A THRESHOLD THE SERVER ENFORCES. `min` in the schema is
         * below 44 on all four keys on purpose — the owner asked to squeeze his
         * own tap targets, and a slider that stops where nobody asked it to stop
         * reads as a bug. See CartPanel::TOUCH_TARGETS.
         */
        return response()->json([
            'tabs' => $tabs,
            'touch' => CartPanel::TOUCH_TARGETS,
            'touchMin' => 44,
            'phoneMax' => 680,
            'tapMax' => 900,
        ]);
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

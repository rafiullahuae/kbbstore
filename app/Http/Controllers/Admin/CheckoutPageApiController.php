<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CheckoutPage;
use App\Services\ModuleSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → Checkout page.
 *
 * The field/tab loop is the one every module API controller in this project
 * has (see CartPageApiController, CartPanelApiController) and is kept in that
 * shape deliberately: the admin screen that draws it is generic, and a
 * controller answering in a different shape would need a renderer of its own.
 *
 * Two endpoints and no third. Unlike the cart page this screen picks no
 * products, so there is no catalogue search here and nothing that reads a
 * model — every value that crosses this boundary is an integer or a boolean
 * from a schema both sides already know.
 */
class CheckoutPageApiController extends Controller
{
    public function __construct(private CheckoutPage $page) {}

    public function show(): JsonResponse
    {
        // One schema, drawn by one renderer. This was the same fifteen lines
        // that nine other controllers carried, and the reason the three
        // constants a screen depends on could disagree without anything saying
        // so. `POLICY` travels with the schema because ModuleSchema's cast is
        // strict by default and this screen is not — see CheckoutPage::POLICY.
        $tabs = ModuleSchema::tabs(
            CheckoutPage::SCHEMA,
            CheckoutPage::TABS,
            $this->page->all(),
            CheckoutPage::POLICY,
        );

        return response()->json([
            'tabs' => $tabs,
            // The screen draws its own preview and has to know where the page
            // changes surface, so it is told rather than left to hard-code 900
            // a third time.
            'mobileMax' => CheckoutPage::MOBILE_MAX,
            // The keys "Squeeze everything" drives to their minimum. Sent
            // rather than repeated in the screen's script: the list belongs
            // beside the schema it names, and a second copy would be a second
            // thing to forget when a control is added.
            'squeeze' => CheckoutPage::SQUEEZE,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(CheckoutPage::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        $this->page->save($data['settings']);

        return response()->json(['ok' => true]);
    }
}

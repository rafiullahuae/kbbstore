<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CheckoutPage;
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
        $values = $this->page->all();
        $fields = [];

        foreach (CheckoutPage::SCHEMA as $key => $def) {
            [$type, $label, $default, $help] = array_pad($def, 4, '');

            $fields[$key] = [
                'key' => $key, 'type' => $type, 'label' => $label, 'help' => $help,
                'default' => $default, 'value' => $values[$key], 'options' => $def[4] ?? null,
            ];
        }

        $tabs = [];

        foreach (CheckoutPage::TABS as $key => [$label, $description, $keys]) {
            $tabs[] = [
                'key' => $key, 'label' => $label, 'description' => $description,
                'fields' => array_values(array_filter(array_map(fn ($k) => $fields[$k] ?? null, $keys))),
            ];
        }

        return response()->json([
            'tabs' => $tabs,
            // The screen draws its own preview and has to know where the page
            // changes surface, so it is told rather than left to hard-code 900
            // a third time.
            'mobileMax' => CheckoutPage::MOBILE_MAX,
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

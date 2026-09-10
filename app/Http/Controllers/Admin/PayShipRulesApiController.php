<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PayShipRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Store → Payment & Shipping Rules. */
class PayShipRulesApiController extends Controller
{
    public function __construct(private PayShipRules $rules) {}

    public function show(): JsonResponse
    {
        $values = $this->rules->all();
        $fields = [];

        foreach (PayShipRules::SCHEMA as $key => $def) {
            [$type, $label, $default, $help] = array_pad($def, 4, '');

            $fields[$key] = [
                'key' => $key, 'type' => $type, 'label' => $label, 'help' => $help,
                'default' => $default, 'value' => $values[$key],
            ];
        }

        $tabs = [];

        foreach (PayShipRules::TABS as $key => [$label, $description, $keys]) {
            $tabs[] = [
                'key' => $key, 'label' => $label, 'description' => $description,
                'fields' => array_values(array_filter(array_map(fn ($k) => $fields[$k] ?? null, $keys))),
            ];
        }

        return response()->json([
            'tabs' => $tabs,
            'module_on' => app(\App\Services\SettingsService::class)->moduleEnabled('pay_ship_rules', false),
            'currency' => (string) app(\App\Services\SettingsService::class)->get('currency_symbol', 'د.إ'),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(PayShipRules::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $this->rules->save($data['settings']);

        return response()->json(['ok' => true]);
    }
}

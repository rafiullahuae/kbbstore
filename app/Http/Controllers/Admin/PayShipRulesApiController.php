<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
use App\Services\PayShipRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Store → Payment & Shipping Rules. */
class PayShipRulesApiController extends Controller
{
    public function __construct(private PayShipRules $rules) {}

    public function show(): JsonResponse
    {
        // The field and tab payloads are ModuleSchema's job now — this loop was
        // one of the two copies of it that had to agree by hand.
        $tabs = ModuleSchema::tabs(PayShipRules::SCHEMA, PayShipRules::TABS, $this->rules->all());

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

        // A value the schema refuses is reported, not clamped. The previous
        // save() ran `max(0, (int) $value)` over the two money fields, so a
        // mistyped "12.50" became 12 fils and the screen said Saved — the
        // hundredfold error EcommerceApiController::castFils() already refuses
        // for the same reason, now refused here by the same rule.
        $rejected = $this->rules->save($data['settings']);

        if ($rejected !== []) {
            return response()->json([
                'ok' => false,
                'error' => '“' . implode('”, “', $rejected) . '” is not a valid value.',
            ], 422);
        }

        return response()->json(['ok' => true]);
    }
}

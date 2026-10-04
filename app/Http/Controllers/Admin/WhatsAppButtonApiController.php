<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
use App\Services\WhatsAppButton;
use App\Support\Locale;
use App\Support\SupportContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → WhatsApp button.                                     (Lane WA)
 *
 * The fields are drawn by ModuleSchema::tabs() and saved through
 * WhatsAppButton::save(), which casts every one against the same schema the
 * screen was drawn from, so there is no field list here to drift.
 *
 * ONE READ FEEDS THE WHOLE LIVE PREVIEW. Alongside the tabs it sends the
 * stylesheet for all seven designs, the icon and the two face symbols — the
 * very constants the storefront partial prints — plus the standard wording in
 * both languages. The screen builds the preview from those, so dragging the
 * size bar, switching design or typing a line never goes back to the server
 * (CLAUDE.md, "super light": nothing the browser can build from data it
 * already has goes back to the server).
 *
 * NOTHING IS FLUSHED ON SAVE. The button is rendered per request from
 * settings SettingsService::set() already invalidates; no cached storefront
 * fragment carries any of it.
 */
class WhatsAppButtonApiController extends Controller
{
    public function __construct(private WhatsAppButton $button) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(WhatsAppButton::SCHEMA));

        if ($unknown !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown setting: '.implode(', ', $unknown),
            ], 422);
        }

        $result = $this->button->save($data['settings']);

        if ($result['rejected'] !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'Not saved — check: '.implode(', ', $result['rejected']),
                'rejected' => array_keys($result['rejected']),
                'values' => $this->button->all(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'saved' => count($result['written']),
            'values' => $this->button->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $values = $this->button->all();

        return [
            'tabs' => ModuleSchema::tabs(
                WhatsAppButton::SCHEMA,
                WhatsAppButton::TABS,
                $values,
                WhatsAppButton::POLICY,
                WhatsAppButton::overrides(),
            ),
            'preview' => [
                'css' => WhatsAppButton::cssAll(),
                'icon' => WhatsAppButton::ICON,
                'symbols' => implode('', WhatsAppButton::SYMBOLS),
                'speeds' => WhatsAppButton::SPEEDS,
                'phone' => SupportContact::whatsapp(),
                'digits' => SupportContact::whatsappDigits(),
                'rtl' => Locale::rtlEnabled(),
                // The standard wording each language falls back to, as the
                // shop would print it today: the Arabic is an APPROVED
                // translation or, until there is one, the English standard.
                'standard' => [
                    'en' => $this->standard('en'),
                    'ar' => $this->standard('ar'),
                ],
            ],
        ];
    }

    /** @return array<string, string> */
    private function standard(string $locale): array
    {
        $out = [];

        foreach (WhatsAppButton::KEYS as $name => $key) {
            $out[$name] = (string) __($key, [], $locale);
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
use App\Services\UgcRail;
use App\Services\UgcSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → Video rail.
 *
 * ONE SCHEMA, DRAWN BY ONE RENDERER. This whole show() body used to be fifteen
 * lines copied into nine controllers that had to agree by hand — see
 * docs/M-PHASE3-SETTINGS-SCHEMA.md, and the sixteen colour fields across four
 * modules that each stored a hex with no `#` because there were four copies of
 * the same three lines and nothing tied them together.
 *
 * So there is no cast() here, no field loop and no validation list: every value
 * goes through ModuleSchema::write(), which is the one security boundary for
 * every migrated module. Rule 5's "a select stores one of its own options or the
 * default" is that method's job, applied from UgcSettings::POLICY.
 */
class UgcAppearanceController extends Controller
{
    public function __construct(private UgcSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'tabs' => ModuleSchema::tabs(
                UgcSettings::SCHEMA,
                UgcSettings::TABS,
                $this->settings->all(),
                UgcSettings::POLICY,
            ),
            /*
             * SAID ON THE SCREEN, because every control here is inert without it
             * and "I moved the sliders and nothing happened" is the support call
             * this one line prevents.
             */
            'module_on' => $this->settings->enabled(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(UgcSettings::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        $result = $this->settings->save($data['settings']);

        /*
         * The rail cache holds CONTENT and not settings — UgcRail's header says so
         * and that is deliberate, so moving a slider takes effect on the next page
         * load with nothing to clear. It is flushed anyway, and the reason is
         * `metrics_age`: the age gate is applied outside the cache, but a future
         * setting that did reach the payload would be a silent ten-minute lie, and
         * a flush on a screen somebody saves by hand costs nothing at all.
         */
        UgcRail::flush();

        return response()->json([
            'ok' => true,
            'written' => $result['written'],
            // A refused value is REPORTED rather than dropped in silence. A save
            // that quietly discarded a bad number is the fault ModuleSchema exists
            // to remove.
            'rejected' => $result['rejected'],
        ]);
    }
}

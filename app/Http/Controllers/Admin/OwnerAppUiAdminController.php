<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\OwnerApp\AppController;
use App\Services\OwnerApp\OwnerAppSettings;
use App\Services\OwnerApp\OwnerAppUi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

/**
 * Platform → Users & Roles → Owner app → Customise app (Lane OA4).
 *
 * Capability `ownerapp.manage` — AdminCapabilities::RULES maps every path
 * under admin-api/owner-app/** to it, Full Admin only, failing closed. One
 * GET (what is saved, the defaults, the options and the app's stylesheet for
 * the live preview) and one PUT that writes the whole card at once.
 */
final class OwnerAppUiAdminController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(self::payload());
    }

    public function save(Request $request): JsonResponse
    {
        $errors = OwnerAppUi::put((array) $request->json()->all());
        if ($errors !== null) {
            return response()->json(['ok' => false, 'message' => reset($errors), 'errors' => array_map(fn ($m) => [$m], $errors)], 422);
        }

        return response()->json(self::payload());
    }

    /** @return array<string,mixed> */
    private static function payload(): array
    {
        $css = '';
        try {
            $css = Vite::asset(AppController::CSS);
        } catch (\Throwable) {
        }

        return [
            'ok' => true,
            'ui' => OwnerAppUi::all(),
            'defaults' => OwnerAppUi::defaults(),
            'options' => [
                'presets' => OwnerAppUi::PRESETS,
                'screens' => OwnerAppUi::SCREENS,
                'sections' => OwnerAppUi::SECTIONS,
                'functions' => OwnerAppUi::FUNCTIONS,
                'choices' => OwnerAppUi::CHOICES,
                'live' => OwnerAppUi::LIVE_BOUNDS,
                'min_contrast' => OwnerAppUi::MIN_CONTRAST,
            ],
            // "Show loading bars after (minutes)" stays in the Settings card; shown here for the link.
            'stale_minutes' => OwnerAppSettings::staleMinutes(),
            'css' => $css,
        ];
    }
}

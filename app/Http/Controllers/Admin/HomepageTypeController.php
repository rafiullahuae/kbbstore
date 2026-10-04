<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use App\Support\SectionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /admin-api/homepage-hub/type — one section's Fonts & size tab.
 *                                                                 (Lane FS)
 *
 * The ONLY writer of `homepage_section_type`. Capability `homepagehub.type`
 * (App\Support\AdminCapabilities), so a role without it is refused before this
 * runs; an unmapped path would fall through to owner-only, which is closed.
 *
 * Refuses rather than clamps: an unknown section, a control the section does
 * not have, a font outside the library or a size outside its range is a 422
 * that names the control, and NOTHING of that post is stored — a half-saved
 * tab would be a page that matches neither what the owner saw nor what he had.
 */
final class HomepageTypeController extends Controller
{
    public function save(Request $request, SettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'values' => ['present', 'array', 'max:20'],
        ]);

        $key = $data['key'];

        if (! SectionType::applies($key)) {
            return response()->json(['ok' => false, 'message' => 'That section has no Fonts & size tab.'], 422);
        }

        $all = $settings->all();
        $stored = is_array($all[SectionType::SETTING] ?? null) ? $all[SectionType::SETTING] : [];
        [$clean, $errors] = SectionType::clean($key, is_array($stored[$key] ?? null) ? $stored[$key] : [], $data['values']);

        if ($errors !== []) {
            return response()->json(['ok' => false, 'message' => 'Not saved: '.implode(' ', array_map(
                fn ($k, $m) => $k.' — '.$m, array_keys($errors), $errors)), 'errors' => array_map(fn ($m) => [$m], $errors)], 422);
        }

        if ($clean === []) {
            unset($stored[$key]);
        } else {
            $stored[$key] = $clean;
        }

        $settings->set(SectionType::SETTING, $stored);

        return response()->json([
            'ok' => true,
            'key' => $key,
            'values' => $clean,
            'fields' => SectionType::fields($key, $clean, \App\Support\HomeSections::settings()),
        ]);
    }
}

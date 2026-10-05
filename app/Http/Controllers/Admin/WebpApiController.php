<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Media\WebpBulk;
use App\Services\Media\WebpSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Content -> Media Library -> WebP images. (Lane WP)
 *
 * Every endpoint is one bounded batch (WebpBulk::MAX_FILES files or
 * MAX_SECONDS of work) and hands back whether there is more, so the screen
 * drives a run with one request per batch and stops on `done` — never a
 * polling loop, never a request that can outlive max_execution_time.
 *
 * Behind `media.optimize` (owner, manager) through AdminCapabilities, which
 * fails closed: a path the map does not know is owner-only.
 *
 * Nothing here returns an absolute server path. Paths are web-root relative
 * (`uploads/…`), which is what the media library already shows.
 */
class WebpApiController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json(['ok' => true] + WebpBulk::status());
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'keep_original' => ['sometimes', 'boolean'],
            'quality' => ['sometimes', 'integer', 'between:'.WebpSettings::QUALITY_MIN.','.WebpSettings::QUALITY_MAX],
            'max_width' => ['sometimes', 'integer', 'min:0', 'max:'.WebpSettings::WIDTH_MAX],
        ]);

        return response()->json(['ok' => true, 'settings' => WebpSettings::save($data)]);
    }

    public function plan(Request $request): JsonResponse
    {
        $request->validate(['after' => ['nullable', 'string', 'max:255']]);

        return response()->json(['ok' => true] + WebpBulk::plan((string) $request->input('after', '')));
    }

    public function run(): JsonResponse
    {
        return $this->batch(WebpBulk::run());
    }

    public function restore(Request $request): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'in:UNDO']]);

        return $this->batch(WebpBulk::restore());
    }

    public function removeOriginals(Request $request): JsonResponse
    {
        $request->validate([
            'confirm' => ['required', 'in:REMOVE'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->batch(WebpBulk::removeOriginals((int) $request->input('after', 0)));
    }

    private function batch(array $result): JsonResponse
    {
        if (isset($result['error'])) {
            return response()->json(['ok' => false, 'message' => $result['error']] + $result, ! empty($result['busy']) ? 409 : 422);
        }

        return response()->json(['ok' => true] + $result);
    }
}

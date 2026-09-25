<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use App\Support\ReviewSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Store → Reviews → Review Settings.
 *
 * ADMIN ONLY, AND THAT IS NOT INCIDENTAL. These routes live in
 * routes/review-settings-admin.php, which the integrator mounts inside the
 * `admin-api` group in routes/web.php — the one already wrapped in
 * `auth:admin`. Nothing here may move to routes/api.php: CLAUDE.md records
 * that everything under /api/* is unauthenticated by design.
 *
 * NOTHING THIS SCREEN WRITES REACHES /api/*. The settings govern the product
 * page's own review section and the submit endpoint's limits; Api\ReviewController
 * keeps its own PUBLIC_COLUMNS allowlist and is not read from here, so no
 * setting on this screen can widen what the public endpoint returns. A test
 * pins that directly rather than leaving it to be re-derived
 * (tests/Feature/ReviewSettingsScreenTest.php).
 *
 * The response is the whole normalised set plus the option lists the screen
 * draws, so the front end never carries a second copy of the defaults. A
 * screen that knows its own defaults is a screen that can disagree with the
 * storefront about what "unset" means, which is how a control ends up
 * reading back its own value and looking fine while the page ignores it.
 */
class ReviewSettingsApiController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => ReviewSettings::all($this->settings),
            'sorts' => ReviewSettings::SORTS,
            'photo_ceiling' => ReviewSettings::PHOTO_CEILING,
            'text_max' => ReviewSettings::TEXT_MAX,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /*
         * Validated against the schema, then normalised through the SAME
         * ReviewSettings::normalise() the storefront reads with — so a value
         * that survives this method is a value the page will honour, and the
         * screen cannot store something the readers would silently clamp to
         * something else. Every field is `sometimes`: the screen saves the
         * whole form, but a partial save from a narrower caller is a legal
         * request and must not reset the fields it did not mention.
         */
        $rules = [];

        /*
         * READ OFF THE SCHEMA, not off a second copy of the bounds — and the
         * schema is App\Services\ModuleSchema's shape as of Lane M3, so the
         * clamp lives in `options` and the type of a picker is `select` rather
         * than `enum`. The rules built here are unchanged: same keys, same
         * bounds, same messages. Only where the numbers are read from moved.
         */
        foreach (ReviewSettings::SCHEMA as $key => $def) {
            $type = $def['type'];
            $min = $def['options']['min'] ?? null;
            $max = $def['options']['max'] ?? null;

            $rules[$key] = match ($type) {
                'bool' => ['sometimes', 'boolean'],
                'int' => ['sometimes', 'integer', 'min:' . $min, 'max:' . $max],
                'select' => ['sometimes', 'string', Rule::in(array_keys(ReviewSettings::SORTS))],
                /*
                 * `nullable`, and that is not defensive padding. Laravel's
                 * global TrimStrings and ConvertEmptyStringsToNull middleware
                 * run before validation, so an owner who selects the
                 * empty-state line and deletes it sends "   " and the
                 * controller receives NULL — which a bare `string` rule
                 * refuses with "The sr empty text field must be a string."
                 * Clearing the box is a legitimate request meaning "put the
                 * default back", and ReviewSettings::normalise() does exactly
                 * that with an empty value. Found by test, not by reading.
                 */
                default => ['sometimes', 'nullable', 'string', 'max:' . ReviewSettings::TEXT_MAX],
            };
        }

        $validated = $request->validate($rules);

        foreach ($validated as $key => $value) {
            $this->settings->set($key, ReviewSettings::normalise($key, $value));
        }

        return response()->json([
            'ok' => true,
            'settings' => ReviewSettings::all($this->settings),
        ]);
    }
}

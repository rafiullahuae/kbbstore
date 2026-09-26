<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
use App\Services\SiteLayout;
use App\Support\Shortcodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Appearance → Site layout.
 *
 * Nine controls, drawn by ModuleSchema::tabs() and saved through
 * SiteLayout::save(), which casts every one of them against the same schema the
 * screen was drawn from. There is no field list in this file and no cast in it:
 * a control this endpoint would accept but the screen would not draw, or the
 * other way round, is not expressible.
 *
 * WHAT A SAVE HAS TO INVALIDATE, and it is more than it looks.
 *
 * Nothing here is cached by this screen, but two other caches hold RENDERED
 * HTML that carries a grid in it: `Support\Shortcodes` caches the output of
 * `[kbb_products]`, and `kbb.home.rails` holds the homepage rails. Both bake
 * `<div class="kbb-pgrid">` into a string, so a column setting that changed
 * without clearing them would show on a category page and not on the home page,
 * which reads as "the setting half works" rather than as a stale cache. Copied
 * from ProductStylesApiController, which flushes the same two for the same
 * reason.
 */
class SiteLayoutApiController extends Controller
{
    public function __construct(private SiteLayout $layout) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'tabs' => ModuleSchema::tabs(
                SiteLayout::SCHEMA,
                SiteLayout::TABS,
                $this->layout->all(),
                SiteLayout::POLICY,
                SiteLayout::overrides(),
            ),
            /*
             * The stylesheet this shop is currently sending, verbatim, so the
             * screen can show what it is doing and a reader can see that a shop
             * at its defaults sends NOTHING. An empty string here is the
             * feature, not a missing value: see SiteLayout::cssVariables().
             */
            'css' => $this->layout->css(),
            'is_default' => $this->layout->isDefault(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(SiteLayout::SCHEMA));

        if ($unknown !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown setting: '.implode(', ', $unknown),
            ], 422);
        }

        $result = $this->layout->save($data['settings']);

        /*
         * A REFUSED VALUE IS REPORTED, NOT SWALLOWED. save() returns the keys
         * it would not store, and this is the only place that can tell the
         * owner. A 422 with the labels is the difference between "Site width
         * did not save" and a screen that says Saved and shows a number the
         * shop is not using.
         */
        if ($result['rejected'] !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'Could not save: '.implode(', ', $result['rejected']),
                'rejected' => array_keys($result['rejected']),
                'values' => $this->layout->all(),
            ], 422);
        }

        Shortcodes::flush();
        Cache::forget('kbb.home.rails');

        return response()->json([
            'ok' => true,
            'saved' => count($result['written']),
            'values' => $this->layout->all(),
            'css' => $this->layout->css(),
            'is_default' => $this->layout->isDefault(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
use App\Services\PageWash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → Page background.
 *
 * Nine controls, drawn by ModuleSchema::tabs() and saved through
 * PageWash::save(), which casts every one of them against the same schema the
 * screen was drawn from. There is no field list in this file and no cast in it:
 * a control this endpoint would accept but the screen would not draw, or the
 * other way round, is not expressible.
 *
 * NOTHING IS FLUSHED ON SAVE, and that is worth a line because the two screens
 * this one is modelled on both flush two caches. SiteLayoutApiController clears
 * `Support\Shortcodes` and `kbb.home.rails` because a column count is baked
 * into the RENDERED HTML those caches hold. Nothing this screen writes reaches
 * any markup: the whole of its output is one `<style>` element assembled per
 * request in the layout, from settings that `Setting::map()` already caches.
 * Clearing the rail cache here would be cargo-culting a fix for a problem this
 * screen does not have — and it would throw away a cache on every colour
 * fiddle, which is a real cost for no effect. PageWashTest pins the shape this
 * depends on: no cached storefront fragment carries any part of the wash.
 */
class PageWashApiController extends Controller
{
    public function __construct(private PageWash $wash) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'tabs' => ModuleSchema::tabs(
                PageWash::SCHEMA,
                PageWash::TABS,
                $this->wash->all(),
                PageWash::POLICY,
                PageWash::overrides(),
            ),
            /*
             * The four preview treatments, sent as data rather than hard-coded
             * into the screen. The screen's four buttons move the ordinary
             * sliders to these numbers and its four preview frames link to
             * `?kbbwash=<key>`; both read this array, so a treatment that is
             * changed in PageWash cannot fall out of step with the button that
             * applies it or the frame that shows it.
             */
            'treatments' => PageWash::TREATMENTS,
            'palettes' => PageWash::PALETTES,
            /*
             * The stylesheet this shop is currently sending, verbatim, so the
             * screen can show what it is doing and a reader can see that a shop
             * which has not switched the wash on sends NOTHING. An empty string
             * here is the feature, not a missing value.
             *
             * Built with a NULL request on purpose: this is the admin-api
             * request, so passing it through would let `where => content` test
             * the admin path rather than a storefront one and answer '' for the
             * wrong reason. A null request makes css() read the current one,
             * which on this endpoint is /admin-api/page-wash — not a plain
             * prefix — so the answer is the general one the storefront gets.
             */
            'css' => $this->wash->css(),
            'is_default' => $this->wash->isDefault(),
            'preview_param' => PageWash::PREVIEW_PARAM,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(PageWash::SCHEMA));

        if ($unknown !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown setting: '.implode(', ', $unknown),
            ], 422);
        }

        $result = $this->wash->save($data['settings']);

        /*
         * A REFUSED VALUE IS REPORTED, NOT SWALLOWED. save() returns the keys
         * it would not store, and this is the only place that can tell the
         * owner. A 422 with the labels is the difference between "the palette
         * did not save" and a screen that says Saved and shows a colour the
         * shop is not using.
         */
        if ($result['rejected'] !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'Could not save: '.implode(', ', $result['rejected']),
                'rejected' => array_keys($result['rejected']),
                'values' => $this->wash->all(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'saved' => count($result['written']),
            'values' => $this->wash->all(),
            'css' => $this->wash->css(),
            'is_default' => $this->wash->isDefault(),
        ]);
    }
}

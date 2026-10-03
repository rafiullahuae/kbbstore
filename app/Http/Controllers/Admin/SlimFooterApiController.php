<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
use App\Services\SiteFooter;
use App\Services\SlimFooter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → Footer.
 *
 * The field/tab loop is the one every module API controller in this project
 * has, kept in that shape deliberately: the admin screens that draw it are
 * generic, and a controller answering in a different shape would need a
 * renderer of its own.
 *
 * Two endpoints and nothing else. This screen picks no products and reads no
 * model — every value that crosses is a string, an integer or a boolean from a
 * schema both sides already know.
 *
 * ── TWO SCHEMAS ON ONE SCREEN (Lane HB) ─────────────────────────────────────
 *
 * The site footer on every page (App\Services\SiteFooter — the new design,
 * the switch back to the old one, the help strip, the addresses and the big
 * name) is drawn here too, in three tabs FIRST, because "Appearance → Footer"
 * is where the owner looks for the footer. Its keys all start `site_` and none
 * of SlimFooter's do, so one flat payload splits without ambiguity, and a key
 * belonging to neither is still refused with the same 422. Same endpoint, same
 * capability (`slimfooter.manage`): no new route.
 */
class SlimFooterApiController extends Controller
{
    public function __construct(private SlimFooter $footer) {}

    public function show(): JsonResponse
    {
        // One schema, drawn by one renderer. This loop used to be copied
        // into nine controllers that had to agree by hand.
        $tabs = array_merge(
            ModuleSchema::tabs(
                SiteFooter::SCHEMA,
                SiteFooter::TABS,
                app(SiteFooter::class)->all(),
                SiteFooter::POLICY,
            ),
            ModuleSchema::tabs(
                SlimFooter::SCHEMA,
                SlimFooter::TABS,
                $this->footer->all(),
                SlimFooter::POLICY,
            ),
        );

        /*
         * The squeeze list travels with the fields rather than being written
         * out again in the screen's JavaScript, for the reason CheckoutPage's
         * own screen states: two copies of a key list drift, and the copy that
         * drifts is the one in the file nobody opens.
         */
        return response()->json(['tabs' => $tabs, 'squeeze' => SlimFooter::SQUEEZE]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(
            array_keys($data['settings']),
            array_keys(SlimFooter::SCHEMA),
            array_keys(SiteFooter::SCHEMA),
        );

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        $site = array_intersect_key($data['settings'], SiteFooter::SCHEMA);

        $this->footer->save(array_diff_key($data['settings'], SiteFooter::SCHEMA));

        if ($site !== []) {
            app(SiteFooter::class)->save($site);
        }

        return response()->json(['ok' => true]);
    }
}

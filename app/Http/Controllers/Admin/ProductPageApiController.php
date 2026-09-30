<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
use App\Services\ProductLayout;
use App\Services\ProductSections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → Product page: which modules render, and how the page is laid out.
 *
 * ── TWO HALVES ON ONE SCREEN, AND ONE ROUTE ────────────────────────────  R4 ──
 *
 * `sections` is what this endpoint has always answered: a switch per device for
 * each entry of ProductSections::REGISTRY.
 *
 * `layout` is new (Lane PDP2 round 4) and is the owner's own request —
 * *"i have control on the product page spacing between sections and elements
 * etc. and fonts sizes control etc. pleas give me proper tabs for that on the
 * product page > Layout."* It is ModuleSchema::tabs() over
 * ProductLayout::SCHEMA, exactly the payload Appearance → Product styles and
 * the Newsletter screen already draw, so the console renders it with the
 * renderer it already has.
 *
 * ONE ROUTE AND NOT A SECOND PAIR. routes/web.php belongs to the integrator and
 * a lane may not edit it, but that is the smaller reason: the Layout tabs and
 * the Sections list are one screen to the owner, and a screen whose two halves
 * load from two endpoints has two ways to be half-loaded. `save` takes either
 * half, or both, and says how many of each it wrote.
 */
class ProductPageApiController extends Controller
{
    public function __construct(
        private ProductSections $sections,
        private ProductLayout $layout,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'sections' => array_values($this->sections->all()),
            'layout' => ModuleSchema::tabs(
                ProductLayout::SCHEMA,
                ProductLayout::TABS,
                $this->layout->all(),
                ProductLayout::POLICY,
            ),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        /*
         * `sections` IS NO LONGER `required`, AND `layout` IS NOT EITHER.
         *
         * The screen posts whichever half the owner was editing. Requiring both
         * would mean the Layout tabs had to re-post eighteen module switches
         * they never showed him — and a payload a screen assembles from values
         * it did not draw is how a control it does not draw gets overwritten.
         * Requiring NEITHER would let an empty POST report success, so the two
         * are required together-or-either and an empty body is a 422.
         */
        $data = $request->validate([
            'sections' => ['sometimes', 'array', 'min:1'],
            'sections.*.key' => ['required', 'string', 'max:40'],
            'sections.*.desktop' => ['required', 'boolean'],
            'sections.*.mobile' => ['required', 'boolean'],
            'layout' => ['sometimes', 'array', 'min:1'],
        ]);

        if (! isset($data['sections']) && ! isset($data['layout'])) {
            return response()->json(['ok' => false, 'error' => 'Nothing to save.'], 422);
        }

        $saved = 0;

        if (isset($data['sections'])) {
            $payload = [];

            foreach ($data['sections'] as $row) {
                if (! isset(ProductSections::REGISTRY[$row['key']])) {
                    return response()->json(['ok' => false, 'error' => "Unknown module: {$row['key']}."], 422);
                }

                $payload[$row['key']] = ['desktop' => $row['desktop'], 'mobile' => $row['mobile']];
            }

            $this->sections->save($payload);
            $saved += count($payload);
        }

        if (isset($data['layout'])) {
            /*
             * AN UNKNOWN KEY IS REFUSED RATHER THAN DROPPED, the same way
             * ProductStylesApiController refuses one. ProductLayout::save()
             * already ignores anything not in its SCHEMA, so a typo would
             * otherwise report "Saved 30 settings" having written 29 — the
             * "reports success and writes nothing" failure ModuleSchema's own
             * header names as the reason the framework exists.
             */
            $unknown = array_diff(array_keys($data['layout']), array_keys(ProductLayout::SCHEMA));

            if ($unknown !== []) {
                return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
            }

            $this->layout->save($data['layout']);
            $saved += count($data['layout']);
        }

        return response()->json([
            'ok' => true,
            'saved' => $saved,
            'sections' => array_values($this->sections->all()),
            'layout' => ModuleSchema::tabs(
                ProductLayout::SCHEMA,
                ProductLayout::TABS,
                $this->layout->all(),
                ProductLayout::POLICY,
            ),
        ]);
    }
}

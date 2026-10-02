<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\AlsoLikeSettings;
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
        private AlsoLikeSettings $also,
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
            'preview' => $this->preview(),
            /*
             * THE THIRD HALF: "You may also like".               (Lane PS)
             * Its own ModuleSchema::tabs() payload over AlsoLikeSettings, and
             * its own key in save(), for the same reason `layout` has one: a
             * POST that carries only the carousel's settings must not rewrite
             * the module switches or the thirty layout values.
             */
            'also' => $this->also->tabs(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        /*
         * `sections` IS NO LONGER `required`, AND `layout` IS NOT EITHER.
         *
         * The screen posts whichever half the owner was editing. Requiring both
         * would mean the Layout tabs had to re-post seventeen module switches
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
            'also' => ['sometimes', 'array', 'min:1'],
        ]);

        if (! isset($data['sections']) && ! isset($data['layout']) && ! isset($data['also'])) {
            return response()->json(['ok' => false, 'error' => 'Nothing to save.'], 422);
        }

        /*
         * "You may also like" (Lane PS): an unknown key is refused rather than
         * dropped, like `layout`'s below — and checked BEFORE anything is
         * written, so a refused POST has saved nothing at all.
         */
        if (isset($data['also'])) {
            $unknown = array_diff(array_keys($data['also']), array_keys(AlsoLikeSettings::SCHEMA));

            if ($unknown !== []) {
                return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
            }
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

        if (isset($data['also'])) {
            $this->also->save($data['also']);
            $saved += count($data['also']);
        }

        return response()->json([
            'ok' => true,
            'saved' => $saved,
            'also' => $this->also->tabs(),
            'sections' => array_values($this->sections->all()),
            'layout' => ModuleSchema::tabs(
                ProductLayout::SCHEMA,
                ProductLayout::TABS,
                $this->layout->all(),
                ProductLayout::POLICY,
            ),
        ]);
    }

    /**
     * What the live preview on that screen needs, and nothing else.       R5
     *
     *     "where's the preview on the product controls page? i need a proper
     *      preview of mobile and desktop both."
     *
     * Round 4 shipped thirty sliders with NOTHING TO SEE THEM AGAINST. The
     * screen now carries two frames — a phone and a laptop, side by side — and
     * each one loads THE SHIPPED PRODUCT PAGE, `/product/{slug}`.
     *
     * ▲ AND NOT ONE OF THE FIVE DESIGN PREVIEWS, WHICH IS THE OBVIOUS CHOICE
     *   AND THE WRONG ONE. `admin-api/catalog/pdp-preview/{candidate}/{slug}`
     *   renders a CANDIDATE — markup of its own, every class `pv-…` — and the
     *   thirty custom properties are read by `.pdp .bb-title`, `.sec h2`,
     *   `.details .dtabbar` and the rest in kbb-product.css. Checked in the
     *   templates, not assumed: the only selector the five share with the shop
     *   is `.dcontent`. A panel framing a candidate would therefore have drawn
     *   a handsome product page that answered ONE of the thirty controls, and
     *   looked completely finished doing it. The shipped page answers all
     *   thirty because they were written for it.
     *
     * ▲ `Product::url()` AND NOT A PATH BUILT IN THE CONSOLE. That method is
     *   URL contract U-01 — `/product/{slug}/`, trailing slash and all — and it
     *   carries the base prefix, which is EMPTY on extrabeauty.ae and
     *   `/kbb-upgrade` on the old box. A path assembled in JavaScript from the
     *   admin URL would be right on one of the two, and `route()` would drop
     *   the trailing slash the contract requires. The console still checks what
     *   comes back is same-origin before it becomes an `src` — rule 5, both
     *   ends.
     *
     * ▲ A SHOP WITH NO VISIBLE PRODUCT GETS `slug => null` and no url, and the
     *   console draws the panel's explanation instead of a frame pointed at a
     *   404. A fresh install really is in that state until the catalogue
     *   imports.
     *
     * @return array{slug: string|null, url: string|null, props: array<string, string>}
     */
    private function preview(): array
    {
        /* ORDERED, so the panel shows the same product on every visit —
           `value()` on an unordered query is whatever the engine hands back
           first, which is not stable across requests. */
        $product = Product::query()->visible()->orderBy('id')->first(['id', 'slug']);

        return [
            'slug' => $product?->slug,
            'url' => $product?->url(),
            'props' => ProductLayout::PROPS,
        ];
    }
}

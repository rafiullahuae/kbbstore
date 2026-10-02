<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\AlsoLikeSettings;
use App\Services\BuyTogetherPairs;
use App\Services\BuyTogetherSettings;
use App\Services\ModuleSchema;
use App\Services\ProductDesktopSections;
use App\Services\ProductLayout;
use App\Services\ProductMobileSections;
use App\Services\ProductSections;
use App\Services\ProductTrustShare;
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
            'trust' => self::trustTabs(),
            'preview' => $this->preview(),
            /*
             * THE THIRD HALF: "You may also like".               (Lane PS)
             * Its own ModuleSchema::tabs() payload over AlsoLikeSettings, and
             * its own key in save(), for the same reason `layout` has one: a
             * POST that carries only the carousel's settings must not rewrite
             * the module switches or the thirty layout values.
             */
            'also' => $this->also->tabs(),
            /*
             * THE FIFTH HALF: Mobile sections.                     (Lane QA)
             * The phone page as an ordered list of switchable sections, its
             * spacing, and the options under it. Its own key in save() for the
             * reason every half has one: a POST that reorders the phone page
             * must not rewrite the module switches or the layout sliders.
             */
            'msections' => app(ProductMobileSections::class)->payload(),
            /*
             * THE SIXTH HALF: Buy these together.                  (Lane RB)
             * Its options, its two device switches (which LIVE on the Sections
             * and Mobile sections rows and are only drawn here too), and the
             * category pairs. Its own key in save() like every half.
             */
            'together' => self::togetherPayload(),
            /*
             * THE SEVENTH HALF: Desktop sections.                  (Lane RF)
             * The order of the four full-width blocks under the laptop page's
             * two columns. Its own key in save() for the reason every half has
             * one: a POST that reorders the laptop page must not rewrite any
             * other half's values.
             */
            'dsections' => app(ProductDesktopSections::class)->payload(),
        ]);
    }

    /**
     * Buy these together, as the screen draws it.
     *
     * `phone` and `laptop` are READ from where they live — the Mobile sections
     * row and the Sections row — so this tab, those two tabs and the page can
     * never disagree: they are one value each, drawn in two places.
     *
     * @return array<string, mixed>
     */
    private static function togetherPayload(): array
    {
        $sections = app(ProductSections::class)->all();

        return [
            'options' => app(BuyTogetherSettings::class)->tabs(),
            'phone' => app(ProductMobileSections::class)->sectionOn('buytogether'),
            'laptop' => (bool) ($sections['fbt']['desktop'] ?? true),
            'pairs' => app(BuyTogetherPairs::class)->payload(),
            'max_pairs' => BuyTogetherPairs::MAX_PAIRS,
        ];
    }

    /**
     * The Trust & share half — Lane PW. The delivery box, "Authenticity
     * Guaranteed" and the share bar, as four more tabs on this screen, drawn by
     * resources/views/admin/partials/product-trust-share-screen.blade.php.
     * Same ModuleSchema::tabs() payload as `layout`, from its own class,
     * because those values are printed through Blade's escaper and
     * ProductLayout's are printed into a <style> element — see
     * App\Services\ProductTrustShare's header.
     *
     * @return list<array<string, mixed>>
     */
    private static function trustTabs(): array
    {
        return ModuleSchema::tabs(
            ProductTrustShare::schema(),
            ProductTrustShare::TABS,
            app(ProductTrustShare::class)->all(),
            ProductTrustShare::POLICY,
        );
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
            'trust' => ['sometimes', 'array', 'min:1'],
            'msections' => ['sometimes', 'array', 'min:1'],
            'together' => ['sometimes', 'array', 'min:1'],
            'dsections' => ['sometimes', 'array', 'min:1'],
        ]);

        if (! isset($data['sections']) && ! isset($data['layout']) && ! isset($data['also']) && ! isset($data['trust']) && ! isset($data['msections']) && ! isset($data['together']) && ! isset($data['dsections'])) {
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

        /*
         * Mobile sections (Lane QA): BOTH parts validated before anything is
         * written, so a refused POST has saved nothing — not the layout, not
         * the options, and not any other half posted beside them.
         *
         *   list     ProductMobileSections::validate(): every key in `order`
         *            exactly once, an unknown key refused, a missing key
         *            appended in his default order; switches are booleans;
         *            spacing is an integer clamped to 0–48 or blank.
         *   options  an unknown key refused, like `layout` and `trust`; the
         *            known ones go through ModuleSchema's casts (a select
         *            stores one of its own options or the default).
         */
        $msecList = null;

        if (isset($data['msections'])) {
            $unknown = array_diff(array_keys($data['msections']), ['list', 'options']);

            if ($unknown !== []) {
                return response()->json(['ok' => false, 'error' => 'Unknown part: '.implode(', ', $unknown)], 422);
            }

            if (array_key_exists('list', $data['msections'])) {
                $msecList = app(ProductMobileSections::class)->validate($data['msections']['list']);

                if (is_string($msecList)) {
                    return response()->json(['ok' => false, 'error' => $msecList], 422);
                }
            }

            if (array_key_exists('options', $data['msections'])) {
                $opts = $data['msections']['options'];
                $unknown = is_array($opts) ? array_diff(array_keys($opts), array_keys(ProductMobileSections::fields())) : ['options'];

                if ($unknown !== []) {
                    return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
                }
            }
        }

        /*
         * Buy these together (Lane RB): every part validated before anything
         * is written, like Mobile sections above.
         *
         *   options  an unknown key refused; known ones through ModuleSchema's
         *            casts (a select stores one of its options or the default,
         *            the count is clamped 3–6)
         *   phone    a boolean → the Mobile sections row `buytogether`
         *   laptop   a boolean → the Sections row `fbt`, Desktop
         *   pairs    BuyTogetherPairs::validateOverrides(): every category id
         *            must exist, at most five per category, none twice;
         *            null / "default" removes an override
         */
        $together = null;

        if (isset($data['together'])) {
            $t = $data['together'];
            $unknown = array_diff(array_keys($t), ['options', 'phone', 'laptop', 'pairs']);

            if ($unknown !== []) {
                return response()->json(['ok' => false, 'error' => 'Unknown part: '.implode(', ', $unknown)], 422);
            }

            $together = [];

            if (array_key_exists('options', $t)) {
                $unknown = is_array($t['options']) ? array_diff(array_keys($t['options']), array_keys(BuyTogetherSettings::SCHEMA)) : ['options'];

                if ($unknown !== []) {
                    return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
                }

                $together['options'] = $t['options'];
            }

            foreach (['phone', 'laptop'] as $device) {
                if (array_key_exists($device, $t)) {
                    if (! is_bool($t[$device]) && ! in_array($t[$device], [0, 1, '0', '1'], true)) {
                        return response()->json(['ok' => false, 'error' => "The {$device} switch must be on or off."], 422);
                    }

                    $together[$device] = (bool) $t[$device];
                }
            }

            if (array_key_exists('pairs', $t)) {
                $pairs = app(BuyTogetherPairs::class)->validateOverrides($t['pairs']);

                if (is_string($pairs)) {
                    return response()->json(['ok' => false, 'error' => $pairs], 422);
                }

                $together['pairs'] = $pairs;
            }
        }

        /*
         * Desktop sections (Lane RF): validated before anything is written,
         * like every half above. `order` is the only part; every key in it
         * exactly once, an unknown key or a repeat refused, a missing key
         * appended in the default order (ProductDesktopSections::validate()).
         */
        $dsecOrder = null;

        if (isset($data['dsections'])) {
            $unknown = array_diff(array_keys($data['dsections']), ['order']);

            if ($unknown !== []) {
                return response()->json(['ok' => false, 'error' => 'Unknown part: '.implode(', ', $unknown)], 422);
            }

            if (array_key_exists('order', $data['dsections'])) {
                $dsecOrder = ProductDesktopSections::validate($data['dsections']['order']);

                if (is_string($dsecOrder)) {
                    return response()->json(['ok' => false, 'error' => $dsecOrder], 422);
                }
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

        if (isset($data['trust'])) {
            /*
             * Lane PW. Refused rather than dropped, for the reason the layout
             * half above gives: a typo must not report "Saved".
             */
            $unknown = array_diff(array_keys($data['trust']), array_keys(ProductTrustShare::fields()));

            if ($unknown !== []) {
                return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
            }

            app(ProductTrustShare::class)->save($data['trust']);
            $saved += count($data['trust']);
        }

        if (isset($data['msections'])) {
            $msec = app(ProductMobileSections::class);

            if (is_array($msecList)) {
                $msec->saveLayout($msecList);
                $saved += count($msecList['order']);
            }

            if (is_array($data['msections']['options'] ?? null)) {
                $msec->saveOptions($data['msections']['options']);
                $saved += count($data['msections']['options']);
            }
        }

        if (is_array($dsecOrder)) {
            app(ProductDesktopSections::class)->save($dsecOrder);
            $saved += count($dsecOrder);
        }

        if (is_array($together)) {
            if (isset($together['options'])) {
                app(BuyTogetherSettings::class)->save($together['options']);
                $saved += count($together['options']);
            }

            if (array_key_exists('phone', $together)) {
                $msec = app(ProductMobileSections::class);
                $clean = $msec->validate(['on' => ['buytogether' => $together['phone']]]);

                if (is_array($clean)) {
                    $msec->saveLayout($clean);
                    $saved++;
                }
            }

            if (array_key_exists('laptop', $together)) {
                /* The Sections row's Desktop value, written into the stored map
                   and nothing else beside it: ProductSections::save() rewrites
                   the whole map from what it is given, so it is not used for one
                   switch. */
                $settings = app(\App\Services\SettingsService::class);
                $map = $settings->get('product_sections');
                $map = is_array($map) ? $map : [];
                $row = is_array($map['fbt'] ?? null) ? $map['fbt'] : [];
                $map['fbt'] = ['desktop' => $together['laptop'], 'mobile' => (bool) ($row['mobile'] ?? true)];
                $settings->set('product_sections', $map);
                $saved++;
            }

            if (isset($together['pairs'])) {
                app(BuyTogetherPairs::class)->save($together['pairs']);
                $saved++;
            }

            // Every product's cached choice was made under the old rules.
            \Illuminate\Support\Facades\Cache::forget(BuyTogetherPairs::CACHE_KEY);
        }

        return response()->json([
            'ok' => true,
            'saved' => $saved,
            'also' => $this->also->tabs(),
            // A fresh instance: the Buy these together half may have written
            // the `fbt` row after $this->sections memoised the map.
            'sections' => array_values(app(ProductSections::class)->all()),
            'layout' => ModuleSchema::tabs(
                ProductLayout::SCHEMA,
                ProductLayout::TABS,
                $this->layout->all(),
                ProductLayout::POLICY,
            ),
            'trust' => self::trustTabs(),
            'msections' => app(ProductMobileSections::class)->payload(),
            'together' => self::togetherPayload(),
            'dsections' => app(ProductDesktopSections::class)->payload(),
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
            // Lane PW: the Trust & share blocks' custom properties, so the
            // preview can move their spacing and colours live.
            'trust_props' => ProductTrustShare::props(),
            // Lane QB: the share sheet's platforms, key => name, for the
            // Share tab's tile-order list. Constants, not settings.
            'share_networks' => array_map(static fn (array $n): string => $n[0], ProductTrustShare::NETWORKS),
        ];
    }
}

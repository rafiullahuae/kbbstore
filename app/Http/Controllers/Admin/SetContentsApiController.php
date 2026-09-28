<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\SettingsService;
use App\Support\SetEagerLoad;
use App\Support\SetPanelDesign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → Set contents. (Lane SF)
 *
 * Which of four drawings a set's product page uses for the block that names
 * what is in the box — and a real picture of each, so the owner picks from the
 * page rather than from a description of it:
 *
 *   "there should be list of products which are inside the set, present it
 *    beautifully. better to preview me the set product front-end preview. so i
 *    can choose from."
 *
 * ── THREE ENDPOINTS ─────────────────────────────────────────────────────────
 *
 *   GET  /admin-api/set-contents           the four designs and which is live
 *   POST /admin-api/set-contents           store one of the four
 *   POST /admin-api/set-contents/preview   one design, rendered, as HTML
 *
 * ── A SELECT STORES ONE OF ITS OWN OPTIONS OR THE DEFAULT ──────────────────
 *
 * CLAUDE.md rule 5. save() refuses anything that is not a key of
 * SetPanelDesign::DESIGNS with a 422 and writes nothing — the raw input never
 * reaches the settings table. SetPanelDesign::current() then refuses it a
 * second time on the way out, which is the half that matters: a row can arrive
 * in `settings` from an import or a restored backup without passing through
 * this controller at all.
 *
 * ── WHAT A SET ROW MAY SAY HERE ────────────────────────────────────────────
 *
 * `products` carries `wc_id`, `sku` and `total_sales`, which is why
 * CLAUDE.md's second landmine says to allowlist what a model returns and never
 * the model. This is an ADMIN endpoint behind `auth:admin` and
 * `setcontents.manage`, so none of those three would be a leak — and it is
 * still an explicit four-key list below, because the habit is what makes the
 * unauthenticated endpoints next door right, and a list written once cannot
 * grow a column by someone adding one to the table.
 *
 * ── THE PREVIEW RENDERS THE SHOP'S OWN TEMPLATE ────────────────────────────
 *
 * Not a mock. resources/views/admin/partials/set-contents-preview.blade.php
 * includes partials/set-contents-panel.blade.php — the file the product page
 * includes — with the design handed in. Same arrangement as Appearance →
 * Homepage's Preview, and the same reason: a drawing of a page is not a page.
 *
 * NOTHING HERE WRITES ON THE PREVIEW PATH. It is a POST because the design key
 * and the set id are a request body rather than an address worth caching or
 * logging, which is the argument routes/homepage-preview-admin.php makes at
 * length; the verb is about the body, not about the effect.
 */
class SetContentsApiController extends Controller
{
    /**
     * How many sets the picker offers.
     *
     * A ceiling rather than the whole catalogue: this list exists so the owner
     * can preview against a set he recognises, and a shop with four hundred
     * sets does not need four hundred rows in a <select> to do that. Ordered
     * by member count so the fullest box — the one that actually tests a
     * design — is at the top.
     */
    private const SET_LIMIT = 50;

    public function __construct(private SettingsService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'current' => SetPanelDesign::current($this->settings),
            'default' => SetPanelDesign::DEFAULT,
            'designs' => SetPanelDesign::options($this->settings),
            'sets' => $this->sets(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'design' => ['required', 'string', 'max:40'],
        ]);

        /*
         * THE ALLOWLIST, AND IT IS THE SCHEMA'S OWN KEYS RATHER THAN A SECOND
         * COPY OF THEM. Rule::in(array_keys(...)) would validate the same
         * thing; this answers with the option list in the error, which is what
         * a screen showing four radio buttons and a stale cache needs to say.
         */
        if (! SetPanelDesign::valid($data['design'])) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown design: '.$data['design'].'. Choose one of: '
                    .implode(', ', array_keys(SetPanelDesign::DESIGNS)).'.',
            ], 422);
        }

        $this->settings->set(SetPanelDesign::KEY, $data['design']);

        return response()->json([
            'ok' => true,
            'current' => SetPanelDesign::current($this->settings),
            'designs' => SetPanelDesign::options($this->settings),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'design' => ['required', 'string', 'max:40'],
            'set_id' => ['sometimes', 'nullable', 'integer'],
            'dir' => ['sometimes', 'nullable', 'string', 'max:3'],
        ]);

        if (! SetPanelDesign::valid($data['design'])) {
            return response()->json(['ok' => false, 'error' => 'Unknown design: '.$data['design'].'.'], 422);
        }

        $set = $this->set($data['set_id'] ?? null);

        if ($set === null) {
            return response()->json([
                'ok' => false,
                'error' => 'There is no Set to preview yet. Create one under Catalog → Sets, put a product or two in it, and come back.',
            ], 422);
        }

        /*
         * THE SAME THREE BATCHED QUERIES THE PRODUCT PAGE PAYS, and no more —
         * SetEagerLoad::on() loads every member, brand and chosen variant for
         * the whole list at once. Without it the panel would cost one query
         * per member here, which is the N+1 StorefrontQueryBudgetTest exists
         * to stop and is no more acceptable on an admin screen.
         */
        SetEagerLoad::on([$set]);

        /*
         * `dir` DECIDES ONE ATTRIBUTE ON <html> AND IS NOT INTERPOLATED FROM
         * THE REQUEST. This storefront is bilingual and the designs have to
         * hold up mirrored, so the screen offers the owner an Arabic preview —
         * but the value printed is one of two literals chosen here, never the
         * string that arrived.
         */
        $dir = ($data['dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';

        return response()->json([
            'ok' => true,
            'design' => $data['design'],
            'set' => $this->row($set),
            'html' => view('admin.partials.set-contents-preview', [
                'product' => $set,
                'kbbSetDesignOverride' => $data['design'],
                'kbbPreviewDir' => $dir,
            ])->render(),
        ]);
    }

    /**
     * The sets this screen may preview against.
     *
     * @return list<array{id: int, name: string, slug: string, members: int}>
     */
    private function sets(): array
    {
        $sets = Product::query()
            ->where('type', 'set')
            ->withCount('setItems')
            ->orderByDesc('set_items_count')
            ->orderBy('name')
            ->limit(self::SET_LIMIT)
            ->get(['id', 'name', 'slug', 'type']);

        return $sets->map(fn (Product $p) => $this->row($p))->values()->all();
    }

    /**
     * One set, by id, or the fullest one there is.
     *
     * A set id that is not a set — an ordinary product's id, or one that has
     * been deleted — falls back to the default rather than 404ing, because the
     * screen's own <select> is the only thing that sends one and a stale tab is
     * not an error worth showing the owner a red banner for.
     */
    private function set(?int $id): ?Product
    {
        $query = Product::query()->where('type', 'set');

        if ($id !== null) {
            $chosen = (clone $query)->whereKey($id)->first();

            if ($chosen !== null) {
                return $chosen;
            }
        }

        return $query->withCount('setItems')->orderByDesc('set_items_count')->orderBy('name')->first();
    }

    /**
     * ▲ THE ALLOWLIST. Four keys, named, never the model. ▲
     *
     * @return array{id: int, name: string, slug: string, members: int}
     */
    private function row(Product $set): array
    {
        return [
            'id' => (int) $set->id,
            'name' => (string) $set->name,
            'slug' => (string) $set->slug,
            // `set_items_count` is absent on a row fetched by id without
            // withCount(), and an absent count is 0 rather than a second query.
            'members' => (int) ($set->set_items_count ?? 0),
        ];
    }
}

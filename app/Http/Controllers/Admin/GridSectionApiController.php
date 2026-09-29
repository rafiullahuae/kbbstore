<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GridSection;
use App\Models\Product;
use App\Services\GridSections;
use App\Services\HomepageSections;
use App\Services\ModuleSchema;
use App\Support\GridSkins;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Appearance → Grid sections. The console's endpoints. (Lane GS — Phase 23)
 *
 * One reusable grid section, created as many times as the owner likes, each
 * instance edited on its own. `App\Services\GridSections` is the module and
 * carries the design argument; `routes/grid-sections-admin.php` lists the
 * paths and the capability split.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * EVERY WRITE GOES THROUGH ModuleSchema::cast(), AND THERE IS ONE WAY IN
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `apply()` below is the only method that puts a posted value onto a row, and
 * it walks `GridSections::fields()` rather than the request. That is what makes
 * CLAUDE.md rule 5 structural rather than intended:
 *
 *   - an unknown key cannot ride in, because the loop is over the SCHEMA;
 *   - a select stores one of its own options or the default, because
 *     `cast()` is given the same `overrides()` the picker was drawn from;
 *   - an int is clamped, because `POLICY` says `clamp => true`;
 *   - a key the payload does not carry is left alone, so a screen that posts
 *     one control cannot blank another.
 *
 * The three things the schema cannot express are handled explicitly and each
 * says why: the manual id list, the slug, and the `position`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * AND EVERY WRITE FLUSHES BOTH CACHE LAYERS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `GridSections::registryRows()` memoises in a process-level static as well as
 * in the cache — the same shape `Setting::map()` has, and CLAUDE.md names the
 * trap: within one long-lived process it will not see writes made after the
 * first call. Every mutating method here ends in `GridSections::flush()`, which
 * clears both. Miss it on one method and the console saves an instance, reloads
 * its own list from the memo, and shows the owner the row he just changed in
 * its old shape — which reads as "the save did not work".
 */
class GridSectionApiController extends Controller
{
    /**
     * The whole screen in one request: the instances, the presets, and the
     * option sets the pickers are drawn from.
     */
    public function index(): JsonResponse
    {
        $rows = GridSection::query()->orderBy('position')->orderBy('id')->get();

        return response()->json([
            'ok' => true,
            'sections' => $rows->map(fn (GridSection $s) => self::summary($s))->values()->all(),
            'presets' => collect(GridSections::PRESETS)
                ->map(fn ($p, $k) => ['key' => $k, 'label' => $p['label']])
                ->values()->all(),
        ] + self::optionSets());
    }

    /**
     * One instance, with its controls rendered by the SAME
     * `SCHEMA`/`TABS`/`POLICY` call every other settings screen in this console
     * is drawn by.
     *
     * A second, parallel description of these controls — a hand-written list of
     * inputs in the Blade — is the thing docs/M-PHASE3-SETTINGS-SCHEMA.md §1
     * measured: the picker offering an option the cast refuses, or the cast
     * falling back to a default the picker does not show. Drawn from `tabs()`,
     * a field added to `GridSections::SCHEMA` appears on the screen, is
     * validated on the way in and is persisted, from one statement.
     */
    public function show(GridSection $grid): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'section' => self::summary($grid),
            'tabs' => GridSections::tabsFor($grid),
            'manual' => self::manualProducts($grid),
        ] + self::optionSets());
    }

    /**
     * The option sets a select's own payload cannot carry, as their own
     * top-level keys.
     *
     * ── WHY THEY ARE NOT ON THE FIELD ───────────────────────────────────────
     *
     * `ModuleSchema::fields()` emits `options` from the module's DECLARED
     * schema and deliberately not from `overrides()` — its own comment says
     * why, and names the two screens (ProductStyles' card style, SectionDividers'
     * picker) that draw their picker from a separate top-level key for exactly
     * this reason. `overrides()` exists so that `cast()` can hold a control to
     * an option set that lives in another registry; putting those sets on the
     * render payload instead moved two other modules' payloads when it was
     * tried, and was caught by a snapshot diff rather than by reading.
     *
     * So the three that come from elsewhere — the brands, the categories and
     * the 28 card templates — travel beside the tabs, and the screen composes
     * them onto the three fields by key. `GridSectionApiSurfaceTest` asserts
     * the keys the cast checks against and the keys the screen is handed are
     * the same sets, so the picker cannot offer a value the save refuses.
     *
     * @return array<string, mixed>
     */
    private static function optionSets(): array
    {
        $overrides = GridSections::overrides();

        return [
            'brands' => $overrides['source_brand_id']['options'] ?? [],
            'categories' => $overrides['source_category_id']['options'] ?? [],
            'skins' => $overrides['skin']['options'] ?? GridSkins::ALL,
        ];
    }

    /**
     * Create an instance — blank, or from one of the owner's two presets.
     *
     * ▲ IT IS CREATED AS A DRAFT, whatever the preset says, and that is rule 1
     * on the most visible page in the shop. Pressing "Add" must not put a new
     * band on the live front page before the owner has looked at it; he
     * publishes it when he is ready, from the switch on this screen.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preset' => ['sometimes', 'nullable', 'string', 'max:40'],
            'name' => ['sometimes', 'nullable', 'string', 'max:190'],
        ]);

        $preset = (string) ($data['preset'] ?? '');
        $values = isset(GridSections::PRESETS[$preset])
            ? GridSections::PRESETS[$preset]['values']
            : [];

        $name = trim((string) ($data['name'] ?? '')) !== ''
            ? trim((string) $data['name'])
            : (string) ($values['name'] ?? 'Product grid');

        $section = new GridSection;

        // Every schema default first, then the preset over it. In this order a
        // preset that names four keys still produces a complete row, and a
        // field added to the schema later does not leave older presets writing
        // nulls into a NOT NULL column.
        self::apply($section, self::defaults());
        self::apply($section, $values);

        /*
         * ▲ AND THE NAME AND THE STATUS AFTER BOTH, WHICH IS NOT TIDYING.
         *
         * These three lines used to sit ABOVE the two apply() calls, and
         * `name`'s shipped default is `''` — so the defaults pass blanked the
         * name that had just been written and "Add a blank grid" created a row
         * with no name at all: blank in this screen's list AND blank on
         * Appearance → Homepage, which is where the owner orders it against the
         * shop's other sections. Two screens with an unnamed row he cannot tell
         * from the next one.
         *
         * Invisible from the preset side, because both presets carry a `name`
         * of their own that lands in the second apply(). Caught by
         * GridSectionApiSurfaceTest's "it gives a blank grid a name".
         *
         * `status` is last for a different reason and is not through apply()
         * at all: it IS in the schema, and a preset that named it could
         * otherwise publish straight onto the live front page.
         */
        $section->name = $name;
        $section->slug = self::uniqueSlug($name);
        $section->status = 'draft';
        $section->position = (int) (GridSection::query()->max('position') ?? 0) + 1;

        $section->save();
        GridSections::flush();

        return response()->json(['ok' => true, 'section' => self::summary($section)], 201);
    }

    /** Save one instance. Only the keys the payload carries. */
    public function update(Request $request, GridSection $grid): JsonResponse
    {
        /*
         * `sometimes` AND NOT `required` on both keys, which is the same rule
         * apply() follows one level down: a caller that moves ONE control must
         * not be forced to send every other one back, or a narrow write becomes
         * a wide one and a field the caller never saw is overwritten with
         * whatever it last read. A PUT carrying only `manual_ids` is a
         * legitimate request and used to 422.
         */
        $data = $request->validate([
            'values' => ['sometimes', 'array'],
            'manual_ids' => ['sometimes', 'array'],
            'manual_ids.*' => ['integer'],
        ]);

        $values = (array) ($data['values'] ?? []);

        self::apply($grid, $values);

        if (array_key_exists('name', $values)) {
            $grid->slug = self::uniqueSlug((string) $grid->name, (int) $grid->id);
        }

        if (array_key_exists('manual_ids', $data)) {
            $grid->manual_ids = self::cleanManualIds((array) $data['manual_ids']);
        }

        $grid->save();
        GridSections::flush();

        return response()->json([
            'ok' => true,
            'section' => self::summary($grid),
            'tabs' => GridSections::tabsFor($grid),
            'manual' => self::manualProducts($grid),
        ] + self::optionSets());
    }

    /**
     * Copy an instance, as a draft.
     *
     * The copy is a draft for the same reason a new one is, and its name is
     * suffixed rather than shared: two rows called "Best sellers" on
     * Appearance → Homepage are two rows the owner cannot tell apart, and that
     * screen is where he orders them.
     */
    public function duplicate(GridSection $grid): JsonResponse
    {
        $copy = $grid->replicate(['slug', 'created_at', 'updated_at']);
        $copy->name = Str::limit($grid->name.' (copy)', 190, '');
        $copy->slug = self::uniqueSlug((string) $copy->name);
        $copy->status = 'draft';
        $copy->position = (int) (GridSection::query()->max('position') ?? 0) + 1;
        $copy->save();

        GridSections::flush();

        return response()->json(['ok' => true, 'section' => self::summary($copy)], 201);
    }

    /**
     * Delete an instance.
     *
     * ▲ ITS ROW IN `homepage_sections` IS LEFT BEHIND ON PURPOSE.
     *
     * `HomepageSections::all()` walks `registry()` and reads the saved blob
     * BY KEY, so a `grid_9` entry whose instance is gone is never read again —
     * it is inert, not stale. Deleting it would mean a write to a second
     * settings row from a third place, inside the same request, to remove data
     * nothing can reach; and `save()` on that screen rewrites the blob from the
     * registry the next time the owner touches it anyway, which is when it
     * actually disappears. A guarded write that leaves state behind is the
     * shape CLAUDE.md's updater landmine is about, and the cheapest way not to
     * have one here is not to make the write.
     */
    public function destroy(GridSection $grid): JsonResponse
    {
        $grid->delete();
        GridSections::flush();

        return response()->json(['ok' => true]);
    }

    /**
     * Re-order the instances among THEMSELVES.
     *
     * This is `grid_sections.position`, which decides the order they are drawn
     * in inside the homepage's loop — the DEFAULT order. Where each one sits
     * among the shop's other sections is `homepage_sections` and is moved on
     * Appearance → Homepage, which is the one ordering mechanism this feature
     * has and the one it inherits. Two answers to "where does this section sit"
     * would be a screen that lies; this one answers a different question, and
     * the screen says so in as many words.
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $position = 0;

        foreach ($data['order'] as $id) {
            GridSection::query()->whereKey((int) $id)->update(['position' => $position++]);
        }

        GridSections::flush();

        return response()->json(['ok' => true]);
    }

    /**
     * The instance as the shop will draw it, from the SAVED row.
     *
     * Renders THE SAME PARTIAL the homepage renders. A second copy of the
     * markup on the screen would disagree with the shop the first time either
     * was touched — the fault `HomepageLayouts::summaries()` shipped, and the
     * reason the cards-banner preview is built this way too.
     */
    public function preview(GridSection $grid): JsonResponse
    {
        return self::draw($grid);
    }

    /**
     * The instance as the shop will draw it, from the editor's UNSAVED buffer.
     *
     * The whole point of a buffered editor is to see a COMBINATION before
     * committing it. A preview that read the database would show the owner the
     * row he is trying to get away from.
     *
     * IT WRITES NOTHING BY CONSTRUCTION: the stored row is loaded, the
     * validated buffer is laid over it IN MEMORY with the same `apply()` the
     * save uses — so the preview cannot drift from what Save will produce — and
     * `save()` is never called. The model is discarded with the response.
     */
    public function previewDraft(Request $request, GridSection $grid): JsonResponse
    {
        $data = $request->validate([
            'values' => ['sometimes', 'array'],
            'manual_ids' => ['sometimes', 'array'],
            'manual_ids.*' => ['integer'],
        ]);

        self::apply($grid, (array) ($data['values'] ?? []));

        if (array_key_exists('manual_ids', $data)) {
            $grid->manual_ids = self::cleanManualIds((array) $data['manual_ids']);
        }

        return self::draw($grid);
    }

    /**
     * The catalogue, searched by name, for the manual picker.
     *
     * ── FIVE COLUMNS, AND THE ALLOWLIST IS THE POINT ────────────────────────
     *
     * This is not `/api/*` — it is behind `auth:admin` and its own capability —
     * and it STILL names its columns. CLAUDE.md lists what a bare `->get()` on
     * this table hands out: `wc_id`, `sku` and `total_sales`, every one of
     * which leaked in production through an endpoint somebody was sure was
     * private. The picker needs a name, a picture and a price to show a row
     * the owner can recognise, and nothing else; `GridSectionApiSurfaceTest`
     * asserts the response carries no other key.
     *
     * `visible()` because a picker that offers a draft product produces a
     * manual list with a hole in it — `GridSections::fetchPool()` re-applies
     * the same scope on the way out, so an invisible product silently vanishes
     * from the grid and the owner would never learn why it was not there.
     */
    public function products(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $query = Product::query()
            ->select(['id', 'name', 'slug', 'image', 'price'])
            ->visible();

        if ($q !== '') {
            // `escapeLike` is not available here and `%` in a search box is a
            // wildcard the owner typed, not an injection: the value is bound.
            $query->where('name', 'like', '%'.$q.'%');
        }

        $rows = $query->orderBy('name')->limit(40)->get();

        return response()->json([
            'ok' => true,
            'products' => $rows->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'slug' => (string) $p->slug,
                'image' => (string) ($p->image ?? ''),
                'price' => (int) $p->price,
            ])->all(),
        ]);
    }

    /* ══════════════════════════════ the innards ════════════════════════════ */

    /**
     * Draw one instance through the storefront partial, inside the section
     * frame the homepage gives it.
     *
     * `HomepageSections` is handed in because the partial asks it for the
     * section's visibility class. For an instance that is not in the registry
     * yet — a brand-new one, or the unsaved buffer — `classFor()` answers `''`,
     * which is the same string a shop with the switches untouched gets.
     */
    private static function draw(GridSection $grid): JsonResponse
    {
        $built = app(GridSections::class)->forPreview($grid);

        if ($built === null || $built['items']->isEmpty()) {
            return response()->json(['ok' => true, 'empty' => true, 'html' => '', 'css' => GridSections::css()]);
        }

        return response()->json([
            'ok' => true,
            'empty' => false,
            'css' => GridSections::css(),
            'stylesheets' => self::builtStylesheets(),
            'html' => view('partials.home.grid-section', [
                'section' => $built['section'],
                'items' => $built['items'],
                'sections' => app(HomepageSections::class),
            ])->render(),
        ]);
    }

    /**
     * The shop's own built stylesheets, so the preview draws the REAL card.
     *
     * The card is 28 CSS templates in a built bundle, and a preview that
     * reproduced any of them here would be a second copy of Lane PG2's file
     * that goes stale the day that file changes — which is the exact failure
     * the cards-banner preview's own header records paying for with `--pink`.
     * So the frame links the built files instead.
     *
     * An empty list is a correct answer and not an error: `public/build` is not
     * present on a checkout that has never run `npx vite build` (CLAUDE.md,
     * Known gaps — `package.json` defines no build script and CI does not build
     * assets), and the preview then draws the right LAYOUT with unstyled cards,
     * which is honest. Nothing is cached, nothing is written, and the shop does
     * not go through this path at all.
     *
     * @return list<string>
     */
    private static function builtStylesheets(): array
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            return [];
        }

        $map = json_decode((string) @file_get_contents($manifest), true);

        if (! is_array($map)) {
            return [];
        }

        $out = [];

        foreach (['resources/css/kbb/kbb.css', 'resources/css/kbb/kbb-grid-skins.css'] as $src) {
            $file = $map[$src]['file'] ?? null;

            if (is_string($file) && $file !== '') {
                $out[] = \App\Support\Url::to('/build/'.$file);
            }
        }

        return $out;
    }

    /**
     * Put a posted payload onto a row, one schema field at a time.
     *
     * THE LOOP IS OVER THE SCHEMA AND NOT OVER THE REQUEST, which is what makes
     * "an unknown key cannot ride in" a property of the code rather than a
     * check somebody has to remember. `array_key_exists` and not `isset`,
     * because a legitimately empty heading posts as `''` and `isset` would keep
     * the old one — the "cleared box does not clear" defect, which is why
     * `POLICY` also says `blank => keep`.
     *
     * `name` and `status` are written through this loop like everything else;
     * `store()` overrides `status` afterwards, deliberately and in one place.
     *
     * @param  array<string, mixed>  $values
     */
    private static function apply(GridSection $section, array $values): void
    {
        $fields = GridSections::fields();

        foreach ($fields as $key => $field) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $cast = ModuleSchema::cast($field, $values[$key]);

            if ($cast === null) {
                // `invalid => default` means cast() answers the default rather
                // than null for a value it will not store, so a null here is the
                // narrow case ModuleSchema reserves it for. Skipping leaves the
                // stored value alone, which is the safe direction.
                continue;
            }

            /*
             * The two ids are SELECTS whose option keys are strings, so they
             * come back as strings and '' is the "not chosen" option. The
             * column is a nullable integer: '' has to become null and not 0,
             * because 0 is an id nobody issued and `fetchPool()` tests for
             * "greater than zero" rather than for null.
             */
            if ($key === 'source_brand_id' || $key === 'source_category_id') {
                $section->{$key} = ((string) $cast) === '' ? null : (int) $cast;

                continue;
            }

            // The three column-count selects are stored as ints; their options
            // are strings for cast()'s sake and nothing else.
            if (in_array($key, ['desktop_cols', 'mobile_cols'], true)) {
                $section->{$key} = (int) $cast;

                continue;
            }

            $section->{$key} = $cast;
        }
    }

    /**
     * Every schema field at its shipped default, for a brand-new row.
     *
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        $out = [];

        foreach (GridSections::fields() as $key => $field) {
            $out[$key] = $field['default'];
        }

        return $out;
    }

    /**
     * The manual pick: ids validated against rows that exist, de-duplicated,
     * in the owner's own order.
     *
     * ── WHY IT IS CHECKED AGAINST THE TABLE AND NOT MERELY CAST TO INT ──────
     *
     * CLAUDE.md rule 5: "A manual product list is ids validated against rows
     * that exist." An int is not a product. Without the `whereIn` a POST of
     * `[1,2,3,…,10000]` would be stored verbatim and `fetchPool()` would carry
     * it into a `whereIn` of ten thousand bound parameters on the homepage's
     * critical path — a denial of service written into a settings row by
     * somebody with an editor account.
     *
     * The cap is 48, which is `fetchCount()`'s own ceiling: storing more than
     * the row can ever draw is storing something nothing will read.
     *
     * `array_unique` BEFORE the query, because a list of the same id 48 times
     * is one product and 47 gaps.
     *
     * @param  list<mixed>  $raw
     * @return list<int>
     */
    private static function cleanManualIds(array $raw): array
    {
        $ids = [];

        foreach ($raw as $value) {
            $id = (int) $value;

            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }

            if (count($ids) >= 48) {
                break;
            }
        }

        if ($ids === []) {
            return [];
        }

        $exists = Product::query()->whereIn('id', $ids)->pluck('id')->all();
        $exists = array_map('intval', $exists);

        // The ORDER is the owner's, so the filter keeps his sequence rather
        // than the order the database happened to answer in.
        return array_values(array_filter($ids, fn (int $id) => in_array($id, $exists, true)));
    }

    /**
     * The manual pick, hydrated for the screen's list.
     *
     * Same five-column allowlist as products() above, and the same reason.
     *
     * @return list<array<string, mixed>>
     */
    private static function manualProducts(GridSection $section): array
    {
        $ids = array_map('intval', (array) ($section->manual_ids ?? []));

        if ($ids === []) {
            return [];
        }

        $rows = Product::query()
            ->select(['id', 'name', 'slug', 'image', 'price'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($ids as $id) {
            $p = $rows->get($id);

            if ($p === null) {
                continue;
            }

            $out[] = [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'slug' => (string) $p->slug,
                'image' => (string) ($p->image ?? ''),
                'price' => (int) $p->price,
            ];
        }

        return $out;
    }

    /**
     * What the screen's list needs about one instance.
     *
     * `section_key` is in here because the screen tells the owner where to go
     * to move it — "Appearance → Homepage, the row called …" — and a key the
     * screen composed for itself would be a second answer to
     * `GridSection::sectionKey()`.
     *
     * @return array<string, mixed>
     */
    private static function summary(GridSection $section): array
    {
        return [
            'id' => (int) $section->id,
            'name' => (string) $section->name,
            'slug' => (string) $section->slug,
            'status' => (string) $section->status,
            'position' => (int) $section->position,
            'section_key' => $section->sectionKey(),
            'values' => GridSections::valuesOf($section),
            'manual_ids' => array_map('intval', (array) ($section->manual_ids ?? [])),
        ];
    }

    /**
     * A slug nothing else holds.
     *
     * Suffixed rather than refused, for the reason `banner_sets` gives: renaming
     * two instances to the same words is not an error the owner should have to
     * solve, and the slug is a handle rather than something he typed.
     */
    private static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) !== '' ? Str::slug($name) : 'product-grid';
        $slug = $base;
        $n = 2;

        while (GridSection::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$n++;

            if ($n > 200) {
                $slug = $base.'-'.Str::lower(Str::random(6));

                break;
            }
        }

        return $slug;
    }
}

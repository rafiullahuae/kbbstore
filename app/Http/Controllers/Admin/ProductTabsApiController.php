<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductTab;
use App\Support\ProductTabs;
use App\Support\RichText;
use App\Support\TranslationInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Catalog -> Product tabs.  (Lane PT)
 *
 * The owner: "the Product Tabs i want that user can create a new tabs for any
 * product, or set as global for all products too. make a nice functionality and
 * give full control."
 *
 * App\Support\ProductTabs is the design -- what a tab is, how a global one and
 * a per-product one interact, and why the three built-in tabs stayed built in.
 * This file is the way in, and it has five rules.
 *
 * ── 1. A BODY IS SANITISED ON THE SERVER, IN BOTH LANGUAGES ────────────────
 *
 * partials/product-tabs.blade.php prints every tab's body with {!! !!} --
 * TWICE, once for the desktop panel and once for the mobile accordion -- and it
 * has no idea which language it is holding. So the English body goes through
 * App\Support\RichText::clean() here, and the Arabic body goes through the SAME
 * function via TranslationInput::clean($bag, self::RICH_FIELDS). That is the
 * identical pair of calls PageEditorApiController makes for `content` and
 * PostEditorApiController makes for `body`, and it is the T4b fix: the master
 * plan records that the Arabic halves of two {!! !!} tabs going in unsanitised
 * WAS the stored-XSS hole, not an oversight beside it.
 *
 * No second sanitiser was written for this feature and no {!! !!} was added to
 * the partial that the existing tab bodies do not already travel through.
 *
 * ── 2. A TITLE IS NOT RICH TEXT, AND IS NOT CLEANED EITHER ─────────────────
 *
 * It is printed ESCAPED, by `{{ $tab['title'] }}`, into a <button> and into an
 * accordion heading. So it is stored exactly as typed, trimmed and bounded at
 * the column's own 120 characters, and NOT run through RichText::clean().
 *
 * That second half is deliberate and is the argument RichText's own header
 * makes about names: clean() parses its input as HTML, so a tab honestly called
 * "Ingredients & INCI" or "Why <3 this" would come back re-encoded or shorn,
 * and the shop would change under the owner for a value he typed correctly.
 * Escaping at the point of printing is the correct control for a short string
 * in an attribute-free text position, and Blade already applies it.
 *
 * ── 3. `source_key` IS A CLOSED VOCABULARY, CHECKED AGAINST A REGEX ────────
 *
 * ProductTabs::SOURCE_KEY_PATTERN is anchored and admits exactly
 * `builtin:description|ingredients|how_to_use` and `global:<digits>`. A
 * `global:` key is then checked to name a global tab THAT EXISTS, so a row
 * cannot be written that points at nothing. CLAUDE.md rule 5: a select stores
 * one of its own options or the default.
 *
 * ── 4. `position` IS BOUNDED, ON BOTH SIDES OF THE VALIDATOR ──────────────
 *
 * `integer|between:0|MAX_POSITION` in the rules, and then clamped again with
 * min()/max() before the write. The column is an unsignedSmallInteger, which
 * is a third bound one layer down. An ordering value is bounded.
 *
 * ── 5. THE SCOPE OF A ROW IS SET BY THE ROUTE, NEVER BY THE PAYLOAD ───────
 *
 * `product_id` is taken from the URL segment and `source_key` from the
 * dedicated override endpoint. Neither is ever read out of the request body, so
 * a payload cannot turn a per-product tab into a global one -- which would put
 * one product's text on all seven hundred -- and cannot re-aim an override at a
 * tab it was not opened for.
 */
class ProductTabsApiController extends Controller
{
    /**
     * The fields the storefront prints with {!! !!}.
     *
     * One list, read by the English write below and handed to
     * TranslationInput::clean() for the Arabic, so a field cannot be sanitised
     * in one language and not the other.
     *
     * @var list<string>
     */
    public const RICH_FIELDS = ['body'];

    /**
     * The ceiling on a body.
     *
     * `products.description` is a mediumText and so is this column, so this is
     * a sanity bound rather than a storage one: it exists so a paste cannot
     * make an unbounded write, and it is comfortably above the longest product
     * description in this catalogue.
     */
    private const MAX_BODY = 200000;

    /** How many tabs one scope may hold. A tab strip, not a catalogue. */
    private const MAX_PER_SCOPE = 40;

    /* -------------------------------------------------------------- globals */

    /**
     * The global tabs, and the three built-ins they sort against.
     *
     * The built-ins are sent so the screen can draw ONE ordered list rather
     * than two -- the whole point of the single ordering scale is that the
     * owner sees where a global tab lands relative to Description and
     * Ingredients before he saves it.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'tabs' => array_map(
                fn (ProductTab $t): array => $this->payload($t),
                $this->rows(null)
            ),
            'builtins' => $this->builtinPayload(),
            /*
             * THE EMPTY SHAPE OF THE ARABIC BOXES, for the ADD form. (The same
             * thing ProductEditorApiController::bootstrap() hands down, for the
             * same reason.) A tab being created has no row and therefore no
             * translations, but the blank form still has to draw a box for
             * every translatable field. Handing the shape down is what lets the
             * create form know which fields those are without this screen
             * holding a second copy of ProductTab::$translatable -- exactly the
             * kind of list that drifts. KBBArabic.boxIf() asks it before it
             * draws anything, so a box is never offered for a field the server
             * would silently drop.
             */
            'translations' => (new ProductTab)->translationsForEditor(),
            'limits' => [
                'max_title' => 120,
                'max_position' => ProductTabs::MAX_POSITION,
                'max_per_scope' => self::MAX_PER_SCOPE,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->create($request, null);
    }

    /**
     * Save one row, global or per-product, override or not.
     *
     * One method for all four shapes because they are one row with one
     * validator. What a row IS was decided when it was created, by the route
     * that created it, and nothing here can change it: `product_id` and
     * `source_key` are not in the rules and are never assigned.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tab = ProductTab::find($id);

        if (! $tab) {
            return response()->json(['ok' => false, 'message' => 'That tab no longer exists.'], 404);
        }

        $rules = $this->rules($tab->source_key !== null);
        $data = $request->validate($rules + TranslationInput::rules($tab, $rules));

        $this->fill($tab, $data);
        $tab->save();

        $tab->saveTranslations(TranslationInput::fromRequest($request, self::RICH_FIELDS));

        return response()->json(['ok' => true, 'tab' => $this->payload($tab->refresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        $tab = ProductTab::find($id);

        if (! $tab) {
            return response()->json(['ok' => false, 'message' => 'That tab no longer exists.'], 404);
        }

        /*
         * The translations go with it. They are rows in another table keyed by
         * (group, item_id), so nothing deletes them by cascade -- and an id is
         * reused by the next AUTO_INCREMENT on a table that has had rows
         * deleted, which would hand a brand new tab somebody else's Arabic.
         * Both writes in one transaction so a failure leaves neither half.
         */
        DB::transaction(static function () use ($tab): void {
            $tab->translations()->delete();
            $tab->delete();
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Re-order in one request.
     *
     * Dragging a list and saving one PUT per row is N requests and a screen
     * that is half-saved if one of them fails. This takes the whole arrangement
     * and writes it in a transaction.
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'max:' . (self::MAX_PER_SCOPE * 2)],
            'order.*.id' => ['required', 'integer', 'min:1'],
            'order.*.position' => ['required', 'integer', 'min:0', 'max:' . ProductTabs::MAX_POSITION],
        ]);

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $data['order']);

        /*
         * Fetched first, so a row belonging to another scope -- or no row at
         * all -- simply is not written rather than being created. An id that
         * arrives is a row this endpoint may move or it is nothing.
         */
        $rows = ProductTab::query()->whereIn('id', $ids)->get()->keyBy('id');

        DB::transaction(static function () use ($data, $rows): void {
            foreach ($data['order'] as $entry) {
                $row = $rows->get((int) $entry['id']);

                if (! $row) {
                    continue;
                }

                $row->position = max(0, min(ProductTabs::MAX_POSITION, (int) $entry['position']));
                $row->save();
            }
        });

        return response()->json(['ok' => true]);
    }

    /* --------------------------------------------------------- per product */

    /**
     * One product's whole tab picture: what it inherits and what it has done
     * about it.
     *
     * The screen needs all three states of every inherited tab -- inherited,
     * overridden, hidden here -- and it needs them for the built-ins as well as
     * for the globals, so they are assembled here rather than left to the
     * browser to work out from two lists.
     */
    public function forProduct(int $productId): JsonResponse
    {
        $product = Product::query()->select('id', 'name', 'slug', 'status')->find($productId);

        if (! $product) {
            return response()->json(['ok' => false, 'message' => 'That product no longer exists.'], 404);
        }

        $rows = $this->rows($productId);
        $overrides = [];
        $own = [];

        foreach ($rows as $row) {
            if ($row->source_key === null) {
                $own[] = $this->payload($row);

                continue;
            }

            $overrides[(string) $row->source_key] = $row;
        }

        $inherited = [];

        foreach ($this->builtinPayload() as $builtin) {
            $inherited[] = $this->inheritedPayload($builtin, $overrides);
        }

        foreach ($this->rows(null) as $global) {
            if (! $global->is_enabled) {
                // Switched off shop-wide. It is not on this product either, and
                // showing it here with an "Override" button would offer the
                // owner a control that changes nothing.
                continue;
            }

            $inherited[] = $this->inheritedPayload([
                'key' => 'global:' . $global->id,
                'label' => (string) $global->title,
                'title' => (string) $global->title,
                'body' => (string) ($global->body ?? ''),
                'position' => (int) $global->position,
                'kind' => 'global',
            ], $overrides);
        }

        return response()->json([
            'ok' => true,
            'product' => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'slug' => (string) $product->slug,
                'status' => (string) $product->status,
            ],
            'inherited' => $inherited,
            'own' => $own,
            // The blank shape again, for this product's own add form.
            'translations' => (new ProductTab)->translationsForEditor(),
            'limits' => [
                'max_title' => 120,
                'max_position' => ProductTabs::MAX_POSITION,
                'max_per_scope' => self::MAX_PER_SCOPE,
            ],
        ]);
    }

    /** A tab that exists on this product and nowhere else. */
    public function storeForProduct(Request $request, int $productId): JsonResponse
    {
        if (! Product::query()->whereKey($productId)->exists()) {
            return response()->json(['ok' => false, 'message' => 'That product no longer exists.'], 404);
        }

        return $this->create($request, $productId);
    }

    /**
     * Hide, override or restore one inherited tab on one product.
     *
     * Three acts, one endpoint, because they are three states of ONE row and
     * splitting them would let a screen create two rows for the same key.
     *
     *   mode = hide      is_enabled 0, nothing else needed
     *   mode = override  is_enabled 1, plus whichever of title/body is filled
     *   mode = inherit   the row is DELETED, which is the only way to say "back
     *                    to whatever the global says" that survives the global
     *                    being edited afterwards
     */
    public function override(Request $request, int $productId): JsonResponse
    {
        if (! Product::query()->whereKey($productId)->exists()) {
            return response()->json(['ok' => false, 'message' => 'That product no longer exists.'], 404);
        }

        $base = [
            'source_key' => ['required', 'string', 'max:64', 'regex:' . ProductTabs::SOURCE_KEY_PATTERN],
            'mode' => ['required', 'string', 'in:hide,override,inherit'],
        ];

        $rules = $base + $this->rules(true);

        /*
         * THE ARABIC BAG IS VALIDATED HERE TOO, and it was not in the first
         * shape of this method. TranslationInput::rules() DERIVES the Arabic
         * bounds from the English ones above -- a rule restated is a rule that
         * drifts -- so without this line the English title was capped at 120
         * and the Arabic one was not capped at all.
         */
        $data = $request->validate($rules + TranslationInput::rules(new ProductTab, $rules));

        $key = (string) $data['source_key'];

        if (! $this->sourceKeyExists($key)) {
            return response()->json([
                'ok' => false,
                'message' => 'That tab no longer exists, so there is nothing to override here.',
            ], 422);
        }

        $existing = ProductTab::query()
            ->where('product_id', $productId)
            ->where('source_key', $key)
            ->first();

        if ($data['mode'] === 'inherit') {
            if ($existing) {
                DB::transaction(static function () use ($existing): void {
                    $existing->translations()->delete();
                    $existing->delete();
                });
            }

            return response()->json(['ok' => true, 'mode' => 'inherit']);
        }

        $tab = $existing ?? new ProductTab([
            'product_id' => $productId,
            'source_key' => $key,
            // BOTH EMPTY, and the column is NOT NULL. An empty box on an
            // override row means INHERIT -- see ProductTabs::applyOverrides --
            // so a hide is a row with no text of its own at all, and that has
            // to be storable. `null` is not: `title` is NOT NULL, which is
            // right, because a row whose title is missing and a row whose title
            // is deliberately blank must not be two different states.
            'title' => '',
            'body' => '',
            'position' => $this->basePosition($key),
        ]);

        // Set from the route and the validated key, never from the body. An
        // existing row keeps the scope it was created with.
        $tab->product_id = $productId;
        $tab->source_key = $key;

        $this->fill($tab, $data, true);
        $tab->is_enabled = $data['mode'] === 'override';
        $tab->save();

        $tab->saveTranslations(TranslationInput::fromRequest($request, self::RICH_FIELDS));

        return response()->json(['ok' => true, 'mode' => $data['mode'], 'tab' => $this->payload($tab->refresh())]);
    }

    /* ------------------------------------------------------------- picker */

    /**
     * The product picker's search.
     *
     * The same LIKE-with-an-explicit-ESCAPE shape ProductEditorApiController
     * uses, and for the same reason it documents: MySQL treats a backslash as
     * the default LIKE escape and SQLite has no default at all, so a term
     * containing '%' finds a product on exactly one of the two engines.
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $query = Product::query()->select('id', 'name', 'slug', 'sku', 'status', 'image');

        if ($term !== '') {
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';

            $query->where(function ($q) use ($pattern): void {
                $q->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        $scoped = ProductTabs::scopedProductIds();

        return response()->json([
            'ok' => true,
            'products' => $query->orderByDesc('id')->limit(30)->get()->map(fn (Product $p): array => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'slug' => (string) $p->slug,
                'sku' => $p->sku,
                'image' => $p->image,
                'status' => (string) $p->status,
                // So the picker can mark the products that already carry a tab,
                // which is the question "which ones have I done" in one glance.
                'has_tabs' => in_array((int) $p->id, $scoped, true),
            ])->values(),
        ]);
    }

    /* ------------------------------------------------------------ internals */

    /** @return array<string, list<string>> */
    private function rules(bool $titleOptional): array
    {
        return [
            // Optional on an OVERRIDE row, where an empty box means inherit the
            // global's own title, and required everywhere else, where an empty
            // title is a tab the storefront would drop on sight.
            'title' => [$titleOptional ? 'nullable' : 'required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:' . self::MAX_BODY],
            'position' => ['nullable', 'integer', 'min:0', 'max:' . ProductTabs::MAX_POSITION],
            'is_enabled' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The English half of a write.
     *
     * @param  array<string, mixed>  $data
     */
    private function fill(ProductTab $tab, array $data, bool $keepEnabled = false): void
    {
        if (array_key_exists('title', $data)) {
            // Stored as typed, trimmed and cut to the column's own width. Not
            // sanitised: see rule 2 in this file's header.
            $tab->title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 120);
        }

        if (array_key_exists('body', $data)) {
            // On the way IN, on the server, every time -- whatever the payload
            // claims to be and whichever editor it came from.
            $tab->body = RichText::clean((string) ($data['body'] ?? ''));
        }

        if (array_key_exists('position', $data) && $data['position'] !== null) {
            // Bounded a second time. The validator already refused anything
            // outside the range; this is what the row is actually written with.
            $tab->position = max(0, min(ProductTabs::MAX_POSITION, (int) $data['position']));
        }

        if (! $keepEnabled && array_key_exists('is_enabled', $data) && $data['is_enabled'] !== null) {
            $tab->is_enabled = (bool) $data['is_enabled'];
        }
    }

    private function create(Request $request, ?int $productId): JsonResponse
    {
        $count = ProductTab::query()
            ->when($productId === null,
                static fn ($q) => $q->whereNull('product_id'),
                static fn ($q) => $q->where('product_id', $productId))
            ->whereNull('source_key')
            ->count();

        if ($count >= self::MAX_PER_SCOPE) {
            return response()->json([
                'ok' => false,
                'message' => 'That is already ' . self::MAX_PER_SCOPE . ' tabs. A product page is a tab strip, not a catalogue.',
            ], 422);
        }

        $rules = $this->rules(false);
        $data = $request->validate($rules + TranslationInput::rules(new ProductTab, $rules));

        $tab = new ProductTab([
            // From the route, never from the body. See rule 5.
            'product_id' => $productId,
            'source_key' => null,
            'position' => $this->nextPosition($productId),
            'is_enabled' => true,
        ]);

        $this->fill($tab, $data);
        $tab->save();

        $tab->saveTranslations(TranslationInput::fromRequest($request, self::RICH_FIELDS));

        return response()->json(['ok' => true, 'tab' => $this->payload($tab->refresh())], 201);
    }

    /**
     * Every row in one scope, in the order the storefront reads them.
     *
     * @return list<ProductTab>
     */
    private function rows(?int $productId): array
    {
        return ProductTab::query()
            ->when($productId === null,
                static fn ($q) => $q->whereNull('product_id'),
                static fn ($q) => $q->where('product_id', $productId))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * One row on the wire.
     *
     * AN ALLOWLIST, not the model. These endpoints are behind `auth:admin` and
     * a capability, so this is not the /api/* rule -- but a payload built from
     * a column list is a payload that grows a column the day somebody adds one,
     * and this is the habit CLAUDE.md asks for either way. `created_at` and
     * `updated_at` are left off because no screen shows them.
     *
     * @return array<string, mixed>
     */
    private function payload(ProductTab $tab): array
    {
        return [
            'id' => (int) $tab->id,
            'product_id' => $tab->product_id === null ? null : (int) $tab->product_id,
            'source_key' => $tab->source_key === null ? null : (string) $tab->source_key,
            'title' => (string) $tab->title,
            'body' => (string) ($tab->body ?? ''),
            'position' => (int) $tab->position,
            'is_enabled' => (bool) $tab->is_enabled,
            'translations' => $tab->translationsForEditor(),
        ];
    }

    /**
     * The three built-in tabs as the screen sees them.
     *
     * Their bodies are NOT here, and that is deliberate: a built-in's body is a
     * column on each product, so there is no one body to show on a shop-wide
     * screen. The row exists so the owner can see where Description sits in the
     * order, and move it.
     *
     * @return list<array<string, mixed>>
     */
    private function builtinPayload(): array
    {
        $out = [];

        foreach (ProductTabs::BUILTINS as $key => $spec) {
            $out[] = [
                'key' => 'builtin:' . $key,
                // The heading as the shop prints it, through the same interface
                // string, so the screen and the storefront cannot disagree
                // about what the tab is called.
                'label' => (string) __($spec['string']),
                'title' => (string) __($spec['string']),
                'body' => '',
                'position' => (int) $spec['position'],
                'kind' => 'builtin',
                'field' => (string) $spec['field'],
            ];
        }

        return $out;
    }

    /**
     * One inherited tab plus what this product has done about it.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, ProductTab>  $overrides
     * @return array<string, mixed>
     */
    private function inheritedPayload(array $base, array $overrides): array
    {
        $row = $overrides[(string) $base['key']] ?? null;

        $base['state'] = 'inherited';
        $base['row'] = null;

        if ($row !== null) {
            $base['state'] = $row->is_enabled ? 'overridden' : 'hidden';
            $base['row'] = $this->payload($row);
            $base['position'] = (int) $row->position;
        }

        return $base;
    }

    /** Does the tab this key names actually exist? */
    private function sourceKeyExists(string $key): bool
    {
        if (str_starts_with($key, 'builtin:')) {
            return array_key_exists(substr($key, 8), ProductTabs::BUILTINS);
        }

        return ProductTab::query()
            ->whereKey((int) substr($key, 7))
            ->whereNull('product_id')
            ->exists();
    }

    /** Where an override starts, so creating one moves nothing. */
    private function basePosition(string $key): int
    {
        if (str_starts_with($key, 'builtin:')) {
            return (int) (ProductTabs::BUILTINS[substr($key, 8)]['position'] ?? 0);
        }

        $global = ProductTab::query()->whereKey((int) substr($key, 7))->first();

        return $global ? (int) $global->position : ProductTabs::DEFAULT_GLOBAL_POSITION;
    }

    /** Ten past the last tab in this scope, so a new one lands at the end. */
    private function nextPosition(?int $productId): int
    {
        $floor = $productId === null
            ? ProductTabs::DEFAULT_GLOBAL_POSITION
            : ProductTabs::DEFAULT_PRODUCT_POSITION;

        $max = (int) ProductTab::query()
            ->when($productId === null,
                static fn ($q) => $q->whereNull('product_id'),
                static fn ($q) => $q->where('product_id', $productId))
            ->max('position');

        return min(ProductTabs::MAX_POSITION, max($floor, $max + 10));
    }
}

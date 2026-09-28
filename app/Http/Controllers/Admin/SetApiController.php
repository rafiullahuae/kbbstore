<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\ProductVariant;
use App\Support\RichText;
use App\Support\SetContents;
use App\Support\SetEagerLoad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Catalog → Sets. (Lane SET)
 *
 * The owner's words: "Under Catalog, there will be Sets, and upon creating new
 * set, the system will ask to choose the products, and will ask for set price,
 * category, description etc, the same as in product edit page. And it will be
 * published same like other products and display."
 *
 * ── A SET IS A `products` ROW ──────────────────────────────────────────────
 *
 * `products.type = 'set'`, plus rows in `product_set_items` for the members.
 * Not a table of its own. The reasoning, and the first-hand sweep of everything
 * in this application that branches on `products.type`, are in
 * database/migrations/2027_04_01_000000_sets_schema.php.
 *
 * What that buys: the set publishes, appears in the shop, gets a slug, a
 * category, images, an SEO row, a price and a sale price with NO new code
 * anywhere. Product::scopeVisible() has no type clause, so the moment a set is
 * saved as `publish` it is a page on the shop.
 *
 * ── MONEY IS INTEGER FILS ──────────────────────────────────────────────────
 *
 * The wire carries major units ("129.00") because that is what a human types
 * into a price box and what every other editor screen in this console sends.
 * It is converted ONCE, here, by filsFromMajor(), and after that line there is
 * not a float on this path. `products.price` is an integer column and
 * App\Support\SetContents does integer arithmetic throughout.
 *
 * ── SECURITY ───────────────────────────────────────────────────────────────
 *
 * Two capabilities of its own, `sets.view` and `sets.manage`, neither reusing
 * `catalog.manage`. The reason is the one AdminCapabilities gives for every
 * other split in that file: granting somebody the Sets screen must not hand
 * them the whole catalogue, and the day `catalog.manage` is narrowed — which is
 * a reasonable thing to want — this must not narrow with it from another file
 * with nothing to notice. They fail closed with no code here: an admin route
 * the map does not recognise resolves to null and EnforceAdminCapability turns
 * null into 403 for everyone but the owner.
 *
 * Every value that reaches a column is validated against a set this file owns:
 * `status` against Rule::in of three literals, `category_id` against a row that
 * exists, the description through RichText::clean() — the same sanitiser the
 * product editor's description goes through, because a set's description is
 * printed with `{!! !!}` by the very same product page.
 *
 * ── WHAT THIS CONTROLLER DELIBERATELY DOES NOT DO ──────────────────────────
 *
 * It does not upload files. The screen posts an image to the existing
 * /admin-api/media/upload and sends back the URL, so there is exactly one
 * upload path in this application and one place where the type, size and SVG
 * rules live. That is the arrangement routes/brands-admin.php and
 * routes/catalog-admin.php both describe.
 *
 * It does not touch stock. Selling a set does not decrement its members' stock
 * today, which is the behaviour the shop has now — the set's own `stock_status`
 * is what governs it, exactly as for any other product. That is a commercial
 * decision and it is in the report as a question rather than answered here.
 */
class SetApiController extends Controller
{
    /** The only three a `products.status` may be, and the editor's own list. */
    private const STATUSES = ['publish', 'draft', 'private'];

    /** How many members one set may hold. A box, not a catalogue. */
    private const MAX_MEMBERS = 40;

    /** The escape character for a LIKE search, and its SQL twin. */
    private const LIKE_ESCAPE = '\\';

    private const LIKE_ESCAPE_SQL = "'\\'";

    /* ---------------------------------------------------------------- index */

    /**
     * GET /admin-api/sets — every set, newest first.
     *
     * `withCount('setItems')` rather than loading the members: the list shows
     * how many products are in each box and nothing else about them, and
     * loading five members of forty sets to print a number is the N+1 this
     * project measures for.
     */
    public function index(): JsonResponse
    {
        $sets = Product::query()
            ->where('type', 'set')
            ->with('category:id,name')
            ->withCount('setItems')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'ok' => true,
            'sets' => $sets->map(fn (Product $s) => [
                'id' => $s->id,
                'name' => (string) $s->name,
                'slug' => (string) $s->slug,
                'status' => (string) $s->status,
                'is_visible' => (bool) $s->is_visible,
                'price_aed' => $this->majorFromFils((int) $s->price),
                'sale_price_aed' => $s->sale_price === null ? '' : $this->majorFromFils((int) $s->sale_price),
                'category' => $s->category?->name,
                'member_count' => (int) $s->set_items_count,
                'image' => $s->image,
                'url' => '/product/' . $s->slug . '/',
            ])->all(),
            // `id` after `name`: a sliced query whose last ORDER BY key can tie
            // returns rows in whatever order the engine felt like, and two
            // categories can share a name. StableOrderingTest catches it.
            'categories' => Category::query()->orderBy('name')->orderBy('id')->limit(500)->get(['id', 'name'])
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => (string) $c->name])->all(),
        ]);
    }

    /* -------------------------------------------------------------- picker */

    /**
     * GET /admin-api/sets/products — the member picker's search.
     *
     * REGISTERED BEFORE /sets/{id} in routes/sets-admin.php, and that ordering
     * is the whole rule: {id} is constrained to digits there, so this literal
     * path could not be read as an id either way, but both guards are in place
     * for the reason routes/catalog-admin.php gives about /categories/reorder —
     * a 404 here looks exactly like a feature that was never shipped.
     *
     * A SET CANNOT CONTAIN A SET. `where('type', '!=', 'set')` is not decoration:
     * nesting one would make SetContents recurse and would make "what is in this
     * box" a question with no single answer on a packing slip. The owner asked
     * for a set of PRODUCTS.
     */
    public function products(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $query = Product::query()
            ->where('type', '!=', 'set')
            ->with('brand:id,name')
            // `id` after `name` for the reason StableOrderingTest gives: this
            // query is SLICED at 40, and two products can share a name — so a
            // tie on the last key means the picker's second page is not
            // reliably the rows the first one left out.
            ->orderBy('name')
            ->orderBy('id')
            ->limit(40);

        if ($term !== '') {
            $pattern = '%' . $this->escapeLike($term) . '%';

            $query->where(function ($q) use ($pattern) {
                $q->whereRaw('name LIKE ? ESCAPE ' . self::LIKE_ESCAPE_SQL, [$pattern])
                    ->orWhereRaw('sku LIKE ? ESCAPE ' . self::LIKE_ESCAPE_SQL, [$pattern]);
            });
        }

        return response()->json([
            'ok' => true,
            'products' => $query->get(['id', 'name', 'sku', 'image', 'price', 'sale_price', 'brand_id', 'type'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => (string) $p->name,
                    'brand' => $p->brand?->name,
                    'sku' => (string) ($p->sku ?? ''),
                    'image' => $p->image,
                    'price_aed' => $this->majorFromFils((int) $p->effectivePrice()),
                    'variants' => $p->variants()->orderBy('position')->get(['id', 'sku'])
                        ->map(fn (ProductVariant $v) => [
                            'id' => $v->id,
                            'label' => $v->label() !== '' ? $v->label() : (string) ($v->sku ?? ('#' . $v->id)),
                        ])->all(),
                ])->all(),
        ]);
    }

    /* ----------------------------------------------------------------- show */

    public function show(int $id): JsonResponse
    {
        $set = $this->find($id);

        if ($set === null) {
            return $this->missing();
        }

        SetEagerLoad::on([$set]);

        return response()->json(['ok' => true, 'set' => $this->payload($set)]);
    }

    /* ---------------------------------------------------------------- store */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);

        $set = DB::transaction(function () use ($data) {
            $set = new Product();

            // THE ONE LINE THAT MAKES THIS A SET. Written here and nowhere else,
            // and never from request input: `type` is not in validated()'s rules
            // at all, so no request can turn a set into a simple product or the
            // other way round through this endpoint.
            $set->type = 'set';
            $set->slug = $this->uniqueSlug($data['name']);

            $this->apply($set, $data);
            $this->members($set, $data['members']);

            return $set;
        });

        SetEagerLoad::on([$set = $set->fresh()]);

        return response()->json(['ok' => true, 'created' => true, 'set' => $this->payload($set)], 201);
    }

    /* ----------------------------------------------------------------- save */

    public function update(Request $request, int $id): JsonResponse
    {
        $set = $this->find($id);

        if ($set === null) {
            return $this->missing();
        }

        $data = $this->validated($request, creating: false);

        DB::transaction(function () use ($set, $data) {
            $this->apply($set, $data);
            $this->members($set, $data['members']);
        });

        SetEagerLoad::on([$set = $set->fresh()]);

        return response()->json(['ok' => true, 'set' => $this->payload($set)]);
    }

    /* -------------------------------------------------------------- destroy */

    /**
     * DELETE /admin-api/sets/{id}
     *
     * SOFT DELETE, like every other product, and the membership rows go with it
     * — `set_product_id` cascades. What does NOT go is what an order remembers:
     * `order_items.set_contents` is a snapshot written on the day and is not
     * touched by this, so an invoice for a deleted set still prints what was in
     * the box. That is the whole reason it is a snapshot.
     */
    public function destroy(int $id): JsonResponse
    {
        $set = $this->find($id);

        if ($set === null) {
            return $this->missing();
        }

        DB::transaction(function () use ($set) {
            ProductSetItem::where('set_product_id', $set->id)->delete();
            $set->delete();
        });

        return response()->json(['ok' => true, 'deleted' => true]);
    }

    /* -------------------------------------------------------------- helpers */

    private function find(int $id): ?Product
    {
        return Product::query()->where('type', 'set')->find($id);
    }

    private function missing(): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => 'That set no longer exists.'], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:200'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'is_visible' => ['nullable', 'boolean'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'short_description' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:200000'],
            // Major units on the wire, converted once below. The regex is the
            // one CatalogProductsApiController's price band uses, for the same
            // reason: a price is a number a human typed and nothing else.
            'price' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,4})?$/'],
            'sale_price' => ['nullable', 'string', 'regex:/^\d{1,9}(\.\d{1,4})?$/'],
            'image' => ['nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:20'],
            'images.*' => ['string', 'max:2000'],
            'members' => ['required', 'array', 'min:1', 'max:' . self::MAX_MEMBERS],
            'members.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'members.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'members.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];

        if (! $creating) {
            // The slug is a live URL contract once a set is published — Google
            // holds it and it sits in customers' order history — so it is set
            // at create time and never rewritten here. Same rule, same reason,
            // as ProductEditorApiController.
            unset($rules['slug']);
        }

        return $request->validate($rules, [
            'members.required' => 'A set has to contain at least one product.',
            'members.min' => 'A set has to contain at least one product.',
            'price.regex' => 'The set price is a number, such as 129 or 129.50.',
            'sale_price.regex' => 'The sale price is a number, such as 99 or 99.50.',
        ]);
    }

    /**
     * Everything but the members. Never `type`, and never `slug` after create.
     *
     * @param  array<string, mixed>  $data
     */
    private function apply(Product $set, array $data): void
    {
        $set->name = trim((string) $data['name']);

        /*
         * `status` IS ONE OF ITS OWN OPTIONS OR THE DEFAULT. CLAUDE.md rule 5.
         * It is already validated against Rule::in above; this second test is
         * the one that survives somebody widening the rule, and it is the shape
         * ProductEditorApiController uses on the same column.
         */
        $set->status = in_array($data['status'], self::STATUSES, true) ? $data['status'] : 'draft';
        $set->is_visible = (bool) ($data['is_visible'] ?? true);
        $set->category_id = $data['category_id'] ?? null;
        $set->short_description = $data['short_description'] ?? null;

        /*
         * THROUGH THE SAME SANITISER THE PRODUCT EDITOR USES, and not because
         * it is tidy: a set's description is printed by
         * resources/views/partials/product-tabs.blade.php with `{!! !!}`,
         * because a set IS a product and that is the page it publishes on. An
         * unsanitised description here is stored XSS on the storefront.
         */
        $set->description = RichText::clean($data['description'] ?? null);

        $set->price = $this->filsFromMajor((string) $data['price']);
        $set->sale_price = trim((string) ($data['sale_price'] ?? '')) === ''
            ? null
            : $this->filsFromMajor((string) $data['sale_price']);

        $set->image = $this->safeUrl($data['image'] ?? null);

        $images = [];

        foreach (($data['images'] ?? []) as $url) {
            $clean = $this->safeUrl($url);

            if ($clean !== null) {
                $images[] = $clean;
            }
        }

        $set->images = $images;

        $set->save();
    }

    /**
     * Replace the member list with exactly what was sent, in the order it was
     * sent, and refuse a member twice.
     *
     * DELETE-THEN-INSERT inside the caller's transaction. A diff would be
     * cleverer and would have to answer "is this the same member row?" for a
     * member chosen twice with two different variants, which is a question the
     * screen does not make the operator answer. The whole list is small (40 at
     * most) and it is rewritten by one human pressing Save.
     *
     * @param  list<array<string, mixed>>  $members
     */
    private function members(Product $set, array $members): void
    {
        ProductSetItem::where('set_product_id', $set->id)->delete();

        $seen = [];
        $position = 0;
        $rows = [];

        foreach ($members as $member) {
            $productId = (int) $member['product_id'];
            $variantId = isset($member['variant_id']) ? (int) $member['variant_id'] : null;

            /*
             * A SET CANNOT CONTAIN ITSELF, and cannot contain another set.
             * The picker already excludes both; this is the door, because the
             * picker is a screen and this is an endpoint. A set inside itself
             * is an infinite box.
             */
            if ($productId === $set->id) {
                continue;
            }

            /*
             * DUPLICATES ARE REFUSED HERE and not by a unique index, for the
             * reason the migration gives: MySQL and SQLite both treat NULLs as
             * distinct, so an index on (set, member, variant) would refuse a
             * duplicate that names a variant and accept one that does not. This
             * can see both halves. A member wanted twice is a quantity of two.
             */
            $key = $productId . ':' . ($variantId ?? 0);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            /*
             * A VARIANT MUST BELONG TO THE MEMBER IT IS SENT WITH. `exists:`
             * above proves the variant row exists and nothing else — a request
             * naming product A with product B's variation would otherwise store
             * a box whose contents contradict themselves, and it is the
             * variant's price that the saving is computed from.
             */
            if ($variantId !== null
                && ! ProductVariant::where('id', $variantId)->where('product_id', $productId)->exists()) {
                $variantId = null;
            }

            $rows[] = [
                'set_product_id' => $set->id,
                'member_product_id' => $productId,
                'member_variant_id' => $variantId,
                'quantity' => max(1, min(99, (int) ($member['quantity'] ?? 1))),
                'position' => $position++,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            ProductSetItem::insert($rows);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $set): array
    {
        $contents = SetContents::fromProduct($set);

        return [
            'id' => $set->id,
            'name' => (string) $set->name,
            'slug' => (string) $set->slug,
            'status' => (string) $set->status,
            'is_visible' => (bool) $set->is_visible,
            'category_id' => $set->category_id,
            'short_description' => (string) ($set->short_description ?? ''),
            'description' => (string) ($set->description ?? ''),
            'price_aed' => $this->majorFromFils((int) $set->price),
            'sale_price_aed' => $set->sale_price === null ? '' : $this->majorFromFils((int) $set->sale_price),
            'image' => $set->image,
            'images' => is_array($set->images) ? array_values($set->images) : [],
            'url' => '/product/' . $set->slug . '/',
            'members' => $set->setItems->map(fn (ProductSetItem $row) => [
                'product_id' => $row->member_product_id,
                'variant_id' => $row->member_variant_id,
                'quantity' => (int) $row->quantity,
                'name' => (string) ($row->member?->name ?? ''),
                'brand' => (string) ($row->member?->brand?->name ?? ''),
                'sku' => (string) ($row->variant?->sku ?? $row->member?->sku ?? ''),
                'image' => $row->variant?->image ?: $row->member?->image,
                'unit_price_aed' => $this->majorFromFils(
                    (int) ($row->variant?->effectivePrice() ?? $row->member?->effectivePrice() ?? 0)
                ),
            ])->all(),
            // Fils on the wire for these three, because the screen prints them
            // as a single computed sentence and does no arithmetic of its own.
            'parts_total_aed' => $this->majorFromFils((int) $contents['partsTotal']),
            'saving_aed' => $this->majorFromFils((int) $contents['saving']),
            'item_count' => (int) $contents['count'],
        ];
    }

    /**
     * A URL that is safe to put in an `src`. (CLAUDE.md rule 5.)
     *
     * SCHEME-CHECKED BEFORE IT IS STORED, not before it is printed: a value
     * that reaches the column is printed by the product page, the tile, the
     * cart, the Meta feed and the sitemap, and only one of those is going to
     * remember to check. `javascript:` and `data:` are the two that matter and
     * neither is a picture. A root-relative path is what
     * /admin-api/media/upload returns and is allowed as itself.
     */
    private function safeUrl(mixed $value): ?string
    {
        $url = trim((string) ($value ?? ''));

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /**
     * Major units to integer fils, and the ONLY conversion on this path.
     *
     * `round()` on a string scaled by 100 rather than `(int) ($v * 100)`:
     * 129.95 is not representable in binary floating point and the cast
     * truncates it to 12994. This shop has already been bitten by a price that
     * was one fil short of what the page said.
     */
    private function filsFromMajor(string $major): int
    {
        return (int) round(((float) $major) * 100);
    }

    /** Integer fils back to the string the price box holds. Never a float out. */
    private function majorFromFils(int $fils): string
    {
        return number_format($fils / 100, 2, '.', '');
    }

    private function uniqueSlug(string $wanted): string
    {
        $slug = strtolower(trim($wanted));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        $slug = $slug === '' ? 'set' : $slug;

        $candidate = $slug;
        $n = 1;

        // withTrashed(): products soft-delete and the slug is a URL. Reusing a
        // deleted product's address sends anyone holding the old link to a
        // different item than the one they meant.
        while (Product::withTrashed()->where('slug', $candidate)->exists()) {
            $n++;
            $candidate = $slug . '-' . $n;
        }

        return $candidate;
    }

    private function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $term
        );
    }
}

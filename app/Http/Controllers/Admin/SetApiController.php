<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\ProductVariant;
use App\Support\SetEagerLoad;
use App\Support\SetPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * The escape character for a LIKE search, and its SQL twin.
     *
     * ▲ `!` AND NOT A BACKSLASH, which is what this shipped with and what the
     *   MySQL parity run caught. `ESCAPE '\\'` is a syntax error on MySQL —
     *   the backslash escapes the closing quote, and the server answers
     *   SQLSTATE[42000] 1064 on every search, so the member picker 500'd for
     *   every operator. SQLite accepts it, which is why a green SQLite suite
     *   said nothing.
     *
     *   `!` is the character every other search in this back office already
     *   uses — CatalogProductsApiController, ProductEditorApiController,
     *   ReviewAssignApiController and ReviewBulkApiController all declare
     *   exactly this pair — so this is the convention rather than a second
     *   answer to the same question.
     */
    private const LIKE_ESCAPE = '!';

    private const LIKE_ESCAPE_SQL = "'!'";

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

        /*
         * THE LIST PRINTS THE DERIVED PRICE, AND IT IS FLAT. (Lane SP)
         *
         * A set priced as a discount off its parts works its price out from the
         * members' current prices, and `products.price` is only a cache of that
         * which can lag when a member is repriced elsewhere. So the list asks
         * for the real figure — and asks for every set's members in THREE
         * batched queries rather than one per set, which is what SetEagerLoad
         * is for. Two hundred sets cost three statements, not two hundred.
         */
        SetEagerLoad::on($sets);

        return response()->json([
            'ok' => true,
            'sets' => $sets->map(fn (Product $s) => [
                'id' => $s->id,
                'name' => (string) $s->name,
                'slug' => (string) $s->slug,
                'status' => (string) $s->status,
                'is_visible' => (bool) $s->is_visible,
                'price_aed' => $this->majorFromFils((int) $s->effectivePrice()),
                'sale_price_aed' => $s->sale_price === null ? '' : $this->majorFromFils((int) $s->sale_price),
                'price_mode' => SetPricing::mode($s),
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

    /* ─────────────────────────────────────────────────────────────────────
       WHAT USED TO BE HERE, AND WHERE IT WENT. (Lane SP)

       show(), store() and update() -- read one set, create one, save one --
       are gone, with the Catalog → Sets editor that called them. A set is now
       created and saved on Catalog → Product editor, because a set IS a
       `products` row and that editor already owns every other thing one has:
       the slug, the status, the category, the descriptions, the images, the
       search appearance, the tags, the brand and the position.

       ProductEditorApiController::store() and ::save() carry the members and
       the pricing rule; ::show() returns them. Two endpoints that can both
       write a set is the same defect as two screens that can both edit one.

       WHAT REMAINS is the list, the member picker's catalogue search -- which
       the product editor now calls -- and delete.
       ───────────────────────────────────────────────────────────────────── */

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
    /* ─────────────────────────────────────────────────────────────────────
       AND THE HELPERS THAT WENT WITH THEM. (Lane SP)

       validated(), apply(), members(), applyPricing(), syncDerivedPrice(),
       tags(), payload(), safeUrl(), filsFromMajor() and uniqueSlug() were all
       reachable only from store() and update(). Deleted rather than left behind
       a flag: dead code that still compiles is code the next reader has to
       decide about, and two implementations of "save a set" is the exact shape
       this merge removed. Every one of them now has a single home in
       Admin\ProductEditorApiController, which was already doing the same job
       for every other kind of product.
       ───────────────────────────────────────────────────────────────────── */

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

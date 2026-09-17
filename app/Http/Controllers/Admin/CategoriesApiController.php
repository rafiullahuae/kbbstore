<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\ShopController;
use App\Models\Category;
use App\Support\CategoryPath;
use App\Support\TranslationInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Category CRUD for the admin — Catalog → Categories.
 *
 * The Categories tab rendered a hard-coded CAT_CATEGORIES array with
 * "(preview)" buttons: eleven invented rows, slugs derived in JavaScript from
 * the invented names, and no endpoint behind any of it. An owner could not
 * create a category anywhere in this admin. This is the endpoint that tab now
 * talks to, written to match Admin\BrandsApiController line for line where the
 * two screens do the same job — same response envelope, same slug derivation,
 * same delete-protection shape — because the two tabs sit next to each other
 * and behaving differently would be the surprise.
 *
 * What a category actually carries (see the `categories` table in
 * create_kbb_schema): slug, name, parent_id, description, image, position,
 * plus two cached columns — `depth` and `path` — and `source_term_id` from the
 * WooCommerce import. There are no SEO columns on this table, so the screen
 * does not pretend there are; product SEO lives in `products.seo` and the
 * category archive takes its title from the category name.
 *
 * `depth` and `path` are derived, never typed. `path` is the full nested slug
 * chain (skincare/face-cleansers/makeup-removers) and SeoFilesController reads
 * it straight out of the column to build the sitemap — a category created with
 * a null path would be listed in the sitemap under its bare slug, which for a
 * nested category is a URL that does not exist. So every structural write ends
 * by recomputing both columns for the whole table.
 *
 * `image` is a URL string, not an upload. Images go through
 * Admin\MediaUploadController (/admin-api/media/upload) exactly as the brand
 * logo does — one upload path, one set of type and size rules, one place where
 * the SVG screening lives.
 */
class CategoriesApiController extends Controller
{
    /** Production nests product_cat four levels deep; this is the ceiling, not the norm. */
    private const MAX_DEPTH = 10;

    /**
     * GET /admin-api/categories — the whole tree, with what is attached to each.
     *
     * Three counts, because a category is attached to products two different
     * ways and to other categories a third, and a delete that ignores any of
     * them loses data:
     *
     *   products_count  — rows in `category_product`, i.e. catalogue membership
     *   primary_count   — products whose `category_id` points here
     *   children_count  — child categories
     *
     * Correlated subqueries rather than joins: joining the pivot AND the
     * products table AND the self-join for children would multiply the rows
     * against each other and every count would be wrong. One query either way.
     */
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->select('categories.id', 'categories.slug', 'categories.name', 'categories.parent_id',
                'categories.description', 'categories.image', 'categories.position',
                'categories.depth', 'categories.path', 'categories.seo', 'categories.banner')
            // The headline count, and it has to agree with the archive page.
            //
            // It did not. This subquery filtered on `deleted_at IS NULL` alone,
            // while the page it labels renders Product::visible() —
            // status='publish' AND is_visible=1 — through
            // whereHas('categories', …). A category holding one live product,
            // one draft and one hidden product was labelled "3" beside a page
            // listing 1. Asserted both ways in CategoryCountHonestyTest.
            //
            // Product::visible() is not reusable here (this is a query-builder
            // subquery, not an Eloquent one), so the two clauses are spelled
            // out and a test pins them against the scope itself — if
            // visible() ever gains a third condition, that test fails rather
            // than this count quietly drifting again.
            ->selectSub(
                DB::table('category_product')
                    ->join('products', 'products.id', '=', 'category_product.product_id')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('category_product.category_id', 'categories.id')
                    ->whereNull('products.deleted_at')
                    ->where('products.status', 'publish')
                    ->where('products.is_visible', true),
                'products_count'
            )
            // Everything filed under it, live or not. Not the headline — the
            // owner still needs to see that a category holds eleven drafts,
            // or "0 products" on a category they just filled reads as a bug.
            ->selectSub(
                DB::table('category_product')
                    ->join('products', 'products.id', '=', 'category_product.product_id')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('category_product.category_id', 'categories.id')
                    ->whereNull('products.deleted_at'),
                'filed_count'
            )
            ->selectSub(
                DB::table('products')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('products.category_id', 'categories.id')
                    ->whereNull('products.deleted_at'),
                'primary_count'
            )
            ->selectSub(
                DB::table('categories as kids')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('kids.parent_id', 'categories.id'),
                'children_count'
            )
            ->orderBy('categories.depth')
            ->orderBy('categories.position')
            ->orderBy('categories.name')
            ->get();

        /*
         * THE ARABIC BOXES' PREFILL. (Lane EX, T4b)
         *
         * ONE query for the whole tree, not one per row. translationsForEditor()
         * is per model and would have added a query per category to a screen
         * that goes out of its way to be a fixed number of them — the same
         * argument the four selectSub counts above are written the way they are.
         *
         * Drafts included: a machine translation waiting for approval has to be
         * in the box, or "Approve" approves something invisible.
         */
        $translations = TranslationInput::editorMapFor($categories);

        // Set as an attribute so it rides along in the JSON, and NOT saved back:
        // `translations` is also the name of the trait's relation method and
        // there is no such column, so these instances are read-only from here.
        foreach ($categories as $category) {
            $category->setAttribute('translations', $translations[(int) $category->id] ?? null);
        }

        return response()->json([
            'ok' => true,
            'categories' => $categories,
            /*
             * The EMPTY shape of the Arabic boxes, for the "add" form — a row
             * being created has no translations but still has to draw a box for
             * every translatable field. Handed down from the server so the
             * screen never holds a second copy of Category::$translatable.
             */
            'translatable' => (new Category)->translationsForEditor(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $translations = $this->translationsFrom($data);

        $category = DB::transaction(function () use ($data, $translations) {
            $category = Category::query()->create($data);
            // After create(), because the row has no id before it. Same call on
            // both paths, in the same request and the same transaction as the
            // English row. See App\Support\TranslationInput.
            $category->saveTranslations($translations);
            $this->resyncTree();

            return $category;
        });

        $this->flushStorefrontCaches();

        return response()->json(['ok' => true, 'category' => $category->fresh()], 201);
    }

    /**
     * PUT /admin-api/categories/{category}
     *
     * RENAMING IS A URL DECISION, so it is made here rather than left to
     * whatever the operator happens to type.
     *
     * The display NAME and the URL SLUG are independent. Editing the name never
     * touches the slug — the derivation from the name only fires when the slug
     * arrives empty, which is the create case. This is the first and most
     * important protection, because almost every rename an owner performs is
     * cosmetic ("Sun Care" -> "Suncare & SPF") and must not move an indexed URL
     * at all. Asserted in CategoryPathContractTest.
     *
     * When the path genuinely does change — the operator edited the slug, or
     * re-parented the category — the old path is recorded in
     * `category_redirects` and answers 301 from then on. Deliberately a
     * redirect rather than a refusal: moving a category is a legitimate
     * merchandising act, and refusing it would make the tree less useful than
     * the spreadsheet it replaces. And deliberately not silence: silence is
     * what happens today, and because an unknown category path renders "Shop
     * all" with a 200 rather than 404ing, the breakage is invisible to
     * everyone except the ranking.
     *
     * The whole subtree is handled, not just this row. Re-parenting a category
     * with children rewrites every descendant's path too, so every descendant's
     * old path needs its own redirect — otherwise moving a parent silently
     * kills every child URL under it, which is the larger of the two blast
     * radii and the easy one to miss.
     */
    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $this->validated($request, $category);
        $translations = $this->translationsFrom($data);

        // Snapshot every path in this subtree BEFORE the write, keyed by id.
        $before = $this->subtreePaths($category);

        DB::transaction(function () use ($category, $data, $before, $translations) {
            $category->update($data);
            $category->saveTranslations($translations);
            $this->resyncTree();

            foreach ($before as $id => $oldPath) {
                $moved = Category::query()->find($id);

                if ($moved === null) {
                    continue;
                }

                $newPath = CategoryPath::canonicalPath($moved);

                if ($newPath !== $oldPath) {
                    CategoryPath::record($oldPath, $id, 'slug');
                }
            }
        });

        $this->flushStorefrontCaches();

        return response()->json([
            'ok' => true,
            'category' => $category->fresh(),
            'redirects' => $this->movedPaths($before),
        ]);
    }

    /**
     * DELETE /admin-api/categories/{category}
     *
     * The database would accept this quietly and do three destructive things
     * at once: `products.category_id` is nullable()->constrained()->nullOnDelete()
     * so every product whose primary category this is loses it; `category_product`
     * is cascadeOnDelete so every membership row vanishes, taking those products
     * out of the archive; and `categories.parent_id` is nullOnDelete so every
     * child category is flung to the top level with a stale `path`. On the live
     * catalogue that is one click to silently empty an archive page.
     *
     * So, exactly as the brands screen does: a category with anything attached
     * is refused with the counts, and the operator has to pass `force=1` to
     * mean it. The forced path then does each step explicitly inside a
     * transaction rather than leaning on the FK actions — the same delete
     * behaves identically on MySQL, on SQLite, and on any connection where the
     * constraints were never created.
     *
     * Products are never deleted, only detached. Children are re-parented to
     * this category's own parent rather than orphaned to the root, which keeps
     * the rest of the tree in the shape the operator built it.
     */
    public function destroy(Request $request, Category $category): JsonResponse
    {
        $counts = $this->attachmentCounts($category);
        $force = $request->boolean('force');

        if (! $force && ($counts['products_count'] > 0 || $counts['primary_count'] > 0 || $counts['children_count'] > 0)) {
            return response()->json([
                'ok' => false,
                'error' => 'category_in_use',
                'products_count' => $counts['products_count'],
                'primary_count' => $counts['primary_count'],
                'children_count' => $counts['children_count'],
                'message' => $this->inUseMessage($counts),
            ], 422);
        }

        $before = $this->subtreePaths($category);
        $ownPath = CategoryPath::canonicalPath($category);
        $parentId = $category->parent_id === null ? null : (int) $category->parent_id;

        DB::transaction(function () use ($category, $before, $ownPath, $parentId) {
            DB::table('products')
                ->where('category_id', $category->id)
                ->update(['category_id' => null]);

            DB::table('category_product')->where('category_id', $category->id)->delete();

            DB::table('categories')
                ->where('parent_id', $category->id)
                ->update(['parent_id' => $category->parent_id]);

            $category->delete();

            $this->resyncTree();

            // The deleted category's own URL. It points at the parent if there
            // is one — the nearest page that still means something to a
            // visitor who followed a link to "Ampoules" — and at nothing when
            // the category was top level, which resolves to a 404. A 404 is
            // the honest answer there; the alternative is redirecting every
            // dead archive to /shop/, which is a soft 404 wearing a 301 and is
            // treated as one.
            CategoryPath::record($ownPath, $parentId, 'delete');

            // Children were re-parented, so their paths moved too. Each old
            // child path redirects to that child's new home.
            foreach ($before as $id => $oldPath) {
                if ($oldPath === $ownPath) {
                    continue;
                }

                $moved = Category::query()->find($id);

                if ($moved === null) {
                    continue;
                }

                $newPath = CategoryPath::canonicalPath($moved);

                if ($newPath !== $oldPath) {
                    CategoryPath::record($oldPath, $id, 'move');
                }
            }
        });

        $this->flushStorefrontCaches();

        return response()->json([
            'ok' => true,
            'detached' => $counts['products_count'] + $counts['primary_count'],
            'reparented' => $counts['children_count'],
        ]);
    }

    /**
     * POST /admin-api/categories/{category}/merge — fold one category into
     * another and delete it.
     *
     * The safe answer to "this category should not exist any more, but it has
     * 40 products in it". A force-delete detaches those products and empties an
     * archive page that is probably indexed; a merge moves them somewhere real
     * and 301s the old URL there, so nothing is orphaned and no link dies.
     *
     * Refused when the target is the category itself or one of its own
     * descendants: merging a parent into its child would delete the child's
     * ancestor mid-operation and leave the subtree pointing at a row that no
     * longer exists.
     *
     * insertOrIgnore for the pivot, because a product may already be filed
     * under both. `category_product` has a unique pair index, so a plain insert
     * would raise on the first product the two categories share — which, for
     * two categories similar enough to be worth merging, is most of them.
     */
    public function merge(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'target_id' => ['required', 'integer', Rule::exists('categories', 'id')],
        ], [
            'target_id.exists' => 'That category no longer exists.',
        ]);

        $target = Category::query()->findOrFail((int) $data['target_id']);

        if ((int) $target->id === (int) $category->id) {
            throw ValidationException::withMessages([
                'target_id' => 'A category cannot be merged into itself.',
            ]);
        }

        if (in_array((int) $target->id, $this->descendantIds($category), true)) {
            throw ValidationException::withMessages([
                'target_id' => 'That category sits under this one. Move it out first, or merge the other way round.',
            ]);
        }

        $counts = $this->attachmentCounts($category);
        $before = $this->subtreePaths($category);
        $ownPath = CategoryPath::canonicalPath($category);

        DB::transaction(function () use ($category, $target, $before, $ownPath) {
            $productIds = DB::table('category_product')
                ->where('category_id', $category->id)
                ->pluck('product_id')
                ->all();

            foreach (array_chunk($productIds, 500) as $chunk) {
                DB::table('category_product')->insertOrIgnore(array_map(
                    fn ($pid) => ['category_id' => $target->id, 'product_id' => $pid],
                    $chunk
                ));
            }

            DB::table('category_product')->where('category_id', $category->id)->delete();

            // Products whose PRIMARY category this was follow it to the target
            // rather than being left with none.
            DB::table('products')
                ->where('category_id', $category->id)
                ->update(['category_id' => $target->id]);

            DB::table('categories')
                ->where('parent_id', $category->id)
                ->update(['parent_id' => $category->parent_id]);

            $category->delete();

            $this->resyncTree();

            CategoryPath::record($ownPath, (int) $target->id, 'merge');

            foreach ($before as $id => $oldPath) {
                if ($oldPath === $ownPath) {
                    continue;
                }

                $moved = Category::query()->find($id);

                if ($moved === null) {
                    continue;
                }

                $newPath = CategoryPath::canonicalPath($moved);

                if ($newPath !== $oldPath) {
                    CategoryPath::record($oldPath, $id, 'move');
                }
            }
        });

        $this->flushStorefrontCaches();

        return response()->json([
            'ok' => true,
            'moved' => $counts['products_count'],
            'reparented' => $counts['children_count'],
            'target' => $target->fresh(),
        ]);
    }

    /**
     * POST /admin-api/categories/reorder — the ▲▼ buttons on the tree.
     *
     * Takes the ids of one set of siblings in their new order and writes
     * `position` from that. Only ids that exist are written, and an id sent
     * twice is written once, so a stale page cannot corrupt the ordering of
     * rows it was not showing.
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1', 'max:500'],
            'order.*' => ['required', 'integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['order'])));

        $known = Category::query()->whereIn('id', $ids)->pluck('id')->all();
        $known = array_flip(array_map('intval', $known));

        DB::transaction(function () use ($ids, $known) {
            $position = 0;

            foreach ($ids as $id) {
                if (! isset($known[$id])) {
                    continue;
                }

                DB::table('categories')->where('id', $id)->update(['position' => $position++]);
            }
        });

        $this->flushStorefrontCaches();

        return response()->json(['ok' => true, 'ordered' => count($ids)]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Every cached surface that shows categories.
     *
     * NOTHING IN THIS CONTROLLER CALLED THIS BEFORE. ShopController::
     * flushSidebarCache() has existed all along and four other admin
     * controllers call it, but the one screen whose entire job is editing
     * categories did not. So creating, renaming, re-parenting, reordering or
     * deleting a category left the shop sidebar and the homepage tiles showing
     * the previous catalogue for the remaining life of a 900-second cache
     * entry — long enough for the owner to conclude the save had not worked
     * and do it again.
     *
     * kbb.home.cats is flushed here too: HomeController caches the category
     * tiles under its own key, which flushSidebarCache() does not touch,
     * because until now nothing that edits categories ever ran.
     */
    private function flushStorefrontCaches(): void
    {
        ShopController::flushSidebarCache();
        Cache::forget('kbb.home.cats');
        Cache::forget('kbb.home.rails');
    }

    /**
     * This category's path and every descendant's, keyed by id, as they are
     * right now.
     *
     * Taken before a structural write so the caller can see which paths moved
     * and record a redirect for each. Reads the whole table once: the tree is
     * tens of rows, and walking children with a query per level is how a
     * "cheap" helper becomes the slowest thing on the screen.
     *
     * @return array<int, string>
     */
    private function subtreePaths(Category $category): array
    {
        $ids = array_merge([(int) $category->id], $this->descendantIds($category));

        $paths = [];

        foreach (Category::query()->whereIn('id', $ids)->get() as $row) {
            $paths[(int) $row->id] = CategoryPath::canonicalPath($row);
        }

        return $paths;
    }

    /**
     * Ids of everything below this category.
     *
     * @return array<int, int>
     */
    private function descendantIds(Category $category): array
    {
        $rows = DB::table('categories')->select('id', 'parent_id')->get();

        $byParent = [];

        foreach ($rows as $row) {
            $byParent[$row->parent_id === null ? 0 : (int) $row->parent_id][] = (int) $row->id;
        }

        $out = [];
        $stack = $byParent[(int) $category->id] ?? [];
        $guard = 0;

        while ($stack !== [] && $guard++ < 10000) {
            $id = array_pop($stack);
            $out[] = $id;

            foreach ($byParent[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $out;
    }

    /**
     * Which of the snapshotted paths actually moved, and where to.
     *
     * Returned to the screen so it can tell the operator "2 URLs moved and now
     * redirect" instead of leaving them to find out from search traffic.
     *
     * @param  array<int, string>  $before
     * @return array<int, array{from:string, to:string}>
     */
    private function movedPaths(array $before): array
    {
        $moved = [];

        foreach ($before as $id => $oldPath) {
            $row = Category::query()->find($id);

            if ($row === null) {
                continue;
            }

            $newPath = CategoryPath::canonicalPath($row);

            if ($newPath !== $oldPath) {
                $moved[] = ['from' => $oldPath, 'to' => $newPath];
            }
        }

        return $moved;
    }

    /** @return array{products_count:int,primary_count:int,children_count:int} */
    private function attachmentCounts(Category $category): array
    {
        return [
            'products_count' => (int) DB::table('category_product')
                ->join('products', 'products.id', '=', 'category_product.product_id')
                ->where('category_product.category_id', $category->id)
                ->whereNull('products.deleted_at')
                ->count(),
            'primary_count' => (int) DB::table('products')
                ->where('category_id', $category->id)
                ->whereNull('deleted_at')
                ->count(),
            'children_count' => (int) DB::table('categories')
                ->where('parent_id', $category->id)
                ->count(),
        ];
    }

    /** @param array{products_count:int,primary_count:int,children_count:int} $counts */
    private function inUseMessage(array $counts): string
    {
        $parts = [];

        if ($counts['products_count'] > 0) {
            $parts[] = $counts['products_count'] . ' ' . Str::plural('product', $counts['products_count']) .
                ' ' . ($counts['products_count'] === 1 ? 'is' : 'are') . ' filed under it';
        }

        if ($counts['primary_count'] > 0) {
            $parts[] = $counts['primary_count'] . ' ' . Str::plural('product', $counts['primary_count']) .
                ' ' . ($counts['primary_count'] === 1 ? 'has' : 'have') . ' it as their primary category';
        }

        if ($counts['children_count'] > 0) {
            $parts[] = $counts['children_count'] . ' ' .
                ($counts['children_count'] === 1 ? 'sub-category sits' : 'sub-categories sit') . ' under it';
        }

        return 'This category cannot be removed on its own: ' . implode(', ', $parts) .
            '. Deleting it empties the archive page for those products and moves any sub-category up a level. The products themselves are kept.';
    }

    /**
     * Shared rules for create and edit.
     *
     * The slug is derived from the name when the operator leaves it blank, and
     * the derived value is validated too — otherwise a second "Sun Care" would
     * skip the uniqueness rule entirely and surface as a QueryException, i.e. a
     * 500 on a duplicate name. Same reasoning, same shape as the brands screen.
     */
    private function validated(Request $request, ?Category $category): array
    {
        $slug = Str::slug((string) $request->input('slug') ?: (string) $request->input('name'));
        $request->merge(['slug' => $slug]);

        $english = [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255',
                // Lower-case words joined by single hyphens. The slug is a URL
                // segment inside /product-category/{nested/path}/, so anything
                // else either does not round-trip or has to be encoded at every
                // use site.
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')->ignore($category?->id),
            ],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'description' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'string', 'max:2048'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            // Bounded deliberately. These land in a <title> and a
            // <meta name="description">, where anything past roughly 60 and
            // 160 characters is truncated by the search engine anyway, and an
            // unbounded string here is a row the operator can grow until the
            // column rejects it with a 500.
            'seo' => ['nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:255'],
            'seo.description' => ['nullable', 'string', 'max:500'],
            // The banner bag is validated as a shape only. Every field inside
            // it is clamped by App\Support\PageBanner::sanitize(), which is
            // also what the storefront reads it back through, so there is one
            // definition of what a banner is rather than a validation rule
            // here and a renderer somewhere else that disagree.
            'banner' => ['nullable', 'array'],
        ];

        /*
         * The Arabic boxes, shape-validated off the English rules above rather
         * than restated — `name` is max:255 in both languages because there is
         * one number. Required-ness does not carry: blank Arabic means "not
         * translated yet" and deletes the row. See App\Support\TranslationInput.
         */
        $data = $request->validate($english + TranslationInput::rules(new Category, $english), [
            'slug.regex' => 'The slug may contain only lower-case letters, numbers and single hyphens.',
            'slug.unique' => 'Another category already uses that slug.',
            'slug.required' => 'A category needs a name it can make a slug from.',
            'parent_id.exists' => 'That parent category no longer exists.',
        ]);

        $data['parent_id'] = $this->safeParentId($category, $data['parent_id'] ?? null);
        $data['image'] = $this->safeImageUrl($data['image'] ?? null);
        $data['position'] = (int) ($data['position'] ?? $category?->position ?? 0);

        // Only the two keys the screen edits are kept, and empties are dropped
        // rather than stored as "". A stored empty title is not the same as no
        // title: the archive would render an empty <title> instead of falling
        // back to the category name.
        $seo = array_filter([
            'title' => trim((string) ($data['seo']['title'] ?? '')),
            'description' => trim((string) ($data['seo']['description'] ?? '')),
        ], fn ($v) => $v !== '');

        $data['seo'] = $seo === [] ? null : $seo;
        $data['banner'] = \App\Support\PageBanner::sanitize($data['banner'] ?? null);

        return $data;
    }

    /**
     * Lift the `translations` bag out of the validated data.
     *
     * It has to come OUT before the array reaches create() or update():
     * Category is `$guarded = []`, so a stray `translations` key would be mass
     * assigned as though it were a column and the write would fail on a column
     * that does not exist. Taken by reference so there is one place this can be
     * forgotten rather than two.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, string|null>>
     */
    private function translationsFrom(array &$data): array
    {
        $bag = $data['translations'] ?? [];
        unset($data['translations']);

        // `description` is the one field here the storefront prints as prose.
        // It is plain text on both sides — the category dialog has no rich-text
        // control — so nothing is named rich and RichText is not involved.
        return TranslationInput::clean(is_array($bag) ? $bag : []);
    }

    /**
     * A category may not be its own ancestor.
     *
     * Without this a single edit — "Serums, parent: Ampoules" where Ampoules
     * already sits under Serums — makes a cycle. buildPath() has a guard of 10
     * so the page would not hang, but every URL in that loop would be wrong
     * and the sitemap would carry them. Cheaper to refuse.
     */
    private function safeParentId(?Category $category, int|string|null $parentId): ?int
    {
        $parentId = $parentId === null || $parentId === '' ? null : (int) $parentId;

        if ($parentId === null) {
            return null;
        }

        if ($category !== null && $parentId === (int) $category->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'A category cannot be its own parent.',
            ]);
        }

        if ($category !== null) {
            $seen = 0;
            $node = $parentId;

            while ($node !== null && $seen++ < self::MAX_DEPTH) {
                if ($node === (int) $category->id) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'That category already sits under this one, so this would make a loop.',
                    ]);
                }

                $next = DB::table('categories')->where('id', $node)->value('parent_id');
                $node = $next === null ? null : (int) $next;
            }
        }

        return $parentId;
    }

    /**
     * Keep the image to something that is safe as an <img src>.
     *
     * Deliberately the same rule the brand logo gets, and for the same reason:
     * the value normally arrives from MediaUploadController, but the field is a
     * plain string and the screen also lets an operator paste a URL by hand.
     * `javascript:` in an `src` is inert; `data:` is not — a data: URL of type
     * image/svg+xml renders as a document and can carry script. Only http(s)
     * and site-relative paths are kept.
     */
    private function safeImageUrl(?string $image): ?string
    {
        $image = trim((string) $image);

        if ($image === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $image) === 1) {
            return $image;
        }

        if (str_starts_with($image, '/') && ! str_contains($image, '..')) {
            return $image;
        }

        throw ValidationException::withMessages([
            'image' => 'The image must be an uploaded file or an http(s) URL.',
        ]);
    }

    /**
     * Rebuild `path` and `depth` for every row.
     *
     * These are caches of the parent chain, and any structural write — a new
     * child, a renamed slug, a re-parent, a delete that moves children up —
     * invalidates them for a whole subtree, not just the row that changed.
     * Working out exactly which subtree is more code than it is worth on a
     * table that holds tens of rows on production, so the whole table is
     * recomputed from one SELECT and written back.
     *
     * Written with the query builder, not the model, so `updated_at` is left
     * alone: it feeds sitemap <lastmod>, and re-parenting one category should
     * not tell search engines that every category page changed.
     */
    private function resyncTree(): void
    {
        $rows = DB::table('categories')->select('id', 'slug', 'parent_id', 'path', 'depth')->get();

        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row->id] = $row;
        }

        foreach ($byId as $id => $row) {
            $segments = [(string) $row->slug];
            $depth = 0;
            $parent = $row->parent_id === null ? null : (int) $row->parent_id;
            $guard = 0;

            // The guard is belt and braces: safeParentId already refuses a
            // cycle, but rows imported from WooCommerce were never checked.
            while ($parent !== null && $guard++ < self::MAX_DEPTH) {
                $node = $byId[$parent] ?? null;

                if ($node === null) {
                    break;
                }

                array_unshift($segments, (string) $node->slug);
                $depth++;
                $parent = $node->parent_id === null ? null : (int) $node->parent_id;
            }

            $path = implode('/', $segments);

            if ($path === (string) ($row->path ?? '') && $depth === (int) ($row->depth ?? -1)) {
                continue;
            }

            DB::table('categories')->where('id', $id)->update([
                'path' => $path,
                'depth' => $depth,
            ]);
        }
    }
}

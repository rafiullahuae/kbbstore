<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Support\ProductRating;
use App\Support\ReviewStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Reviews → Assign / Duplicate.
 *
 * MOVE is the everyday job and the one this screen exists for: a review landed
 * on the wrong product — the WooCommerce import matched the wrong post, or a
 * shopper reviewed the bundle instead of the item — and it belongs somewhere
 * else.
 *
 * COPY IS THE ONE TO READ THE COMMENT ABOUT. Copying a review onto a second
 * product makes it look as though a customer reviewed a product they did not
 * review. That is the same objection this repo already recorded, in
 * app.blade.php's NOT_BUILT_NOTE, for the reason Bulk Add and Bulk Likes were
 * deliberately not built: publishing invented reviews is against UAE
 * consumer-protection rules and the EU rules that follow this store's
 * international orders, and the same content feeds the star rating Google
 * shows for the shop.
 *
 * Copy is still here, because there is a legitimate version of it — the same
 * product listed twice, a 30ml and a 50ml of one item, a product replaced by
 * its own successor — and this is the owner's store and the owner's call. What
 * this file does about it is refuse to make that call quietly:
 *
 *   - a copy lands as PENDING by default, so nothing appears on a product page
 *     until a human approves it on the moderation screen;
 *   - keeping the original's status is possible and is a deliberate choice the
 *     caller has to send;
 *   - the screen states the objection where the button is, not in a help page.
 *
 * EVERY PATH HERE RECOMPUTES THE RATINGS, ON BOTH SIDES. A move changes the
 * approved set of the product it leaves AND the product it joins; a copy
 * changes the product it joins. `products.rating` and `products.review_count`
 * are what the shop cards print and what Store\ProductController hands to Seo
 * as the schema.org aggregateRating Google publishes. This calls the SAME
 * App\Support\ProductRating::refresh() the moderation screen calls, for the
 * same reason Lane AM gave: two copies of that sum is how they come to
 * disagree.
 *
 * ADMIN ONLY. Mounted in routes/reviews-screens-admin.php inside the
 * `admin-api` group, which is inside `auth:admin`. The search below returns
 * reviewer names and the moderation status of unapproved rows; under /api/*,
 * which CLAUDE.md records is unauthenticated by design, that is a public
 * directory of unmoderated reviews and a public button for moving them.
 */
class ReviewAssignApiController extends Controller
{
    /** Reviews returned by one search. */
    private const SEARCH_LIMIT = 50;

    /** Products offered by one product search. */
    private const PRODUCT_LIMIT = 30;

    /**
     * Reviews moved or copied in one call.
     *
     * The same ceiling the bulk moderation endpoint uses, and for the same
     * reason: this runs in a web request on shared hosting, and the work per
     * row includes a rating recomputation for a product.
     */
    private const BULK_MAX = 200;

    private const LIKE_ESCAPE = '!';

    /** The same literal, quoted for the `escape` clause. See escapeLike(). */
    private const LIKE_ESCAPE_SQL = "'!'";

    /**
     * GET /admin-api/review-assign/reviews
     *
     * The reviews to act on. Searchable, because the owner arrives here from a
     * product page or a customer email and knows a name or a phrase, not an id.
     */
    public function reviews(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'product_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'string', Rule::in(array_merge(['all'], ReviewStatus::ALL))],
        ]);

        $query = DB::table('reviews')
            ->leftJoin('products', 'products.id', '=', 'reviews.product_id')
            ->select([
                'reviews.id', 'reviews.product_id', 'reviews.rating', 'reviews.status',
                'reviews.author_name', 'reviews.title', 'reviews.content', 'reviews.created_at',
                'products.name as product_name',
            ])
            ->orderByDesc('reviews.id')
            ->limit(self::SEARCH_LIMIT);

        $status = (string) ($data['status'] ?? 'all');

        if ($status !== 'all') {
            $query->where('reviews.status', '=', $status);
        }

        if (($data['product_id'] ?? null) !== null) {
            $query->where('reviews.product_id', '=', (int) $data['product_id']);
        }

        $search = trim((string) ($data['q'] ?? ''));

        if ($search !== '') {
            $like = '%' . $this->escapeLike($search) . '%';

            $query->where(function ($q) use ($like, $search): void {
                foreach (['reviews.author_name', 'reviews.title', 'reviews.content', 'products.name'] as $column) {
                    $q->orWhereRaw($column . ' like ? escape ' . self::LIKE_ESCAPE_SQL, [$like]);
                }

                // Typing an id finds that review. Moderation notes and support
                // tickets quote ids, and this screen is where somebody arrives
                // holding one.
                if (ctype_digit($search)) {
                    $q->orWhere('reviews.id', '=', (int) $search);
                }
            });
        }

        $rows = $query->get()->map(function ($r): array {
            $content = (string) ($r->content ?? '');

            return [
                'id' => (int) $r->id,
                'product_id' => $r->product_id === null ? null : (int) $r->product_id,
                'product' => $r->product_name,
                'author' => (string) ($r->author_name ?? ''),
                'rating' => (int) $r->rating,
                'status' => ReviewStatus::normalise((string) $r->status),
                'title' => (string) ($r->title ?? ''),
                // No author_email and no ip. Neither is needed to decide where
                // a review belongs, and CLAUDE.md names both as data that has
                // already leaked from this table in production.
                'excerpt' => mb_substr($content, 0, 180),
                'truncated' => mb_strlen($content) > 180,
                'created_at' => (string) ($r->created_at ?? ''),
            ];
        })->all();

        return response()->json(['reviews' => $rows, 'limit' => self::SEARCH_LIMIT]);
    }

    /**
     * GET /admin-api/review-assign/products
     *
     * The destination picker. Searched rather than listed: this catalogue runs
     * to thousands of products and a select with all of them in it is not a
     * control, it is a scroll.
     */
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:120']]);

        $search = trim((string) ($data['q'] ?? ''));

        // `review_count` is 0 for most of this catalogue and product names are
        // not unique, so without `id` this LIMIT truncates a tied block the
        // database gets to order.
        $query = DB::table('products')
            ->select(['id', 'name', 'sku', 'rating', 'review_count'])
            ->orderByDesc('review_count')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::PRODUCT_LIMIT);

        if ($search !== '') {
            $like = '%' . $this->escapeLike($search) . '%';

            $query->where(function ($q) use ($like, $search): void {
                foreach (['name', 'sku'] as $column) {
                    $q->orWhereRaw($column . ' like ? escape ' . self::LIKE_ESCAPE_SQL, [$like]);
                }

                if (ctype_digit($search)) {
                    $q->orWhere('id', '=', (int) $search);
                }
            });
        }

        return response()->json([
            'products' => $query->get()->map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'sku' => $p->sku,
                'rating' => round((float) ($p->rating ?? 0), 2),
                'review_count' => (int) ($p->review_count ?? 0),
            ])->all(),
            'limit' => self::PRODUCT_LIMIT,
        ]);
    }

    /**
     * POST /admin-api/review-assign/move
     *
     * `product_id` may be null, which is this schema's shop review — the rows
     * Review::scopeBusiness() selects, product_id = 0 in the WordPress the
     * store came from. Moving a review off a product is a real action here, not
     * an accident of a missing field, so it is spelt with an explicit
     * `to_business` flag rather than by omitting the id.
     */
    public function move(Request $request): JsonResponse
    {
        try {
            $data = $this->validateTarget($request);

            $ids = $data['ids'];

            $before = DB::table('reviews')->whereIn('id', $ids)->pluck('product_id')->all();
            $found = DB::table('reviews')->whereIn('id', $ids)->pluck('id')->all();

            $missing = array_values(array_diff($ids, array_map('intval', $found)));

            if ($found === []) {
                return response()->json(['ok' => false, 'message' => 'None of those reviews exist any more.'], 404);
            }

            $affected = DB::transaction(function () use ($found, $data): int {
                return DB::table('reviews')
                    ->whereIn('id', $found)
                    ->update(['product_id' => $data['product_id'], 'updated_at' => now()]);
            });

            // Both sides. The product losing the review and the product
            // gaining it each have a new approved set.
            $refreshed = ProductRating::refresh(array_merge($before, [$data['product_id']]));
            $this->forgetHomeWall();

            return response()->json([
                'ok' => true,
                'action' => 'move',
                'affected' => $affected,
                'missing' => $missing,
                'ratings_refreshed' => $refreshed,
                'product' => $this->productSummary($data['product_id']),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'review_move_failed', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /admin-api/review-assign/copy
     *
     * The copy is a NEW row, and three of its columns are deliberately not the
     * original's:
     *
     *  - `source_id` is null. The schema carries a unique index on
     *    (source, source_id) — 2026_09_22_000000_add_import_external_ids — so a
     *    copy that kept it would either collide outright or, worse, make the
     *    next review import treat the copy as the original and update the wrong
     *    row. A null source_id is outside a unique index on every engine this
     *    runs on, which is what makes many copies of one imported review legal.
     *  - `source` says 'duplicate', so a row that no customer submitted is
     *    legible as such in the table and in an export afterwards.
     *  - `status` is pending unless the caller explicitly asked to keep the
     *    original's. See this class's own doc comment for why.
     *
     * `helpful` is reset to 0 as well: those votes were cast on the original.
     */
    public function copy(Request $request): JsonResponse
    {
        try {
            $data = $this->validateTarget($request, ['status' => ['sometimes', 'in:pending,keep']]);

            $keepStatus = (string) ($request->input('status', 'pending')) === 'keep';

            $rows = DB::table('reviews')->whereIn('id', $data['ids'])->get();

            if ($rows->isEmpty()) {
                return response()->json(['ok' => false, 'message' => 'None of those reviews exist any more.'], 404);
            }

            $now = now();
            $copies = [];

            foreach ($rows as $row) {
                $status = $keepStatus ? ReviewStatus::normalise((string) $row->status) : ReviewStatus::PENDING;

                $copies[] = [
                    'source' => 'duplicate',
                    'source_id' => null,
                    'product_id' => $data['product_id'],
                    'customer_id' => $row->customer_id,
                    'author_name' => $row->author_name,
                    'author_email' => $row->author_email,
                    'rating' => (int) $row->rating,
                    'title' => $row->title,
                    'content' => $row->content,
                    'images' => $row->images,
                    'status' => $status,
                    'verified' => $row->verified,
                    'helpful' => 0,
                    'reply' => $row->reply,
                    'ip' => '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::transaction(function () use ($copies): void {
                foreach (array_chunk($copies, 100) as $chunk) {
                    DB::table('reviews')->insert($chunk);
                }
            });

            // Only the destination moved: the original is still where it was,
            // still counted where it was.
            $refreshed = ProductRating::refresh([$data['product_id']]);
            $this->forgetHomeWall();

            return response()->json([
                'ok' => true,
                'action' => 'copy',
                'affected' => count($copies),
                'status' => $keepStatus ? 'kept' : ReviewStatus::PENDING,
                'ratings_refreshed' => $refreshed,
                'product' => $this->productSummary($data['product_id']),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'review_copy_failed', 'message' => $e->getMessage()], 500);
        }
    }

    /* --------------------------------------------------------------- helpers */

    /**
     * The shared shape of both writes: which reviews, and where to.
     *
     * @param  array<string, mixed>  $extra
     * @return array{ids: list<int>, product_id: int|null}
     */
    private function validateTarget(Request $request, array $extra = []): array
    {
        $data = $request->validate(array_merge([
            'ids' => ['required', 'array', 'min:1', 'max:' . self::BULK_MAX],
            'ids.*' => ['integer'],
            // exists:, not merely integer. A destination that is not a product
            // would otherwise be written straight into a foreign key and
            // surface as a 500 from the driver rather than as a message.
            'product_id' => ['required_without:to_business', 'nullable', 'integer', 'exists:products,id'],
            'to_business' => ['sometimes', 'in:0,1'],
        ], $extra));

        $toBusiness = (string) ($data['to_business'] ?? '0') === '1';

        return [
            'ids' => array_values(array_unique(array_map('intval', $data['ids']))),
            'product_id' => $toBusiness ? null : (int) $data['product_id'],
        ];
    }

    /**
     * What the destination product's numbers are AFTER the write.
     *
     * Returned so the screen can show the owner the score that actually
     * changed, rather than asserting that something happened. A screen that
     * only says "done" is a screen whose rating bug nobody notices.
     *
     * @return array<string, mixed>|null
     */
    private function productSummary(?int $productId): ?array
    {
        if ($productId === null) {
            return null;
        }

        $row = DB::table('products')->select(['id', 'name', 'rating', 'review_count'])->where('id', '=', $productId)->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'rating' => round((float) ($row->rating ?? 0), 2),
            'review_count' => (int) ($row->review_count ?? 0),
        ];
    }

    /**
     * Drop the homepage's cached review wall.
     *
     * Store\HomeController wraps that wall in Cache::remember('kbb.home.reviews',
     * 900, ...) and it aggregates every APPROVED review SHOP-WIDE, printing the
     * product each one belongs to. Moving an approved review does not change the
     * membership of that set, but it does change which product the wall links
     * that review to; a copy kept at 'approved' adds to the set outright. Both
     * leave the homepage wrong for up to fifteen minutes, and telling the two
     * cases apart costs a query to save one cache write.
     *
     * ProductRating::refresh() does not cover it: that writes the denormalised
     * pair on `products`, which is a different reader. Same helper, same key and
     * the same reasoning as Admin\ReviewsApiController::forgetHomeWall(), which
     * found this on the moderation path.
     */
    private function forgetHomeWall(): void
    {
        Cache::forget('kbb.home.reviews');
    }

    /**
     * Escape the wildcards in a search term.
     *
     * Without it a search for "50%" matches every review in the table, and a
     * search for "_" matches every single character — the owner reads that as
     * "search is broken", correctly. The escape character is doubled FIRST, or
     * a term containing `!` would escape the character after it.
     *
     * Only meaningful beside the `escape '!'` clause on each LIKE; the two are
     * the same mechanism and neither works alone. Same pair, for the same
     * reason, as Admin\ReviewsApiController.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $term,
        );
    }
}

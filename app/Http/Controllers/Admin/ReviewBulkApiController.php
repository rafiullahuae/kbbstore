<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use App\Support\ProductRating;
use App\Support\ReviewStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Store → Reviews → Bulk Add and Store → Reviews → Bulk Likes.
 *
 * WHAT THESE ENDPOINTS DO, SAID PLAINLY. `add` writes rows into `reviews` that
 * no customer submitted. `likes` raises `reviews.helpful` — the "was this
 * helpful?" counter — on rows nobody voted for. The owner asked for both after
 * being told what they are; that decision is theirs and this file implements it
 * properly rather than half-way. The screen carries the same statement so that
 * whoever operates it after the owner knows what they are looking at.
 *
 * ADMIN ONLY. These routes live in routes/review-bulk-admin.php, mounted inside
 * the `admin-api` group in routes/web.php — the one already wrapped in
 * `auth:admin`. Nothing here may move to routes/api.php: CLAUDE.md records that
 * everything under /api/* is unauthenticated by design, and an unauthenticated
 * endpoint that manufactures review rows is a stranger's content-injection
 * vector into the storefront AND into the structured data Google reads.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE RECOMPUTATION, WHICH IS THE PART THAT IS EASY TO GET WRONG
 * ────────────────────────────────────────────────────────────────────────────
 *
 * There are TWO figures called "the rating" on this storefront and they are
 * computed in different places. App\Support\ProductRating's own docblock sets
 * this out; what follows is the same trace walked again from THIS direction,
 * because a bulk writer is the one caller that can move one and not the other.
 *
 *   1. COMPUTED PER REQUEST, FROM `reviews`.
 *      Store\ProductController::reviewSummary() groups `reviews` by rating with
 *      ->approved() applied, and hands its average and total to App\Support\Seo
 *      as the schema.org aggregateRating. Store\HomeController's review wall
 *      does the same for the whole shop. These pick up a new APPROVED row on
 *      the next request with nothing else having to happen.
 *
 *   2. DENORMALISED ON `products` — `products.rating`, `products.review_count`.
 *      These are what the shop cards print, what ShopController sorts
 *      ?sort=rating and ?sort=popular by, what CollectionController's "popular"
 *      uses and what the `top_rated` shortcode selects on. NOTHING recomputes
 *      them on its own. The only writers in the application are
 *      ProductRating::refresh() and DemoReviewsSeeder::refreshAggregate().
 *
 * So a bulk insert of approved reviews that did not call refresh() would leave
 * the product page and Google showing one number while every shop card, every
 * rating sort and the "top rated" shortcode showed the number from before the
 * insert — indefinitely, with no error anywhere. That is the bug this file was
 * told to get right, and it is prevented by calling the SAME helper the
 * moderation screen calls, unconditionally, inside the same transaction as the
 * insert.
 *
 * UNCONDITIONALLY, AND ON PURPOSE. Store\ReviewController::submit() does NOT
 * call refresh(), and it is right not to: it only ever writes `pending`, which
 * cannot move an approved-only aggregate. This controller can write either
 * status, so it refreshes either way. When the rows land `pending` the refresh
 * is arithmetically a no-op — it recomputes the same numbers — and that is the
 * cheap half of never having to reason about which branch needed it.
 *
 * THE HOMEPAGE WALL IS CACHED AND IS EVICTED HERE. Store\HomeController wraps
 * its review wall in Cache::remember('kbb.home.reviews', 900, ...). Without the
 * forget() below, a bulk add of approved reviews leaves the homepage showing
 * the old count and average for up to fifteen minutes while the product pages
 * show the new ones — the two displayed figures disagreeing, which is the exact
 * class of defect this lane was told to prevent. (The moderation screen does
 * not evict it either; that file belongs to another lane and is reported rather
 * than changed here.)
 *
 * BULK LIKES DOES NOT REFRESH, AND THAT IS NOT AN OVERSIGHT.
 * ProductRating::refresh() reads COUNT(*) and AVG(rating) only — `helpful` is
 * not an input to either figure, so a vote count cannot move a rating and a
 * refresh after it would be a write that changes nothing. What `helpful`
 * actually drives, every reader checked: the 👍 button's number on each card in
 * resources/views/partials/reviews.blade.php, ReviewSettings::applySort()'s
 * 'helpful' ordering on the product page, this console's `helpful_desc` sort and
 * the `helpful` column in the reviews CSV export.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * BOUNDS
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Every request is bounded before it reaches the database, because a screen
 * that can insert ten thousand rows in one call is a way to take a shared host
 * down. MAX_ROWS caps what one call may create, LIKES_MAX_REVIEWS caps what one
 * call may touch, and both are reported to the screen so it can say the number
 * rather than discovering it with a 422.
 *
 * Writes go through ONE transaction so a request that fails part-way leaves no
 * half-populated product, and the aggregate write is inside it so a rolled-back
 * insert cannot leave `products.rating` describing rows that no longer exist.
 */
class ReviewBulkApiController extends Controller
{
    /**
     * Which build of THIS FILE is executing, reported with any error.
     *
     * Same reason ReviewsApiController and CustomersApiController each carry
     * one: a live 500 that looks identical before and after a package leaves
     * two indistinguishable explanations — the fix is wrong, or the fix is not
     * running — and OPcache on this host makes the second one real.
     */
    private const BUILD = '2.60.154';

    /** Rows one `add` call may create, across every product it names. */
    private const MAX_ROWS = 200;

    /** Products one `add` call may name. */
    private const MAX_TARGETS = 50;

    /** Entries in one supplied text pool (names, bodies, titles). */
    private const MAX_POOL = 500;

    /** Rows per INSERT round trip. */
    private const INSERT_CHUNK = 50;

    /** Reviews one `likes` call may touch. */
    private const LIKES_MAX_REVIEWS = 500;

    /** The largest `helpful` value this controller will ever write. */
    private const LIKES_CEILING = 9999;

    /** Products offered by the picker in one response. */
    private const PRODUCT_OPTIONS = 200;

    /**
     * `reviews.source` for everything this controller writes.
     *
     * NOT cosmetic. The column already distinguishes 'sorina' (the WordPress
     * import), 'wp_comment' and 'kbb' (Store\ReviewController::submit()), and
     * it is published by ReviewsApiController::show() and by the reviews CSV
     * export — so it has real readers and this value is visible wherever those
     * are. It is the only thing that tells anyone later which rows came from
     * this screen, which is what makes them findable, exportable and reversible
     * instead of indistinguishable from the 3,700 reviews real customers left.
     */
    private const SOURCE = 'admin_bulk';

    /** The two statuses it is meaningful to CREATE a review in. */
    private const CREATE_STATUSES = [ReviewStatus::PENDING, ReviewStatus::APPROVED];

    /* ----------------------------------------------------------- the picker */

    /**
     * What both screens need before they can draw: products to aim at, the
     * bounds, and the status vocabulary.
     *
     * The product list is built from `products` and not, as the moderation
     * screen's is, from `reviews` — the whole point here is to reach a product
     * that has NO reviews yet, so filtering to products that already carry one
     * would hide every product the owner actually wants. Bounded and
     * searchable instead, because a catalogue of several thousand is not a
     * dropdown.
     */
    public function options(Request $request): JsonResponse
    {
        try {
            $search = trim((string) $request->query('q', ''));

            $query = Product::query()
                ->select(['id', 'name', 'sku', 'rating', 'review_count'])
                ->orderBy('name');

            if ($search !== '') {
                $like = '%' . $this->escapeLike($search) . '%';

                $query->where(function ($q) use ($like, $search) {
                    $q->whereRaw('name like ? escape ' . self::LIKE_ESCAPE_SQL, [$like])
                        ->orWhereRaw('sku like ? escape ' . self::LIKE_ESCAPE_SQL, [$like]);

                    if (ctype_digit($search)) {
                        $q->orWhere('id', '=', (int) $search);
                    }
                });
            }

            $products = $query->limit(self::PRODUCT_OPTIONS)->get()->map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'sku' => (string) $p->sku,
                'rating' => (float) $p->rating,
                'review_count' => (int) $p->review_count,
            ])->all();

            return response()->json([
                'build' => self::BUILD,
                'products' => $products,
                'truncated' => count($products) >= self::PRODUCT_OPTIONS,
                'statuses' => self::CREATE_STATUSES,
                'limits' => [
                    'max_rows' => self::MAX_ROWS,
                    'max_targets' => self::MAX_TARGETS,
                    'max_pool' => self::MAX_POOL,
                    'likes_max_reviews' => self::LIKES_MAX_REVIEWS,
                    'likes_ceiling' => self::LIKES_CEILING,
                ],
                'source' => self::SOURCE,
            ]);
        } catch (\Throwable $e) {
            return $this->failed($e);
        }
    }

    /* --------------------------------------------------------------- create */

    /**
     * POST /admin-api/review-bulk/add
     *
     * The text is the OWNER'S. This endpoint takes pools of author names,
     * review bodies and (optionally) titles and distributes them across the
     * products named, at the star distribution asked for, over the date range
     * asked for. It ships no canned sentences of its own: a bulk tool's input
     * is the content, the same way the CSV importer's is, and inventing a
     * library of review prose here would put words in the owner's shop that
     * neither they nor a customer chose.
     *
     * POOLS ARE WALKED, NOT SAMPLED. A pool is shuffled and consumed in order,
     * reshuffling when it wraps. Independent random picks per row would, at the
     * sizes this screen is used at, leave one line printed eight times and
     * another never printed at all — which is both obviously wrong on the page
     * and worse than the even spread that costs nothing to do properly.
     */
    public function add(Request $request): JsonResponse
    {
        try {
            $data = $this->validateAdd($request);

            $productIds = array_values(array_unique(array_map('intval', $data['product_ids'])));
            $perProduct = (int) $data['per_product'];
            $status = (string) $data['status'];
            $verified = (bool) ($data['verified'] ?? false);

            [$from, $to] = $this->dateWindow($data);
            $weights = $this->ratingWeights($data['ratings']);

            // One spread across the WHOLE request, not one per product, so a
            // pool of 30 names across 3 products x 10 reviews uses all 30 once
            // instead of drawing the same 10 on every product.
            $total = count($productIds) * $perProduct;
            $authors = $this->spread($data['authors'], $total);
            $bodies = $this->spread($data['bodies'], $total);
            $titles = $this->spread($data['titles'] ?? [], $total);

            $rows = [];
            $n = 0;

            foreach ($productIds as $productId) {
                for ($i = 0; $i < $perProduct; $i++, $n++) {
                    $at = $this->momentBetween($from, $to);

                    $rows[] = [
                        'source' => self::SOURCE,
                        'source_id' => null,
                        'product_id' => $productId,
                        'customer_id' => null,
                        'author_name' => $authors[$n],
                        // Deliberately empty, not a generated address. There is
                        // no person here, and `reviews.author_email` is the
                        // column CLAUDE.md names as data that leaked in
                        // production — writing a plausible-looking address into
                        // it would put fake PII in the one place the owner uses
                        // to recognise a real repeat reviewer. The schema's own
                        // default for the column is ''.
                        'author_email' => '',
                        'rating' => $this->pickRating($weights),
                        'title' => $titles[$n],
                        'content' => $bodies[$n],
                        'images' => null,
                        'status' => $status,
                        // (int), not the bool. This goes through the query
                        // builder's insert() rather than through the model, so
                        // Review::casts()'s 'verified' => 'bool' never runs on
                        // it and the value reaches the driver as it is written.
                        'verified' => (int) $verified,
                        // Left at zero on purpose: manufacturing the vote count
                        // at the same time as the review is what Bulk Likes is
                        // for, and one control in one place beats the same
                        // control in two that can disagree.
                        'helpful' => 0,
                        'reply' => null,
                        // Empty for the same reason as author_email: there was
                        // no submission, so there is no address it came from.
                        'ip' => '',
                        'created_at' => $at,
                        // created_at, not now(): a row whose "last touched" is
                        // months after it was written sorts to the top of this
                        // console's updated_desc and reads as though somebody
                        // had just moderated it.
                        'updated_at' => $at,
                    ];
                }
            }

            $created = 0;

            DB::transaction(function () use ($rows, $productIds, &$created) {
                foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                    Review::query()->insert($chunk);
                    $created += count($chunk);
                }

                // The same helper the moderation screen calls, in the same
                // transaction as the insert. See the note at the top of this
                // file: without it the shop cards and the rating sorts keep
                // the numbers they had before this request, for ever.
                ProductRating::refresh($productIds);
            });

            $this->forgetHomeWall();

            return response()->json([
                'build' => self::BUILD,
                'ok' => true,
                'created' => $created,
                'status' => $status,
                'source' => self::SOURCE,
                // The recomputed pair, read back AFTER the refresh, so the
                // screen can show what the shop cards will now print rather
                // than asserting that something happened.
                'products' => $this->aggregateFor($productIds),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e);
        }
    }

    /* ---------------------------------------------------------------- likes */

    /**
     * POST /admin-api/review-bulk/likes
     *
     * Raises `reviews.helpful` on reviews chosen either by product or by
     * explicit id, by a random amount inside the range asked for.
     *
     * APPROVED-ONLY BY DEFAULT, because that is the only thing the real vote
     * path can reach: Store\ReviewController::helpful() looks the row up
     * through ->approved() and answers 404 for anything else, so a pending
     * review cannot accumulate a single genuine vote. "Any status" is offered
     * for an owner who wants the count in place before they approve a batch,
     * and it is a choice they make rather than a default they inherit.
     *
     * THE NEW VALUE IS COMPUTED IN PHP, NOT IN SQL. The obvious implementation
     * is one UPDATE ... SET helpful = helpful + n per row, or a LEAST() to hold
     * the ceiling — but LEAST is MySQL's spelling and SQLite's is MIN, so a
     * clamp written in SQL behaves differently under the suite than in
     * production, which is the dialect gap docs/MYSQL-PARITY.md exists for. The
     * rows are read, the arithmetic is done here, and the writes are then
     * GROUPED BY THE RESULTING VALUE — so a request touching 500 reviews across
     * a range of ten costs ten UPDATEs, not five hundred.
     */
    public function likes(Request $request): JsonResponse
    {
        try {
            $data = $this->validateLikes($request);

            $mode = (string) $data['mode'];
            $min = (int) $data['min'];
            $max = (int) $data['max'];

            $targets = $this->likeTargets($data);

            if ($targets->isEmpty()) {
                return response()->json([
                    'build' => self::BUILD,
                    'ok' => true,
                    'affected' => 0,
                    'matched' => 0,
                    'note' => 'No reviews matched — nothing was changed.',
                ]);
            }

            // new value => [ids]. At most one entry per distinct result, so the
            // number of UPDATEs is bounded by the smaller of the row count and
            // the width of the range.
            $groups = [];

            foreach ($targets as $row) {
                $current = (int) $row->helpful;
                $roll = random_int($min, $max);

                $value = $mode === 'set' ? $roll : $current + $roll;
                $value = max(0, min(self::LIKES_CEILING, $value));

                if ($value === $current) {
                    continue;   // nothing to write for this row
                }

                $groups[$value][] = (int) $row->id;
            }

            $affected = 0;
            $stamp = now();

            DB::transaction(function () use ($groups, $stamp, &$affected) {
                foreach ($groups as $value => $ids) {
                    // updated_at by hand, because a mass update does not touch
                    // timestamps — and because the real path does move it:
                    // Eloquent's increment() on Store\ReviewController::helpful()
                    // writes updated_at alongside the counter.
                    $affected += Review::query()
                        ->whereKey($ids)
                        ->update(['helpful' => (int) $value, 'updated_at' => $stamp]);
                }
            });

            // `helpful` is not an input to ProductRating::refresh() — see the
            // note at the top of this file — so no aggregate is recomputed
            // here. The homepage wall carries review BODIES though, and its
            // cached copy holds the vote counts that were current when it was
            // built, so it is evicted for the same reason `add` evicts it.
            $this->forgetHomeWall();

            return response()->json([
                'build' => self::BUILD,
                'ok' => true,
                'affected' => (int) $affected,
                'matched' => $targets->count(),
                'mode' => $mode,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e);
        }
    }

    /* ----------------------------------------------------------- validation */

    /** @return array<string, mixed> */
    private function validateAdd(Request $request): array
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:' . self::MAX_TARGETS],
            /*
             * `exists:products,id` and NOT the visible-products predicate that
             * Store\ReviewController::submit() uses.
             *
             * That predicate is there to stop a STRANGER reviewing a draft or
             * scheduled product by posting its id at a public endpoint, and to
             * stop the accept/reject split telling them which unpublished ids
             * are real. Neither applies to a caller who is already
             * authenticated as the owner and can see the whole catalogue in
             * this console. Preparing a product's review section before it goes
             * on sale is a legitimate thing for the owner to do, and refusing
             * it here would be friction with no one on the other side of it.
             */
            'product_ids.*' => ['integer', Rule::exists('products', 'id')],
            'per_product' => ['required', 'integer', 'min:1', 'max:' . self::MAX_ROWS],
            'status' => ['required', 'string', Rule::in(self::CREATE_STATUSES)],
            'verified' => ['sometimes', 'boolean'],

            'authors' => ['required', 'array', 'min:1', 'max:' . self::MAX_POOL],
            'authors.*' => ['required', 'string', 'min:1', 'max:100'],
            'bodies' => ['required', 'array', 'min:1', 'max:' . self::MAX_POOL],
            'bodies.*' => ['required', 'string', 'min:1', 'max:5000'],
            'titles' => ['sometimes', 'nullable', 'array', 'max:' . self::MAX_POOL],
            'titles.*' => ['required', 'string', 'min:1', 'max:120'],

            'ratings' => ['required', 'array'],
            'ratings.*' => ['integer', 'min:0', 'max:1000'],

            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date'],
        ]);

        /*
         * The total, checked after the per-field rules rather than inside them,
         * because it is the product of two fields and neither is wrong on its
         * own. Reported as a validation error on `per_product` so the screen
         * puts the message where the number the owner can change is.
         */
        $total = count(array_unique(array_map('intval', $data['product_ids']))) * (int) $data['per_product'];

        if ($total > self::MAX_ROWS) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'per_product' => sprintf(
                    'That is %d reviews in one go. This endpoint creates at most %d — lower the number per product, or pick fewer products.',
                    $total,
                    self::MAX_ROWS
                ),
            ]);
        }

        $weights = $this->ratingWeights($data['ratings']);

        if (array_sum($weights) <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'ratings' => 'Give at least one star rating a share above zero.',
            ]);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function validateLikes(Request $request): array
    {
        $data = $request->validate([
            'scope' => ['required', 'string', Rule::in(['product', 'ids'])],
            'mode' => ['required', 'string', Rule::in(['add', 'set'])],
            'min' => ['required', 'integer', 'min:0', 'max:' . self::LIKES_CEILING],
            'max' => ['required', 'integer', 'min:0', 'max:' . self::LIKES_CEILING, 'gte:min'],

            'product_ids' => ['required_if:scope,product', 'array', 'min:1', 'max:' . self::MAX_TARGETS],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')],

            'ids' => ['required_if:scope,ids', 'array', 'min:1', 'max:' . self::LIKES_MAX_REVIEWS],
            'ids.*' => ['integer'],

            // 'any' is the explicit opt-out; anything else must be a real
            // status, so a typo cannot silently widen the set.
            'status' => ['sometimes', 'string', Rule::in(array_merge(ReviewStatus::ALL, ['any']))],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:' . self::LIKES_MAX_REVIEWS],
        ]);

        return $data;
    }

    /* -------------------------------------------------------------- queries */

    /**
     * The reviews a `likes` call will touch: id and current count only, bounded.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function likeTargets(array $data): \Illuminate\Support\Collection
    {
        $status = (string) ($data['status'] ?? ReviewStatus::APPROVED);
        $limit = (int) ($data['limit'] ?? self::LIKES_MAX_REVIEWS);
        $limit = max(1, min(self::LIKES_MAX_REVIEWS, $limit));

        $query = Review::query()->select(['id', 'helpful']);

        if (($data['scope'] ?? '') === 'ids') {
            $query->whereKey(array_values(array_unique(array_map('intval', $data['ids']))));
        } else {
            $query->whereIn(
                'product_id',
                array_values(array_unique(array_map('intval', $data['product_ids'])))
            );
        }

        if ($status !== 'any') {
            $query->where('status', '=', $status);
        }

        // Ordered before the limit, or "the 500 it picked" is whatever the
        // engine felt like returning and two identical requests touch different
        // rows. Newest first, which is the set an owner means by "the reviews
        // on this product".
        return $query->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * `products.rating` and `products.review_count` as they stand now.
     *
     * @return list<array<string, mixed>>
     */
    private function aggregateFor(array $productIds): array
    {
        return Product::query()
            ->select(['id', 'name', 'rating', 'review_count'])
            ->whereKey($productIds)
            ->get()
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'rating' => (float) $p->rating,
                'review_count' => (int) $p->review_count,
            ])
            ->all();
    }

    /**
     * Drop Store\HomeController's cached review wall.
     *
     * Its key is a literal in that controller — Cache::remember('kbb.home.reviews',
     * 900, ...) — not a constant this file can import, so it is repeated here
     * and named in the test that pins it, which is what stops the two drifting
     * apart silently.
     */
    private function forgetHomeWall(): void
    {
        Cache::forget('kbb.home.reviews');
    }

    /* -------------------------------------------------------------- helpers */

    private const LIKE_ESCAPE = '!';

    private const LIKE_ESCAPE_SQL = "'!'";

    /**
     * The escape character is doubled first, or a term containing `!` would
     * escape the character after it. Same treatment, and the same reasons, as
     * ReviewsApiController::escapeLike(): `%` and `_` are ordinary characters
     * in a product name, MySQL defaults to a backslash escape and SQLite has
     * none at all.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $term
        );
    }

    /**
     * Star weights as a 1..5 map of non-negative integers.
     *
     * Read by key rather than by position, and defaulted to zero, so a request
     * that names only the stars it wants is legal and a request naming star 9
     * contributes nothing instead of producing a rating the column cannot hold.
     *
     * @return array<int, int>
     */
    private function ratingWeights(array $input): array
    {
        $weights = [];

        foreach ([1, 2, 3, 4, 5] as $star) {
            $weights[$star] = max(0, (int) ($input[$star] ?? $input[(string) $star] ?? 0));
        }

        return $weights;
    }

    /** @param array<int, int> $weights */
    private function pickRating(array $weights): int
    {
        $total = array_sum($weights);

        // Cannot happen — validateAdd() refuses a zero total — but a divide by
        // a zero total would be a 500 rather than a message, so the floor is
        // here as well as there.
        if ($total <= 0) {
            return 5;
        }

        $roll = random_int(1, $total);

        foreach ($weights as $star => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return (int) $star;
            }
        }

        return 5;
    }

    /**
     * The window bulk-created reviews are dated across.
     *
     * NEITHER END MAY BE IN THE FUTURE. A review dated tomorrow prints a
     * tomorrow's date on the product page, sorts above everything real under
     * every "newest" ordering the storefront has, and is the single most
     * obvious tell that a row was not written by a customer. Both ends are
     * clamped rather than refused, so a date picker left on today's date at
     * 00:00 in a different timezone is not a 422.
     *
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    private function dateWindow(array $data): array
    {
        $now = now();

        $to = ! empty($data['date_to']) ? \Illuminate\Support\Carbon::parse($data['date_to'])->endOfDay() : $now->copy();
        $from = ! empty($data['date_from'])
            ? \Illuminate\Support\Carbon::parse($data['date_from'])->startOfDay()
            : $now->copy()->subDays(90);

        if ($to->greaterThan($now)) {
            $to = $now->copy();
        }

        if ($from->greaterThan($to)) {
            $from = $to->copy();
        }

        return [$from, $to];
    }

    private function momentBetween(
        \Illuminate\Support\Carbon $from,
        \Illuminate\Support\Carbon $to
    ): \Illuminate\Support\Carbon {
        $a = $from->getTimestamp();
        $b = $to->getTimestamp();

        return \Illuminate\Support\Carbon::createFromTimestamp($b > $a ? random_int($a, $b) : $a);
    }

    /**
     * `$count` entries drawn from `$values`, shuffled and cycled.
     *
     * NOT `$values[array_rand($values)]` per row. Independent picks at the
     * sizes this screen is used at leave one line printed eight times and
     * another never printed at all — both obviously wrong on the page, and
     * worse than an even spread that costs nothing to do properly. The pool is
     * shuffled, walked to its end, then reshuffled: every entry is used once
     * before any entry is used twice, and the order still varies.
     *
     * strip_tags for the same reason Store\ReviewController::submit() does it —
     * this text is printed on a public product page, and an admin textarea is
     * not a sanitiser.
     *
     * @param  array<int, mixed>  $values
     * @return list<string>  exactly $count entries; all '' when the pool is empty
     */
    private function spread(array $values, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $clean = array_values(array_filter(
            array_map(fn ($v) => trim(strip_tags((string) $v)), $values),
            fn (string $v) => $v !== ''
        ));

        // An empty pool is legal for titles, which are optional. Every row then
        // gets '', which is the schema's own default for that column.
        if ($clean === []) {
            return array_fill(0, $count, '');
        }

        $out = [];

        while (count($out) < $count) {
            $batch = $clean;
            shuffle($batch);   // presentation order, not a secret

            foreach ($batch as $value) {
                $out[] = $value;

                if (count($out) >= $count) {
                    break;
                }
            }
        }

        return $out;
    }

    private function failed(\Throwable $e): JsonResponse
    {
        return response()->json([
            'build' => self::BUILD,
            'error' => 'review_bulk_failed',
            'message' => $e->getMessage(),
        ], 500);
    }
}

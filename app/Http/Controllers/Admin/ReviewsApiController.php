<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Support\AggregatesQueries;
use App\Support\ProductRating;
use App\Support\ReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Store -> Reviews -> All Reviews. The moderation screen's backend.
 *
 * WHAT THIS REPLACED. GET /admin-api/reviews on AdminController: one
 * unpaginated fetch of EVERY review in the table, no search, no rating filter,
 * no product filter, the review text truncated to 140 characters with no way to
 * read the rest, and four chips whose counts were taken from the whole table
 * rather than from the set the screen was showing. Its `rejected` chip counted
 * a value the schema does not define (see App\Support\ReviewStatus), and a row
 * carrying the schema's own `spam` appeared under no chip at all and answered
 * 422 when moderated. That controller belongs to another lane and is left
 * exactly as it is; these are new paths beside it.
 *
 * CHIP COUNTS DESCRIBE THE NARROWED SET. Every count on this screen is computed
 * from the query with the rating filter, the product filter and the search
 * already applied and only the STATUS filter lifted — which is the only reading
 * that makes sense: "of the 1-star reviews of this product matching this
 * search, how many are waiting?". A count taken from the whole table next to a
 * filtered list is a number that answers a question nobody asked.
 *
 * AGGREGATES GO THROUGH App\Support\AggregatesQueries, NOT selectRaw() ON THE
 * ROW QUERY. selectRaw() appends rather than replaces, and applySort()/forPage()
 * mutate the builder they are handed, so a count computed from the same
 * instance as the page inherits its columns, its ORDER BY and — the one that is
 * wrong on every engine — its OFFSET. That last one makes every total read zero
 * from page two on while the endpoint still answers 200. It shipped twice in
 * this repo already, on Customers and then on Orders. Page 2 of this list
 * reports the same totals as page 1, and a test asserts it.
 *
 * SEARCH ESCAPES ITS WILDCARDS. `%` and `_` are ordinary characters in a review
 * — "100% worth it" is a sentence a shopper writes — and an unescaped one turns
 * the search into a match-anything. ESCAPE '!' explicitly, because MySQL
 * defaults to backslash and SQLite has NO default escape at all, so the same
 * pattern behaves differently under the suite than in production.
 *
 * AUTHOR EMAIL AND IP. `reviews` carries both, CLAUDE.md names them as data
 * that leaked in production, and the public endpoints must never return them —
 * Api\ReviewController and Api\ProductController::reviews() each publish a
 * named column list, and Review::$hidden is the backstop. This controller is a
 * different case: it sits behind `auth:admin` and moderation is exactly the
 * job those two fields exist for. They are still not handed out freely:
 *
 *   - the LIST carries author_email, because recognising the same address
 *     across a page of reviews is how spam is actually spotted;
 *   - the IP appears ONLY on the single-review detail endpoint, because no
 *     workflow needs a column of IP addresses, and it is the most sensitive
 *     field on the table;
 *   - the CSV carries neither IP nor anything else that is not on the screen.
 *
 * Every projection below is an explicit column list, never a model, so a column
 * added to `reviews` later is private until someone publishes it deliberately.
 */
class ReviewsApiController extends Controller
{
    use AggregatesQueries;

    private const PER_PAGE_DEFAULT = 25;

    private const PER_PAGE_MIN = 10;

    private const PER_PAGE_MAX = 200;

    /** Hard ceiling on one CSV. A shared host is not a reporting server. */
    private const EXPORT_MAX = 50000;

    /** Rows per database round trip while streaming the CSV. */
    private const EXPORT_CHUNK = 500;

    /** Ceiling on one bulk action, so a stuck loop cannot empty the table. */
    private const BULK_MAX = 500;

    /**
     * Which build of THIS FILE is executing, reported with any error.
     *
     * Same reason CustomersApiController carries one: a live 500 that looks
     * identical before and after a package leaves two indistinguishable
     * explanations — the fix is wrong, or the fix is not running — and OPcache
     * on this host makes the second one real. Bump it whenever this file
     * changes.
     */
    private const BUILD = '2.60.134';

    /**
     * The chip filters, named once so the list, the chip counts and the export
     * cannot drift apart. `all` plus the canonical vocabulary — there is no
     * `rejected` chip because there is no `rejected` status; Reject writes
     * `spam`, which is this schema's name for "refused, not published".
     */
    private const SEGMENTS = ['all', ReviewStatus::PENDING, ReviewStatus::APPROVED, ReviewStatus::SPAM];

    private const SORTS = [
        'newest', 'oldest', 'rating_desc', 'rating_asc', 'helpful_desc', 'updated_desc',
    ];

    /**
     * MySQL treats a backslash as the default LIKE escape and SQLite has none
     * at all, so the escape character is named explicitly. `!` has no special
     * meaning in a string literal on either engine, so there is no second layer
     * of escaping to get wrong.
     */
    private const LIKE_ESCAPE = '!';

    /** The literal as written into SQL. No binding: it is a constant. */
    private const LIKE_ESCAPE_SQL = "'!'";

    /* ------------------------------------------------------------------ list */

    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $this->clampPerPage((int) $request->query('per_page', (string) self::PER_PAGE_DEFAULT));
            $page = max(1, (int) $request->query('page', '1'));
            $segment = $this->segment($request);
            $sort = $this->sort($request);

            // Built ONCE, without the status filter, and used for both the chip
            // counts and (after the status filter is added) the page itself.
            $narrowed = $this->baseQuery($request);

            $counts = $this->counts($narrowed);

            $rows = clone $narrowed;

            if ($segment !== 'all') {
                $rows->where('reviews.status', '=', $segment);
            }

            // The total for the pager is the count of the CHIP's set, which is
            // the same number the chip itself shows.
            $total = $segment === 'all' ? $counts['all'] : ($counts[$segment] ?? 0);

            $this->applySort($rows, $sort);

            $records = $rows->forPage($page, $perPage)->get();

            return response()->json([
                'build' => self::BUILD,
                'reviews' => $records->map(fn ($r) => $this->rowToApi($r))->values()->all(),
                'counts' => $counts,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) max(1, (int) ceil($total / $perPage)),
                'filter' => $segment,
                'sort' => $sort,
                'statuses' => ReviewStatus::ALL,
                'products' => $this->productOptions(),
            ]);
        } catch (\Throwable $e) {
            // Reported, not swallowed. The Customers screen returned a bare
            // "Server Error" on the live host for a week because the one thing
            // that would have identified it — the driver's own message — was
            // being caught and thrown away.
            return $this->failed($e);
        }
    }

    /* ---------------------------------------------------------------- detail */

    /**
     * One review, in full.
     *
     * The list truncates the body for the table; this is where the owner reads
     * the whole thing, which is the single most common reason to open a review
     * at all. The IP lives here and nowhere else.
     */
    public function show(int $review): JsonResponse
    {
        try {
            $row = $this->rowQuery()->where('reviews.id', '=', $review)->first();

            if ($row === null) {
                return response()->json(['error' => 'not_found'], 404);
            }

            return response()->json([
                'build' => self::BUILD,
                // array_merge, NOT the `+` operator: `+` keeps the LEFT side's
                // value for a duplicate key, so `truncated` would stay true
                // from the list projection and the screen would go on offering
                // to show more text it had already been given in full.
                'review' => array_merge($this->rowToApi($row), [
                    'content' => (string) $row->content,
                    'truncated' => false,
                    'ip' => (string) $row->ip,
                    'source' => (string) $row->source,
                    'source_id' => $row->source_id,
                    'customer_id' => $row->customer_id,
                    'images' => $row->images ?: [],
                    'updated_at' => optional($row->updated_at)->toIso8601String(),
                ]),
            ]);
        } catch (\Throwable $e) {
            return $this->failed($e);
        }
    }

    /* ------------------------------------------------------------- moderation */

    /** PUT /admin-api/reviews/{review}/moderate — one review, status and/or reply. */
    public function moderate(Request $request, int $review): JsonResponse
    {
        try {
            $data = $request->validate([
                // Rule::in over ReviewStatus::accepted(), so the accepted set
                // and the stored set are the same list rather than two copies
                // of it that can drift. `rejected` is accepted and normalised;
                // nothing stores it again.
                'status' => ['sometimes', 'string', Rule::in(ReviewStatus::accepted())],
                'reply' => ['sometimes', 'nullable', 'string', 'max:5000'],
            ]);

            $row = Review::query()->whereKey($review)->first();

            if ($row === null) {
                return response()->json(['error' => 'not_found'], 404);
            }

            $before = (string) $row->status;

            if (array_key_exists('status', $data)) {
                $row->status = ReviewStatus::normalise((string) $data['status']);
            }

            if (array_key_exists('reply', $data)) {
                // strip_tags for the same reason the storefront submit path
                // does it: this reply is printed under the review on a public
                // page, and the admin textarea is not a sanitiser.
                $row->reply = $data['reply'] === null ? null : strip_tags((string) $data['reply']);
            }

            $row->save();

            // The denormalised pair on `products` is what the shop cards print.
            // Nothing recomputed it on moderation before this package, so
            // approving a review never changed the number a shopper sees. See
            // App\Support\ProductRating.
            if ($before !== (string) $row->status) {
                ProductRating::refresh([$row->product_id]);
                self::forgetHomeWall();
            }

            return response()->json([
                'build' => self::BUILD,
                'ok' => true,
                'id' => $row->id,
                'status' => $row->status,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e);
        }
    }

    /** POST /admin-api/reviews/bulk-moderate — the same actions, many at once. */
    public function bulkModerate(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'action' => ['required', 'string', Rule::in(array_merge(ReviewStatus::accepted(), ['delete']))],
                'ids' => ['required', 'array', 'min:1', 'max:' . self::BULK_MAX],
                'ids.*' => ['integer'],
            ]);

            $ids = array_values(array_unique(array_map('intval', $data['ids'])));

            // The products whose scores this could move, read BEFORE the rows
            // change — after a delete there is nothing left to read them from.
            $productIds = Review::query()
                ->whereKey($ids)
                ->pluck('product_id')
                ->all();

            if ($data['action'] === 'delete') {
                $affected = Review::query()->whereKey($ids)->delete();
            } else {
                $target = ReviewStatus::normalise($data['action']);

                // One statement, not a loop of saves. `updated_at` is set by
                // hand because a mass update does not touch timestamps, and
                // this screen sorts and reports on it.
                $affected = Review::query()
                    ->whereKey($ids)
                    ->where('status', '!=', $target)   // correct rows are left alone
                    ->update(['status' => $target, 'updated_at' => now()]);
            }

            ProductRating::refresh($productIds);
            self::forgetHomeWall();

            return response()->json([
                'build' => self::BUILD,
                'ok' => true,
                'affected' => (int) $affected,
                'requested' => count($ids),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e);
        }
    }

    /**
     * Drop the homepage's cached review wall.
     *
     * Store\HomeController wraps that wall in Cache::remember('kbb.home.reviews',
     * 900, ...) and it aggregates every APPROVED review shop-wide. Moderation
     * changes exactly that set, and nothing here evicted the key — so for up to
     * fifteen minutes after approving or rejecting a review the homepage showed
     * one set of reviews while the product pages showed another, with the owner
     * having no way to tell which was right or that anything was stale.
     *
     * ProductRating::refresh() above does not cover it: that writes the
     * denormalised pair on `products`, which is a different reader.
     *
     * Deliberately unconditional on the bulk path. Working out whether any of
     * the rows that actually changed were approved-or-were-approved costs a
     * second query to save one cache write, and a wrong answer there is the
     * stale homepage all over again.
     */
    private static function forgetHomeWall(): void
    {
        Cache::forget('kbb.home.reviews');
    }

    /* ---------------------------------------------------------------- export */

    /**
     * CSV of the current filtered view — the same rows, in the same order, as
     * the screen the owner is looking at, not "every review".
     *
     * Streamed in chunks, because a shared host will not hold the whole review
     * table in memory alongside the request, and bounded by counting rows
     * written rather than by ->limit(): chunk() walks with forPage(), which
     * SETS limit and offset instead of intersecting with one already on the
     * builder, so a limit placed here would be overwritten on the first round
     * trip and the export would stream the entire table. That exact mistake is
     * documented in CustomersApiController, which found it.
     *
     * No IP column: see the note at the top of this file.
     */
    public function export(Request $request): StreamedResponse
    {
        $segment = $this->segment($request);
        $query = $this->baseQuery($request);

        if ($segment !== 'all') {
            $query->where('reviews.status', '=', $segment);
        }

        $this->applySort($query, $this->sort($request));

        $filename = 'reviews-' . now()->format('Y-m-d') . '.csv';

        return response()->stream(function () use ($query) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM, or Excel on Windows reads an accented or Arabic
            // reviewer name as mojibake — and this store has both.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'id', 'status', 'rating', 'product_id', 'product', 'author', 'email',
                'verified', 'title', 'content', 'reply', 'helpful', 'source',
                'created_at', 'updated_at',
            ]);

            $written = 0;

            $query->chunk(self::EXPORT_CHUNK, function ($chunk) use ($out, &$written) {
                foreach ($chunk as $r) {
                    if ($written >= self::EXPORT_MAX) {
                        return false;
                    }

                    $written++;

                    fputcsv($out, array_map($this->csvCell(...), [
                        $r->id,
                        $r->status,
                        (int) $r->rating,
                        $r->product_id ?? '',
                        $r->product_name ?? '',
                        $r->author_name ?? '',
                        $r->author_email ?? '',
                        $r->verified ? 'yes' : 'no',
                        $r->title ?? '',
                        $r->content ?? '',
                        $r->reply ?? '',
                        (int) $r->helpful,
                        $r->source ?? '',
                        optional($r->created_at)->toDateTimeString() ?? '',
                        optional($r->updated_at)->toDateTimeString() ?? '',
                    ]));
                }

                return true;
            });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /* ----------------------------------------------------------- the queries */

    /**
     * Rows plus the product name, with an explicit column list.
     *
     * A LEFT join, not an inner one: a business review carries no product
     * (product_id NULL — in WordPress that was product_id = 0, and the schema
     * says so beside the column). An inner join would drop every one of them
     * from the moderation screen, which is how an unmoderated review becomes
     * invisible rather than merely unapproved.
     */
    private function rowQuery(): Builder
    {
        return Review::query()
            ->leftJoin('products', 'products.id', '=', 'reviews.product_id')
            ->select([
                'reviews.id', 'reviews.product_id', 'reviews.customer_id', 'reviews.source',
                'reviews.source_id', 'reviews.author_name', 'reviews.author_email',
                'reviews.rating', 'reviews.title', 'reviews.content', 'reviews.images',
                'reviews.status', 'reviews.verified', 'reviews.helpful', 'reviews.reply',
                'reviews.ip', 'reviews.created_at', 'reviews.updated_at',
                'products.name as product_name', 'products.slug as product_slug',
            ]);
    }

    /** rowQuery plus every filter EXCEPT the status chip. */
    private function baseQuery(Request $request): Builder
    {
        $query = $this->rowQuery();

        $rating = (int) $request->query('rating', '0');

        if ($rating >= 1 && $rating <= 5) {
            $query->where('reviews.rating', '=', $rating);
        }

        $product = trim((string) $request->query('product_id', ''));

        if ($product !== '') {
            // 'business' is the review with no product at all, which is a real
            // thing on this schema and has no id to filter by.
            if ($product === 'business') {
                $query->whereNull('reviews.product_id');
            } elseif (ctype_digit($product)) {
                $query->where('reviews.product_id', '=', (int) $product);
            }
        }

        $verified = (string) $request->query('verified', '');

        if ($verified === 'yes') {
            $query->where('reviews.verified', '=', true);
        } elseif ($verified === 'no') {
            $query->where('reviews.verified', '=', false);
        }

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $like = '%' . $this->escapeLike($search) . '%';

            $query->where(function ($q) use ($like, $search) {
                foreach ([
                    'reviews.author_name',
                    'reviews.author_email',
                    'reviews.title',
                    'reviews.content',
                    'reviews.reply',
                    'products.name',
                ] as $column) {
                    $q->orWhereRaw($column . ' like ? escape ' . self::LIKE_ESCAPE_SQL, [$like]);
                }

                // Typing an id finds that review. Moderation notes and support
                // tickets quote ids, so this is how somebody gets back to one.
                if (ctype_digit($search)) {
                    $q->orWhere('reviews.id', '=', (int) $search);
                }
            });
        }

        return $query;
    }

    /**
     * The chip counts, from the set the OTHER filters already narrowed to.
     *
     * One grouped query, not four COUNT(*) round trips, and through
     * aggregateQuery() so the row columns, the ORDER BY and the page window do
     * not come with it. `status` is the grouped column and everything else is
     * an aggregate, which is what ONLY_FULL_GROUP_BY requires.
     *
     * @return array<string, int>
     */
    private function counts(Builder $narrowed): array
    {
        $rows = $this->aggregateQuery($narrowed, 'reviews.status as status, COUNT(*) as n')
            ->groupBy('reviews.status')
            ->pluck('n', 'status');

        $counts = ['all' => 0];

        foreach (ReviewStatus::ALL as $status) {
            $counts[$status] = 0;
        }

        foreach ($rows as $status => $n) {
            // A row still holding a value the migration has not reached is
            // counted somewhere rather than vanishing: that is precisely how
            // the imported `spam` row became unreachable before.
            $key = ReviewStatus::normalise((string) $status);
            $counts[$key] = ($counts[$key] ?? 0) + (int) $n;
            $counts['all'] += (int) $n;
        }

        return $counts;
    }

    /**
     * Products that actually carry a review, for the filter select.
     *
     * Bounded, and built from `reviews` rather than from `products`: a catalogue
     * of several thousand products is not a dropdown, and the only products
     * worth filtering by here are the ones with something to moderate.
     */
    private function productOptions(): array
    {
        return DB::table('reviews')
            ->leftJoin('products', 'products.id', '=', 'reviews.product_id')
            ->select('reviews.product_id')
            ->selectRaw('MAX(products.name) as name')
            ->selectRaw('COUNT(*) as n')
            ->whereNotNull('reviews.product_id')
            ->groupBy('reviews.product_id')
            ->orderByDesc('n')
            ->limit(200)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->product_id,
                'name' => (string) ($r->name ?? ('Product #' . $r->product_id)),
                'reviews' => (int) $r->n,
            ])
            ->all();
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->orderBy('reviews.id'),
            'rating_desc' => $query->orderByDesc('reviews.rating')->orderByDesc('reviews.id'),
            'rating_asc' => $query->orderBy('reviews.rating')->orderByDesc('reviews.id'),
            'helpful_desc' => $query->orderByDesc('reviews.helpful')->orderByDesc('reviews.id'),
            'updated_desc' => $query->orderByDesc('reviews.updated_at')->orderByDesc('reviews.id'),
            default => $query->orderByDesc('reviews.id'),
        };
    }

    /* ------------------------------------------------------------ projection */

    /**
     * The list projection. Named fields, never a model.
     *
     * The body is truncated for the table and `truncated` says so, so the
     * screen knows whether opening the review will actually show more — the old
     * screen cut at 140 characters with an ellipsis and no way to read the
     * rest, which made a long review unmoderatable.
     */
    private function rowToApi(object $r): array
    {
        $content = (string) ($r->content ?? '');

        return [
            'id' => (int) $r->id,
            'product_id' => $r->product_id === null ? null : (int) $r->product_id,
            'product' => $r->product_name,
            'product_slug' => $r->product_slug,
            'author' => (string) $r->author_name,
            'author_email' => (string) $r->author_email,
            'rating' => (int) $r->rating,
            'title' => (string) $r->title,
            'excerpt' => mb_substr($content, 0, 240),
            'truncated' => mb_strlen($content) > 240,
            'length' => mb_strlen($content),
            'verified' => (bool) $r->verified,
            'helpful' => (int) $r->helpful,
            'reply' => $r->reply,
            'status' => ReviewStatus::normalise((string) $r->status),
            'created_at' => optional($r->created_at)->toIso8601String(),
        ];
    }

    /* --------------------------------------------------------------- helpers */

    private function segment(Request $request): string
    {
        $segment = (string) $request->query('filter', 'all');

        // Normalised, so ?filter=rejected — a bookmark from the old screen —
        // lands on the spam chip instead of silently showing everything.
        if ($segment !== 'all' && ! in_array($segment, self::SEGMENTS, true)) {
            $segment = ReviewStatus::normalise($segment);
        }

        return in_array($segment, self::SEGMENTS, true) ? $segment : 'all';
    }

    private function sort(Request $request): string
    {
        $sort = (string) $request->query('sort', 'newest');

        return in_array($sort, self::SORTS, true) ? $sort : 'newest';
    }

    private function clampPerPage(int $requested): int
    {
        if ($requested <= 0) {
            return self::PER_PAGE_DEFAULT;
        }

        return max(self::PER_PAGE_MIN, min(self::PER_PAGE_MAX, $requested));
    }

    /**
     * The escape character is doubled first, or a term containing `!` would
     * escape the character after it.
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
     * Neutralise a spreadsheet formula before it reaches a cell.
     *
     * Excel, LibreOffice and Sheets all execute a cell beginning =, +, - or @,
     * and a leading tab or carriage return sneaks past a naive check of the
     * first character. EVERY value in this export is typed by the public — the
     * review body most of all — so this is not a theoretical case here.
     */
    private function csvCell(mixed $value): string
    {
        $string = (string) $value;

        if ($string !== '' && str_contains("=+-@\t\r", $string[0])) {
            return "'" . $string;
        }

        return $string;
    }

    private function failed(\Throwable $e): JsonResponse
    {
        return response()->json([
            'build' => self::BUILD,
            'error' => 'reviews_query_failed',
            'message' => $e->getMessage(),
        ], 500);
    }
}

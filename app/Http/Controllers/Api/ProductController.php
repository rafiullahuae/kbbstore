<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use App\Services\SettingsService;
use App\Support\ReviewSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ProductController extends Controller
{
    /** GET /api/products */
    /**
     * The columns toApi() actually publishes, and nothing else.
     *
     * `id` is here because it is the model key — not published, but Eloquent
     * wants it. Everything else is exactly the field list in Product::toApi().
     * `brand` is absent on purpose: toApi() reports it only when the relation
     * is loaded, this endpoint has never loaded it, and eager-loading it here
     * would change what the endpoint RETURNS rather than what it costs.
     *
     * Kept next to the query rather than on the model: this is a statement
     * about one endpoint's cost, and a second caller wanting different columns
     * should say so itself rather than widening this list.
     *
     * `sale_starts_at` and `sale_ends_at` ARE SELECTED AND ARE NOT PUBLISHED.
     * They are the schedule on `sale_price`, and toApi() needs them to decide
     * whether the markdown is live: quoting `sale_price` raw advertised sales
     * that had ended and sales that had not opened, while
     * Api\CheckoutController charged effectivePrice() and got it right.
     *
     * Leaving them out is not the safe, narrow choice it looks like. Eloquent
     * returns null for a column it never selected, and two null bounds read as
     * "no start, no end" — a sale that is always on. The omission FAILED OPEN
     * on the very endpoint the bug was reported against.
     * Product::advertisedSalePrice() now refuses to advertise a sale whose
     * window it cannot see, so dropping these two columns again turns the
     * expired-sale bug into a missing-sale bug: wrong, but loud, and caught by
     * ApiAdvertisedPriceTest rather than shipped.
     *
     * Two datetimes are the cheapest columns on this table; the cost argument
     * above is about `description` and the SEO blob, which stay out.
     */
    private const INDEX_COLUMNS = [
        'id', 'slug', 'name', 'price', 'sale_price', 'image', 'images',
        'rating', 'review_count', 'stock_status', 'short_description',
        'sale_starts_at', 'sale_ends_at',
    ];

    /** GET /api/products */
    public function index()
    {
        // 'active' matched nothing -- products use 'publish'. The bug was
        // quietly containing the next one: with the filter broken this
        // endpoint returned an empty array, so nobody noticed it had no
        // visibility rules either. scopeVisible() applies both status and
        // is_visible, and the model's soft deletes are honoured automatically.
        //
        // NAMED COLUMNS, AND THE REASON IS COST, NOT TIDINESS. This endpoint is
        // unauthenticated (CLAUDE.md: "/api/* is unauthenticated") and carries
        // no throttle, so anyone may call it as often as they like. With no
        // select() Eloquent hydrated every column of every visible product --
        // including `description`, which the product editor accepts up to
        // 200,000 characters of, plus `ingredients`, `how_to_use` and the SEO
        // blob -- and then discarded all of it, because toApi() publishes ten
        // named fields and none of those is one of them. Measured at 250
        // products with a 20KB description each: 6MB through memory to emit
        // 54KB of JSON, on shared hosting, for free, to anybody.
        //
        // THE ROW COUNT IS STILL UNBOUNDED, AND THE REASON GIVEN FOR IT WAS
        // NOT TRUE (Lane DM). This comment said a cap "would silently drop
        // products off a real page", because resources/views/store/category
        // .blade.php fetches this endpoint and filters the whole catalogue
        // client-side. That view is rendered by NO controller and reachable at
        // no URL: it is a design mock whose own 60-brand menu links to
        // /category?cat=…, a route this application does not register. It is
        // the only consumer of /api/products anywhere in the repository, so
        // there is no real page to drop a product off.
        //
        // The cost above is therefore paid for nobody: "6MB through memory to
        // emit 54KB of JSON, on shared hosting, for free, to anybody", measured
        // in this same comment. What it should be replaced with — a cap, a
        // cursor, a throttle, or the endpoint's removal — is a decision for
        // whoever owns this endpoint, and Lane DM's recommendation is in its
        // report rather than applied here: changing what a public endpoint
        // returns is not a change to make as a side effect of fixing a
        // different page. Only the false justification is removed.
        $rows = Product::visible()
            ->select(self::INDEX_COLUMNS)
            ->orderBy('position')
            ->get()
            ->map(fn (Product $p) => $p->toApi());

        return response()->json($rows);
    }

    /** GET /api/products/{slug} */
    public function show(string $slug)
    {
        // Unfiltered, this served drafts, private products and anything
        // hidden from the storefront to anyone who guessed a slug -- including
        // pricing on products not yet launched. Same rule as the product page:
        // not visible means not found.
        $p = Product::visible()->where('slug', $slug)->first();
        if (!$p) return response()->json(['error' => 'not_found'], 404);

        return response()->json($p->toApi());
    }

    /** GET /api/products/{slug}/reviews */
    public function reviews(string $slug)
    {
        // Named columns, not the whole model: a Review carries author_email
        // and ip, and this endpoint is public.
        // reviews key on product_id, not product_slug -- there is no such
        // column, so this endpoint raised SQLSTATE 42S22 on every request and
        // has never once returned a review.
        $product = Product::visible()->where('slug', $slug)->first(['id']);

        if (! $product) {
            return response()->json([]);
        }

        $rows = Review::query()
            ->select(['id', 'product_id', 'author_name', 'rating', 'title', 'content', 'verified', 'reply', 'created_at'])
            ->where('product_id', $product->id)
            ->where('status', 'approved')
            // Same reason as Api\ReviewController::index(): this endpoint is
            // public and must not publish what the product page withholds.
            ->real()
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json($rows);
    }

    /** POST /api/products/{slug}/reviews */
    public function submitReview(Request $request, string $slug)
    {
        $settings = app(SettingsService::class);

        /*
         * `sr_allow_submit` is enforced HERE as well as on the storefront, and
         * that is the whole point of this block.
         *
         * Store\ReviewController was wired to the setting precisely so that
         * "Accept new reviews: off" would refuse on the server rather than
         * merely hide the button. This door was not, so the switch did not
         * hold: the storefront answered 403 and wrote nothing while this
         * endpoint answered 201 and wrote a row. routes/api.php's own comment
         * claims this path "applies the same per-IP limit and honeypot the
         * storefront uses" — it applied the honeypot and not the settings.
         *
         * /api/* is unauthenticated (CLAUDE.md), so this is the door a script
         * finds first. Answered before validation, before the honeypot and
         * before the rate limiter, because a store that is not accepting
         * reviews has nothing to say about the shape of one.
         */
        if (! ReviewSettings::get($settings, 'sr_allow_submit')) {
            return response()->json([
                'error' => 'Reviews are not being accepted.',
            ], 403);
        }

        // The same key the storefront form uses, so the two paths share one
        // budget rather than offering five submissions each. Keyed on IP
        // alone, not the product, so cycling products does not reset it.
        $key = 'review-submit:' . $request->ip();

        /*
         * And the same NUMBER, read from the same setting. It was hardcoded to
         * five here while the storefront read `sr_rate_limit` (1..50), so an
         * owner tightening the cap to one an hour still left five an hour on
         * this endpoint — a shared key with two different budgets, where the
         * looser one wins.
         */
        $perHour = (int) ReviewSettings::get($settings, 'sr_rate_limit');

        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return response()->json([
                'error' => 'Too many reviews submitted — please try again later.',
            ], 429);
        }

        // Honeypot, matching the storefront field name. Answered with the same
        // success shape a real submission gets: telling a bot it was rejected
        // only teaches it which field gave it away.
        if ((string) $request->input('sr_website', '') !== '') {
            RateLimiter::hit($key, 3600);

            return response()->json(['ok' => true], 201);
        }

        $data = $request->validate([
            'author' => 'required|string|max:120',
            'rating' => 'required|integer|min:1|max:5',
            'title'  => 'nullable|string|max:200',
            'body'   => 'nullable|string|max:5000',
        ]);

        RateLimiter::hit($key, 3600);

        // Resolve the product before writing: reviews key on product_id, and
        // a review for something that is not published should not exist.
        $product = Product::visible()->where('slug', $slug)->first(['id']);

        if (! $product) {
            return response()->json(['error' => 'not_found'], 404);
        }

        // `author`, `body` and `likes` are not columns on `reviews` -- the
        // table calls them author_name, content and helpful -- so every
        // submission through this endpoint died on the INSERT with SQLSTATE
        // HY000. The broken-filter lesson again: because nothing ever reached
        // the response, nobody noticed the response handed back the whole
        // Review model, and a Review carries author_email and ip. Fixing the
        // insert alone would have switched that leak on.
        Review::create([
            'product_id'  => $product->id,
            'author_name' => strip_tags($data['author']),
            'rating'      => $data['rating'],
            'title'       => strip_tags($data['title'] ?? ''),
            'content'     => strip_tags($data['body'] ?? ''),
            'verified'    => 0,
            'helpful'     => 0,
            'status'      => 'pending', // held for moderation in the admin Reviews screen
            'ip'          => (string) $request->ip(),
        ]);

        // Nothing but an acknowledgement. Named rather than the model, so a
        // column added to `reviews` later is private by default -- and
        // identical to what the honeypot branch above answers, so the response
        // does not tell a bot which of the two branches it landed in.
        return response()->json(['ok' => true], 201);
    }
}

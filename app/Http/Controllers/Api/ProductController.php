<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ProductController extends Controller
{
    /** GET /api/products */
    public function index()
    {
        // 'active' matched nothing -- products use 'publish'. The bug was
        // quietly containing the next one: with the filter broken this
        // endpoint returned an empty array, so nobody noticed it had no
        // visibility rules either. scopeVisible() applies both status and
        // is_visible, and the model's soft deletes are honoured automatically.
        $rows = Product::visible()
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
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json($rows);
    }

    /** POST /api/products/{slug}/reviews */
    public function submitReview(Request $request, string $slug)
    {
        // The same key the storefront form uses, so the two paths share one
        // budget rather than offering five submissions each. Keyed on IP
        // alone, not the product, so cycling products does not reset it.
        $key = 'review-submit:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
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

        $review = Review::create([
            // Same column fault on the write path: every submission through
            // this endpoint failed at the insert.
            'product_id' => $product->id,
            'author'       => $data['author'],
            'rating'       => $data['rating'],
            'title'        => $data['title'] ?? '',
            'body'         => $data['body'] ?? '',
            'verified'     => 0,
            'likes'        => 0,
            'status'       => 'pending', // held for moderation in the admin Reviews screen
            'created_at'   => now()->toISOString(),
        ]);

        return response()->json($review, 201);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /** GET /api/products */
    public function index()
    {
        $rows = Product::where('status', 'active')
            ->orderBy('position')
            ->get()
            ->map(fn (Product $p) => $p->toApi());

        return response()->json($rows);
    }

    /** GET /api/products/{slug} */
    public function show(string $slug)
    {
        $p = Product::where('slug', $slug)->first();
        if (!$p) return response()->json(['error' => 'not_found'], 404);

        return response()->json($p->toApi());
    }

    /** GET /api/products/{slug}/reviews */
    public function reviews(string $slug)
    {
        $rows = Review::where('product_slug', $slug)
            ->where('status', 'approved')
            ->orderByDesc('id')
            ->get();

        return response()->json($rows);
    }

    /** POST /api/products/{slug}/reviews */
    public function submitReview(Request $request, string $slug)
    {
        $data = $request->validate([
            'author' => 'required|string|max:120',
            'rating' => 'required|integer|min:1|max:5',
            'title'  => 'nullable|string|max:200',
            'body'   => 'nullable|string|max:5000',
        ]);

        $review = Review::create([
            'product_slug' => $slug,
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

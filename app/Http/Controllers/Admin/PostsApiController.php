<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\JsonResponse;

/**
 * The admin sidebar's "Blog" and "Posts" links previously loaded an iframe
 * pointing at a standalone HTML file (kbb-admin-blog.html) that was never
 * actually built — every file in that iframe mechanism is a stub, not just
 * this one. This gives both links a real, working screen instead, built on
 * the same pattern already proven for Orders. Read-only for now: listing
 * and a link to preview each post on the real site is what was actually
 * missing and reported; a full create/edit editor is separate, larger
 * scope not implied by "the blog page gives a 404."
 */
class PostsApiController extends Controller
{
    public function index(): JsonResponse
    {
        $posts = Post::query()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get(['id', 'slug', 'title', 'tag', 'author', 'status', 'published_at', 'created_at']);

        return response()->json([
            'posts' => $posts->map(fn (Post $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'title' => $p->title,
                'tag' => $p->tag,
                'author' => $p->author,
                'status' => $p->status,
                'published_at' => optional($p->published_at)->toAtomString(),
                'created_at' => optional($p->created_at)->toAtomString(),
            ]),
        ]);
    }
}

<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Post;
class PostController extends Controller
{
    /** GET /api/posts */
    public function index()
    {
        return response()->json(
            // Named columns. `seo` is an internal settings blob and `status`
            // is a workflow field; neither belongs in a public feed.
            Post::query()
                ->select(['id', 'slug', 'title', 'excerpt', 'cover', 'tag', 'author', 'published_at'])
                ->where('status', 'published')
                // `created_at` ties for every post an import wrote in the same
                // second, and this truncates at 100: without `id` it is not
                // settled which posts the hundred are.
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get()
        );
    }
    /** GET /api/posts/{slug} */
    public function show(string $slug)
    {
        // Drafts were readable by slug. The index has always filtered on
        // status; this did not.
        $p = Post::query()
            ->select(['id', 'slug', 'title', 'excerpt', 'body', 'cover', 'tag', 'author', 'published_at'])
            ->where('status', 'published')
            ->where('slug', $slug)
            ->first();
        return $p ? response()->json($p) : response()->json(['error' => 'not_found'], 404);
    }
}

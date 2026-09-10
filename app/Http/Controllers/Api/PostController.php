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
            Post::where('status', 'published')->orderByDesc('created_at')->get()
        );
    }
    /** GET /api/posts/{slug} */
    public function show(string $slug)
    {
        $p = Post::where('slug', $slug)->first();
        return $p ? response()->json($p) : response()->json(['error' => 'not_found'], 404);
    }
}

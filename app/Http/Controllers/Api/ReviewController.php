<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /**
     * GET /api/reviews — public, unauthenticated.
     *
     * This ran Review::query() with an optional status taken straight from the
     * query string and returned every whole model. Three problems in one line:
     * ?status=pending handed out unmoderated content, no status at all handed
     * out everything including spam, and the full model carried author_email
     * and ip — every reviewer's address and IP, harvestable in one request.
     *
     * Approved only, no status parameter, an explicit column list, and a cap.
     * The columns are named rather than excluded so a column added later is
     * private until someone publishes it deliberately.
     */
    private const PUBLIC_COLUMNS = [
        'id', 'product_id', 'author_name', 'rating',
        'title', 'content', 'verified', 'reply', 'created_at',
    ];

    public function index(Request $request)
    {
        $limit = min(max((int) $request->query('limit', 50), 1), 100);

        $rows = Review::query()
            ->select(self::PUBLIC_COLUMNS)
            ->where('status', 'approved')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json($rows);
    }
}

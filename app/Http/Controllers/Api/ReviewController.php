<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;
class ReviewController extends Controller
{
    /** GET /api/reviews?status=approved */
    public function index(Request $request)
    {
        $q = Review::query();
        if ($request->filled('status')) $q->where('status', $request->query('status'));
        return response()->json($q->orderByDesc('id')->get());
    }
}

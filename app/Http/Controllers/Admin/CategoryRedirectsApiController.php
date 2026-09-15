<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\CategoryPath;
use App\Support\SearchTerms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Store → Catalog → Categories → "URLs that moved".
 *
 * Every rename, move, merge and delete of a category leaves a row in
 * `category_redirects` so the old /product-category/…/ URL answers 301 instead
 * of dying. This is the screen that shows them, because a redirect nobody can
 * see is a redirect nobody can fix: the owner renames a slug by accident, the
 * old URL starts pointing somewhere odd, and without a list there is no way to
 * find out that it happened, let alone undo it.
 *
 * Deleting a row here makes that old URL 404 again, which is the correct thing
 * to want when the redirect was created by a mistake that has since been undone.
 */
class CategoryRedirectsApiController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * GET /admin-api/categories/redirects
     *
     * Paged. The table grows by one row per URL move and nothing prunes it, so
     * a store that has been merchandised for a year can hold hundreds; loading
     * all of them into a modal is the kind of thing that is fine for six months
     * and then is not.
     */
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $search = trim((string) $request->query('q', ''));

        $query = DB::table('category_redirects')
            ->leftJoin('categories', 'categories.id', '=', 'category_redirects.category_id')
            ->select(
                'category_redirects.id',
                'category_redirects.from_path',
                'category_redirects.reason',
                'category_redirects.updated_at',
                'category_redirects.category_id',
                'categories.name as target_name',
                'categories.path as target_path',
            );

        if ($search !== '') {
            // SearchTerms::whereLike escapes % and _ with ESCAPE '!'. Without
            // it an operator pasting a path fragment containing an underscore
            // matches every single-character variant of it — and a lone "%"
            // matches the whole table, which reads as "search is broken".
            $query->where(function ($q) use ($search) {
                SearchTerms::whereLike($q, 'category_redirects.from_path', $search);
                SearchTerms::orWhereLike($q, 'categories.name', $search);
            });
        }

        // The total is taken from a query with no ORDER BY, LIMIT or OFFSET.
        // A surviving OFFSET is the bug CLAUDE.md records as having shipped
        // twice: an aggregate returns one row, so skipping 50 of them makes
        // every total read zero from page two on, silently, with a 200.
        $total = (clone $query)->reorder()->count();

        $rows = $query
            ->orderByDesc('category_redirects.updated_at')
            ->orderByDesc('category_redirects.id')
            ->forPage($page, self::PER_PAGE)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'from' => CategoryPath::url($row->from_path),
                'from_path' => $row->from_path,
                'reason' => $row->reason,
                'updated_at' => $row->updated_at,
                // A null target is a redirect whose destination was itself
                // deleted. The resolver answers 404 for it, and the screen
                // says so rather than showing a blank cell.
                'to' => $row->category_id === null
                    ? null
                    : CategoryPath::url((string) ($row->target_path ?: '')),
                'target_name' => $row->target_name,
            ]);

        return response()->json([
            'ok' => true,
            'redirects' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
        ]);
    }

    /**
     * DELETE /admin-api/categories/redirects/{redirect}
     *
     * The old URL goes back to answering 404.
     */
    public function destroy(int $redirect): JsonResponse
    {
        $deleted = DB::table('category_redirects')->where('id', $redirect)->delete();

        if ($deleted === 0) {
            return response()->json([
                'ok' => false,
                'error' => 'not_found',
                'message' => 'That redirect no longer exists — someone may have removed it already.',
            ], 404);
        }

        return response()->json(['ok' => true]);
    }
}

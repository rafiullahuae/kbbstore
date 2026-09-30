<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Maintenance\PreMigrationCleanup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Store → Import → "Clean up before the migration".                  (Lane IE)
 *
 * TWO ENDPOINTS AND THE SHAPE OF THEM IS THE SAFETY. The GET writes nothing and
 * is the only place the confirmation figures come from; the POST deletes and
 * refuses unless it is handed those figures back. There is no endpoint that
 * both decides what to delete and deletes it.
 *
 * NOT UNDER /api/. `routes/api.php` is unauthenticated in this application by
 * design — CLAUDE.md and tests/Feature/ApiSecurityTest.php — and this reads the
 * shop's row counts and deletes rows. It sits under `admin-api` behind
 * `auth:admin`, and `AdminCapabilities` gates it on `data.cleanup`, owner only.
 */
class CleanupApiController extends Controller
{
    /**
     * The screen.
     *
     * A STANDALONE DOCUMENT rather than a panel inside the console's
     * app.blade.php, the way admin/import-history.blade.php and
     * admin/media-progress.blade.php already are. Two reasons, and the second
     * is this lane's: that file is 22,900 lines and two other lanes are editing
     * it this round, so a screen that does not need to touch it should not.
     */
    public function page(): Response
    {
        return response()->view('admin.cleanup');
    }

    public function preview(PreMigrationCleanup $cleanup): JsonResponse
    {
        return response()->json(['ok' => true] + $cleanup->preview());
    }

    public function purge(Request $request, PreMigrationCleanup $cleanup): JsonResponse
    {
        /*
         * THE WORD, TYPED. Not a checkbox and not a second button: a delete
         * that cannot be reached without typing DELETE cannot be reached by a
         * mis-click, by a bookmarked POST, or by a page reloaded out of the
         * browser's history.
         */
        if (strtoupper(trim((string) $request->input('confirm'))) !== 'DELETE') {
            return response()->json([
                'ok' => false,
                'message' => 'Type DELETE to confirm. Nothing was deleted.',
            ], 422);
        }

        $keys = $request->input('buckets');
        $expect = $request->input('expect');

        if (! is_array($keys) || ! is_array($expect)) {
            return response()->json([
                'ok' => false,
                'message' => 'That request did not carry the list it was answering. Draw the list again.',
            ], 422);
        }

        /*
         * ALLOW-LIST, NEVER THE REQUEST -- CLAUDE.md rule 5.
         *
         * Only the four names this service knows come through; anything else is
         * dropped rather than passed on. Non-scalars are discarded FIRST,
         * because a nested array arriving as `buckets[0][0]` would otherwise
         * reach a `(string)` cast and an `(int)` cast that warn on it. Neither
         * could delete anything it should not -- the intersect and the count
         * comparison both fail on the result -- but a delete endpoint should not
         * be the place where that is merely true by luck.
         */
        $scalar = static fn ($v): bool => is_string($v) || is_int($v) || is_float($v) || is_bool($v);

        $keys = array_values(array_intersect(
            PreMigrationCleanup::BUCKETS,
            array_map(static fn ($k) => (string) $k, array_filter($keys, $scalar))
        ));

        $expect = array_map(
            static fn ($v) => (int) $v,
            array_filter(
                array_intersect_key($expect, array_flip(PreMigrationCleanup::BUCKETS)),
                $scalar
            )
        );

        $result = $cleanup->purge($keys, $expect);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 409);
    }
}

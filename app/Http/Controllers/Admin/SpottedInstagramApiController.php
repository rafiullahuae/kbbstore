<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InstagramPost;
use App\Services\Instagram\InstagramCredentials;
use App\Services\Instagram\InstagramSync;
use App\Services\InstagramSettings;
use App\Services\SpottedInstagram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → #KBeautyBliss Spotted → From Instagram.          (Lane SG, 2.60.417)
 *
 *   GET  /admin-api/spotted/instagram   every synced post (the picker's list),
 *                                       the account line and the connection state
 *   POST /admin-api/spotted/instagram   {ids: [...]} — the posts on the page, in
 *                                       order; reads the shares of the newly
 *                                       ticked ones and makes their srcset copies
 *
 * Capability `spotted.instagram` (AdminCapabilities::RULES, above the screen's
 * own '/**' line). Inside the guarded admin-api group; nothing here is /api/*.
 * Fetching from Instagram is NOT here: the panel's Refresh button calls the
 * existing POST /admin-api/instagram/refresh (instagram.manage).
 *
 * The list is an allowlist (SpottedInstagram::adminList): no token, no remote
 * id. The screen filters and searches it in the browser — no request per
 * keystroke.
 */
class SpottedInstagramApiController extends Controller
{
    /** Seconds the save may spend reading shares for newly ticked posts. */
    private const SAVE_SECONDS = 8;

    public function __construct(private SpottedInstagram $spotted) {}

    public function show(InstagramSettings $settings): JsonResponse
    {
        $profile = $settings->profile();
        $username = (string) ($profile['username'] ?? '');

        return response()->json([
            'ok' => true,
            'posts' => $this->spotted->adminList(),
            'account' => [
                'handle' => preg_match('/^[A-Za-z0-9._]{1,30}$/', $username) === 1 ? $username : SpottedInstagram::HANDLE,
                // What Instagram says the account has, beside what we hold —
                // "Showing 214 of 214". Null when never synced.
                'media_count' => isset($profile['posts']) && is_numeric($profile['posts']) ? (int) $profile['posts'] : null,
                'synced_at' => isset($profile['fetched_at']) && is_numeric($profile['fetched_at'])
                    ? date(DATE_ATOM, (int) $profile['fetched_at']) : null,
                'connected' => InstagramCredentials::hasToken(),
            ],
            'max' => SpottedInstagram::MAX,
        ]);
    }

    public function save(Request $request, InstagramSync $sync): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['present', 'array', 'max:'.SpottedInstagram::MAX],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $added = $this->spotted->select(array_values($data['ids']));

        $note = '';
        $token = InstagramCredentials::token();

        if ($added !== [] && $token !== null) {
            $posts = InstagramPost::query()->whereIn('id', $added)
                ->get(['id', 'remote_id', 'media_type', 'local_path', 'share_count', 'view_count', 'insights_at']);
            $note = $sync->selectedInsights($token, microtime(true) + self::SAVE_SECONDS, $posts)['note'];
            SpottedInstagram::flush();
        }

        return response()->json([
            'ok' => true,
            'selected' => InstagramPost::query()->whereNotNull('spotted_sort')->count(),
            'note' => $note,
            'posts' => $this->spotted->adminList(),
        ]);
    }
}

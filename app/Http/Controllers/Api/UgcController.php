<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Models\UgcVideoLike;
use App\Services\Ugc\Tile;
use App\Services\UgcSettings;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The two public shoppable-video endpoints.
 *
 * ══ /api/* IS UNAUTHENTICATED AND EVERY ENDPOINT HERE IS PUBLIC ═════════════
 *
 * CLAUDE.md names this by name, and tests/Feature/ApiSecurityTest.php pins it
 * because each of its cases leaked in production. So:
 *
 *   WHAT A FEED RETURNS IS AN EXPLICIT LIST, ITERATED, NEVER A MODEL.
 *   UgcVideo::toApi() is that list and Product::toApi() is the one inside it —
 *   the existing allowlists, not a second copy that stops being updated. Never
 *   returned: rights_status, rights_evidence, rights_granted_at, status,
 *   position, locale, any filesystem path that is not a served one, and any
 *   timestamp that is not published_at.
 *
 *   A LIKE IS A PUBLIC WRITE, so it carries three separate guards: the route's
 *   own throttle, a per-address ceiling in the RateLimiter, and a UNIQUE INDEX
 *   in the database that is the actual rule. It stores no personal data at all.
 *
 *   THE MODULE SWITCH FAILS CLOSED. Both endpoints 404 while
 *   `shoppable_video` is off, which is every install until the owner turns it on
 *   — so applying this package adds no reachable surface.
 *
 * ══ WHY THE LIKE IS A 404 AND NOT A 403 WHEN THE CLIP IS NOT PUBLISHED ══════
 *
 * Api\QuizController::expertRequest already paid for this lesson (CLAUDE.md,
 * Known gaps): an endpoint that answers differently for "exists but you may not"
 * and "does not exist" is a directory of the rows you may not have. A draft clip,
 * a clip whose creator has refused permission, and a slug that was never issued
 * all return the SAME 404, byte for byte, because they are the same return
 * statement. Branching them apart restores the oracle.
 */
class UgcController extends Controller
{
    /**
     * Likes per address per hour.
     *
     * Deliberately generous — a shopper scrolling a rail of twelve may honestly
     * like several — and deliberately finite, because the cookie below is the
     * caller's to throw away and this is the only limit that is not.
     */
    public const MAX_LIKES_PER_HOUR = 40;

    public function __construct(private UgcSettings $settings) {}

    /**
     * GET /api/ugc/{section} — one section's clips, through the allowlist.
     *
     * Exists so something other than a Blade rail can consume the library — the
     * /videos page §1 contemplates, or a headless front end. It is the same data
     * the rail renders and NOT a wider view of it: every clip goes through
     * UgcVideo::toApi() and every product through Product::toApi().
     */
    public function section(Request $request, string $section): JsonResponse
    {
        if (! $this->settings->enabled()) {
            return $this->gone();
        }

        if (preg_match(UgcSection::HANDLE_RE, $section) !== 1) {
            return $this->gone();
        }

        $locale = app()->getLocale();

        $row = UgcSection::query()->published()->forLocale($locale)->where('handle', $section)->first();

        if ($row === null) {
            return $this->gone();
        }

        /*
         * The same three queries the rail costs, and the same eager load: this
         * endpoint must not be the one place in the feature with an N+1. The
         * narrow column list is Tile::PRODUCT_COLUMNS for the same reason — a
         * column added to `products` next year is off this wire by default.
         */
        $videos = $row->videos()
            ->published()
            ->forLocale($locale)
            ->with(['products' => fn ($q) => $q->select(Tile::PRODUCT_COLUMNS)->with('brand:id,name,slug')])
            ->limit($row->tileCap())
            ->get();

        return response()->json([
            'section' => [
                // The operator's private label (`title`) is NOT here. `heading`
                // and `subheading` are what the shop prints; `title` is what he
                // calls it in the admin, and a public feed has no business with
                // his filing system.
                'handle' => (string) $row->handle,
                'heading' => (string) ($row->t('heading') ?? ''),
                'subheading' => (string) ($row->t('subheading') ?? ''),
            ],
            'videos' => $videos->map(fn (UgcVideo $v) => $v->toApi())->values()->all(),
        ]);
    }

    /**
     * POST /api/ugc/{slug}/like — one like, from one browser, on one clip.
     *
     * ── HOW "ONE PER BROWSER" IS ACTUALLY ENFORCED ─────────────────────────
     *
     * A cookie carries a random 32-hex token this shop minted; the table stores
     * its SHA-256 with a UNIQUE INDEX on (clip, hash). The INDEX is the rule and
     * the insert is how it is applied: a read-then-write guard is a race that two
     * taps in the same second both win, which on a tap target in a rail is not
     * hypothetical. A constraint violation is therefore caught and answered
     * "already", which is the same thing from the shopper's side and the only
     * answer that cannot double-count.
     *
     * The cookie is the caller's to discard, so it is a convenience and not a
     * limit — exactly what Store\ReviewController says about its own vote cookie.
     * MAX_LIKES_PER_HOUR per address is the limit.
     *
     * ── AND IT STORES NOTHING ABOUT ANYBODY ────────────────────────────────
     *
     * No address, no user agent, no customer id, no referrer. The rate limit
     * lives in the RateLimiter's own expiring cache and is never written to a
     * table. See App\Models\UgcVideoLike, whose docblock lists each omission as a
     * decision.
     */
    public function like(Request $request, string $slug): JsonResponse
    {
        $config = $this->settings->all();

        // TWO switches, and both fail closed: the module, and the control on
        // Appearance → Video rail → Likes. Neither is on by default.
        if (! $this->settings->enabled() || ! ($config['likes_on'] ?? false)) {
            return $this->gone();
        }

        $key = 'ugc-like:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_LIKES_PER_HOUR)) {
            return response()->json(['ok' => false, 'error' => 'too_many'], 429);
        }

        /*
         * published(), so a draft clip and a clip whose creator refused
         * permission are both the 404 below — the same 404 a slug that was never
         * issued gets. Not `find()` plus a status check afterwards: that is two
         * returns, and two returns is how the states drift apart.
         */
        $video = UgcVideo::query()->published()->where('slug', $slug)->first();

        if ($video === null) {
            return $this->gone();
        }

        $token = (string) $request->cookie(UgcVideoLike::COOKIE, '');
        $minted = false;

        /*
         * A cookie that is not the shape this shop mints is REPLACED rather than
         * trusted. It is attacker-controlled, and hashing four kilobytes of junk
         * and writing a row for it is work somebody else chose for us.
         */
        if (! UgcVideoLike::looksMinted($token)) {
            $token = UgcVideoLike::mint();
            $minted = true;
        }

        $already = false;

        try {
            UgcVideoLike::query()->create([
                'ugc_video_id' => $video->id,
                'token_hash' => UgcVideoLike::hash($token),
            ]);

            /*
             * increment() is one UPDATE ... SET likes = likes + 1, not a read and
             * a write: two shoppers liking the same clip in the same second both
             * count. `likes` is then re-read from the database rather than from
             * the model, for the same reason.
             */
            $video->increment('likes');

            // Charged against the allowance only when it actually wrote, so a
            // shopper re-tapping a clip they already liked does not spend it.
            RateLimiter::hit($key, 3600);
        } catch (QueryException) {
            // The unique index did its job. Not an error to this caller.
            $already = true;
        }

        $response = response()->json([
            'ok' => true,
            'likes' => (int) UgcVideo::query()->whereKey($video->id)->value('likes'),
            'already' => $already,
        ]);

        if ($minted) {
            /*
             * httpOnly (the default) and same-site by config. Nothing in the
             * browser needs to read this value — the count comes back in the
             * response body — so there is no reason for script to see it.
             */
            $response->cookie(UgcVideoLike::COOKIE, $token, UgcVideoLike::COOKIE_MINUTES);
        }

        return $response;
    }

    /**
     * The ONE 404 every refusal returns.
     *
     * A single private method rather than four `return response()->json(...)`
     * lines, because the whole point is that the states are indistinguishable and
     * four copies of a body is four chances for one of them to grow a different
     * word.
     */
    private function gone(): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => 'not_found'], 404);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InstagramPost;
use App\Services\Instagram\IgPath;
use App\Services\UgcPath;
use Illuminate\Support\Facades\Cache;

/**
 * The storefront's whole reading of Instagram — and the file where this feature's
 * cost is decided.
 *
 * Phase 21, Lane IG. docs/IG-PROFILE.md §10.
 *
 * ── THE STOREFRONT NEVER CALLS INSTAGRAM. NOT ONCE, NOT EVER. ───────────────
 *
 * docs/UGC-ENGAGEMENT.md's rule, carried forward word for word: "A rail must not
 * make a third-party call per tile per render — twelve outbound calls behind a page
 * a shopper is waiting for, any one of which can hang." So App\Services\Instagram\
 * InstagramClient is resolved by ADMIN ENDPOINTS ONLY. This class reads rows and a
 * cached array, and the thumbnails it points at are files on our own disk.
 *
 * The practical consequence is the one that matters: Instagram going down, the
 * token lapsing, or Meta deprecating an endpoint cannot take this shop's homepage
 * with it. The section goes stale. It does not go blank and it does not go slow.
 *
 * ── THE COST IS ONE QUERY, FLAT ─────────────────────────────────────────────
 *
 * Rule 4 forbids N+1s and asks for the SLOPE rather than a total. There is only one
 * query to have a slope:
 *
 *   1  the posts, ordered, limited, filtered to the ones with a local picture
 *
 * and that is all. The profile box costs ZERO additional queries, deliberately: it
 * is a blob in `module_settings`, which SettingsService reads as one cached
 * snapshot that this module is already reading for its appearance settings. A
 * `instagram_profiles` table with one row in it would have been one more SELECT on
 * the homepage forever, to render an avatar and a number.
 *
 * Measured at 1, 2, 5, 10 and 20 posts and asserted not to move — see
 * InstagramSlopeTest. A total proves nothing: a feed that costs 21 queries at ten
 * posts costs 5 at two, and 5 looks fine.
 *
 * ── AND THEN IT IS CACHED, AS PLAIN ARRAYS ──────────────────────────────────
 *
 * Cache::remember for FEED_TTL, keyed by the post cap. What goes in is scalars and
 * arrays, NOT Eloquent models — UgcRail's own header makes the argument: a cached
 * model is a serialised object graph that has to be woken up, and it pins the shape
 * of every relation at the moment it was written.
 *
 * WHAT IS NOT IN THE CACHE IS THE SETTINGS. The layout, the gap, whether counts are
 * drawn — all read at render from InstagramSettings, so moving a control on
 * Content → Instagram → Look takes effect on the next page load with nothing to
 * clear. Only content is cached, and every admin write path that changes content
 * calls flush().
 */
class InstagramFeed
{
    /**
     * Ten minutes — the same TTL UgcRail and App\Support\Shortcodes give their own
     * content, and matching them is worth more than a number chosen fresh.
     *
     * It is the BACKSTOP and not the mechanism: every admin write that could change
     * this calls flush(), so the TTL is for a write that happened somewhere nobody
     * thought of.
     */
    public const FEED_TTL = 600;

    /** Cache keys this class created, so flush() does not empty the store. */
    private const INDEX = 'kbb.ig.feed.index';

    public function __construct(private InstagramSettings $settings) {}

    /**
     * Everything the section needs, or the empty shape.
     *
     * Returns `tiles => []` and says nothing at all in every case where there is
     * nothing to draw: the module is off, nothing has been fetched, or every fetched
     * post's thumbnail failed to download. A shortcode that cannot resolve must not
     * leave `[kbb_instagram]` in the middle of a published page for a shopper to
     * read, and must not print an error either — the storefront is not where a
     * configuration problem is reported. Content → Instagram is, and it says exactly
     * which of the six setup steps is outstanding.
     *
     * ── THE MODULE SWITCH IS CHECKED FIRST, BEFORE ANY READ ─────────────────
     *
     * enabled() reads the `module_toggles` snapshot, which every storefront page has
     * already warmed. Asking for the settings first would touch the SEPARATE
     * `module_settings` snapshot, which is one SELECT on a cold cache — on the
     * homepage of a shop that has this module OFF. StorefrontQueryBudgetTest is a
     * budget and a feature that ships off must cost nothing.
     *
     * @return array{profile: array<string, mixed>, tiles: list<array<string, mixed>>}
     */
    public function section(?int $limit = null): array
    {
        $empty = ['profile' => [], 'tiles' => []];

        if (! $this->settings->enabled()) {
            return $empty;
        }

        $conf = $this->settings->all();
        $cap = $limit !== null ? max(1, min(48, $limit)) : (int) $conf['posts'];

        $key = 'kbb.ig.feed.'.$cap;
        $this->remember($key);

        $tiles = Cache::remember($key, self::FEED_TTL, fn () => $this->build($cap));

        if (! is_array($tiles) || $tiles === []) {
            return $empty;
        }

        return ['profile' => $this->profileBox(), 'tiles' => $tiles];
    }

    /**
     * The one query.
     *
     * `drawable()` is a SCOPE and not a filter afterwards, which matters because of
     * the LIMIT: filtering in PHP after the fact is how a "9 posts" setting renders
     * six the week a couple of thumbnails failed to download.
     *
     * @return list<array<string, mixed>>
     */
    private function build(int $cap): array
    {
        return InstagramPost::query()
            ->drawable()
            ->recent()
            ->limit($cap)
            /*
             * A NARROW COLUMN LIST, not `*`. `remote_id` is Instagram's internal id
             * and `seen_at` is a fact about our fetch schedule; neither has any
             * business being loaded to draw a tile, and naming the columns keeps a
             * column added later off this path by default. The same reason
             * UgcRail selects Ugc\Tile::PRODUCT_COLUMNS rather than everything.
             */
            ->get([
                'id', 'media_type', 'permalink', 'shortcode', 'caption',
                'local_path', 'width', 'height', 'like_count', 'comments_count',
            ])
            ->map(fn (InstagramPost $p) => $p->toTile())
            // A row whose stored path fails IgPath::stored() on the way OUT — the
            // scope can only tell us the column is not null. Dropped rather than
            // drawn: a tile with no picture is a hole in the page at first paint.
            ->filter(fn (array $tile) => $tile['image'] !== null)
            ->values()
            ->all();
    }

    /**
     * The profile box's own data, sanitised, or [].
     *
     * ── EVERY FIELD HERE CAME FROM META AND IS TREATED AS SUCH ──────────────
     *
     * The blob in `module_settings` is whatever the last fetch wrote, and the fetch
     * wrote what Instagram said. So this is an ALLOWLIST with a type per key rather
     * than a pass-through: a key Meta adds next year does not reach a template, and
     * the two values that become attributes are checked here rather than there.
     *
     * `avatar` is OUR OWN LOCAL COPY through IgPath — the profile picture URL Meta
     * returns is a signed CDN address with the same expiry problem as a media_url
     * (docs/IG-PROFILE.md §2), so the sync downloads it like any other thumbnail.
     * `url` is built from the USERNAME, after the username has matched Instagram's
     * own handle alphabet, so the Follow button's href is a constant with checked
     * characters in it rather than a remote string.
     *
     * @return array<string, mixed>
     */
    private function profileBox(): array
    {
        $raw = $this->settings->profile();

        if ($raw === []) {
            return [];
        }

        $username = (string) ($raw['username'] ?? '');

        /*
         * Instagram's own rule for a handle: letters, digits, dots and underscores,
         * thirty characters. Checked because this value becomes part of a URL we
         * build — rule 5's "anything printed unescaped is a constant, never a
         * setting", and the only way to keep that true of an href is to make the
         * variable part unable to carry anything but these characters.
         */
        $handle = preg_match('/^[A-Za-z0-9._]{1,30}$/', $username) === 1 ? $username : '';

        return [
            'username' => $username,
            'name' => (string) ($raw['name'] ?? ''),
            'avatar' => IgPath::stored(is_string($raw['avatar'] ?? null) ? $raw['avatar'] : null),
            // A count is a count, and null is "Instagram did not tell us" — the same
            // three-valued rule InstagramPost applies to likes, for the same reason.
            'followers' => isset($raw['followers']) && is_numeric($raw['followers']) ? (int) $raw['followers'] : null,
            'posts' => isset($raw['posts']) && is_numeric($raw['posts']) ? (int) $raw['posts'] : null,
            'url' => $handle === '' ? null : 'https://www.instagram.com/'.$handle.'/',
            /*
             * Belt and braces. `url` above is assembled from a checked handle and is
             * already safe; running it through the same scheme check every other
             * outbound link on this feature goes through means there is one answer
             * to "how does a URL become an href here" rather than two.
             */
            'checked' => $handle !== '' ? UgcPath::link('https://www.instagram.com/'.$handle.'/') : null,
        ];
    }

    /** Track which keys we created, so flushing does not wipe unrelated caches. */
    private function remember(string $key): void
    {
        $index = (array) Cache::get(self::INDEX, []);

        if (! in_array($key, $index, true)) {
            $index[] = $key;
            Cache::put(self::INDEX, array_slice($index, -100), 86400);
        }
    }

    /**
     * Called after every admin write that could change the section.
     *
     * Clears only this class's entries. Cache::flush() would empty the whole store —
     * including sessions on some drivers, logging everybody out — which is the reason
     * UgcRail and App\Support\Shortcodes each keep their own index too.
     */
    public static function flush(): void
    {
        foreach ((array) Cache::get(self::INDEX, []) as $key) {
            Cache::forget($key);
        }

        Cache::forget(self::INDEX);
    }
}

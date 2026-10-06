<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InstagramPost;
use App\Services\Instagram\IgPath;
use App\Support\ImageVariants;
use Illuminate\Support\Facades\Cache;

/**
 * #KBeautyBliss Spotted, drawn from OUR Instagram posts.       (Lane SG, 2.60.417)
 *
 * THE OWNER, 6 October 2026: "i want a function to fetch our instagram all posts /
 * videos, and to choose from the list which one need to be shown on the page. and
 * the grid will auto cover from our selected instagram posts / videos auto. The
 * grid card design will display the cover image auto from instaram, and display
 * all vitals like likes, comments, shares icons with counts. and our profile
 * @kbeauty.bliss. and with post / video descirption short like 1-2 lines, and on
 * click it will go the original posts on our instagram. i don't want to upload
 * everything manual at all." And, the same day: "if the post is video, it should
 * play on our website directly by embed from the instagram."
 *
 * The posts are fetched by App\Services\Instagram\InstagramSync (admin button and
 * the daily `kbb:instagram-sync`); this class is the SELECTION and the CARDS.
 *
 * ── THE SELECTION IS ONE COLUMN ──────────────────────────────────────────────
 *
 * `instagram_posts.spotted_sort`: NULL is "not on the page", 1, 2, 3 … is "on the
 * page, in this order". The tick and the order cannot disagree because they are
 * the same value.
 *
 * ── WHAT THE SHOP READS, AND HOW OFTEN ───────────────────────────────────────
 *
 * cards() is ONE cache read on a warm page and, on a miss, ONE query for the
 * posts plus the `module_settings` snapshot for the profile line — and that cost
 * does not grow with the number of posts (SpottedInstagramTest renders 3 and 40
 * and compares). It never calls Instagram. The cache entry holds no translated
 * text, so one entry serves both languages; the Blade adds the words.
 *
 * ── WHAT IS PRINTED ──────────────────────────────────────────────────────────
 *
 * Every card is an allowlist built here: the picture is our own file
 * (IgPath::stored), the link is https on instagram.com with a shortcode that
 * matched InstagramPost::SHORTCODE_RE, the embed is REBUILT from that shortcode
 * (never a URL Meta sent), counts are integers formatted here, and the caption is
 * plain text the Blade escapes. No token, no remote id, no internal id.
 */
final class SpottedInstagram
{
    public const CACHE = 'kbb.spotted.ig.cards';

    private const TTL = 3600;

    /** The most posts the page draws, and the most a selection may hold. */
    public const MAX = 200;

    /** The handle drawn when the synced profile has none (the owner named it). */
    public const HANDLE = 'kbeauty.bliss';

    private const CAPTION = 220;

    /**
     * The page's cards and the profile line, from the cache.
     *
     * @return array{profile: array{handle: string, avatar: ?string}, cards: list<array<string, mixed>>}
     */
    public function cards(): array
    {
        $empty = ['profile' => ['handle' => self::HANDLE, 'avatar' => null], 'cards' => []];

        try {
            $built = Cache::remember(self::CACHE, self::TTL, fn (): array => $this->build());
        } catch (\Throwable) {
            // A storefront page must never go down for this: a column not yet
            // migrated is "nothing selected", and the page falls back to the
            // manual posts.
            return $empty;
        }

        return is_array($built) && isset($built['cards'], $built['profile']) ? $built : $empty;
    }

    /** @return array{profile: array{handle: string, avatar: ?string}, cards: list<array<string, mixed>>} */
    private function build(): array
    {
        $rows = InstagramPost::query()
            ->whereNotNull('spotted_sort')
            ->whereNotNull('local_path')
            ->orderBy('spotted_sort')
            ->orderBy('id')
            ->limit(self::MAX)
            ->get(['id', 'media_type', 'permalink', 'caption', 'local_path',
                'like_count', 'comments_count', 'share_count', 'view_count', 'posted_at']);

        $cards = [];

        foreach ($rows as $row) {
            $card = self::card($row);

            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return ['profile' => $cards === [] ? ['handle' => self::HANDLE, 'avatar' => null] : $this->profile(), 'cards' => $cards];
    }

    /** @return array<string, mixed>|null */
    public static function card(InstagramPost $row): ?array
    {
        $image = IgPath::stored($row->local_path);
        $link = self::permalink($row->permalink);

        // No picture is a hole in the grid; no checked link is a card that cannot
        // do the one thing the owner asked a tap to do. Either way: not drawn.
        if ($image === null || $link === null) {
            return null;
        }

        $video = $row->media_type === 'VIDEO';

        return [
            'image' => $image,
            'video' => $video,
            'album' => $row->media_type === 'CAROUSEL_ALBUM',
            'caption' => self::caption($row->caption),
            'likes' => self::compact($row->like_count),
            'comments' => self::compact($row->comments_count),
            'shares' => self::compact($row->share_count),
            'views' => $video ? self::compact($row->view_count) : null,
            'date' => $row->posted_at?->toDateString(),
            'href' => $link['url'],
            'embed' => $video ? self::embed($link) : null,
        ];
    }

    /** @return array{handle: string, avatar: ?string} */
    private function profile(): array
    {
        try {
            $raw = app(InstagramSettings::class)->profile();
        } catch (\Throwable) {
            $raw = [];
        }

        $username = (string) ($raw['username'] ?? '');
        $avatar = IgPath::stored(is_string($raw['avatar'] ?? null) ? $raw['avatar'] : null);

        return [
            'handle' => preg_match('/^[A-Za-z0-9._]{1,30}$/', $username) === 1 ? $username : self::HANDLE,
            // The 200w copy when one exists (a profile picture is ~150-320 px and
            // drawn at 28 CSS px), else our stored file. Never Meta's CDN.
            'avatar' => $avatar === null ? null : ImageVariants::variantUrl($avatar, 200),
        ];
    }

    /**
     * An Instagram post's address, checked: https, instagram.com or
     * www.instagram.com, and a /p/, /reel/, /reels/ or /tv/ path whose shortcode
     * matches InstagramPost::SHORTCODE_RE. Rebuilt from those parts, so nothing
     * else the stored string carried (a query, a fragment, a userinfo) survives.
     *
     * @return array{url: string, kind: string, code: string}|null
     */
    public static function permalink(?string $raw): ?array
    {
        $raw = trim((string) $raw);

        if ($raw === '' || strlen($raw) > 255) {
            return null;
        }

        $parts = parse_url($raw);

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        if (! in_array(strtolower((string) ($parts['host'] ?? '')), ['instagram.com', 'www.instagram.com'], true)) {
            return null;
        }

        if (preg_match('#^/(?:[A-Za-z0-9._]{1,30}/)?(p|reel|reels|tv)/([^/]+)/?$#', (string) ($parts['path'] ?? ''), $m) !== 1) {
            return null;
        }

        if (preg_match(InstagramPost::SHORTCODE_RE, $m[2]) !== 1) {
            return null;
        }

        $kind = $m[1] === 'p' || $m[1] === 'tv' ? 'p' : 'reel';

        return ['url' => 'https://www.instagram.com/'.$kind.'/'.$m[2].'/', 'kind' => $kind, 'code' => $m[2]];
    }

    /**
     * Instagram's own embed for a checked link: a constant host and a shortcode
     * that has matched SHORTCODE_RE. Never set as an iframe src in the markup —
     * the page's script sets it on tap (no request to Instagram until then).
     *
     * @param  array{kind: string, code: string}  $link
     */
    public static function embed(array $link): ?string
    {
        if (! in_array($link['kind'] ?? '', ['p', 'reel'], true) || preg_match(InstagramPost::SHORTCODE_RE, (string) ($link['code'] ?? '')) !== 1) {
            return null;
        }

        return 'https://www.instagram.com/'.$link['kind'].'/'.$link['code'].'/embed/';
    }

    /**
     * A count as Instagram writes one: 999, 1.2K, 12.3K, 123K, 1.2M. Rounded
     * DOWN, so a card never claims more than the post has. Null stays null —
     * "Instagram did not tell us" is drawn as nothing, never as 0.
     */
    public static function compact(mixed $n): ?string
    {
        if ($n === null || ! is_numeric($n)) {
            return null;
        }

        $n = max(0, (int) $n);

        if ($n < 1000) {
            return (string) $n;
        }

        foreach ([[1_000_000_000, 'B'], [1_000_000, 'M'], [1_000, 'K']] as [$unit, $suffix]) {
            if ($n >= $unit) {
                $v = $n / $unit;
                $v = $v >= 100 ? floor($v) : floor($v * 10) / 10;
                // "1.0K" is written "1K"; "100K" keeps its zeros.
                $text = preg_replace('/\.0$/', '', number_format($v, $v >= 100 ? 0 : 1, '.', ''));

                return $text.$suffix;
            }
        }

        return (string) $n;
    }

    /**
     * The `sizes` for a card picture: the page's own column counts (option
     * keys, never a stored string), a phone at 900px and narrower, and the
     * 1680px site width as the ceiling — at or a shade above the box.
     */
    public static function sizes(int $colsD, int $colsM, string $style = 'a'): string
    {
        $colsD = in_array($colsD, [3, 4, 5], true) ? $colsD : 4;
        $colsM = in_array($colsM, [1, 2], true) ? $colsM : 2;

        // Style D's 9:16 tile crops a 4:5 picture by its HEIGHT, so the picture
        // is drawn ~1.43x wider than the tile and asks for that much more.
        $k = $style === 'd' ? 1.43 : 1.0;

        return '(max-width: 900px) '.(int) ceil(100 / $colsM * $k).'vw, (max-width: 1680px) '
            .'calc((100vw - 64px) * '.$k.' / '.$colsD.'), '.(int) ceil(1680 * $k / $colsD).'px';
    }

    /** Plain text, one line of whitespace, at most CAPTION characters. */
    public static function caption(?string $raw): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $raw));

        if (mb_strlen($text) > self::CAPTION) {
            $text = rtrim(mb_substr($text, 0, self::CAPTION - 1)).'…';
        }

        return $text;
    }

    /* ------------------------------------------------------------- the admin */

    /**
     * Every synced post, for the picker: newest first, with the selection's
     * order. Read in the browser — the screen filters and searches over THIS
     * list and sends nothing per keystroke. An allowlist: no remote id, no token.
     *
     * @return list<array<string, mixed>>
     */
    public function adminList(): array
    {
        $rows = InstagramPost::query()
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(1000)
            ->get(['id', 'media_type', 'permalink', 'caption', 'local_path', 'like_count',
                'comments_count', 'share_count', 'view_count', 'posted_at', 'spotted_sort']);

        $out = [];

        foreach ($rows as $row) {
            $image = IgPath::stored($row->local_path);
            $link = self::permalink($row->permalink);

            $out[] = [
                'id' => (int) $row->id,
                'type' => match ($row->media_type) {
                    'VIDEO' => 'video',
                    'CAROUSEL_ALBUM' => 'album',
                    default => 'photo',
                },
                // The 200w copy when it exists: the picker draws ~120 px tiles
                // and may hold a thousand of them.
                'thumb' => $image === null ? null : ImageVariants::variantUrl($image, 200),
                'caption' => mb_substr(self::caption($row->caption), 0, 160),
                'likes' => $row->like_count === null ? null : (int) $row->like_count,
                'comments' => $row->comments_count === null ? null : (int) $row->comments_count,
                'shares' => $row->share_count === null ? null : (int) $row->share_count,
                'views' => $row->view_count === null ? null : (int) $row->view_count,
                'date' => $row->posted_at?->toDateString(),
                'url' => $link['url'] ?? null,
                'drawable' => $image !== null && $link !== null,
                'sort' => $row->spotted_sort === null ? null : (int) $row->spotted_sort,
            ];
        }

        return $out;
    }

    /**
     * Store the selection: these posts, in this order, and nothing else.
     * Ids that are not synced posts are ignored. Returns the ids that are now
     * selected AND were not before, so the caller can read their shares.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function select(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
        $ids = array_slice(array_values(array_filter($ids, fn (int $id): bool => $id > 0)), 0, self::MAX);

        $before = InstagramPost::query()->whereNotNull('spotted_sort')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        \Illuminate\Support\Facades\DB::transaction(function () use ($ids): void {
            InstagramPost::query()->whereNotNull('spotted_sort')->update(['spotted_sort' => null]);

            foreach ($ids as $i => $id) {
                InstagramPost::query()->whereKey($id)->update(['spotted_sort' => $i + 1]);
            }
        });

        self::flush();

        return array_values(array_diff($ids, $before));
    }

    public static function flush(): void
    {
        try {
            Cache::forget(self::CACHE);
        } catch (\Throwable) {
            // A cold or missing store has nothing to forget.
        }
    }
}

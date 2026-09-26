<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UgcSection;
use App\Services\Ugc\Tile as UgcTile;
use Illuminate\Support\Facades\Cache;

/**
 * One section, resolved into the flat data a rail prints — and the file where
 * this feature's query cost is decided.
 *
 * ── THE COST IS A SLOPE, AND THE SLOPE IS FLAT ──────────────────────────────
 *
 * Rule 4 forbids N+1s, and a shoppable rail is the shape that invites one: every
 * tile needs a product, and every product needs a brand. Written the obvious way
 * that is 1 + 2n queries — 21 for a rail of ten — and it would have been invisible
 * on a demo library of three.
 *
 * So the whole rail is THREE queries whatever its length:
 *
 *   1  the section, by handle
 *   2  its published videos, in the pivot's order, limited
 *   3  every tagged product of every one of those videos, with its brand,
 *      through one eager load
 *
 * Measured as a slope rather than as a total — see UgcRailBudgetTest, which runs
 * it at 1, 2, 5 and 10 videos and asserts the count does not move. A total proves
 * nothing: a rail that costs 21 queries at ten videos costs 5 at two, and 5 looks
 * fine.
 *
 * ── AND THEN IT IS CACHED, AS PLAIN ARRAYS ──────────────────────────────────
 *
 * `Cache::remember` for RAIL_TTL seconds, keyed by handle, locale and tile cap.
 * What goes in the cache is scalars and arrays, NOT Eloquent models: a cached
 * model is a serialised object graph that has to be woken up, and it pins the
 * shape of every relation at the moment it was written. The payload holds prices
 * as minor units and lets the template format them, so a currency or a display
 * setting changing does not need this cache cleared.
 *
 * WHAT IS NOT IN THE PAYLOAD IS THE SETTINGS. Whether the rating bar is drawn,
 * whether the price is struck, whether a like button appears — all read at render
 * from UgcSettings, so moving a switch on Appearance → Video rail takes
 * effect on the next page load with no cache to clear. Only content is cached.
 *
 * ── EVERY PATH AND EVERY URL IS SANITISED BEFORE IT IS A PAYLOAD ────────────
 *
 * `poster`, `src` and `teaser` go through UgcPath::stored() and the creator's
 * link through UgcPath::link(), HERE, on the way into the payload — not in the
 * template. Rule 5: a URL from a setting is scheme-checked before it becomes an
 * href, and the check belongs at the boundary rather than at the twelve places
 * that print it. A path that fails the allowlist becomes null and the tile draws
 * no element for it, which for `poster` means the video is skipped entirely,
 * because a tile with no poster is a hole in the page at first paint.
 */
class UgcRail
{
    /**
     * Ten minutes, the same TTL App\Support\Shortcodes gives its own product
     * grids. The rail is content the owner edits in the admin, and every admin
     * write path that touches it calls flush() — so the TTL is the backstop for a
     * write that happened somewhere nobody thought of, not the mechanism.
     */
    public const RAIL_TTL = 600;

    /** Cache keys this class created, so flush() does not empty the store. */
    private const INDEX = 'kbb.ugc.rail.index';

    public function __construct(private UgcSettings $settings) {}

    /**
     * The tiles for one section handle, or an empty list.
     *
     * Returns `[]` — and says nothing at all — in every case where there is
     * nothing to draw: the module is off, no such handle, a draft section, a
     * section restricted to the other storefront, or a section every one of whose
     * clips is unpublished. A shortcode that cannot resolve must not leave
     * `[kbb_videos section=...]` in the middle of a published page for a shopper
     * to read, and must not print an error either — the storefront is not where
     * an authoring mistake is reported. Content → Shoppable video → Sections is,
     * and it shows every section's status beside its shortcode.
     *
     * @return array{heading: string, subheading: string, columns: string|null, tiles: list<array<string, mixed>>}
     */
    public function section(string $handle, string $locale, ?int $limit = null): array
    {
        $empty = ['heading' => '', 'subheading' => '', 'columns' => null, 'tiles' => []];

        if (! $this->settings->enabled()) {
            return $empty;
        }

        if (preg_match(UgcSection::HANDLE_RE, $handle) !== 1) {
            return $empty;
        }

        $key = 'kbb.ugc.rail.'.md5($handle.'|'.$locale.'|'.($limit ?? 0));
        $this->remember($key);

        $payload = Cache::remember($key, self::RAIL_TTL, function () use ($handle, $locale, $limit) {
            return $this->build($handle, $locale, $limit);
        });

        if (! is_array($payload) || $payload === []) {
            return $empty;
        }

        return $payload;
    }

    /**
     * The three queries.
     *
     * @return array{heading: string, subheading: string, columns: string|null, tiles: list<array<string, mixed>>}
     */
    private function build(string $handle, string $locale, ?int $limit): array
    {
        $empty = ['heading' => '', 'subheading' => '', 'columns' => null, 'tiles' => []];

        /* 1 — the section. */
        $section = UgcSection::query()->published()->forLocale($locale)->where('handle', $handle)->first();

        if ($section === null) {
            return $empty;
        }

        $cap = $limit !== null ? max(1, min(UgcSection::MAX_TILES, $limit)) : $section->tileCap();

        /*
         * 2 and 3 — the videos and, through ONE eager load, every product of
         * every one of them with its brand.
         *
         * `select` on the product side is the narrow card column list rather than
         * `*`: a rail of twelve was otherwise pulling every description and every
         * SEO column out of the catalogue to print a name and a price. It also
         * keeps a column added later off this path by default.
         */
        $videos = $section->videos()
            ->published()
            ->forLocale($locale)
            ->with(['products' => fn ($q) => $q
                ->select(UgcTile::PRODUCT_COLUMNS)
                ->with('brand:id,name,slug')])
            ->limit($cap)
            ->get();

        $tiles = [];

        foreach ($videos as $video) {
            $tile = UgcTile::fromVideo($video, $locale);

            // A clip whose poster failed the path allowlist is dropped rather
            // than drawn: §2 budgets layout shift at zero and the box is
            // reserved from the poster's own dimensions.
            if ($tile !== null) {
                $tiles[] = $tile;
            }
        }

        return [
            'heading' => (string) ($section->t('heading') ?? ''),
            'subheading' => (string) ($section->t('subheading') ?? ''),
            'columns' => $section->columns === null ? null : (string) $section->columns,
            'tiles' => $tiles,
        ];
    }

    /** Track which keys we created, so flushing does not wipe unrelated caches. */
    private function remember(string $key): void
    {
        $index = (array) Cache::get(self::INDEX, []);

        if (! in_array($key, $index, true)) {
            $index[] = $key;
            Cache::put(self::INDEX, array_slice($index, -200), 86400);
        }
    }

    /**
     * Called after every admin write that could change a rail.
     *
     * Clears only this class's entries. Cache::flush() would empty the whole
     * store — including sessions on some drivers, logging everybody out — which
     * is the reason App\Support\Shortcodes keeps its own index too.
     */
    public static function flush(): void
    {
        foreach ((array) Cache::get(self::INDEX, []) as $key) {
            Cache::forget($key);
        }

        Cache::forget(self::INDEX);
    }
}

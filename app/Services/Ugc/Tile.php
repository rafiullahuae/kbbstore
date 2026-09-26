<?php

declare(strict_types=1);

namespace App\Services\Ugc;

use App\Models\Product;
use App\Models\UgcVideo;
use App\Services\UgcPath;

/**
 * One video row, flattened into the scalars a tile prints.
 *
 * ── WHY A MAPPER AND NOT A MODEL IN THE VIEW ────────────────────────────────
 *
 * Three reasons, and the third is the one that matters:
 *
 *   1. UgcRail caches this. A cached Eloquent model is a serialised object graph
 *      that has to be woken up and that pins the shape of every relation at the
 *      moment it was written; an array of scalars is an array of scalars.
 *   2. Every path and every URL is sanitised ONCE, here, on the way in — rather
 *      than at the dozen places a template prints one. Rule 5 puts the check at
 *      the boundary.
 *   3. A template handed a model can reach anything on it. `rights_evidence` is
 *      on that model — a creator's private message — and so is `rights_status`.
 *      A template handed this array cannot reach either, however carelessly it is
 *      later edited. The same argument UgcVideo::toApi() makes for the public
 *      feed, applied to the storefront.
 *
 * ── PRICES STAY MINOR UNITS ─────────────────────────────────────────────────
 *
 * `now`, `was` are fils, and the template formats them with App\Support\Money the
 * way every other card on this shop does. Formatting here would bake the currency
 * symbol and the digit style into a ten-minute cache entry, and the Arabic
 * storefront reads the same entry.
 */
final class Tile
{
    /**
     * The narrow column list a card needs — App\Support\Shortcodes::CARD_COLUMNS
     * minus what a video tile has no use for, plus the two a rating needs.
     *
     * `type`, `sale_starts_at` and `sale_ends_at` ARE ON THE LIST AND MUST STAY.
     * Product::effectivePrice() reads the sale window, and its variable-product
     * branch reads `type` and the presence of `price` in the attributes —
     * VariantPricing::entry() says so in its own comment — so a narrower select
     * makes a scheduled markdown silently not apply and a variable product quote
     * a price nobody is charged.
     *
     * @var list<string>
     */
    public const PRODUCT_COLUMNS = [
        'products.id', 'products.slug', 'products.name', 'products.brand_id',
        'products.price', 'products.sale_price', 'products.sale_starts_at', 'products.sale_ends_at',
        'products.stock_status', 'products.image', 'products.rating', 'products.review_count',
        'products.type',
    ];

    /**
     * @return array<string, mixed>|null  null when this clip cannot honestly be drawn
     */
    public static function fromVideo(UgcVideo $video, string $locale): ?array
    {
        /*
         * THE POSTER DECIDES WHETHER THIS TILE EXISTS AT ALL.
         *
         * UgcVideo::published() already requires a non-null poster_path, so a
         * null here means the stored path failed UgcPath::stored()'s allowlist —
         * which is a path that was written before the allowlist, or edited on the
         * box by hand. Drawing the tile anyway would be a hole in the page at
         * first paint: §2 budgets layout shift at 0 and the box is reserved from
         * the poster's own width/height, because two tests in this repo forbid the
         * element-measuring APIs by name.
         */
        $poster = UgcPath::stored($video->poster_path);

        if ($poster === null) {
            return null;
        }

        $products = $video->relationLoaded('products') ? $video->products : collect();

        return [
            'slug' => (string) $video->slug,
            'title' => (string) $video->t('title'),
            'caption' => (string) ($video->t('caption') ?? ''),
            'poster' => $poster,
            'src' => UgcPath::stored($video->file_path),
            'teaser' => UgcPath::stored($video->teaser_path),
            // The box, from the columns, never from a measurement.
            'width' => (int) ($video->width ?: 720),
            'height' => (int) ($video->height ?: 1280),
            'duration_ms' => (int) $video->duration_ms,
            'handle' => (string) ($video->creator_handle ?? ''),
            // Scheme-checked HERE. UgcPath::link() decodes entities and strips
            // control characters BEFORE it reads the scheme, which is the point:
            // `java&Tab;script:` and `&#106;avascript:` are the same string by the
            // time a browser acts on one.
            'handle_url' => UgcPath::link($video->creator_url),
            'source_url' => UgcPath::link($video->source_url),
            'platform' => (string) $video->source_platform,
            'likes' => (int) $video->likes,
            /*
             * OUR OWN like count, and the only number on a tile this shop did not
             * compute from its own catalogue. There is no key here for a source's
             * likes or comments: the owner cut that mid-round and nothing in this
             * module fetches or stores one.
             */
            'engagement' => ['own_likes' => (int) $video->likes],
            'count' => $products->count(),
            'products' => $products->map(fn (Product $p) => self::product($p))->values()->all(),
        ];
    }

    /**
     * The greatest common divisor, so a tile's reserved aspect-ratio is printed in
     * lowest terms.
     *
     * 360/640 and 9/16 lay out identically, but getComputedStyle reports the
     * string it was given — so the element-by-element run against R3 reported
     * `9 / 16` against `360 / 640` as a delta on every single tile. Reducing makes
     * the computed value identical for an ordinary portrait clip while still
     * reserving the true box for one that is not 9:16.
     *
     * Iterative rather than recursive: this runs once per tile and a recursive
     * gcd on a pathological pair is a stack this shop does not need to risk.
     */
    public static function gcd(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }

    /** @return array<string, mixed> */
    private static function product(Product $p): array
    {
        $now = $p->effectivePrice();
        $regular = $p->price === null ? 0 : (int) $p->price;

        /*
         * `was` is null unless there is genuinely something to strike. The same
         * rule partials/home/grid.blade.php applies — `$reg > 0 && $sale < $reg`
         * — so the rail and the shop card never disagree about whether a product
         * is on sale.
         */
        $was = ($regular > 0 && $now < $regular) ? $regular : null;

        return [
            'id' => (int) $p->id,
            'name' => (string) $p->t('name'),
            'brand' => (string) ($p->brand?->t('name') ?? ''),
            'url' => $p->url(),
            'image' => (string) ($p->image ?? ''),
            'now' => $now,
            'was' => $was,
            'off' => $was === null ? 0 : (int) round((1 - $now / $was) * 100),
            /*
             * THE RATING IS REAL, AND ZERO REVIEWS MEANS NO BAR.
             *
             * `rating` and `review_count` are the denormalised pair the shop
             * cards, ?sort=rating, ?sort=popular, the top_rated shortcode and the
             * schema.org aggregateRating already read. `stars` rounds exactly as
             * partials/home/grid.blade.php rounds — `(int) round((float) rating)`
             * — so a tile and a card never disagree about how many stars a 4.6
             * gets.
             *
             * `reviews` of 0 is what the template checks, and it draws NO BAR AT
             * ALL for it: an empty five-star row reads as "rated badly" and a zero
             * reads as "rated zero", and a product can be three years old and
             * simply unreviewed. The shop already draws this line — grid.blade.php
             * gates its New badge on `! $p->review_count`.
             */
            'rating' => (float) $p->rating,
            'stars' => (int) round((float) $p->rating),
            'reviews' => (int) $p->review_count,
            'in_stock' => (string) $p->stock_status === 'instock',
        ];
    }
}

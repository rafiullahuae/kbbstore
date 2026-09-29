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
        /*
         * ▲ AND THE THREE A SET'S PRICE CANNOT BE READ WITHOUT. (Lane SG)
         *   Qualified like the rest of this list because it is used across a
         *   join. See App\Support\SetPricing::COLUMNS: without them a set
         *   pinned to a clip quotes the number in `products.price` while the
         *   set's own page quotes the derived one.
         */
        'products.set_price_mode', 'products.set_discount', 'products.set_price_basis',
    ];

    /**
     * The poster, or null when the file it names is not on disk.      Lane PERF
     *
     * ── THE DEFECT, MEASURED ON THE OWNER'S OWN SHOP ────────────────────────
     *
     * PageSpeed Insights, extrabeauty.ae, 29 September 2026. Best Practices
     * scored 96 instead of 100 and the ONE audit that cost it was:
     *
     *   Browser errors were logged to the console
     *     …ugc/poster-20….jpg:1:0   Failed to load resource: the server
     *     (extrabeauty.ae)          responded with a status of 404 (Not Found)
     *
     * The row is real and published, `poster_path` holds a perfectly
     * well-formed `/uploads/ugc/poster-20260928-081820-fCoQkEvJ5G.jpg`, and
     * the file was never written — the cover is CUT from the clip by
     * UgcTranscoder, and a cut that fails leaves the column set and the disk
     * empty. `UgcPath::stored()` checks the SHAPE of the string, which is its
     * job and is not this question; nothing between there and the browser ever
     * asks whether the file exists.
     *
     * ── WHY null AND NOT A PLACEHOLDER ──────────────────────────────────────
     *
     * Because the template already draws this case, correctly, and says so:
     * "A clip may now be published without a cover … the reserved box is the
     * tile's own aspect-ratio and does not depend on this element existing, so
     * leaving it out shifts nothing." A cover that is missing from disk and a
     * cover that was never set are the same thing to a shopper; they were only
     * different to the browser, which fetched one of them and got a 404.
     *
     * So the tile still draws, the clip still plays, CLS does not move, and
     * the console is clean.
     *
     * ── THE COST IS ONE is_file() PER TILE, AND IT IS THE PRICE ALREADY PAID ─
     *
     * App\Support\ImageVariants makes this exact trade and states it: "Two
     * is_file() calls per tile, fifty on a full grid, are answered from the
     * kernel's dentry cache and out of PHP's own stat cache; they cost far less
     * than one 190KB download they save." A rail draws at most a handful of
     * tiles. The same header explains why the disk is the authority and a
     * column is not: "A column can disagree with the disk … The disk cannot
     * disagree with itself."
     *
     * NOT MEMOISED, for the reason that file gives as well: a process-level
     * memo is wrong in exactly the request that has just written the file.
     *
     * The path is already `/uploads/ugc/<one segment>` — UgcPath::stored()
     * guarantees it, with no traversal, no second directory and no scheme —
     * so there is nothing left for this to sanitise and it deliberately does
     * not try to. A null in means a null out.
     */
    private static function posterOnDisk(?string $poster): ?string
    {
        if ($poster === null) {
            return null;
        }

        return is_file(public_path(ltrim($poster, '/'))) ? $poster : null;
    }

    /**
     * @return array<string, mixed>|null  null when this clip cannot honestly be drawn
     */
    public static function fromVideo(UgcVideo $video, string $locale): ?array
    {
        /*
         * ── THE VIDEO DECIDES WHETHER THIS TILE EXISTS. THE POSTER NO LONGER ──
         *
         * This used to `return null` for a missing poster, and UgcVideo::
         * published() used to refuse to hand one over anyway. Both changed
         * together when the owner asked to be able to publish without a cover —
         * and BOTH had to, or a clip he published would have been fetched by the
         * query and then dropped silently here, which is the same invisible
         * no-show by a different route.
         *
         * A null poster is now drawn. What is NOT given up is the reserved box:
         * §2 budgets layout shift at 0, the width and height below come from the
         * clip's own columns with a 9:16 fallback, and two tests in this repo
         * forbid the element-measuring APIs by name. So a cover-less tile holds
         * exactly the same space as a covered one and the page does not jump —
         * the shopper simply sees an empty box for the moment before the video
         * paints instead of a still.
         *
         * A SOURCE IS STILL REQUIRED. `src` below is scheme-checked the same way,
         * and a tile with neither a poster nor a playable source is an empty box
         * that plays nothing, so that one is still refused.
         */
        $poster = self::posterOnDisk(UgcPath::stored($video->poster_path));
        $src = UgcPath::stored($video->file_path);

        if ($poster === null && $src === null) {
            return null;
        }

        $products = $video->relationLoaded('products') ? $video->products : collect();

        return [
            'slug' => (string) $video->slug,
            'title' => (string) $video->t('title'),
            'caption' => (string) ($video->t('caption') ?? ''),
            'poster' => $poster,
            'src' => $src,
            'teaser' => UgcPath::stored($video->teaser_path),
            /*
             * ── IS THIS TILE PLACEHOLDER FOOTAGE ────────────────────────────
             *
             * Content -> Demo content writes six `(Demo)` clips that all point
             * at one generated 270x480 plum gradient, so an owner can see the
             * screens working before he has uploaded anything. They are
             * published rows and they render exactly like real ones — which is
             * the point, and which is also how they came to take every
             * playback slot on his homepage rail ahead of the two clips he
             * actually uploaded. The rail's cap was spent in DOCUMENT ORDER and
             * the demo rows were imported first.
             *
             * A COMPARISON AGAINST A CONSTANT, not a heuristic and not a query.
             * UgcMedia::place() names every real upload
             * `<kind>-<timestamp>-<10 random chars>.<ext>`, so the only row that
             * can ever match this is one this application wrote itself. The
             * storefront then ranks a demo tile BELOW a real one
             * (ugc/assets.blade.php) instead of refusing it — a shop whose
             * clips are all demo rows still gets a rail that moves.
             */
            'demo' => $src === \App\Support\UgcDemoMedia::CLIP_PATH,
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

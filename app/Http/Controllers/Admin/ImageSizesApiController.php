<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\ImageVariants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phone-sized copies for the photographs that were already here.
 *
 * MediaUploadController makes a copy of every image from now on. That leaves
 * the catalogue — thousands of photographs uploaded before any of this existed,
 * which is to say the entire shop. They will never have a copy unless something
 * makes one, and on this host there is nothing to make it with.
 *
 * WHAT WAS AVAILABLE TO DO THE WORK, AND WHY THIS IS WHAT IT IS.
 *
 *   An Artisan command. There is no shell on this host. The owner cannot run
 *   it, this lane cannot run it for him, and a command nobody can invoke is a
 *   backlog that never drains. Rejected outright, not written.
 *
 *   A queued job. There is no worker. QUEUE_CONNECTION is sync, so a "queued"
 *   resize happens inside whichever web request dispatched it, which is the
 *   request-time resize under another name.
 *
 *   Generating on first request. The tile would have to point at a PHP route
 *   for a file that may not exist, so a cold grid is twenty-five image decodes
 *   arriving at once on a host with a handful of PHP workers — the first
 *   shopper of the day pays for the whole page, and pays worst on the phone
 *   this is meant to help. Serving them straight off disk instead needs a
 *   rewrite that falls through to PHP on a miss, and that rewrite lives in an
 *   .htaccess in the web root, which no package can ship and no shell can fix
 *   if it is wrong.
 *
 *   Doing nothing, and letting only new uploads have copies. The existing
 *   catalogue IS the catalogue. It would be years before this was worth
 *   anything, and the measurement that justified the work would never be true
 *   of the shop as it stands.
 *
 *   A bounded batch the owner starts from a screen — this. It needs no shell,
 *   no worker and no cron. Each request does a few seconds of work and returns,
 *   so it cannot hit max_execution_time; the browser asks again with the cursor
 *   it was handed. It is idempotent and resumable, so a closed tab, a dropped
 *   connection or a 500 costs the batch in flight and nothing else. And because
 *   the tile reads the disk rather than a column, a half-finished run is not a
 *   half-broken shop: every photograph that has its copies uses them, every one
 *   that does not loads exactly what it loads today.
 *
 * THE COST IS REAL AND IS THE OWNER'S TO SPEND. About 137ms of CPU per
 * 1000x1000 photograph, so roughly seven minutes of a shared host's CPU for
 * three thousand of them, spread over a few hundred small requests while he
 * leaves a tab open. That is the price of the whole thing, once.
 */
class ImageSizesApiController extends Controller
{
    /**
     * How many photographs one request will finish before handing back.
     *
     * Two ceilings, and the time one is the one that matters: the images in
     * this catalogue are not all 1000x1000, and a run of large ones would
     * otherwise turn a batch of forty into a request that outlives
     * max_execution_time. Ten seconds is comfortably inside the thirty a shared
     * host typically allows, with the rest of the request to spare.
     */
    private const MAX_IMAGES = 40;

    private const MAX_SECONDS = 10.0;

    /**
     * How many rows may be looked at in one request, whether or not they need
     * work.
     *
     * Skipping a photograph that already has its copies costs two stat calls,
     * so a run over an almost-finished catalogue would otherwise walk tens of
     * thousands of rows in a single request just to find the stragglers. This
     * keeps the cursor moving in bounded steps either way.
     */
    private const MAX_EXAMINED = 2000;

    /** What is left to do, and whether this PHP can do it at all. */
    public function status(): JsonResponse
    {
        $total = $done = $foreign = $remaining = 0;

        foreach ($this->images() as $image) {
            $total++;

            // A photograph on another domain, an SVG, or a file this web root
            // does not hold. Counted apart from the backlog, so the screen
            // never tells the owner to keep clicking at work that can never
            // finish.
            if (! ImageVariants::isLocal((string) $image)) {
                $foreign++;

                continue;
            }

            ImageVariants::isComplete((string) $image) ? $done++ : $remaining++;
        }

        return response()->json([
            'ok' => true,
            'available' => ImageVariants::available(),
            'widths' => ImageVariants::WIDTHS,
            'total' => $total,
            'done' => $done,
            'not_ours' => $foreign,
            'remaining' => $remaining,
        ]);
    }

    /**
     * One bounded batch, and the cursor to ask for the next.
     *
     * The cursor is the image reference itself, walked in ascending order.
     * Not an offset: an offset shifts under an import that adds a product
     * mid-run and silently skips whatever moved across the boundary.
     */
    public function run(Request $request): JsonResponse
    {
        if (! ImageVariants::available()) {
            return response()->json([
                'ok' => false,
                'message' => 'This server has no image library (GD), so it cannot make smaller copies. '
                    . 'Ask the host to enable the PHP gd extension.',
            ], 422);
        }

        $after = trim((string) $request->input('after', ''));
        $startedAt = microtime(true);

        $examined = $sized = $made = 0;
        $cursor = $after;
        $done = true;

        foreach ($this->images($after) as $image) {
            $image = (string) $image;
            $cursor = $image;
            $examined++;

            $result = ImageVariants::generate($image);

            if ($result['made'] > 0) {
                $sized++;
                $made += $result['made'];
            }

            if ($sized >= self::MAX_IMAGES
                || $examined >= self::MAX_EXAMINED
                || (microtime(true) - $startedAt) >= self::MAX_SECONDS) {
                $done = false;
                break;
            }
        }

        return response()->json([
            'ok' => true,
            'examined' => $examined,
            'sized' => $sized,
            'made' => $made,
            'cursor' => $cursor,
            // True only when the walk ran off the end of the catalogue, never
            // because a ceiling was hit. The client stops on this and nothing
            // else, so a batch that happens to size zero images — a long run of
            // photographs on another domain — is not mistaken for the end.
            'done' => $done,
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Every distinct photograph this SHOP can render, in a stable order — the
     * featured image, every shot in the gallery beside it, every variant's own
     * picture, and every photograph a shopper attached to a review.
     *
     * ── THE GALLERY WAS NEVER IN THIS LIST, AND THAT WAS THE WHOLE BUG ──────
     *
     * The owner, with a screenshot arrowing at the thumbnail strip: "the
     * products gallery thumbnails must load the thumbnail sizes, not the full
     * image, to reduce the page load."
     *
     * He was describing this method. It walked `products.image` and nothing
     * else. `products.images` — the JSON array that IS the gallery, and where
     * shots two onwards live — was never looked at, so the batch could run to
     * completion, report `remaining: 0`, and leave every gallery photograph in
     * the catalogue with no copies at all. The strip then emitted no srcset
     * (srcsetFor() lists only what is on disk, which is the right behaviour and
     * is what made this invisible) and each 66px square downloaded the
     * full-resolution photograph.
     *
     * Measured on a five-shot gallery of 1000x1000 JPEGs, after driving this
     * very controller to `done: true`: the strip made 5 requests for 1188KB,
     * of which 4 were untouched originals at ~290KB each painted into a 52px
     * box. Only shot one — the featured image, the one column this walked —
     * had copies.
     *
     * WHY THE FILTER AND THE ORDER MOVED OUT OF SQL. The cursor is still the
     * image reference itself walked ascending, and it still has to mean one
     * thing across the whole work list. A JSON array cannot be ordered or
     * range-filtered portably (this runs on MySQL in production and SQLite in
     * the suite), and a gallery shot belonging to a row whose featured image
     * sorts BEFORE the cursor can itself sort after it — so filtering rows in
     * SQL would silently skip work. The two columns are therefore read in one
     * query and merged, de-duplicated and sorted here.
     *
     * That is one query, the same as before, over two columns instead of one.
     * It reads the whole catalogue rather than the tail after the cursor, which
     * is the price of a correct work list: 671 products at four shots each is a
     * few thousand short strings, and the request is bounded by MAX_EXAMINED
     * and MAX_SECONDS as it always was.
     *
     * Distinct because this catalogue reuses images across products, and
     * resizing the same file six hundred times would be six hundred decodes to
     * write one file. Sorted by the value itself so the cursor means the same
     * thing on MySQL and on SQLite, neither of which promises an order without
     * being asked.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function images(string $after = ''): \Illuminate\Support\Collection
    {
        $seen = [];

        // toBase(): a work list is strings, and hydrating a model per row to
        // read two columns is the expensive way to get them on a shared host.
        // The cast comes back as raw JSON here, which is why it is decoded
        // below rather than assumed to be an array.
        $rows = Product::query()->toBase()->get(['image', 'images']);

        /*
         * AND THE VARIANTS' OWN PHOTOGRAPHS, which are a third place a small
         * square is drawn from a big file and were as invisible to this list as
         * the gallery was.
         *
         * `product_variants.image` is what the option swatch on the product
         * page paints into a 22px circle (.vsw, kbb-product.css:194 -- the
         * worst ratio on this site), and it is ALSO what every basket and
         * checkout line prefers over the parent's photograph:
         * `$item->variant?->image ?: $p?->image`. So a basket full of chosen
         * options would have gone on pulling full-resolution files however many
         * copies the products had.
         *
         * A second query rather than a join: this is one flat list of strings
         * and a join would multiply product rows by their variants to produce
         * the same set. It is an admin request that is about to decode images,
         * so the query is not the cost here.
         */
        $variants = \Illuminate\Support\Facades\DB::table('product_variants')
            ->whereNotNull('image')
            ->where('image', '<>', '')
            ->pluck('image');

        /*
         * ── AND THE REVIEW PHOTOGRAPHS, WHICH HAD NO COPIES AT ALL ──────────
         *
         * (Lane IM2.) This list walked products and product variants, so a
         * photograph a shopper attached to a review could never have a variant
         * for anything to find: not because the batch skipped it, but because
         * it was never work. The batch would report `remaining: 0` over a shop
         * whose review wall was still serving phone camera originals.
         *
         * WHAT THAT COSTS, measured on this preview: a review photograph off a
         * handset is 1080x1920 and ~530KB. partials/reviews.blade.php draws it
         * into a card 266px wide and 160px tall (.sr-pp.one) or a 76px-tall
         * half-card square (.sr-pp.multi), both object-fit:cover. Four
         * photographs on one product page is ~2.1MB to paint about a
         * postcard's worth of pixels, and unlike a product photograph it is
         * NOT shared across the catalogue — every review brings its own files.
         *
         * IT IS ABOUT TO MATTER MUCH MORE. The WordPress importer brings review
         * photographs across from plugin 1.6.0 onwards (reviews.csv gained an
         * `images` column), so a shop that has one or two of these today will
         * have thousands after the next import.
         *
         * WHY THE COLUMN IS READ THE SAME WAY `products.images` IS, and not
         * with a join or a JSON function: identical reasoning to the block
         * above. `reviews.images` is a JSON array, a JSON array cannot be
         * ordered or range-filtered portably across MySQL and SQLite, and a
         * photograph on a row whose id sorts before the cursor can itself sort
         * after it. So the rows are read, decoded and merged into the same flat
         * sorted set, and the cursor goes on meaning one thing across the whole
         * work list.
         *
         * THE TWO CHEAP SQL PREDICATES ARE NOT A FILTER ON THE CURSOR — they
         * cannot be, for the reason just given. They only skip rows that carry
         * no photograph at all, which on this table is the overwhelming
         * majority: a shop with 4,000 reviews and 120 photographed ones reads
         * 120 short JSON strings instead of 4,000 nulls. `'[]'` is matched by
         * value because that is what an empty cast writes back.
         */
        $reviewImages = \Illuminate\Support\Facades\DB::table('reviews')
            ->whereNotNull('images')
            ->where('images', '<>', '')
            ->where('images', '<>', '[]')
            ->pluck('images');

        $keep = function ($image) use (&$seen, $after): void {
            if (! is_string($image)) {
                return;
            }

            $image = trim($image);

            // `> $after` and not `>=`: the cursor is the last reference this
            // walk FINISHED, so resuming on it would size it twice — harmless
            // but wasted, and on a long catalogue it is the difference between
            // finishing and looping.
            if ($image === '' || ($after !== '' && strcmp($image, $after) <= 0)) {
                return;
            }

            $seen[$image] = true;
        };

        foreach ($rows as $row) {
            $keep($row->image ?? null);

            $gallery = $row->images ?? null;

            if (is_string($gallery) && $gallery !== '') {
                $gallery = json_decode($gallery, true);
            }

            if (is_array($gallery)) {
                foreach ($gallery as $shot) {
                    $keep($shot);
                }
            }
        }

        foreach ($variants as $image) {
            $keep($image);
        }

        foreach ($reviewImages as $json) {
            // toBase() again, so the cast has not run and this is raw JSON —
            // the same shape products.images arrives in above, decoded the same
            // way rather than assumed to be an array.
            $photos = is_string($json) && $json !== '' ? json_decode($json, true) : $json;

            if (is_array($photos)) {
                foreach ($photos as $photo) {
                    $keep($photo);
                }
            }
        }

        // strval because PHP casts an array key that looks like an integer, and
        // a catalogue row holding "123" would otherwise hand the caller an int
        // where every signature here says string.
        $images = array_map('strval', array_keys($seen));
        sort($images, SORT_STRING);

        return collect($images);
    }
}

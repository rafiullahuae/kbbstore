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
     * Every distinct photograph a product tile can render, in a stable order.
     *
     * Distinct because this catalogue reuses images across products, and
     * resizing the same file six hundred times would be six hundred decodes to
     * write one file. Ordered by the value itself so the cursor means the same
     * thing on MySQL and on SQLite, neither of which promises an order without
     * being asked.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function images(string $after = ''): \Illuminate\Support\Collection
    {
        $query = Product::query()
            ->whereNotNull('image')
            ->where('image', '<>', '')
            ->distinct()
            ->orderBy('image');

        if ($after !== '') {
            $query->where('image', '>', $after);
        }

        return $query->pluck('image');
    }
}

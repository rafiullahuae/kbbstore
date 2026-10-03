<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\ImageVariants;
use App\Support\ShareImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Make all share pictures now" — the admin button behind
 * `php artisan kbb:share-images`.                               (2.60.367)
 *
 * THE OWNER, 3 October 2026: the command run over SSH as the server's master
 * user reported "0 made … 770 skipped" — 601 "could not write" and 167
 * "cache directory not writable". The pictures were fine; the share-picture
 * folder belongs to the WEBSITE's own user (PHP made it), and the master login
 * may not write there. He asked for it "inside admin … make sure it don't
 * disturb anything else". So the same work runs here, as the website, which
 * can write there.
 *
 * WHAT IT TOUCHES: files under public/img-cache/share (or share-sq), and
 * nothing else — no setting, no product row, no other cache. Exactly what
 * ShareImage::make() already writes when a product page is first opened.
 *
 * IN SLICES, so no request runs long on a shared host: up to BATCH products
 * or SECONDS of work per call, whichever comes first, from the id after
 * `after`. The screen calls again with the `after` it is handed until
 * `done`. Two queries a call (the count and the slice).
 *
 * Capability `shareimages.make`, its own, failing closed
 * (App\Support\AdminCapabilities).
 */
class ShareImagesApiController extends Controller
{
    /** Products per call at most. */
    public const BATCH = 25;

    /** Seconds of encoding per call at most; the slice stops at the first product past it. */
    public const SECONDS = 8.0;

    public function run(Request $request): JsonResponse
    {
        if (! ImageVariants::available()) {
            return response()->json(['ok' => false, 'error' => 'This server’s PHP has no image library (GD), so share pictures cannot be made. Each product keeps its original picture.'], 422);
        }

        $after = max(0, (int) $request->input('after', 0));
        $base = Product::query()->whereNotNull('image')->where('image', '!=', '');
        $started = microtime(true);
        $made = $fresh = $skipped = 0;
        $reasons = [];
        $last = $after;

        $products = (clone $base)->where('id', '>', $after)->orderBy('id')
            ->limit(self::BATCH)->get(['id', 'image', 'seo']);

        foreach ($products as $product) {
            if ($last !== $after && microtime(true) - $started > self::SECONDS) {
                break;
            }

            $last = (int) $product->id;
            $seo = is_array($product->seo) ? $product->seo : [];

            try {
                $result = ShareImage::make(ImageVariants::rootRelative((string) ($seo['og_image'] ?? $product->image)));
            } catch (\Throwable) {
                $result = ['made' => false, 'reason' => 'could not read'];
            }

            if ($result['made']) {
                $made++;
            } elseif ($result['reason'] === 'fresh') {
                $fresh++;
            } else {
                $skipped++;
                $reason = (string) $result['reason'];
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }

        $done = $products->isEmpty() || ! (clone $base)->where('id', '>', $last)->exists();

        return response()->json([
            'ok' => true,
            'made' => $made,
            'fresh' => $fresh,
            'skipped' => $skipped,
            'reasons' => $reasons,
            'after' => $last,
            'done' => $done,
            'total' => $after === 0 ? (clone $base)->count() : null,
        ]);
    }
}

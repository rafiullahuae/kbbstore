<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The 301 from a picture's old address to the name Catalog → Image SEO gave
 * it. (Lane IR)
 *
 * ── ONE ENTRY POINT ────────────────────────────────────────────────────────
 *
 * targetFor($request) is the whole public surface the 404 handler calls
 * (AppServiceProvider's NotFoundHttpException renderable, after the Store →
 * Redirects match and before the 404 is logged). resolvePath($rel) is the same
 * answer for a caller that already has a web-root-relative path — another
 * image redirect (the SEO lane's /wp-content/uploads → current image) passes
 * its own target through here so a renamed picture is still ONE hop.
 *
 * ── WHY IT CANNOT COST A NORMAL PAGE ANYTHING ──────────────────────────────
 *
 * It runs only for a request that already MISSED: the web server serves a
 * file that exists without starting PHP, and Laravel reaches its 404 handler
 * only after no route matched. Then a string test throws out everything that
 * is not a picture under an upload root or img-cache (bots asking for
 * /wp-login.php cost nothing), and what is left is ONE indexed query on
 * image_renames.old_path.
 *
 * ── THE OLD COPIES REDIRECT TOO ────────────────────────────────────────────
 *
 * A page cached before the rename (by the host, by a browser, by the shop
 * app's service worker) asks for `img-cache/400/uploads/products/old.jpg` in
 * its srcset. Those answer with the matching copy of the new file — and with
 * the new original when that width was never made — never a 404.
 */
final class ImageRenameRedirect
{
    private const EXTENSIONS = '(?:jpe?g|png|webp|gif|avif)';

    private const JPG_SUFFIXED = ['share', 'share-sq'];

    /** The URL to send this request to, or null when it is not a renamed picture. */
    public static function targetFor(Request $request): ?string
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return null;
        }

        $path = rawurldecode($request->getPathInfo());
        $root = rtrim($request->getBaseUrl(), '/');

        // A base path the routes carry (KBB_BASE_PATH on the old staging box)
        // is part of the address, not of the path below the web root.
        $base = Url::base();

        if ($base !== '' && $base !== $root && str_starts_with($path, $base.'/')) {
            $path = substr($path, strlen($base));
            $root .= $base;
        }

        $target = self::resolvePath(ltrim($path, '/'));

        if ($target === null) {
            return null;
        }

        $encoded = implode('/', array_map('rawurlencode', explode('/', $target)));

        return $root.'/'.$encoded;
    }

    /** The web-root-relative path a renamed picture (or copy of one) now lives at. */
    public static function resolvePath(string $rel): ?string
    {
        $rel = ltrim($rel, '/');

        if ($rel === '' || strlen($rel) > 600 || str_contains($rel, "\0") || str_contains($rel, '..')
            || preg_match('#^(?:img-cache/|uploads/|wp-content/uploads/).+\.'.self::EXTENSIONS.'(?:\.jpg)?$#i', $rel) !== 1) {
            return null;
        }

        /** @var array<string, array{0: string, 1: string}> $candidates old path => [prefix, suffix] */
        $candidates = [];

        if (str_starts_with($rel, 'img-cache/')) {
            $segments = explode('/', $rel);

            foreach ([2, 3] as $depth) {
                if (count($segments) <= $depth) {
                    continue;
                }

                $prefix = implode('/', array_slice($segments, 0, $depth)).'/';
                $rest = implode('/', array_slice($segments, $depth));
                $candidates[$rest] = [$prefix, ''];

                if ($depth === 2 && in_array($segments[1], self::JPG_SUFFIXED, true) && str_ends_with($rest, '.jpg')) {
                    $candidates[substr($rest, 0, -4)] = [$prefix, '.jpg'];
                }
            }
        } else {
            $candidates[$rel] = ['', ''];
        }

        try {
            $row = DB::table('image_renames')
                ->whereIn('old_path', array_keys($candidates))
                ->where('status', 'done')
                ->orderByDesc('id')
                ->first(['old_path', 'new_path']);
        } catch (\Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        [$prefix, $suffix] = $candidates[(string) $row->old_path];
        $new = (string) $row->new_path;

        if ($prefix === '') {
            return $new;
        }

        // The same copy under the new name, or the new original when that
        // copy does not exist: a picture, never a 404.
        $copy = $prefix.$new.$suffix;

        return is_file(public_path($copy)) ? $copy : $new;
    }
}

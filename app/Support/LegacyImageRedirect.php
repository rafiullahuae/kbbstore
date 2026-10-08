<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * An old picture address that no longer has a file behind it, sent to the file
 * that shows that picture NOW. (Lane SEO)
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Google Images has kbeautybliss.com/wp-content/uploads/YYYY/MM/<file> indexed,
 * and the shop is moving onto that domain. The importer KEEPS those paths: an
 * imported photograph lives at the same /wp-content/uploads/… path on this
 * server (MediaRewrite, MediaSideloader::targetPath()), so most indexed image
 * URLs keep answering with no help at all. Three kinds do not, and each one is
 * a 404 Google Images would quietly drop:
 *
 *   1. WordPress's resized copies. A WooCommerce product page showed
 *      `serum-600x600.jpg` (and srcset `-300x300`, `-768x768`, `-100x100`),
 *      and those are the URLs Google Images mostly indexed. The import brought
 *      the ORIGINAL only, so every sized copy 404s.            -> the original
 *   2. WordPress 5.3+'s `-scaled` originals: the catalogue holds
 *      `serum-scaled.jpg`, while `serum.jpg` and its sized copies are what was
 *      linked.                                                  -> the -scaled file
 *   3. A JPEG converted to WebP whose original was then removed
 *      (Media → WebP, "Remove originals"). `webp_conversions` records
 *      from_path -> to_path for every one.                      -> the .webp
 *
 * ── ONE HOP, TO THE FILE THAT EXISTS NOW ────────────────────────────────────
 *
 * A move is followed hop by hop INSIDE this class (up to MAX_HOPS) and the
 * answer is only ever a path that `is_file()` confirms on disk at request time.
 * So a picture converted to WebP and later renamed still answers with ONE 301,
 * straight to the renamed file, and a stored address that has gone stale is
 * never handed out. movedTo() is the single place a move is looked up; the
 * image-renaming lane's ledger belongs there, beside webp_conversions.
 *
 * ── WHAT IT COSTS, AND WHO PAYS ─────────────────────────────────────────────
 *
 * Nothing on any page that answered. It is called from the 404 handler in
 * AppServiceProvider, after the redirects table, and its first test is two
 * str_starts_with() calls: anything not under an upload root, and anything not
 * named like a picture, leaves at once. A request that does qualify pays at
 * most three is_file() calls and one indexed lookup (webp_conversions.from_path
 * is UNIQUE) per candidate. A path with no answer falls through to exactly the
 * 404 it got before.
 *
 * ── SAFETY ──────────────────────────────────────────────────────────────────
 *
 * The answer is always a web-root-relative path under one of
 * MediaRegistrar::ROOTS that exists on disk inside public_path(); `..`, a
 * backslash, a NUL or a control byte in the request refuses outright. The
 * Location is this shop's own origin plus that path, so nothing a visitor sends
 * can point the redirect at another host.
 */
final class LegacyImageRedirect
{
    /** Picture extensions only; a .php or .html under uploads is not ours to resolve. */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /** Moves followed inside one answer (WebP, then a rename, then …). */
    private const MAX_HOPS = 4;

    /** WordPress's resized copy: `name-600x600.jpg`. */
    private const SIZED = '/^(.+)-(\d{2,5})x(\d{2,5})(\.[A-Za-z0-9]{3,4})$/';

    /**
     * The one entry point: the current web-root-relative path for an old one,
     * or null when there is nothing to send it to.
     *
     * $path is web-root-relative and decoded (`wp-content/uploads/2019/03/a.jpg`);
     * a leading slash is tolerated.
     */
    public static function targetFor(string $path): ?string
    {
        $path = ltrim($path, '/');

        if (! self::eligible($path)) {
            return null;
        }

        foreach (self::candidates($path) as $candidate) {
            $current = self::current($candidate);

            if ($current !== null && $current !== $path) {
                return $current;
            }
        }

        return null;
    }

    /**
     * The 301 for this request, or null. Called by the 404 handler only.
     */
    public static function respond(Request $request): ?RedirectResponse
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return null;
        }

        $path = $request->decodedPath();

        // The cheap gate, before anything else is computed: two prefix tests.
        if (! self::underRoot($path)) {
            return null;
        }

        $target = self::targetFor($path);

        if ($target === null) {
            return null;
        }

        $encoded = implode('/', array_map('rawurlencode', explode('/', $target)));

        return new RedirectResponse(SiteUrl::origin($request) . Url::raw('/' . $encoded), 301);
    }

    private static function underRoot(string $path): bool
    {
        foreach (MediaRegistrar::ROOTS as $root) {
            if (str_starts_with($path, $root)) {
                return true;
            }
        }

        return false;
    }

    private static function eligible(string $path): bool
    {
        if ($path === '' || strlen($path) > 255 || ! self::underRoot($path)) {
            return false;
        }

        if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, self::EXTENSIONS, true);
    }

    /**
     * The addresses that may hold this picture, best first: the path itself
     * (moved as a whole, e.g. to WebP), the original a WordPress sized copy was
     * cut from, and that original's `-scaled` twin.
     *
     * @return list<string>
     */
    private static function candidates(string $path): array
    {
        $out = [$path];
        $base = $path;

        if (preg_match(self::SIZED, $path, $m) === 1) {
            $base = $m[1] . $m[4];
            $out[] = $base;
        }

        $ext = '.' . pathinfo($base, PATHINFO_EXTENSION);
        $stem = substr($base, 0, -strlen($ext));

        if (! str_ends_with($stem, '-scaled')) {
            $out[] = $stem . '-scaled' . $ext;
        }

        return array_values(array_unique($out));
    }

    /**
     * Follow a picture's moves to the file that shows it today.
     */
    private static function current(string $path): ?string
    {
        $seen = [];

        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            if (isset($seen[$path]) || ! self::eligible($path)) {
                return null;
            }

            $seen[$path] = true;

            if (self::onDisk($path)) {
                return $path;
            }

            $next = self::movedTo($path);

            if ($next === null) {
                return null;
            }

            $path = $next;
        }

        return null;
    }

    /**
     * Where this exact path was moved to, by the records that move files.
     *
     * webp_conversions today. The image-renaming ledger goes HERE, as a second
     * lookup, so every caller keeps one entry point and one hop.
     */
    private static function movedTo(string $path): ?string
    {
        try {
            $to = DB::table('webp_conversions')
                ->where('from_path', $path)
                ->whereIn('status', ['converted', 'removed'])
                ->whereNotNull('to_path')
                ->value('to_path');
        } catch (\Throwable) {
            // No table yet (a package that has not migrated): nothing has moved.
            return null;
        }

        return is_string($to) && $to !== '' ? ltrim($to, '/') : null;
    }

    private static function onDisk(string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', (string) public_path()), '/');

        return $root !== '' && is_file($root . '/' . $path);
    }
}

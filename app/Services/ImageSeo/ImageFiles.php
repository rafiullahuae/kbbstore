<?php

declare(strict_types=1);

namespace App\Services\ImageSeo;

use App\Services\Media\WebpBulk;
use App\Services\Media\WebpReferences;
use App\Support\ImageVariants;
use App\Support\MediaRegistrar;
use App\Support\Url;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a product picture lives on disk, and every file derived from it.
 * (Lane IR)
 *
 * ── ONE SHAPE FOR A PATH ───────────────────────────────────────────────────
 *
 * Everything in this module speaks the web-root-relative path media.path
 * already uses — `uploads/products/x.webp`, `wp-content/uploads/2023/05/x.jpg`.
 * local() turns any stored spelling into it (absolute on this shop's host,
 * root-relative, with or without the base path, percent-encoded) and refuses
 * everything else: another host, a query string, "..", a root that is not one
 * of MediaRegistrar::ROOTS, a customer's or another module's folder
 * (WebpBulk::EXCLUDED), a type that is not a picture.
 *
 * ── WHAT "EVERY FILE DERIVED FROM IT" MEANS ────────────────────────────────
 *
 *   img-cache/<width>/<path>               ImageVariants' phone/banner copies
 *   img-cache/c<w>x<h>/<width>/<path>      ImageVariants' crops
 *   img-cache/share[-sq]/<path>.jpg        ShareImage's og:image
 *   <stem>.jpg beside <stem>.webp          the WebP original WebpBulk kept
 *
 * The img-cache directories are READ OFF THE DISK, the way
 * ImageVariants::forget() reads its crop roots, rather than listed: a width or
 * a frame shape added tomorrow is moved with the picture without anybody
 * remembering this class. A copy left behind under the old name is exactly the
 * failure this lane must not ship: the srcset is built from is_file(), so a
 * missing copy silently drops that width and the shop serves the full-size
 * original to every phone.
 */
final class ImageFiles
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

    /** img-cache children whose copies carry an extra ".jpg" (ShareImage). */
    private const JPG_SUFFIXED = ['share', 'share-sq'];

    /**
     * A stored image reference as a web-root-relative path, or null when it is
     * not a picture this shop holds and may rename.
     */
    public static function local(?string $stored): ?string
    {
        $stored = trim((string) $stored);

        if ($stored === '' || str_contains($stored, "\0") || str_contains($stored, '\\')
            || str_contains($stored, '?') || str_contains($stored, '#')) {
            return null;
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $stored) === 1) {
            $parts = parse_url(str_starts_with($stored, '//') ? 'https:'.$stored : $stored);

            if (! is_array($parts) || ! isset($parts['host'], $parts['path'])
                || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
                return null;
            }

            if (! in_array(strtolower($parts['host']), self::hosts(), true)) {
                return null;
            }

            $path = (string) $parts['path'];
        } else {
            $path = '/'.ltrim($stored, '/');
        }

        $base = Url::base();

        if ($base !== '' && str_starts_with($path, $base.'/')) {
            $path = substr($path, strlen($base));
        }

        $rel = MediaRegistrar::normalise(rawurldecode($path));

        if ($rel === null || str_contains($rel, "\0")) {
            return null;
        }

        foreach (WebpBulk::EXCLUDED as $excluded) {
            if (str_starts_with($rel, $excluded)) {
                return null;
            }
        }

        $extension = strtolower(pathinfo($rel, PATHINFO_EXTENSION));

        return in_array($extension, self::EXTENSIONS, true) ? $rel : null;
    }

    /** The absolute file for a relative path, only when it is a real file inside the web root. */
    public static function absolute(string $rel): ?string
    {
        $root = realpath(public_path());
        $file = realpath(public_path($rel));

        if ($root === false || $file === false || ! is_file($file)) {
            return null;
        }

        return str_starts_with($file, rtrim($root, '/').'/') ? $file : null;
    }

    public static function exists(string $rel): bool
    {
        return self::absolute($rel) !== null;
    }

    /**
     * The derived copies of $rel that are on disk now, each with the relative
     * path it will have once $rel becomes $newRel.
     *
     * @return list<array{from: string, to: string}>
     */
    public static function derived(string $rel, string $newRel): array
    {
        $out = [];
        $cache = public_path(ImageVariants::DIR);

        if (! is_dir($cache)) {
            return [];
        }

        $roots = [];

        foreach (glob($cache.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            $roots[] = [ImageVariants::DIR.'/'.$name, in_array($name, self::JPG_SUFFIXED, true) ? '.jpg' : ''];

            // c<w>x<h>/<width>: one level deeper. Never the mirrored upload
            // roots themselves.
            if (! in_array($name, self::JPG_SUFFIXED, true)) {
                foreach (glob($dir.'/*', GLOB_ONLYDIR) ?: [] as $sub) {
                    $subName = basename($sub);

                    if (! in_array($subName, ['uploads', 'wp-content'], true)) {
                        $roots[] = [ImageVariants::DIR.'/'.$name.'/'.$subName, ''];
                    }
                }
            }
        }

        foreach ($roots as [$root, $suffix]) {
            $from = $root.'/'.$rel.$suffix;

            if (is_file(public_path($from))) {
                $out[] = ['from' => $from, 'to' => $root.'/'.$newRel.$suffix];
            }
        }

        return $out;
    }

    /**
     * The WebP twin: the kept original beside a converted file, or the WebP
     * beside an original. Only one that is on disk.
     *
     * @return list<array{from: string, to: string, conversion: int}>
     */
    public static function siblings(string $rel, string $newRel): array
    {
        if (! self::hasTable('webp_conversions')) {
            return [];
        }

        $out = [];
        $newStem = substr($newRel, 0, (int) strrpos($newRel, '.'));

        $rows = DB::table('webp_conversions')
            ->where(fn ($q) => $q->where('to_path', $rel)->orWhere('from_path', $rel))
            ->get(['id', 'from_path', 'to_path']);

        foreach ($rows as $row) {
            $other = (string) $row->to_path === $rel ? (string) $row->from_path : (string) $row->to_path;

            if ($other === '' || $other === $rel || ! self::exists($other) || \dirname($other) !== \dirname($rel)) {
                continue;
            }

            $out[] = ['from' => $other, 'to' => $newStem.'.'.strtolower(pathinfo($other, PATHINFO_EXTENSION)), 'conversion' => (int) $row->id];
        }

        return $out;
    }

    /**
     * This shop's own hosts: EXACTLY WebpReferences' answer, and deliberately
     * not widened with the request's host. The rewrite only touches references
     * on these hosts, so a picture this called local and the rewrite could not
     * see would keep its old address after a rename.
     */
    public static function hosts(): array
    {
        return WebpReferences::ownHosts();
    }

    public static function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Where a picture Catalog → Image SEO renamed lives now, from its ledger.
 * (Lane IR)
 *
 * NOT AN ENTRY POINT OF ITS OWN. The 404 handler has one image redirect,
 * App\Support\LegacyImageRedirect (Lane SEO), and this ledger is one of the
 * move records it follows (its movedTo()), beside webp_conversions — so an old
 * WordPress URL, a pre-WebP URL and a pre-rename URL all reach the file that
 * exists now in ONE 301, and so do their img-cache copies.
 *
 *   ledgerTarget($rel)  exact old path → new path; one indexed query on
 *                       image_renames.old_path. What LegacyImageRedirect asks.
 *   resolvePath($rel)   the same for a path OR any img-cache copy of it, with
 *                       no disk check — ImageRenamer's in-transaction proof that
 *                       the old address will redirect before anything commits
 *                       (the old file is still on disk at that moment, so a
 *                       disk-following answer would be "no move" there).
 *
 * Chains are collapsed when the ledger is written (A→B then B→C leaves A→C),
 * so either answer is already the final name.
 */
final class ImageRenameRedirect
{
    private const EXTENSIONS = '(?:jpe?g|png|webp|gif|avif)';

    private const JPG_SUFFIXED = ['share', 'share-sq'];

    /** Exact old path → its new path, or null. */
    public static function ledgerTarget(string $rel): ?string
    {
        $rel = ltrim($rel, '/');

        if ($rel === '' || strlen($rel) > 600) {
            return null;
        }

        try {
            $to = DB::table('image_renames')->where('old_path', $rel)->where('status', 'done')->orderByDesc('id')->value('new_path');
        } catch (\Throwable) {
            return null;
        }

        return is_string($to) && $to !== '' ? $to : null;
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

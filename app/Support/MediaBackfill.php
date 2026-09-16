<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;

/**
 * Bring the media table up to date with what is actually on disk.
 *
 * WHY THIS IS NEEDED AT ALL. The upload endpoint has been writing images into
 * public/uploads/ for months and recording nothing: `media` has existed since
 * the original schema and, until this lane, NOTHING in the tree ever wrote a
 * row to it. So on a store that has been running, the file system is the only
 * record of what was uploaded, and a library that started from "rows created
 * from now on" would show an empty grid to an owner with hundreds of images.
 *
 * Run once by the backfill migration, and again on demand from the Rescan
 * button on the Media Library — which also covers the two cases a database
 * insert can be missed: an upload whose row failed to write (the endpoint
 * reports `recorded: false` rather than failing the upload, because the file is
 * already being served by then), and a file put into public/uploads by any
 * route other than the endpoint, such as FTP.
 *
 * IDEMPOTENT, by path. Running it twice adds nothing the second time, which is
 * what makes it safe to bolt onto a button and safe to re-run if a package is
 * applied twice — which, on a host where updates arrive as zips applied by
 * hand, happens.
 *
 * IT NEVER DELETES. A media row whose file has gone is left alone: this walks
 * the disk to find what the table is missing, and a file that is absent here
 * may simply be one the migration has not seen yet on a half-copied deploy.
 * Deleting rows on a scan would turn a slow FTP transfer into data loss.
 */
final class MediaBackfill
{
    /**
     * Extensions worth cataloguing, and the mime each is served as.
     *
     * The same set MediaUploadController accepts, keyed the other way round.
     * Anything else in the uploads tree — a stray .txt, a .zip somebody parked
     * there — is not an image and does not belong in an image library.
     */
    private const EXT_MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
    ];

    /** Belt and braces against a runaway walk of a web root somebody symlinked. */
    private const MAX_FILES = 20000;

    /**
     * Catalogue every image under public/uploads/ that has no row yet.
     *
     * @return int how many rows were added
     */
    public static function run(): int
    {
        $root = public_path('uploads');

        if (! is_dir($root)) {
            return 0;
        }

        $found = self::walk($root);

        if ($found === []) {
            return 0;
        }

        $added = 0;

        // Chunked, because `path` is only indexed, not unique, and asking the
        // database once per file for a tree of several thousand is the kind of
        // loop that turns a migration into a timeout on shared hosting.
        foreach (array_chunk($found, 500) as $chunk) {
            $paths = array_column($chunk, 'path');
            $known = Media::query()->whereIn('path', $paths)->pluck('path')->all();
            $known = array_flip($known);

            $rows = [];
            $now = now();

            foreach ($chunk as $file) {
                if (isset($known[$file['path']])) {
                    continue;
                }

                $rows[] = $file + ['created_at' => $now, 'updated_at' => $now];
            }

            if ($rows !== []) {
                Media::query()->insert($rows);
                $added += count($rows);
            }
        }

        return $added;
    }

    /**
     * Every catalogue-able image under $root, as insertable rows.
     *
     * @return list<array{filename: string, path: string, mime: string, size: int|null, width: int|null, height: int|null, alt: string}>
     */
    private static function walk(string $root): array
    {
        $out = [];

        $iterator = new \RecursiveIteratorIterator(
            // SKIP_DOTS so `.` and `..` never start a loop, and the callback
            // swallows an unreadable directory rather than aborting the walk:
            // on shared hosting one bad permission bit must not cost the whole
            // backfill.
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $entry) {
            if (count($out) >= self::MAX_FILES) {
                break;
            }

            if (! $entry instanceof \SplFileInfo || ! $entry->isFile()) {
                continue;
            }

            $ext = mb_strtolower($entry->getExtension());

            if (! isset(self::EXT_MIME[$ext])) {
                continue;
            }

            $full = $entry->getPathname();

            // Stored relative to the public root and with forward slashes, in
            // the same shape MediaUploadController writes — Media::urlFor()
            // recognises an `uploads/` prefix as "this app's own web root"
            // rather than an imported /wp-content/ path.
            $relative = 'uploads/'.str_replace(
                '\\',
                '/',
                ltrim(substr($full, strlen($root)), '/\\')
            );

            $size = @filesize($full);
            $dimensions = @getimagesize($full);

            $out[] = [
                'filename' => $entry->getFilename(),
                'path' => $relative,
                'mime' => self::EXT_MIME[$ext],
                'size' => is_int($size) && $size > 0 ? $size : null,
                'width' => is_array($dimensions) ? (int) $dimensions[0] : null,
                'height' => is_array($dimensions) ? (int) $dimensions[1] : null,
                'alt' => '',
            ];
        }

        return $out;
    }
}

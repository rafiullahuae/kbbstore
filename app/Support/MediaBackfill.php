<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Facades\Log;

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
 *
 * ── AND IT WALKS TWO TREES, WHICH IT DID NOT BEFORE THIS LANE ───────────────
 *
 * It walked `public_path('uploads')` and nothing else, and the owner's imported
 * catalogue is not in it. `Import\MediaSideloader` fetches every photograph off
 * the old WordPress host into `public_path('wp-content/uploads/…')`, because
 * that is where the paths in `products`.image already point (D-45 keeps shared
 * image URLs working) — inside this application's own web root, served by this
 * application. Nothing registered those on the way in and nothing walked the
 * tree they landed in, so THE MEDIA LIBRARY AFTER A WOOCOMMERCE IMPORT SHOWED
 * NOTHING THE IMPORT HAD BROUGHT, and pressing Rescan added zero rows however
 * many times it was pressed. Both roots are now walked; the list is
 * MediaRegistrar::ROOTS and it is shared with record().
 *
 * THE WALK IS NOT THE PRIMARY FIX FOR AN IMPORT, and it is worth saying which
 * job it does. MediaSideloader now calls MediaRegistrar::record() the moment a
 * fetch lands, so a file the importer brings is catalogued in the same request
 * that wrote it, with the mime its BYTES sniffed to — nobody has to press
 * anything. This walk is for the other route the importer itself recommends:
 * MediaRewrite's ABSENT verdict tells the owner in as many words to "Copy
 * wp-content/uploads across from the old host first", and a tree that arrives by
 * FTP or inside a zip is written by nothing in this application, so a walk is
 * the only thing that can ever find it. Measured on a synthetic WooCommerce tree
 * — 3,000 originals with WordPress's derived sizes beside them — 20,000 files
 * cost ~200 ms and ~10 MB of peak memory. It is a button and three migrations,
 * never a storefront request, so that is a cost nobody browsing the shop pays.
 *
 * A WORD ON WHAT THE SECOND TREE CONTAINS, because it is the owner's to judge
 * and not this class's: a WordPress uploads folder holds every DERIVED size
 * beside each original — `serum.jpg` and `serum-300x300.jpg` and five more — and
 * this walk catalogues all of them, because they are image files in the web root
 * and that is the only rule it has. Filtering them out would mean guessing from
 * the filename, and the guess is provably wrong: the import fixture's own
 * `ginseng-serum-3,-detail.jpg` and `ginseng-serum-300x300.jpg` show that a
 * real name can look exactly like a size. Grouping sizes under their original is
 * a `media`.sizes / `source_attachment_id` job and a change to the library
 * SCREEN, which is a different decision; see this lane's report.
 */
final class MediaBackfill
{
    /**
     * Extensions worth cataloguing, and the mime each is served as.
     *
     * READ OUT OF MediaRegistrar RATHER THAN KEPT HERE, and that is the whole
     * reason that class exists. This file used to hold its own copy of the list,
     * and the copy was images-only — while Services\UgcMedia had been writing
     * .mp4 and .webm into public/uploads/ugc/, which is UNDER THIS VERY WALK and
     * this walk is recursive, for the whole life of the shoppable-video module.
     * So a Rescan catalogued the POSTER of every clip and silently skipped the
     * clip itself. Two lists, one of them stale, in the shape this repo keeps
     * paying for.
     *
     * Anything not in that table — a stray .txt, a .zip somebody parked there —
     * is not media and does not belong in a media library.
     */
    private const EXT_MIME = MediaRegistrar::EXT_MIME;

    /**
     * Belt and braces against a runaway walk of a web root somebody symlinked.
     *
     * PER ROOT, NOT PER RUN, and that is a decision this lane had to make rather
     * than inherit. There are two roots now (self::ROOTS), and one shared budget
     * would let whichever tree is walked FIRST spend all of it — so an owner who
     * copies a five-year WordPress uploads folder across, thumbnails and all,
     * could find his own admin uploads missing from the library because
     * `wp-content/uploads/` got there first. Per root, each tree has exactly the
     * budget `uploads/` has always had, so nothing that works today can be
     * starved by the tree that was added.
     */
    private const MAX_FILES = 20000;

    /**
     * The roots walked, read out of MediaRegistrar rather than kept here.
     *
     * THE SAME REASON EXT_MIME IS READ FROM THERE, and the same bug twice over:
     * this class walked `public_path('uploads')` and that alone, while
     * `Import\MediaSideloader` had been writing the owner's entire WooCommerce
     * catalogue into `public_path('wp-content/uploads/…')` — inside this shop's
     * own web root, served by this shop — for the whole life of the importer. So
     * an import landed hundreds of photographs that the Media Library could not
     * show and RESCAN COULD NOT FIND, because the walk did not know the tree
     * existed. Two lists, one of them short, in the shape this repo keeps paying
     * for. There is now one list, in MediaRegistrar, and record() and this walk
     * both read it.
     */
    private const ROOTS = MediaRegistrar::ROOTS;

    /**
     * TRUNCATION USED TO BE SILENT, and that is the second defect in this file.
     *
     * A tree of 21,000 catalogue-able files produced 20,000 rows and not one
     * word anywhere — measured, on a synthetic WooCommerce tree — so the owner's
     * library was short by a thousand pictures with nothing to say so, and
     * pressing Rescan again added nothing because every file the walk could
     * still see already had a row. A cap that is silently exceeded is
     * indistinguishable from a finished job.
     *
     * NOW IT IS REPORTED TWICE OVER: logged at the moment it bites, because the
     * owner's host has a shell (CLAUDE.md corrects the old note that it does
     * not) and `storage/logs/laravel.log` is somewhere he can actually be told;
     * and returned from runReport() for a caller that wants to put it on the
     * Rescan response.
     *
     * AND IT IS CARRIED IN A LOCAL, NOT A STATIC. The obvious shape — a
     * `private static array $truncated` overwritten per run, read back by a
     * `truncated()` accessor — is the shape StaticMemoIsolationTest exists to
     * refuse, and it is right to: a static outlives the container, so it
     * outlives the test, and CLAUDE.md records Setting::map()'s memo as a
     * landmine for exactly that reason. The verdict belongs to ONE run, so it
     * travels out of that run by return value and nothing keeps a copy. There is
     * then no process state to reset and no ordering bug to have.
     */
    /**
     * Catalogue every image under each of self::ROOTS that has no row yet.
     *
     * @return int how many rows were added
     */
    public static function run(): int
    {
        return self::runReport()['added'];
    }

    /**
     * The same walk, with the cap's verdict beside the count.
     *
     * Separate from run() so the three backfill migrations and the Rescan button
     * keep the `int` they already call and no caller has to change to get the
     * fix. A screen that wants to tell the owner his library was truncated calls
     * this one.
     *
     * $max IS A SEAM, AND IT IS THIS REPOSITORY'S OWN IDIOM FOR ONE.
     * MediaSideloader takes an injected `$free` for the identical reason and
     * says why: "disk_free_space() cannot be made to return a chosen number, so
     * without this seam the disk guard is a branch no test can enter — and a
     * branch no input can enter is the dead `status` filter CLAUDE.md records."
     * The cap here is the same kind of branch. Reaching it honestly means
     * writing 20,001 files — about 84 MB — into the web root inside the suite,
     * which on this volume is not affordable and would prove nothing a cap of
     * three does not. So the number is a defaulted argument: production calls
     * pass nothing and get MAX_FILES, and the branch stops being dead code.
     *
     * @param  int|null  $max  files per root; null means self::MAX_FILES
     * @return array{added: int, truncated: list<string>}
     */
    public static function runReport(?int $max = null): array
    {
        $max = $max === null || $max < 1 ? self::MAX_FILES : $max;
        $added = 0;
        $truncated = [];

        foreach (self::ROOTS as $prefix) {
            $root = public_path(rtrim($prefix, '/'));

            if (! is_dir($root)) {
                continue;
            }

            $cut = false;
            $added += self::catalogue($root, $prefix, $max, $cut);

            if ($cut) {
                $truncated[] = $prefix;
            }
        }

        return ['added' => $added, 'truncated' => $truncated];
    }

    /**
     * One root: walk it, then insert what has no row yet.
     *
     * @return int how many rows were added
     */
    private static function catalogue(string $root, string $prefix, int $max, bool &$truncated): int
    {
        $found = self::walk($root, $prefix, $max, $truncated);

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

                /*
                 * Record what these files are used by.
                 *
                 * insert() above is a QUERY-BUILDER bulk insert, so no
                 * Eloquent `created` event fires and the Media hook in
                 * MediaUsageWriter never sees these rows. Left there, every
                 * file this catalogues would have a media row and no usage
                 * rows — which reads as "unused" on the Media Library and
                 * invites the operator to delete an image that is on the
                 * shop. That is the one direction of drift that is actually
                 * dangerous, so it is closed here rather than left to the
                 * reconcile command.
                 *
                 * Re-read rather than reused: insert() does not give the rows
                 * back their ids, and syncMedia needs them.
                 */
                MediaUsageWriter::syncMedia(
                    Media::query()
                        ->select(['id', 'filename', 'path'])
                        ->whereIn('path', array_column($rows, 'path'))
                        ->get()
                        ->all()
                );
            }
        }

        return $added;
    }

    /**
     * Every catalogue-able image under $root, as insertable rows.
     *
     * @return list<array{filename: string, path: string, mime: string, size: int|null, width: int|null, height: int|null, alt: string}>
     */
    private static function walk(string $root, string $prefix, int $max, bool &$truncated): array
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
            if (count($out) >= $max) {
                /*
                 * REPORTED, NOT JUST OBEYED. See the note on runReport(): this used to
                 * `break` in silence and leave a library short of the disk with
                 * nothing anywhere to say which tree was cut or by how much.
                 * Logged at warning because the owner's host has a shell —
                 * CLAUDE.md corrects the old note that it does not — so
                 * storage/logs/laravel.log is a place he can actually be told.
                 */
                $truncated = true;

                Log::warning(
                    'MediaBackfill stopped at the '.$max.'-file cap while walking '.$root
                    .'. The media library is short of what is on disk under '.$prefix.'; raise '
                    .'MediaBackfill::MAX_FILES or narrow the tree.'
                );

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

            /*
             * Stored relative to the public root and with forward slashes, in
             * the same shape MediaUploadController and MediaSideloader write.
             *
             * THE PREFIX IS THE ROOT THAT WAS WALKED, not a literal, and that is
             * the whole of the fix: Media::urlFor() reads an `uploads/` prefix
             * as "this app's own web root" and anything else as an imported
             * /wp-content/uploads/ path, serving each from the root it belongs
             * to. Hard-coding `uploads/` here — which is what this line did —
             * would file every imported photograph under a prefix that makes
             * urlFor() serve it from the wrong root, so the row would exist and
             * the tile would be a 404. A wrong row is worse than none.
             */
            $relative = $prefix.str_replace(
                '\\',
                '/',
                ltrim(substr($full, strlen($root)), '/\\')
            );

            $size = @filesize($full);

            /*
             * Not asked of a video, which getimagesize() cannot read: it would
             * open and parse the head of a file up to 64 MB to return false. The
             * box a tile reserves comes from the clip's POSTER, whose dimensions
             * the shoppable-video controller reads off the image header at upload
             * time, so there is no number here that anything reads.
             * MediaRegistrar::record() skips it for the same reason and by the
             * same test.
             */
            $dimensions = MediaRegistrar::isVideo(self::EXT_MIME[$ext])
                ? false
                : @getimagesize($full);

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

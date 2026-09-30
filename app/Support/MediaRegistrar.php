<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;

/**
 * THE ONE DOOR INTO THE MEDIA LIBRARY. Every file this shop writes into its own
 * web root becomes a row here, and it becomes one through this class.
 *
 * ── WHAT THE STATE WAS, MEASURED RATHER THAN ASSUMED ────────────────────────
 *
 * Exactly two places in the tree have ever written a `media` row:
 *
 *   Admin\MediaUploadController::record()   Media::create(), one row per upload
 *   Support\MediaBackfill::run()            Media::query()->insert(), in bulk
 *
 * (MediaUsageWriter writes `media_usages`, which is the index over `media`, not
 * `media` itself. It is a different table and it is not a third writer.)
 *
 * And exactly one place has been writing files into the web root and recording
 * NOTHING: Services\UgcMedia, which is where every shoppable-video clip, teaser
 * and poster lands. So the owner's rule — "whenever we upload any media, it
 * should go to Media also" — was true of images uploaded through the picker and
 * false of every video on the shop.
 *
 * The half-truth in the middle is worth stating because it is the reason the
 * backfill is smaller than it looks: MediaBackfill walks public/uploads/
 * RECURSIVELY, and uploads/ugc/ is under it, so UGC POSTERS (.jpg/.png/.webp)
 * have always been catalogued by a Rescan. Only the clips and teasers were
 * invisible, because MediaBackfill's extension table had no video row in it.
 *
 * ── WHY A REGISTRAR AND NOT A THIRD Media::create() ─────────────────────────
 *
 * Because the drift is the defect. Three call sites deciding independently what
 * mime to store, whether to read dimensions, what counts as catalogue-able and
 * whether a re-run duplicates a row is four chances to disagree, and the one
 * that disagrees is always the one nobody is looking at. So the mime table, the
 * path rule and the idempotency key live here, once, and MediaBackfill reads
 * EXT_MIME out of this class rather than keeping its own copy.
 *
 * MediaBackfill keeps its bulk `insert()` — a rescan of several thousand files
 * cannot afford a model per row on this host — so there are two WRITE
 * SHAPES for one set of RULES. That is the split, and it is deliberate.
 *
 * ── A FAILURE HERE NEVER FAILS A CALLER, AND LEAVES NOTHING BEHIND ──────────
 *
 * By the time record() runs the file is already written and already being
 * served. Failing the request now would tell the operator their upload failed
 * while the file sat there working, and they would upload it again. So this
 * returns null and reports, exactly as MediaUploadController::record() already
 * did, and its callers say so in their payload rather than leaving it silent.
 *
 * AND THE GUARD IS SHAPED SO IT CANNOT SEED THE FAILURE IT CONTAINS. CLAUDE.md
 * records what that costs: UpdateRunner wrapped `$release->update()` in a
 * try/catch, `fill()` put the attribute on the model BEFORE the save threw, the
 * catch swallowed the throw, and every later save() on that instance re-sent
 * the bad column until one escaped and bricked the updater. Nothing here can do
 * that: `Media::create()` builds a brand-new model inside the try, so a throw
 * leaves no dirty attribute anywhere a caller can save again, and no caller's
 * model is ever touched.
 */
final class MediaRegistrar
{
    /**
     * What may be catalogued, and the mime each extension is served as.
     *
     * The image half is MediaUploadController's own accept list, keyed the other
     * way round — MediaBackfill has read exactly this set since it was written,
     * and it now reads it from here.
     *
     * THE VIDEO HALF IS NEW, and it is UgcMedia's list and not a wider one. mp4
     * and webm, because those are the two containers the storefront's `<video>`
     * serves and the two UgcMedia will write; a .mov catalogued here would be a
     * library row for a file no shopper's browser can play. Anything else in the
     * uploads tree — a stray .txt, a .zip somebody parked there — is not media
     * and does not belong in a media library.
     */
    public const EXT_MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    /**
     * Catalogue one file this shop has just written, and say which row it is.
     *
     * IDEMPOTENT BY PATH, which is what makes it safe to call from an upload
     * handler, from an adopt, from a transcoder's output and from a migration
     * that may be applied twice on a host where updates are zips. An existing
     * row is RETURNED UNTOUCHED rather than refreshed: `alt` is the operator's
     * own text and `original_name` is the name they know the file by, and a
     * second call has no better information about either than the first had.
     *
     * @param  string  $storedPath  root-relative, with or without a leading slash
     * @param  string|null  $originalName  what the operator called it, for search only
     * @param  string|null  $mime  what the BYTES reported, when the caller has read them
     */
    public static function record(string $storedPath, ?string $originalName = null, ?string $mime = null): ?Media
    {
        try {
            $path = self::normalise($storedPath);

            if ($path === null) {
                return null;
            }

            $extension = self::extension($path);

            if ($extension === null) {
                return null;
            }

            $existing = Media::query()->where('path', $path)->first();

            if ($existing !== null) {
                return $existing;
            }

            $absolute = public_path($path);

            if (! is_file($absolute)) {
                // A row for a file that is not there is a broken thumbnail in
                // the grid forever, which is the very thing forget() exists to
                // prevent. Refused rather than recorded hopefully.
                return null;
            }

            /*
             * THE MIME COMES FROM THE EXTENSION, CROSS-CHECKED AGAINST THE
             * BYTES — never from the bytes alone.
             *
             * The extension on disk is not operator-supplied here: every writer
             * in this application generates the stored name and takes the
             * extension from a content read (MediaUploadController's TYPE_EXT,
             * UgcMedia's two-reader agreement). So it is already a byte-derived
             * answer, and it is the one the web server will use to pick the
             * Content-Type this file is actually served with. Storing a mime the
             * origin does not serve would make the library disagree with the
             * shop.
             *
             * A caller's $mime is honoured only when it maps to the SAME
             * extension, which makes it a corroboration rather than an override
             * — the image/jpeg vs image/pjpeg case, where the caller's finfo
             * answer is the more precise spelling of the same file.
             */
            $mime = self::agreed($extension, $mime);

            $size = @filesize($absolute);

            /*
             * getimagesize() reads the header only. It returns false for SVG,
             * which has no pixel dimensions, and false for a video, which it
             * cannot read at all — both keep null width and height rather than
             * a fabricated 0.
             *
             * NOT PROBED WITH ffprobe FOR A VIDEO, deliberately. The box a tile
             * reserves comes from the POSTER, whose dimensions the shoppable-video
             * controller already reads off the image header; shelling out per
             * catalogued clip would put a process launch inside a rescan loop for a
             * number nothing reads.
             */
            $dimensions = self::isVideo($mime) ? false : @getimagesize($absolute);

            return Media::create([
                'filename' => basename($path),
                /*
                 * basename() because a browser is free to send a path, and
                 * truncated to the column width so an absurd name cannot make a
                 * successful upload fail at the insert. Kept ONLY so the library
                 * can be searched by it — it never decides the stored filename,
                 * the extension or the served Content-Type.
                 */
                'original_name' => $originalName === null
                    ? null
                    : (mb_substr(basename(str_replace('\\', '/', $originalName)), 0, 255) ?: null),
                'path' => $path,
                'mime' => $mime,
                'size' => is_int($size) && $size > 0 ? $size : null,
                'width' => is_array($dimensions) ? (int) $dimensions[0] : null,
                'height' => is_array($dimensions) ? (int) $dimensions[1] : null,
                'alt' => '',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Take a file out of the library because the file itself has gone.
     *
     * ── THE DECISION, AND WHY IT IS THIS WAY ROUND ──────────────────────────
     *
     * UgcMedia::forget() unlinks the file. Left alone, its `media` row would
     * point at nothing and the Media Library would render `<img src>` at a 404
     * — a broken thumbnail the owner cannot clear from any screen, forever,
     * growing by one every time he replaces a clip. So THE ROW FOLLOWS THE
     * FILE: deleted with it, in the same call, by the class that deleted it.
     *
     * WHY THIS DOES NOT GO THROUGH THE LIBRARY'S OWN DELETE GUARD.
     * MediaLibraryApiController::destroy() refuses to delete a row while
     * something still points at the file, and it is right to: there the
     * operator is choosing to remove a picture and the cost of being wrong is a
     * broken image on a live product page. Here nothing is being chosen — the
     * FILE is going regardless, because the clip that owned it was deleted or
     * replaced. Keeping the row would not save the image; it would only hide
     * that it is gone. The honest coupling is the row following the file.
     *
     * WHY IT CANNOT REACH A FILE ANOTHER SCREEN SHARES. Only UgcMedia writes
     * under uploads/ugc/, and UgcMedia::adopt() COPIES a library picture into
     * that directory under a freshly generated name rather than referencing it
     * — so deleting the clip's own copy never touches the original row the
     * picker shows. Its caller passes a path UgcPath::stored() has already
     * bounded to one segment of that one directory.
     *
     * Per-model delete() and not a bulk one, so the Eloquent `deleted` event
     * fires and MediaUsageWriter::forgetMedia() clears `media_usages` with it.
     * A bulk delete fires no events and would leave index rows for a media id
     * that no longer exists — the first of the two gaps that class's header
     * names.
     *
     * @return int how many rows went
     */
    public static function forget(?string $storedPath): int
    {
        try {
            $path = self::normalise((string) $storedPath);

            if ($path === null) {
                return 0;
            }

            $gone = 0;

            foreach (Media::query()->where('path', $path)->get() as $row) {
                if ($row->delete()) {
                    $gone++;
                }
            }

            return $gone;
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Is this row a video?
     *
     * ASKED OF THE MIME, which is the column that carries the answer, and with
     * a prefix test rather than a list — a `media` row imported from WooCommerce
     * may carry a spelling neither EXT_MIME nor this application ever wrote, and
     * "starts with video/" is true of all of them. The Media Library screen uses
     * this to decide what a tile draws; getting it wrong in the generous
     * direction draws a film strip for something playable, which is harmless,
     * and in the mean direction draws `<img>` at a video, which is the broken
     * thumbnail being avoided.
     */
    public static function isVideo(?string $mime): bool
    {
        return str_starts_with(strtolower(trim((string) $mime)), 'video/');
    }

    /**
     * EVERY ROOT IN THIS APPLICATION'S WEB ROOT THAT HOLDS MEDIA, longest first.
     *
     * ── WHY THERE ARE TWO, AND WHY THE SECOND ONE WAS MISSING ───────────────
     *
     * `uploads/` is this application's own: MediaUploadController, UgcMedia,
     * InstagramSync and the review form all write under it.
     *
     * `wp-content/uploads/` is THE IMPORT'S, and it is under public_path() on
     * this server just as surely as the first one is —
     * `Import\MediaSideloader::targetPath()` cuts the old host's URL at
     * `UPLOAD_ROOTS = ['wp-content/uploads/', 'uploads/']` and
     * `MediaSideloader::absolute()` then writes the bytes to
     * `public_path($that)`. So after a fetch the owner's catalogue photographs
     * are files in this shop's own web root, served by this shop, and until this
     * lane NOT ONE OF THEM COULD BE CATALOGUED: this method refused the shape
     * outright, so `record()` returned null for every one of them, and
     * `MediaBackfill` never walked the tree they are in. The Media Library after
     * a WooCommerce import showed nothing the import had brought, and Rescan
     * could not change that.
     *
     * THE LIST AGREES WITH MediaSideloader::UPLOAD_ROOTS ON PURPOSE. It has to:
     * the sideloader decides where the bytes go and this decides what may be
     * catalogued, and a root in one and not the other is either a file nothing
     * can find or a row for a file nothing wrote.
     *
     * LONGEST FIRST, because `wp-content/uploads/` CONTAINS `uploads/` — the
     * same ordering note MediaSideloader::targetPath() carries. Here the roots
     * are matched as prefixes rather than searched for anywhere in the string,
     * so the order does not change which root matches; it is kept so that the
     * two lists read the same way round and neither invites the other's bug.
     *
     * Media::urlFor() tells the two apart by the `uploads/` prefix and serves
     * each from the root it belongs to, so both shapes round-trip to a working
     * URL — `Url::media()` already accepts a path that carries `wp-content/
     * uploads/` and does not prefix it twice.
     */
    public const ROOTS = ['wp-content/uploads/', 'uploads/'];

    /**
     * The stored path, in the one shape `media`.path and Media::urlFor() use:
     * root-relative, no leading slash, under one of self::ROOTS. Null for
     * anything else.
     *
     * AN ALLOWLIST OF SHAPES, NOT A DENYLIST OF TRICKS — UgcPath::stored()'s own
     * rule, applied one directory wider because this class serves every uploads
     * folder and not just ugc's. The path reaching here comes from a caller that
     * generated it, but a column is only ever as trustworthy as everything that
     * has ever written to it, and record() turns this into a public_path()
     * concatenation and forget() turns it into a DELETE.
     *
     * WIDENING THIS DID NOT WIDEN WHAT CAN BE REACHED, and that is the point of
     * doing it here rather than at the call sites. A second ALLOWED PREFIX is
     * added; every other rule is untouched and still applies to both roots. The
     * traversal refusal below runs on the segments of whichever root matched, so
     * `wp-content/uploads/../../etc/passwd` is refused on its `..` exactly as
     * `uploads/../../etc/passwd` always was, and nothing outside public_path()
     * became expressible.
     */
    public static function normalise(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return null;
        }

        // A scheme or a protocol-relative host is not a stored path at all.
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path) === 1) {
            return null;
        }

        $clean = ltrim($path, '/');

        /*
         * A PREFIX TEST, NOT A SEARCH. MediaSideloader::targetPath() uses
         * strpos() because it is cutting a root out of the middle of a remote
         * URL's path; here the caller has already produced a root-relative
         * path, so anything that is not AT the front is not a root, and
         * `etc/wp-content/uploads/x.jpg` is refused rather than silently cut
         * down to the part that looks safe.
         */
        $rooted = false;

        foreach (self::ROOTS as $root) {
            if (str_starts_with($clean, $root)) {
                $rooted = true;

                break;
            }
        }

        if (! $rooted) {
            return null;
        }

        // Traversal, refused on the segments rather than on the string, so no
        // spelling of `..` has to be anticipated.
        foreach (explode('/', $clean) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $clean;
    }

    /** The lower-cased extension, if it is one this library catalogues. */
    private static function extension(string $path): ?string
    {
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return isset(self::EXT_MIME[$extension]) ? $extension : null;
    }

    /**
     * The mime to store: the caller's, when it names the same extension, and the
     * extension's own otherwise.
     *
     * CORROBORATION, NOT OVERRIDE. The caller's mime is accepted only when the
     * table maps it back to the extension already on disk, so a finfo answer can
     * agree with a .jpg and cannot relabel a .png. `image/pjpeg` — which finfo
     * does say, and which MediaUploadController lists for that reason — is not a
     * value in this table, so it falls through to `image/jpeg`: the spelling the
     * web server serves and the spelling every existing row carries.
     */
    private static function agreed(string $extension, ?string $offered): string
    {
        $offered = strtolower(trim((string) $offered));
        $canonical = self::EXT_MIME[$extension];

        return $offered === $canonical ? $offered : $canonical;
    }
}

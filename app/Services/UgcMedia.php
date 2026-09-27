<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\MediaRegistrar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Where a shoppable-video upload is checked, and where it is written.
 *
 * AN UPLOADED FILE IS THE MOST DANGEROUS INPUT THIS SHOP TAKES, and a video is
 * worse than a photograph in one specific way: nothing in PHP validates it the
 * way `image` validates a bitmap, so the framework has no opinion to lean on.
 * This class is that opinion.
 *
 * ── THE PRECEDENT, AND WHERE THIS GOES FURTHER ──────────────────────────────
 *
 * Admin\MediaUploadController already learned this lesson the hard way and its
 * docblock records it: the stored extension used to come from
 * getClientOriginalExtension(), a string the browser copied off whatever the
 * operator called the file, and it decided both what the file was saved as and
 * whether the SVG safety scan ran at all. A hostile SVG uploaded as `photo.png`
 * was stored as `.png` and skipped the scan entirely. The fix was to read the
 * type from the BYTES, once, and let that one answer decide everything.
 *
 * This keeps all of that and adds a second, independent reading:
 *
 *   1. SIZE FIRST, before anything opens the file. A cap per kind, refused
 *      rather than truncated — §3.4 is explicit that the admin should refuse an
 *      upload over a size cap rather than silently serving 20 MB.
 *   2. finfo over the bytes on disk (UploadedFile::getMimeType()), never
 *      getClientMimeType(), which is another browser-supplied string.
 *   3. OUR OWN MAGIC-BYTE READ of the first 32 bytes — ISO-BMFF's `ftyp` box
 *      with its major brand, Matroska/WebM's EBML signature, and the three
 *      still-image signatures. finfo is a database of heuristics maintained
 *      outside this repo and configured outside this application; a second
 *      reading that this file owns means a shop whose `magic` database is odd,
 *      old or absent is not a shop with a different security posture.
 *   4. THE TWO MUST AGREE. Not "either is enough" — both, and on the same
 *      stored extension. A file only finfo likes and a file only we like are
 *      both refused, which is what makes a disguise fail rather than pick
 *      whichever reader is more generous.
 *   5. A LAST LOOK FOR A PHP OPEN TAG in the first 512 bytes. Belt and braces:
 *      a real ISO-BMFF or EBML header there is box sizes and four-character
 *      codes, so `<?php` cannot legitimately appear in it, and a polyglot that
 *      satisfied every check above still does not get written.
 *
 * A file that passes all five is stored under a GENERATED name with the
 * extension the BYTES earned. The client's filename never touches the
 * filesystem.
 *

 * ── WHAT WAS DELIBERATELY NOT DONE: AN .htaccess IN THE UPLOAD DIRECTORY ────
 *
 * The obvious extra lock is a `.htaccess` in public/uploads/ugc/ denying
 * execution. It is NOT written, and that is a decision rather than an omission.
 *
 * Every form of it — `Require all denied` in a FilesMatch, `Options -ExecCGI`,
 * `php_flag engine off` — is refused by Apache with a 500 for the WHOLE
 * directory when the server's AllowOverride does not permit that directive
 * class. Nobody in this repo can see the Cloudways vhost, so shipping one means
 * a coin-flip between a lock that adds nothing (the files already carry the
 * extension their own bytes earned) and a video library that 500s on the live
 * shop with nothing in the application log to explain it. docs/UGC-DATA-MODEL.md
 * records how to add it once somebody has read that vhost.
 *
 * ── WHERE IT IS WRITTEN, AND WHY NOT THE STORAGE DISK ───────────────────────
 *
 * public/uploads/ugc/, directly, NOT the `public` disk plus storage:link. That
 * symlink is created by `php artisan storage:link`, and this app deploys as a
 * zip applied through Store -> Core Updates. Nothing in that path runs
 * storage:link, so the symlink is a silent failure on the live server: the
 * upload succeeds and the file 404s forever with nothing in any log to explain
 * it. Store\ReviewController's own comment records exactly this and
 * Admin\MediaUploadController's repeats it; this is the third place, following
 * the two.
 */
final class UgcMedia
{
    public const KIND_CLIP = 'clip';

    public const KIND_TEASER = 'teaser';

    public const KIND_POSTER = 'poster';

    public const KINDS = [self::KIND_CLIP, self::KIND_TEASER, self::KIND_POSTER];

    /**
     * The ceiling per kind, in bytes, and each number has a reason.
     *
     * CLIP 64 MB. §3.4: phone video is frequently 1080x1920 at 8-15 Mbps, which
     * is ~20 MB for fifteen seconds, and this cap is what stops a two-minute
     * original being served to a phone as-is. It is a refusal, not a truncation
     * — a half-written mp4 is a tile that spins forever.
     *
     * TEASER 8 MB. The thing it holds is ~130 KB. Eight megabytes is sixty
     * times that and still refuses anything that is plainly the full clip
     * uploaded into the wrong box.
     *
     * POSTER 4 MB, matching MediaUploadController's own image cap within a
     * factor it does not need to beat.
     */
    public const MAX_BYTES = [
        self::KIND_CLIP => 64 * 1024 * 1024,
        self::KIND_TEASER => 8 * 1024 * 1024,
        self::KIND_POSTER => 4 * 1024 * 1024,
    ];

    /**
     * finfo's answer => the extension the file is STORED under.
     *
     * Keyed by what the bytes report, never by what the browser said. Both
     * jpeg spellings appear because finfo says image/jpeg and the conventional
     * extension is jpg, the same reason MediaUploadController lists both.
     *
     * NO SVG, deliberately, although the image uploader accepts one after a
     * scan. A poster frame is a photograph; there is no reason to accept XML
     * here, and the safest scan is the one that never has to run.
     *
     * NO QUICKTIME, NO AVI, NO OGG. The storefront serves `<video>` with one
     * source and §2 budgets a byte count per tile; accepting a container half
     * the browsers cannot play is how a rail becomes a rail of black boxes on
     * one phone and not another.
     */
    private const VIDEO_TYPE_EXT = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    private const IMAGE_TYPE_EXT = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** What to call the accepted formats when refusing one that is not. */
    public const ACCEPTED = [
        self::KIND_CLIP => 'MP4 or WebM',
        self::KIND_TEASER => 'MP4 or WebM',
        self::KIND_POSTER => 'JPG, PNG or WebP',
    ];

    /**
     * The one directory, root-relative and with no leading slash — the shape
     * `media`.path and Media::urlFor() already use.
     */
    public const DIR = 'uploads/ugc';

    /**
     * Check an upload and write it, or say in one sentence why not.
     *
     * @return array{ok: bool, message?: string, path?: string, bytes?: int, mime?: string}
     */
    public function store(UploadedFile $file, string $kind): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            // Not reachable from the controller, which validates `kind` against
            // the same list first. Kept because this is a public method and a
            // second caller must not be able to write outside the three shapes.
            return ['ok' => false, 'message' => 'Unknown upload kind.'];
        }

        if (! $file->isValid()) {
            return ['ok' => false, 'message' => 'That upload did not arrive completely — try again.'];
        }

        /*
         * The size is read BEFORE the move, and kept. move() renames the
         * temporary file out from under the UploadedFile, so getSize()
         * afterwards throws "stat failed" — a 500 on a successful upload, which
         * is the worst shape of bug: the file is written and the operator is
         * told it failed.
         */
        $size = (int) $file->getSize();

        $verdict = $this->check((string) $file->getRealPath(), $size, $kind);

        if (! $verdict['ok']) {
            return $verdict;
        }

        $placed = $this->place($kind, $verdict['ext']);

        if (! $placed['ok']) {
            return $placed;
        }

        if (! $file->move($placed['dir'], $placed['name']) || ! is_file($placed['dir'].'/'.$placed['name'])) {
            return ['ok' => false, 'message' => 'Upload failed — check the permissions on public/uploads.'];
        }

        $stored = '/'.self::DIR.'/'.$placed['name'];

        return [
            'ok' => true,
            'path' => $stored,
            'bytes' => $size,
            'mime' => $verdict['mime'],
            /*
             * AND IT JOINS THE MEDIA LIBRARY, which until this lane it did not.
             *
             * The owner's rule, in his words: "whenever we upload any media, it
             * should go to Media also." This class is the ONLY writer of files
             * under uploads/ugc/ and it wrote no `media` row at all, so every
             * clip, teaser and poster on the shop was invisible to the library
             * and to its usage index. One line here covers all three kinds and
             * both entry points, because both end at the same registrar.
             *
             * `media_id` is reported, not required: MediaRegistrar::record()
             * returns null rather than throwing, for the reason its header sets
             * out — the file is already written and already being served by the
             * time this runs, so a failed catalogue entry must not turn a good
             * upload into a refusal. The caller's own response says which way it
             * went, and a Rescan picks up anything missed.
             */
            'media_id' => MediaRegistrar::record($stored, $file->getClientOriginalName(), $verdict['mime'])?->id,
        ];
    }

    /**
     * Take a file the Media Library already holds and make it this video's own.
     *
     * WHY A COPY AND NOT A REFERENCE. Two reasons, and the second is the one
     * that matters. A poster is deleted with its video (a creator who withdrew
     * permission asked for the whole thing to stop being served), and deleting
     * a row out of the shared library because one clip was removed would take
     * the picture off every other page using it. And UgcPath::stored() is an
     * allowlist of ONE directory — widening it to "anywhere under /uploads/"
     * would mean a path column that can name any file in the web root, which is
     * the shape ReviewWall::photos() exists to refuse.
     *
     * THE SAME CHECKS RUN AGAIN, over the bytes on disk. The library's own
     * upload path checked this file when it arrived, but "it was checked once,
     * by somebody else, some time ago" is not a property this code can assert,
     * and the file is about to be given a new name and a new home by this class.
     *
     * @return array{ok: bool, message?: string, path?: string, bytes?: int, mime?: string}
     */
    public function adopt(string $webPath, string $kind): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            return ['ok' => false, 'message' => 'Unknown upload kind.'];
        }

        $source = UgcPath::library($webPath);

        if ($source === null) {
            return ['ok' => false, 'message' => 'That is not a file in this shop’s media library.'];
        }

        $absolute = public_path(ltrim($source, '/'));

        if (! is_file($absolute)) {
            return ['ok' => false, 'message' => 'That library file is not on the server any more.'];
        }

        $verdict = $this->check($absolute, (int) @filesize($absolute), $kind);

        if (! $verdict['ok']) {
            return $verdict;
        }

        $placed = $this->place($kind, $verdict['ext']);

        if (! $placed['ok']) {
            return $placed;
        }

        if (! @copy($absolute, $placed['dir'].'/'.$placed['name'])) {
            return ['ok' => false, 'message' => 'That file could not be copied into the video library.'];
        }

        $stored = '/'.self::DIR.'/'.$placed['name'];

        return [
            'ok' => true,
            'path' => $stored,
            'bytes' => (int) @filesize($placed['dir'].'/'.$placed['name']),
            'mime' => $verdict['mime'],
            /*
             * THE COPY IS ITS OWN LIBRARY ROW, and that follows from the decision
             * at the top of this method rather than contradicting it: the file
             * under uploads/ugc/ is a SEPARATE FILE with its own generated name,
             * deleted when the clip is, and the library's row for the original is
             * untouched by any of that. A row for the copy is what stops it being
             * the one file on this shop that the library cannot see; without it,
             * deleting the clip would remove a file nothing had ever catalogued.
             *
             * The source's basename rides along as `original_name` so the grid
             * reads "cosrx-cover.jpg" rather than "poster-20260927-...-x8.jpg" —
             * search only, exactly as everywhere else. It is the SOURCE's name
             * and not a request value: UgcPath::library() has already bounded the
             * path it came from.
             */
            'media_id' => MediaRegistrar::record($stored, basename($source), $verdict['mime'])?->id,
        ];
    }

    /**
     * Everything that decides whether these bytes may be written, in order.
     *
     * One method, called by both entry points, because two copies of this is
     * two copies that drift — and the half that drifts is always the one
     * nobody is looking at.
     *
     * @return array{ok: bool, message?: string, ext?: string, mime?: string}
     */
    private function check(string $path, int $size, string $kind): array
    {
        /*
         * SIZE BEFORE ANYTHING ELSE. Every check below opens and reads the
         * file; the cap is the one that decides whether it is worth opening at
         * all, and it is a refusal rather than a truncation because a truncated
         * video is a tile that never finishes loading.
         */
        $cap = self::MAX_BYTES[$kind];

        if ($size > $cap) {
            return [
                'ok' => false,
                'message' => 'That file is '.$this->mb($size).' and the limit for a '.$kind.' is '
                    .$this->mb($cap).'. '.($kind === self::KIND_CLIP
                        ? 'Re-encode it to 720x1280 at about 800 kbps and it will be a tenth of the size.'
                        : 'Save it smaller and try again.'),
            ];
        }

        if ($size <= 0) {
            return ['ok' => false, 'message' => 'That file is empty.'];
        }

        $head = $this->head($path);

        /*
         * READING ONE: finfo over the bytes on disk. Not getClientMimeType(),
         * which is a string the browser sends and an attacker sets.
         */
        $detected = $this->finfoType($path);

        if ($detected === null) {
            /*
             * ── THE READER ITSELF IS MISSING OR BROKEN ─────────────────────
             *
             * THE DEFECT, found by the lane that fixed the upload 500 and left
             * here because this file is not that lane's. `@` suppresses PHP
             * WARNINGS and not EXCEPTIONS, and in PHP 8 this line can throw two
             * different things: finfo::__construct raises an exception when the
             * magic database cannot be loaded, and on a host built without
             * ext-fileinfo it is `Error: Class "finfo" not found`. Either one
             * escaped store(), whose whole contract is to RETURN a refusal, and
             * became a 500 on every upload of every kind — image, video and
             * poster alike.
             *
             * AND IT IS REFUSED, NOT WAVED THROUGH. The temptation is to fall
             * back to signature() alone, which is right here in this class and
             * would keep uploads working. That is exactly the wrong repair:
             * "both readings must agree" is the property that makes a disguise
             * have to satisfy two independent readers, and a box with no finfo
             * would silently become a box with one reader and a different
             * security posture — the single point of failure the comment above
             * this block exists to refuse. A shop whose PHP cannot read a file
             * type is a shop that cannot safely accept files, and it says so.
             *
             * The sentence names the extension to install, because "this server
             * could not read the file" is a refusal nobody can act on.
             */
            return [
                'ok' => false,
                'message' => 'This server cannot read what type a file is — PHP’s fileinfo extension is '
                    .'missing or its type database could not be loaded, and nothing is accepted without '
                    .'it. Nothing was saved. Ask your host to enable ext-fileinfo.',
            ];
        }

        /* READING TWO: our own signature read, owned by this file. */
        $sniffed = $this->signature($head);

        $table = $kind === self::KIND_POSTER ? self::IMAGE_TYPE_EXT : self::VIDEO_TYPE_EXT;

        $byFinfo = $table[$detected] ?? null;
        $bySignature = $table[$sniffed] ?? null;

        /*
         * BOTH READINGS, AND THEY MUST AGREE.
         *
         * Either one alone is a single point of failure in a different
         * direction. finfo's database lives outside this repo and is configured
         * outside this application, so a box with an old or unusual `magic` set
         * is a box with a different answer; our own table is short by design and
         * would happily be fooled by a container it has never heard of if it
         * were the only reader. Requiring the SAME stored extension from both
         * means a disguise has to satisfy two independent readers at once, and
         * a file that satisfies neither cannot slip through on the strength of
         * whichever is more generous.
         */
        if ($byFinfo === null || $bySignature === null || $byFinfo !== $bySignature) {
            return [
                'ok' => false,
                'message' => 'That file is '.($detected !== '' ? $detected : 'of a type this server could not read')
                    .' and a '.$kind.' has to be '.self::ACCEPTED[$kind].'. '
                    .'The check reads the file itself, not its name — renaming it will not help.',
            ];
        }

        /*
         * AND ONE LAST LOOK.
         *
         * A valid ISO-BMFF header is box lengths and four-character codes, and
         * a valid EBML one is a binary element tree; neither can legitimately
         * contain a PHP open tag in its first 512 bytes. This costs nothing and
         * catches the polyglot that satisfied both readers above.
         *
         * It is defence in depth and not the defence: the file is stored with
         * the extension its bytes earned, under a generated name, in a
         * directory that serves static files. Executing it would take a second
         * defect. This is what makes that second defect not enough on its own.
         */
        if ($this->carriesPhpTag($head)) {
            return [
                'ok' => false,
                'message' => 'That file has program code in its header, which no video or image should have. It was not saved.',
            ];
        }

        return ['ok' => true, 'ext' => $byFinfo, 'mime' => $detected];
    }

    /**
     * What finfo says these bytes are, or NULL when finfo cannot answer at all.
     *
     * THE DISTINCTION IS THE POINT, and it is the reason this is a method and
     * not an expression. There are three outcomes and the old one-liner had two:
     *
     *   'video/mp4'  finfo read the file and recognised it.
     *   ''           finfo read the file and does not know what it is — which is
     *                a refusal on the merits, and check() composes it.
     *   null         FINFO ITSELF IS NOT AVAILABLE. Nothing was read, nothing is
     *                known, and the answer is not "unrecognised" — it is "this
     *                server cannot perform the check".
     *
     * Collapsing the third into the second would have been the tidier fix and it
     * would be wrong: an operator would be told their perfectly good mp4 "is of
     * a type this server could not read" and would re-encode it forever.
     *
     * Throwable and not Exception: `Class "finfo" not found` is an Error, and an
     * Error is not an Exception. Catching the narrower one is how this defect
     * survives the fix.
     */
    private function finfoType(string $path): ?string
    {
        try {
            $reader = new \finfo(FILEINFO_MIME_TYPE);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        // The read itself. `@` for the warning a permission or a stat failure
        // raises; the catch for the exception a corrupt file can raise from the
        // magic parser. Both end as '' — finfo answered, and does not know.
        try {
            return strtolower((string) (@$reader->file($path) ?: ''));
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }

    /**
     * The directory, and the name the file will have in it.
     *
     * A GENERATED NAME, never the client's. The uploaded filename is
     * visitor-controlled text and never touches a filesystem path — the rule
     * ReviewController states and MediaUploadController repeats. The date
     * prefix is for a human reading an FTP listing; the random tail is what
     * makes it unguessable and collision-free.
     *
     * @return array{ok: bool, message?: string, dir?: string, name?: string}
     */
    private function place(string $kind, string $ext): array
    {
        $dir = public_path(self::DIR);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return ['ok' => false, 'message' => 'Could not create the uploads/ugc directory.'];
        }

        return [
            'ok' => true,
            'dir' => $dir,
            'name' => $kind.'-'.date('Ymd-His').'-'.Str::random(10).'.'.$ext,
        ];
    }

    /**
     * Delete a file this class wrote, and refuse to delete anything else.
     *
     * The path comes out of the database, and a column is only ever as
     * trustworthy as everything that has ever written to it. So the shape is
     * re-checked here rather than assumed: root-relative, under /uploads/ugc/,
     * one path segment, no traversal. UgcPath::stored() is the single place
     * that decides what that means.
     *
     * ── AND THE LIBRARY ROW GOES WITH THE FILE ──────────────────────────────
     *
     * THE DECISION, because it is one and not a detail. From this lane every
     * file this class writes is also a `media` row, and this method unlinks the
     * file — so left alone, a replaced clip and a deleted video would each leave
     * a row pointing at nothing. The Media Library renders `<img>`/`<video>` at a
     * row's URL, so that row is a permanently broken tile with no screen anywhere
     * that can clear it, and it grows by one every time the owner replaces a
     * clip. The row therefore follows the file, in this call, by the class that
     * deleted it.
     *
     * NOT THROUGH THE LIBRARY'S OWN DELETE GUARD, deliberately.
     * MediaLibraryApiController::destroy() refuses while something still points
     * at a file, and it is right to — there the operator is CHOOSING to remove a
     * picture and the cost of being wrong is a broken image on a live product
     * page. Here nothing is being chosen: the file is going regardless, because
     * the clip that owned it was deleted or replaced. Keeping the row would not
     * save the file, only hide that it had gone.
     *
     * IT CANNOT REACH A SHARED FILE. UgcPath::stored() bounds the path to one
     * segment of uploads/ugc/, only this class writes there, and adopt() COPIES a
     * library picture in under a fresh name rather than referencing it — so the
     * original the picker shows is never the row that goes.
     *
     * ORDER MATTERS: the row goes FIRST. A file this method fails to unlink is a
     * few kilobytes nobody sees; a row whose file has gone is a broken tile, so
     * the half that must not be skipped is done before the half that can fail.
     * Neither can throw: MediaRegistrar::forget() reports and returns 0.
     *
     * The bool it returns is unchanged, and it still means what it said — whether
     * the FILE went — because that is what every caller reads it for.
     */
    public function forget(?string $storedPath): bool
    {
        $safe = UgcPath::stored($storedPath);

        if ($safe === null) {
            return false;
        }

        MediaRegistrar::forget($safe);

        $absolute = public_path(ltrim($safe, '/'));

        return is_file($absolute) && @unlink($absolute);
    }

    /** The first 512 bytes, or '' when the file cannot be read. */
    private function head(string $path): string
    {
        if ($path === '' || ! is_file($path)) {
            return '';
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $head = (string) fread($handle, 512);
        fclose($handle);

        return $head;
    }

    /**
     * What the first bytes say this file is, in the same vocabulary finfo uses,
     * or '' for anything not recognised.
     *
     * MP4 is ISO base media format: a box whose 4-byte big-endian length is
     * followed by the literal 'ftyp' at offset 4, then a four-character major
     * brand. The brand is checked rather than waved through, because 'ftyp'
     * alone also fronts containers a browser will not play.
     *
     * WebM is Matroska: the EBML signature 1A 45 DF A3. The doctype sits a
     * little further in and is not read here — finfo is the reader that
     * distinguishes video/webm from video/x-matroska, and this pass only has to
     * agree with it, not replace it.
     */
    private function signature(string $head): string
    {
        if (strlen($head) < 12) {
            return '';
        }

        if (substr($head, 0, 3) === "\xFF\xD8\xFF") {
            return 'image/jpeg';
        }

        if (substr($head, 0, 8) === "\x89PNG\r\n\x1A\n") {
            return 'image/png';
        }

        if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        if (substr($head, 0, 4) === "\x1A\x45\xDF\xA3") {
            return 'video/webm';
        }

        if (substr($head, 4, 4) === 'ftyp') {
            $brand = strtolower(substr($head, 8, 4));

            /*
             * The brands a browser will actually play, plus the two fragmented
             * ones a phone camera writes. 'qt  ' is deliberately absent: a .mov
             * is an ftyp file Safari plays and Chrome on Android frequently
             * does not, and a rail that works on the owner's phone and not on
             * his shoppers' is the worst kind of pass.
             */
            $brands = ['isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'mmp4', 'dash', 'msdh', 'm4v '];

            return in_array($brand, $brands, true) ? 'video/mp4' : '';
        }

        return '';
    }

    /**
     * Is there a PHP open tag in the header?
     *
     * `<?php` and `<?=` only. Not `<?`, which is a legal byte pair in a binary
     * stream and whose short-tag interpretation is off by default, and not
     * `<script`, which a media header can plausibly carry inside an XMP or ID3
     * block and which is inert in a file served as video/mp4 anyway. Narrow on
     * purpose: a check that refuses legitimate uploads gets switched off.
     */
    private function carriesPhpTag(string $head): bool
    {
        return stripos($head, '<?php') !== false || strpos($head, '<?=') !== false;
    }

    private function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.').' MB';
    }
}

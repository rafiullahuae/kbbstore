<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\UgcVideo;
use App\Services\UgcMedia;
use App\Services\UgcPath;
use App\Services\UgcTranscoder;
use App\Support\ServerUploadLimits;
use App\Support\TranslationInput;
use App\Support\UploadArrival;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Content → Shoppable video. The library, the upload, and the product tagging.
 *
 * docs/UGC-VIDEO-PLAN.md §6 names this screen and §7 sets its rules. Nothing
 * here is reachable without `auth:admin`, and every endpoint carries its own
 * capability — `ugc.view` to read, `ugc.manage` to write or upload.
 *
 * ── WHAT IT DOES NOT DO ─────────────────────────────────────────────────────
 *
 * It adds nothing to the storefront. There is no public route, no section, no
 * setting and no query on any page that exists today; UgcShipsOffTest walks the
 * shop with a published video in the table and asserts that every page is byte
 * for byte what it was with an empty one. The rail and the player are the next
 * round's, after the owner picks one of each (§8 questions 1 and 2).
 *
 * ── EVERY VALUE THAT CROSSES IS BOUNDED HERE ────────────────────────────────
 *
 * §7, applied field by field, because /api/* on this shop is unauthenticated
 * and what an operator types today is what an anonymous visitor may be served
 * tomorrow:
 *
 *   status, rights_status, source_platform, locale
 *       SELECTS. A select stores ONE OF ITS OWN OPTIONS OR THE DEFAULT — the
 *       SecurityModule::cast() rule — so a hand-rolled POST of anything else is
 *       stored as the default rather than reaching a match() somewhere later.
 *       Rule::in() here, and the vocabulary lives on the model.
 *
 *   source_url, creator_url
 *       Through UgcPath::link(), which decodes entities and strips control
 *       characters BEFORE reading the scheme, because `java&Tab;script:` and
 *       `&#106;avascript:` are the same string by the time a browser acts on
 *       them. Anything that is not http or https is stored as null, not
 *       rejected: an operator pasting a bad link should not lose the caption
 *       they typed beside it.
 *
 *   file_path, teaser_path, poster_path
 *       NEVER ACCEPTED FROM A REQUEST AT ALL. They are written only by
 *       UgcMedia::store() from a file this server has just checked and named.
 *       There is no field for them on update(), which is why a forged POST
 *       cannot point a <video src> at anything.
 *
 *   product ids
 *       Through Rule::exists on the products table. The pivot has a foreign key
 *       as well, so an id that vanishes between the check and the write is a
 *       constraint violation rather than a dangling row.
 *
 * ── THE PUBLISH GATE FAILS CLOSED ───────────────────────────────────────────
 *
 * UgcVideo::publishBlockers() decides, and this controller refuses a status of
 * 'publish' while it returns anything. The rights half of that is §3.3: this
 * shop re-hosts a creator's video, the creator owns the copyright in it, and
 * being tagged in it grants nothing.
 */
class UgcVideoController extends Controller
{
    public function __construct(
        private UgcMedia $media,
        private UgcTranscoder $transcoder,
        private \App\Services\UgcDerivedFiles $derivedFiles,
        private ServerUploadLimits $serverLimits,
        private UploadArrival $arrival,
    ) {}

    /**
     * What each kind is called in a sentence an operator reads.
     *
     * CONSTANTS, and they are constants because UploadArrival interpolates them
     * into an error message. Rule 5: anything printed is a constant, never a
     * setting.
     */
    private const NOUN = [
        UgcMedia::KIND_CLIP => 'video',
        UgcMedia::KIND_TEASER => 'teaser',
        UgcMedia::KIND_POSTER => 'cover image',
    ];

    /** The library, newest first within the owner's own order. */
    public function index(): JsonResponse
    {
        $rows = UgcVideo::query()
            /*
             * withCount, not a products eager-load. The list prints a number,
             * and loading every tagged product to count them is the N+1 that
             * StorefrontQueryBudgetTest exists to refuse. Two queries for the
             * whole screen, whatever the library grows to.
             */
            ->withCount('products')
            ->orderBy('position')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'ok' => true,
            'videos' => $rows->map(fn (UgcVideo $v) => $this->card($v))->all(),
            /*
             * WHICH OF THE TWO WORLDS THIS SERVER IS IN, said before the owner
             * uploads anything rather than after. §8 question 4 is unanswered,
             * so the screen asks the server instead of assuming.
             */
            'transcoder' => [
                'available' => $this->transcoder->available(),
                /*
                 * WHICH of the two ways it is unavailable, in the server's own
                 * sentence — or null when it can cut.
                 *
                 * THE DEFECT THIS ANSWERS. The screen had only the bool, so it
                 * wrote its own explanation and picked one: "It answered that it
                 * has no ffmpeg." On the box this whole episode was about, that
                 * is FALSE — ffmpeg is installed and PHP is not allowed to start
                 * it — and it sends the owner to install something he already
                 * has instead of to the PHP setting that is really in the way.
                 * blocker() keeps the two apart precisely because the remedies
                 * differ, and that distinction was being thrown away at the last
                 * step by a screen that had not been told.
                 *
                 * A CONSTANT SENTENCE, never a setting, so the screen may print
                 * it as prose; it is escaped there regardless.
                 */
                'blocker' => $this->transcoder->blocker(
                    $this->transcoder->canSpawn(),
                    $this->transcoder->binary()
                ),
                'teaser_seconds' => UgcTranscoder::TEASER_SECONDS,
                'teaser_size' => UgcTranscoder::TEASER_WIDTH.'x'.UgcTranscoder::TEASER_HEIGHT,
            ],
            /*
             * THE SIZE THIS SERVER WILL REALLY TAKE, NOT THE SIZE THE APP ALLOWS.
             *
             * These three keys used to be UgcMedia::MAX_BYTES divided by a
             * megabyte and nothing else, and the screen printed them as "MP4 or
             * WebM, up to 64 MB". On the live shop upload_max_filesize is 2M and
             * post_max_size is 8M, so 64 MB was a number this application could
             * not honour and every refusal above 2 MB blamed the operator's file.
             *
             * They are now min(app cap, upload_max_filesize, post_max_size less
             * the multipart overhead) — see App\Support\ServerUploadLimits, which
             * carries the measurements. The per-kind blocks beside them keep the
             * app's OWN number as `app_mb` and name which of the three is doing
             * the capping in `capped_by`, so the screen can say "the server is
             * the limit, not the shop" instead of quietly showing a smaller
             * number the owner cannot explain.
             *
             * `clip_mb` is still the number the screen prints, so nothing on it
             * had to learn a new key to stop lying.
             */
            'limits' => $this->limits(),
            /*
             * The blank translatable shape, for the Arabic boxes on the form
             * that creates a clip — a row that does not exist yet has no bag
             * and still has to draw a box per field. Same as the Brands editor.
             */
            'translatable' => (new UgcVideo)->translationsForEditor(),
            'vocabulary' => [
                'status' => UgcVideo::STATUSES,
                'rights' => UgcVideo::RIGHTS,
                'platform' => UgcVideo::PLATFORMS,
                'locale' => UgcVideo::LOCALES,
            ],
        ]);
    }

    /** One clip, with the products tagged on it. */
    public function show(string $id): JsonResponse
    {
        $video = $this->find($id);

        if ($video === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $video->load('products.brand');

        return response()->json([
            'ok' => true,
            'video' => $this->card($video) + [
                'caption' => (string) ($video->caption ?? ''),
                /*
                 * The Arabic prefill for KBBArabic's boxes, in the shape every
                 * other editor in this console reads. One query for the row's
                 * whole bag, handed down from the server so this screen never
                 * carries a second copy of UgcVideo::$translatable.
                 */
                'translations' => TranslationInput::editorMapFor([$video])[(int) $video->id] ?? null,
                'rights_evidence' => (string) ($video->rights_evidence ?? ''),
                'source_url' => $video->source_url,
                'creator_url' => $video->creator_url,
                'products' => $video->products->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => (string) $p->name,
                    'brand' => $p->brand?->name,
                    'position' => (int) $p->pivot->position,
                    'at_ms' => $p->pivot->at_ms === null ? null : (int) $p->pivot->at_ms,
                ])->all(),
            ],
            /*
             * THE REAL CEILING, ON THE ENDPOINT THAT OPENS ONE CLIP.
             *
             * index() has carried this since ServerUploadLimits was written, but
             * the shoppable-video SECTIONS screen never calls index() — it opens
             * a clip through this method and nothing else — so it had no number
             * to print and printed a hard-coded "Up to 64MB". On the live box
             * post_max_size is the binding limit and the truth is 9.9 MB, so that
             * line was wrong by a factor of six and a half, in the one place the
             * owner reads before choosing a file.
             *
             * Same shape and same method as index(): a screen that learns the
             * ceiling from either endpoint reads it the same way. Behind
             * auth:admin and the ugc capability — nothing here is on /api/*.
             */
            'limits' => $this->limits(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $video = new UgcVideo(['slug' => $this->slug($request->input('title'))]);

        return $this->write($request, $video, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $video = $this->find($id);

        if ($video === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        return $this->write($request, $video, 200);
    }

    public function destroy(string $id): JsonResponse
    {
        $video = $this->find($id);

        if ($video === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        /*
         * The files go with the row. Not because disk is scarce — §3.4 puts a
         * hundred clips at ~220 MB — but because an orphaned clip under
         * /uploads/ugc/ is a video still being served from this shop's own
         * domain after the owner deleted it, which is precisely the thing a
         * creator who withdrew permission asked to stop.
         *
         * UgcMedia::forget() re-checks the shape of each path before unlinking,
         * so a row edited by hand to name some other file cannot be used to
         * delete it.
         */
        foreach ([$video->file_path, $video->teaser_path, $video->poster_path] as $path) {
            $this->media->forget($path);
        }

        $video->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Upload one of the three files.
     *
     * `kind` decides which column it lands in and which cap and which format
     * list apply. UgcMedia does every check; this method's only job is to bound
     * `kind` to the three the service knows and to write the two columns.
     */
    public function upload(Request $request, string $id): JsonResponse
    {
        $video = $this->find($id);

        if ($video === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        /*
         * ── WHAT PHP DID TO THE REQUEST, BEFORE ANY RULE RUNS ───────────────
         *
         * THE DEFECT. The owner uploaded an 8.4 MB .mp4 here. The bar reached
         * 100% and the screen said "That file was not accepted. 8.4 MB —
         * nothing on the clip was changed." His file was fine: post_max_size on
         * that server is 8M, so PHP threw the entire body away and $_POST and
         * $_FILES both arrived empty. validate() then failed on a missing file
         * and this endpoint returned 422 with a message about the file.
         *
         * The `kind` this request WANTED is read before validation for the same
         * reason: on a discarded body there is no `kind` either, and the cap
         * quoted in the refusal has to be the cap of the box the operator
         * actually dropped his file into. It is bounded to the three kinds the
         * service knows before it is used, so a forged value picks the clip's
         * cap rather than indexing MAX_BYTES with anything it likes.
         */
        $wanted = $request->input('kind');
        // is_string BEFORE the cast: `kind[]=clip` makes input() an array, and
        // (string) on an array is a PHP error rather than a 422 — a 500 on an
        // endpoint whose whole job this round is answering honestly.
        $wanted = is_string($wanted) && in_array($wanted, UgcMedia::KINDS, true)
            ? $wanted
            : UgcMedia::KIND_CLIP;

        $arrived = $this->arrival->check(
            $request,
            'file',
            UgcMedia::MAX_BYTES[$wanted],
            self::NOUN[$wanted],
        );

        if ($arrived !== null) {
            /*
             * The same `error` key every other refusal on this endpoint uses, so
             * the screen prints the server's own sentence rather than falling
             * back to its generic one. `limits` rides along because the panel
             * that shows this refusal is also the panel that has to stop
             * advertising a size this server will not take.
             */
            return response()->json([
                'ok' => false,
                'error' => $arrived['error'],
                'reason' => $arrived['reason'],
                'limits' => $this->limits(),
            ], $arrived['status']);
        }

        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(UgcMedia::KINDS)],
            'file' => ['required', 'file'],
        ]);

        $kind = (string) $validated['kind'];

        /*
         * ── THE SECOND DEFECT, AND THE WORSE ONE ────────────────────────────
         *
         * 27 September 2026. The owner uploaded an 8.4 MB .mp4 — under this
         * server's 9.9 MB ceiling, past the pre-flight, delivered whole — and got
         *
         *     Server Error    8.4 MB — nothing on the clip was changed.
         *
         * Every word after the comma was false. The file WAS written, `file_path`
         * WAS saved, and a refresh showed the clip as a draft with the video on
         * it and no cover. What threw was UgcTranscoder::derive(), out of a
         * `new Process` that sat outside its own guard on a host where proc_open
         * is switched off — see that class's docblock for the measurement. It
         * threw AFTER `$video->save()`, so Laravel answered 500 over a row that
         * had already been committed, the kit read Laravel's own
         * `{"message":"Server Error"}` into the red panel, and the owner spent
         * another half-minute of a ~300 KB/s uplink re-uploading a file that was
         * already in.
         *
         * THE COMMENT ON THE CLIP BRANCH BELOW SAID IT "never fails the upload".
         * It was an intention, not a property — the exact shape CLAUDE.md already
         * records for UpdateRunner::recordManifest(), whose docblock claimed a
         * guarded write could not fail an update and cost three hours. Two rules
         * come out of that entry and both are applied here:
         *
         *   1. A step that may not fail the operation is wrapped, not annotated.
         *      derive() has its own catch now, and a cut that dies leaves the
         *      upload at 200 with a note — the "uploaded, no cover" state this
         *      screen already draws and the owner can already act on.
         *   2. A failure message may not claim nothing changed unless nothing
         *      changed. Everything below the file's arrival runs inside one try
         *      whose answer is composed from `$stage` and from whether the row
         *      was committed, so the sentence is true about the disk and the row
         *      in every branch.
         *
         * And it is written down where he can read it: one Log::error with the
         * clip id, the kind, the byte count and the stage, findable in
         * Safety → Debug & Monitor → "Open the error log →" without SSH.
         */
        $stage = self::STAGE_STORE;

        /*
         * The size, read BEFORE UgcMedia::store() moves the file out from under
         * the handle — getSize() after a move throws "stat failed", which that
         * service's own docblock records as the worst shape of bug. Typed rather
         * than `?->getSize()`: `file[]=a&file[]=b` makes file() an ARRAY, and a
         * method call on one is an Error, not a 422. The `file` rule above
         * already refuses that, so this is the second lock on a door that is
         * shut — which is how UploadLimitsTest's `kind` case got its 500.
         */
        $sent = $request->file('file');
        $bytes = $sent instanceof \Illuminate\Http\UploadedFile ? (int) $sent->getSize() : 0;

        /*
         * ── THE ORPHANS ─────────────────────────────────────────────────────
         *
         * Every file this request writes and has not yet put on the row. A throw
         * between the write and the save leaves bytes in public/uploads/ugc that
         * NO column names, so nothing will ever delete them — not the clip's own
         * deletion, which walks the three columns, and not the replace path, which
         * only forgets the value it is overwriting. The owner re-sent this file
         * more than once, so on his server there is one such file per attempt.
         *
         * Held as a list and emptied the moment the row commits, so the catch can
         * never delete something the row now points at.
         */
        $uncommitted = [];
        $committed = false;

        try {
            $result = $this->media->store($request->file('file'), $kind);

            if (! ($result['ok'] ?? false)) {
                return response()->json(['ok' => false, 'error' => $result['message']], 422);
            }

            $uncommitted[] = (string) $result['path'];
            $stage = self::STAGE_RECORD;

            return $this->writeUpload(
                $video, $kind, $result, $bytes, $stage, $uncommitted, $committed,
            );
        } catch (\Throwable $e) {
            foreach ($uncommitted as $orphan) {
                $this->media->forget($orphan);
            }

            return $this->uploadFailed($e, $video, $kind, $stage, $bytes, $committed);
        }
    }

    /**
     * The writing half of upload(), so its try block has one exit and one catch.
     *
     * `$stage`, `$uncommitted` and `$committed` are by reference because the
     * catch in upload() composes its sentence out of all three, and a value copy
     * would leave it describing the state the request STARTED in — which is the
     * bug being fixed, one level up.
     *
     * @param  array{ok: bool, path?: string, bytes?: int, mime?: string}  $result
     * @param  list<string>  $uncommitted
     */
    private function writeUpload(
        UgcVideo $video,
        string $kind,
        array $result,
        int $bytes,
        string &$stage,
        array &$uncommitted,
        bool &$committed,
    ): JsonResponse {
        $column = [
            UgcMedia::KIND_CLIP => 'file_path',
            UgcMedia::KIND_TEASER => 'teaser_path',
            UgcMedia::KIND_POSTER => 'poster_path',
        ][$kind];

        $bytesColumn = [
            UgcMedia::KIND_CLIP => 'bytes',
            UgcMedia::KIND_TEASER => 'teaser_bytes',
            UgcMedia::KIND_POSTER => 'poster_bytes',
        ][$kind];

        // The one it replaces, removed AFTER the new one is safely on disk.
        $previous = $video->{$column};

        $video->{$column} = $result['path'];
        $video->{$bytesColumn} = $result['bytes'];

        /*
         * THE BOX, FROM THE POSTER'S OWN HEADER.
         *
         * getimagesize() reads the header only, and it is the reason this
         * feature can hold §2's zero-layout-shift budget on a server with no
         * ffprobe: the tile reserves its space from `width`/`height`, and those
         * two are now known from an image this server just received.
         */
        if ($kind === UgcMedia::KIND_POSTER) {
            $size = @getimagesize(public_path(ltrim($result['path'], '/')));

            if (is_array($size)) {
                $video->width = (int) $size[0];
                $video->height = (int) $size[1];
            }
        }

        $notes = [];

        /*
         * A CLIP ARRIVING IS THE ONE MOMENT A DERIVATIVE IS CHEAP. No queue, no
         * cron — MediaUploadController makes its phone-sized copies on the
         * upload request for exactly this reason, and says so.
         *
         * IT NEVER FAILS THE UPLOAD, AND NOW THAT IS A PROPERTY RATHER THAN A
         * SENTENCE. The clip is written and already being served by the save on
         * the line below, and a video with a cover and no teaser — or with
         * neither — is a supported state this screen already draws as
         * "No cover yet". So a cut that dies is a NOTE on a 200, never a 500 over
         * a row that was saved a microsecond earlier.
         */
        if ($kind === UgcMedia::KIND_CLIP) {
            $video->save();

            // From here on the clip IS on this row, and nothing this method
            // answers may say otherwise — nor may the catch upstairs delete it.
            $committed = true;
            $uncommitted = [];
            $stage = self::STAGE_DERIVE;

            try {
                $derived = $this->transcoder->derive($video);
            } catch (\Throwable $e) {
                /*
                 * BELT AND BRACES. derive() is written not to throw and its own
                 * two runners now catch \Throwable, so reaching here means
                 * something in that class changed or something under it did. The
                 * upload still succeeded; this is a note and a log line, not a
                 * failure, and the log line is how anybody finds out it happened.
                 */
                $this->logUploadFault($e, $video, $kind, self::STAGE_DERIVE, $bytes, true);

                $derived = null;
                $notes[] = self::NOTE_CUT_FAILED;
            }

            if ($derived !== null) {
                /*
                 * The six columns go through App\Services\UgcDerivedFiles,
                 * which is the only writer of them — see its header for what a
                 * second copy already cost here. The callback is how this path
                 * keeps its orphan tracking: it has to learn about each file AS
                 * it lands, because the throw it is guarding against can happen
                 * between the write and the save.
                 */
                $this->derivedFiles->apply($video, $derived, function (string $path) use (&$uncommitted): void {
                    $uncommitted[] = $path;
                });

                $notes = array_merge($notes, $derived['notes']);
            }
        }

        $stage = self::STAGE_COMMIT;

        $video->save();

        /*
         * COMMITTED, FOR EVERY KIND. Nothing written this request is an orphan
         * any more, so the catch upstairs must not delete any of it — and a
         * throw from here on (forget(), fresh(), card()) is a throw over a row
         * that DOES carry the new file. This used to stay false for a poster or
         * a teaser, which would have told the owner nothing had changed about a
         * cover that had just been saved: the same false sentence one kind over.
         */
        $uncommitted = [];
        $committed = true;

        if ($previous !== null && $previous !== $video->{$column}) {
            $this->media->forget($previous);
        }

        return response()->json([
            'ok' => true,
            'video' => $this->card($video->fresh()),
            'notes' => $notes,
        ]);
    }

    /**
     * ── STAGES, AND WHY THE FAILURE NAMES ONE ───────────────────────────────
     *
     * The owner's 500 was indistinguishable from a full disk, a dead database and
     * a permissions fault, and every one of those has a different remedy. The
     * stage is in the log line and in the answer, so "it broke" becomes "it broke
     * cutting the cover, and your video is in".
     */
    private const STAGE_STORE = 'store';

    private const STAGE_RECORD = 'record';

    private const STAGE_DERIVE = 'derive';

    private const STAGE_COMMIT = 'commit';

    /**
     * What a failed cut is called on a successful upload.
     *
     * A CONSTANT. Rule 5: what gets printed is never a setting. It says the two
     * things the owner needs — that the clip is in, and that re-uploading it is
     * not the remedy — because the alternative is what happened: he re-sent 8.4 MB
     * over a ~300 KB/s line for a file that was already stored.
     */
    private const NOTE_CUT_FAILED = 'The video is uploaded and is being served. This server could not cut '
        .'the cover or the teaser from it — upload a cover image instead. Re-uploading the video will not '
        .'change this; the reason is in Safety → Debug & Monitor → Open the error log.';

    /**
     * The honest answer to a throw on the upload path.
     *
     * ── THE ONE RULE IT EXISTS TO KEEP ──────────────────────────────────────
     *
     * A sentence about a failure may not claim nothing was changed unless nothing
     * was changed. So `stored` is read off the flag the writer sets the instant it
     * commits the clip, and the sentence branches on it. The row is READ and never
     * written here: CLAUDE.md's UpdateRunner entry is what happens when a failure
     * path writes — `fill()` dirties the model before `save()` throws, and every
     * later save re-sends it. This method cannot dirty anything and cannot throw.
     */
    private function uploadFailed(
        \Throwable $e,
        UgcVideo $video,
        string $kind,
        string $stage,
        int $bytes,
        bool $committed,
    ): JsonResponse {
        $this->logUploadFault($e, $video, $kind, $stage, $bytes, $committed);

        $noun = self::NOUN[$kind] ?? self::NOUN[UgcMedia::KIND_CLIP];

        $what = $committed
            // TRUE, and it is the whole point of this branch. The bytes are on
            // disk and the row points at them; telling him otherwise is what sent
            // him back to the upload box.
            ? 'The '.$noun.' itself WAS saved onto this clip and is being served — reload this screen and '
                .'you will see it. What failed came after that, '
            : 'Nothing on the clip was changed and the file is still fine, ';

        return response()->json([
            'ok' => false,
            // `error` first, because the upload kit prefers it and prints it
            // verbatim; `message` is what Laravel would have put "Server Error" in.
            'error' => $what.'while '.self::STAGE_WORDS[$stage].'. This is a fault on the server rather '
                .'than a problem with the file, so a smaller or re-encoded file will not help. The reason '
                .'is written down in full at Safety → Debug & Monitor → Open the error log.',
            'stage' => $stage,
            'stored' => $committed,
            'video' => $this->safeCard($video),
            'limits' => $this->limits(),
        ], 500);
    }

    /** Each stage in the middle of a sentence. Constants, printed verbatim. */
    private const STAGE_WORDS = [
        self::STAGE_STORE => 'writing the file to this server’s disk',
        self::STAGE_RECORD => 'recording it against the clip',
        self::STAGE_DERIVE => 'cutting the cover and the teaser out of the video',
        self::STAGE_COMMIT => 'saving the clip',
    ];

    /**
     * The row as it now really is, or null if even reading it fails.
     *
     * `fresh()` is a query, and the throw being answered may BE the database. A
     * failure handler that can itself throw is the second defect in CLAUDE.md's
     * updater entry — the one that made the first unrecoverable.
     *
     * @return array<string, mixed>|null
     */
    private function safeCard(UgcVideo $video): ?array
    {
        try {
            $fresh = $video->fresh();

            return $fresh === null ? null : $this->card($fresh);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * One line in storage/logs/laravel.log, and the reason it is a Log call and
     * not a `report()`.
     *
     * ── HOW THE OWNER READS THIS WITHOUT A SHELL ────────────────────────────
     *
     * Safety → Debug & Monitor → "Open the error log →" serves the tail of
     * storage/logs/laravel.log (routes/web.php, the `kbb.health.log` route). The
     * default log stack is `single`, which is that file. So an ordinary
     * Log::error IS the console-visible record, with no new screen, no new route
     * and no edit to a view another lane owns. LOG_MARKER is there so he can find
     * it in 200 lines of tail by eye.
     *
     * ── WHAT IS IN IT, AND WHAT IS DELIBERATELY NOT ─────────────────────────
     *
     * The clip id, the kind, the byte count, the stage, whether the row was
     * committed, and what this box answers about ffmpeg — which is the pair of
     * facts that told this defect apart from a timeout. NOT the uploaded
     * filename: that is visitor-controlled text and a log line is read by eye and
     * pasted into chat, so it stays out. Nothing here is a credential.
     */
    public const LOG_MARKER = '[ugc-upload]';

    private function logUploadFault(
        \Throwable $e,
        UgcVideo $video,
        string $kind,
        string $stage,
        int $bytes,
        bool $committed,
    ): void {
        try {
            Log::error(self::LOG_MARKER.' '.$stage.' stage threw on a '.$kind.' upload', [
                'clip_id' => $video->id,
                'kind' => $kind,
                'bytes' => $bytes,
                'stage' => $stage,
                'clip_stored' => $committed,
                // The two facts that separate "no encoder" from "an encoder it
                // cannot start" from "it ran and failed".
                'ffmpeg' => $this->transcoder->binary(),
                'can_spawn' => $this->transcoder->canSpawn(),
                'transcoder_available' => $this->transcoder->available(),
                'max_execution_time' => (int) ini_get('max_execution_time'),
                'exception' => $e::class.': '.$e->getMessage(),
                'where' => $e->getFile().':'.$e->getLine(),
            ]);
        } catch (\Throwable) {
            /*
             * A LOG THAT CANNOT WRITE MUST NOT BE THE FAILURE. An unwritable
             * storage/logs is one of the faults this handler exists to report, and
             * a handler that throws while reporting it hands the owner the same
             * bare "Server Error" this whole round is about.
             */
        }
    }

    /**
     * Take a poster out of the Media Library.
     *
     * THE OWNER'S OWN RULE, twice in the same words: "on any upload media on
     * the whole backend, the media library is a must to show." A raw browser
     * file picker cannot satisfy it — it can only ever send a file from this
     * computer, so a picture already in the library has to be found,
     * downloaded and uploaded a second time, which is the duplicate-uploading
     * the rule exists to stop. AdminMediaPickerEverywhereTest enforces it, and
     * caught this screen's first draft, which had a bare file input.
     *
     * So the poster has NO raw file input at all: the library popup is the only
     * way in, and its own Upload new is how a picture that is not there yet
     * gets there. The clip and the teaser keep their file inputs, and are
     * excluded from that rule by what they accept — the library is an image
     * library, and putting a 64 MB video through it would be worse than the
     * problem being fixed.
     *
     * The file is COPIED rather than referenced. UgcMedia::adopt() carries the
     * argument and re-runs every content check over the bytes on disk.
     */
    public function poster(Request $request, string $id): JsonResponse
    {
        $video = $this->find($id);

        if ($video === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $request->validate(['url' => ['required', 'string', 'max:1024']]);

        $result = $this->media->adopt((string) $request->input('url'), UgcMedia::KIND_POSTER);

        if (! ($result['ok'] ?? false)) {
            return response()->json(['ok' => false, 'error' => $result['message']], 422);
        }

        $previous = $video->poster_path;

        $video->poster_path = $result['path'];
        $video->poster_bytes = $result['bytes'];

        $size = @getimagesize(public_path(ltrim($result['path'], '/')));

        if (is_array($size)) {
            $video->width = (int) $size[0];
            $video->height = (int) $size[1];
        }

        $video->save();

        if ($previous !== null && $previous !== $video->poster_path) {
            $this->media->forget($previous);
        }

        return response()->json(['ok' => true, 'video' => $this->card($video->fresh())]);
    }

    /**
     * Cut the poster and the teaser again, on demand.
     *
     * The button for the owner who uploaded a clip before ffmpeg existed on the
     * box, or who replaced the clip and wants the derivatives to match it.
     * Answers honestly — and in the same shape — when there is no transcoder.
     */
    public function derive(string $id): JsonResponse
    {
        $video = $this->find($id);

        if ($video === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        /*
         * SAME GUARD AS THE UPLOAD PATH, AND FOR THE SAME REASON. This endpoint
         * calls the same derive() the upload does, so before this round it was the
         * SECOND way to get a bare "Server Error" out of a host that cannot start
         * a program — one button press, on a clip that was already fine. The
         * honest answer is the one it already gives for a box with no ffmpeg: ok,
         * nothing cut, and a note saying why.
         */
        try {
            $derived = $this->transcoder->derive($video, remakePoster: true, remakeTeaser: true);
        } catch (\Throwable $e) {
            $this->logUploadFault($e, $video, UgcMedia::KIND_CLIP, self::STAGE_DERIVE, 0, true);

            /*
             * A REPORT SAYING NOTHING WAS CUT, and then the ordinary exit below.
             * NOT a second response of its own: UgcEditorColumnsTest COUNTS the
             * line that puts the transcoder's answer in the payload and requires
             * exactly two of them — index()'s and this endpoint's — because a
             * rename of only one slipped past a str_contains once. A third copy
             * here would break that pin without breaking anything it guards, so
             * this path falls through to the one exit instead. (Which is also why
             * this comment describes that line rather than quoting it: a quoted
             * claim is counted like the claim.)
             */
            $derived = ['poster' => null, 'teaser' => null, 'width' => null, 'height' => null,
                'duration_ms' => null, 'notes' => [self::NOTE_CUT_FAILED]];
        }

        // One writer for the six columns — App\Services\UgcDerivedFiles. No
        // orphan callback here: this endpoint writes nothing before the save
        // that a throw could strand.
        $this->derivedFiles->apply($video, $derived);

        $video->save();

        return response()->json([
            'ok' => true,
            'available' => $this->transcoder->available(),
            'video' => $this->card($video->fresh()),
            'notes' => $derived['notes'],
        ]);
    }

    /**
     * Replace the whole product list for one clip — REQUIREMENT ONE.
     *
     * Sent whole rather than one add at a time, because the order is part of
     * the data: `position` is what the player's rail follows and what decides
     * which product a rail tile's card shows, so a drag is a re-ordering of the
     * list and not an edit to one row.
     */
    public function tag(Request $request, string $id): JsonResponse
    {
        $video = $this->find($id);

        if ($video === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $validated = $request->validate([
            'products' => ['present', 'array', 'max:24'],
            'products.*.id' => ['required', 'integer', Rule::exists('products', 'id')],
            /*
             * at_ms is player D's and nobody else's, so it is nullable
             * everywhere and bounded to a sane clip length rather than to
             * PHP_INT_MAX. Ten minutes is far longer than any UGC clip and
             * short enough that a typo is refused rather than stored.
             */
            'products.*.at_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
        ]);

        $sync = [];
        $position = 0;

        foreach ($validated['products'] as $row) {
            $productId = (int) $row['id'];

            /*
             * LAST ONE WINS ON A DUPLICATE, silently, rather than 422.
             *
             * The pivot's unique index would refuse the second row with a
             * constraint violation — a 500 the operator cannot act on — and the
             * thing they actually did was click Add twice on a laggy phone.
             * Keyed by product id here, so the list that arrives is de-duped
             * before it reaches the database and the position is the one they
             * last dragged it to.
             */
            $sync[$productId] = [
                'position' => $position++,
                'at_ms' => $row['at_ms'] ?? null,
            ];
        }

        $video->products()->sync($sync);

        return $this->show((string) $video->id);
    }

    /**
     * Products to tag, for the picker.
     *
     * ITS OWN ENDPOINT AND ITS OWN FOUR KEYS, not a reuse of the catalogue
     * list. `products` carries `wc_id`, `sku` and `total_sales` — the three
     * columns ApiSecurityTest exists because of — and a picker needs a name and
     * an id. An explicit list is iterated here; adding a column to the table
     * next year makes it invisible to this endpoint rather than public from it.
     *
     * Visible products only, through the shared predicate, for the reason
     * ReviewController gives about its own product_id rule: a draft or
     * scheduled product has no page, so tagging one would put a card in a
     * player that links to a 404.
     */
    public function products(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        /*
         * ── `recent` IS A SEPARATE MODE, NOT A CHANGE TO THE EXISTING ONE ────
         *
         * The admin screen now shows the newest few products before anybody
         * types, because an empty box under an empty list is what made the owner
         * report the search as broken. That needs an order this endpoint did not
         * have, and reordering the ANSWER IT ALREADY GIVES would be a change to
         * behaviour that works — rule 1. So recency arrives as its own parameter
         * and the termless answer every existing caller gets is byte-identical.
         *
         * Bounded to 1..30 so the parameter cannot be used to ask for the
         * catalogue, and the last ORDER BY key is `id`, which cannot tie:
         * StableOrderingTest refuses a sliced query whose ordering can, and
         * created_at ties freely — an import writes a whole catalogue inside one
         * second.
         */
        $recent = (int) $request->query('recent', 0);

        $query = Product::query()
            ->visible()
            ->with('brand');

        if ($recent > 0 && $term === '') {
            $query->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(max(1, min(30, $recent)));
        } else {
            $query->orderBy('name')
                /*
                 * A tie-breaker that cannot tie, because this query is SLICED.
                 * Two products with the same name sit either side of the limit in
                 * whatever order the engine feels like, so the thirtieth row moves
                 * between identical requests. StableOrderingTest refuses a sliced
                 * query whose last ORDER BY key can tie, and it is right to.
                 */
                ->orderBy('id')
                ->limit(30);
        }

        if ($term !== '') {
            // Bound before it reaches LIKE: an unbounded term is an unbounded
            // pattern, and `%` is a wildcard the operator did not type.
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_substr($term, 0, 60)).'%';
            $query->where('name', 'like', $like);
        }

        $products = $query->get();

        /*
         * ── HOW MANY MATCHED BUT CANNOT BE TAGGED ───────────────────────────
         *
         * THE DEFECT THIS ANSWERS. visible() is `status = publish AND is_visible`
         * plus a schedule check, so a DRAFT product is correctly not returned —
         * and the screen had no way to say so. The owner searched for a product
         * he owns, got an empty box, and reported the search as broken. "Nothing
         * matched" and "that product is a draft" need completely different things
         * done about them, and only the server can tell them apart.
         *
         * Counted ONLY when the visible answer is empty, so the ordinary
         * keystroke costs exactly what it always did: this is one indexed COUNT
         * on the rarest branch, and StorefrontQueryBudgetTest is a storefront
         * budget — this is an authenticated admin endpoint behind its own
         * capability.
         *
         * It returns a COUNT and never the rows: an unpublished product's name is
         * not something this endpoint is allowed to start handing out just
         * because it was searched for.
         */
        $unpublished = 0;

        if ($term !== '' && $products->isEmpty()) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_substr($term, 0, 60)).'%';
            $unpublished = Product::query()
                ->where('name', 'like', $like)
                ->whereNotIn('id', Product::query()->visible()->select('id'))
                ->count();
        }

        return response()->json([
            'ok' => true,
            'unpublished' => $unpublished,
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => (string) $p->name,
                'brand' => $p->brand?->name,
            ])->all(),
        ]);
    }

    /* ───────────────────────────────────────────────────────────── internals */

    /**
     * Create or update, from one validated set of fields.
     *
     * Media paths are absent from this list on purpose — see the class
     * docblock. They are written only by upload() and derive().
     */
    private function write(Request $request, UgcVideo $video, int $created): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(UgcVideo::STATUSES)],
            'source_platform' => ['required', Rule::in(UgcVideo::PLATFORMS)],
            'source_url' => ['nullable', 'string', 'max:512'],
            'creator_handle' => ['nullable', 'string', 'max:120'],
            'creator_url' => ['nullable', 'string', 'max:512'],
            'rights_status' => ['required', Rule::in(UgcVideo::RIGHTS)],
            'rights_evidence' => ['nullable', 'string', 'max:2000'],
            'locale' => ['nullable', Rule::in(UgcVideo::LOCALES)],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'published_at' => ['nullable', 'date'],
        ] + TranslationInput::rules($video, [
            /*
             * The Arabic boxes are bounded off the ENGLISH rules rather than
             * off a number typed here, so widening `title` above widens its
             * translation with it and the two can never drift. §5: these two
             * fields are typed by hand and never machine-translated — a
             * creator's caption is her voice — but the STORAGE path is the
             * ordinary one every other editor uses.
             */
            'title' => ['required', 'string', 'max:180'],
            'caption' => ['nullable', 'string', 'max:2000'],
        ]));

        $translations = TranslationInput::clean(
            is_array($request->input('translations', [])) ? $request->input('translations', []) : []
        );

        /*
         * strip_tags on the two short operator strings, matching what
         * ReviewController does to an author's name. The screen escapes on the
         * way out as well — this is the "anything printed unescaped is a
         * constant, never a setting" rule applied at both ends, because the
         * next lane to print a creator handle should not have to know.
         *
         * NOT on `caption`: it is prose and an apostrophe-and-angle-bracket
         * emoticon in a creator's own words is not markup. It is escaped where
         * it is printed, like every other operator string in this console.
         */
        $video->title = strip_tags((string) $validated['title']);
        $video->caption = $validated['caption'] ?? null;
        $video->source_platform = $validated['source_platform'];
        $video->creator_handle = $validated['creator_handle'] === null
            ? null
            : strip_tags((string) $validated['creator_handle']);
        $video->rights_evidence = $validated['rights_evidence'] ?? null;
        $video->locale = $validated['locale'] ?? null;
        $video->position = (int) ($validated['position'] ?? $video->position ?? 0);
        $video->published_at = $validated['published_at'] ?? null;

        // §7: re-parsed, not pattern-matched, and stored as null when it is not
        // something a page may point at.
        $video->source_url = UgcPath::link($validated['source_url'] ?? null);
        $video->creator_url = UgcPath::link($validated['creator_url'] ?? null);

        $rights = (string) $validated['rights_status'];

        /*
         * The date the permission was given, kept in step with the switch
         * rather than left as a second thing to remember. A row that goes back
         * to pending or refused loses it, because a granted-at on a clip whose
         * permission was withdrawn is the kind of stale evidence that gets a
         * shop into trouble.
         */
        if ($rights === 'granted' && $video->rights_status !== 'granted') {
            $video->rights_granted_at = now();
        } elseif ($rights !== 'granted') {
            $video->rights_granted_at = null;
        }

        $video->rights_status = $rights;

        if ((string) $video->slug === '') {
            $video->slug = $this->slug($video->title);
        }

        /*
         * THE PUBLISH GATE, AND IT FAILS CLOSED.
         *
         * Checked against the row as it will be AFTER this write, so a request
         * that sets rights to granted and status to publish in one go is
         * allowed, and one that publishes a clip with no file, no poster or no
         * permission is refused with the reasons named. 422 and not a silent
         * downgrade to draft: an owner who pressed Publish and got a draft
         * would press it again.
         */
        $video->status = (string) $validated['status'];

        if ($video->status === 'publish' && ! $video->canPublish()) {
            return response()->json([
                'ok' => false,
                'error' => 'This video cannot be published yet.',
                'blockers' => $video->publishBlockers(),
            ], 422);
        }

        $video->save();

        // After save(), because a row being created has no id before it — one
        // call, the same request, both paths. BrandsApiController's own comment
        // says exactly this.
        $video->saveTranslations($translations);

        return response()->json(['ok' => true, 'video' => $this->card($video->fresh())], $created);
    }

    /**
     * What the library list shows per row. An explicit list, iterated — the
     * SettingController::PUBLIC_KEYS pattern, applied to an admin surface too,
     * because a column added later should be invisible by default here as well.
     *
     * @return array<string, mixed>
     */
    private function card(UgcVideo $video): array
    {
        return [
            'id' => $video->id,
            'slug' => $video->slug,
            'title' => (string) $video->title,
            'status' => $video->status,
            'rights_status' => $video->rights_status,
            'rights_granted_at' => $video->rights_granted_at?->toDateString(),
            'source_platform' => $video->source_platform,
            'creator_handle' => $video->creator_handle,
            'locale' => $video->locale,
            'position' => (int) $video->position,
            'published_at' => $video->published_at?->format('Y-m-d\TH:i'),
            'file_path' => UgcPath::stored($video->file_path),
            'teaser_path' => UgcPath::stored($video->teaser_path),
            'poster_path' => UgcPath::stored($video->poster_path),
            'bytes' => $video->bytes,
            'teaser_bytes' => $video->teaser_bytes,
            'poster_bytes' => $video->poster_bytes,
            'width' => $video->width,
            'height' => $video->height,
            'duration_ms' => $video->duration_ms,
            'media_state' => $video->mediaState(),
            'blockers' => $video->publishBlockers(),
            'products_count' => $video->products_count ?? $video->products()->count(),
        ];
    }

    /**
     * The three caps, as this server will really honour them.
     *
     * One place, called by index() and by the upload's own refusal, because the
     * number the screen advertises and the number a refusal quotes have to be
     * the same number — the whole defect being fixed here is two places
     * disagreeing about what fits.
     *
     * @return array<string, mixed>
     */
    private function limits(): array
    {
        $out = [];

        foreach (UgcMedia::KINDS as $kind) {
            $described = $this->serverLimits->describe(UgcMedia::MAX_BYTES[$kind]);

            // The flat key the screen already reads, now carrying the effective
            // number rather than the app's wish.
            $out[$kind.'_mb'] = $described['effective_mb'];
            $out[$kind] = $described;
        }

        /*
         * The ini values themselves, so the screen can name what to edit rather
         * than say "ask your host to raise the limit". This is an admin endpoint
         * behind auth:admin and ugc.view — nothing here is on /api/*.
         */
        $out['server'] = $this->serverLimits->raw() + [
            'per_file_bytes' => $this->serverLimits->perFile(),
            'per_request_bytes' => $this->serverLimits->perRequest(),
            'multipart_overhead' => ServerUploadLimits::MULTIPART_OVERHEAD,
        ];

        return $out;
    }

    private function find(string $id): ?UgcVideo
    {
        /*
         * Typed string and cast here, not an int parameter and not a
         * route-model binding. The route puts no numeric constraint on {id} and
         * this file declares strict_types, so an int parameter turns
         * /ugc-videos/abc into a TypeError and a 500 where a 404 was meant —
         * the fault ReviewController::helpful() names in its own docblock.
         */
        return UgcVideo::query()->whereKey((int) $id)->first();
    }

    /** A slug that is unique, from a title that may be anything. */
    private function slug(?string $title): string
    {
        $base = Str::slug((string) $title);

        if ($base === '') {
            // An Arabic-only title slugs to an empty string, and an empty slug
            // is a unique-index violation the second time it happens.
            $base = 'video';
        }

        $base = mb_substr($base, 0, 150);
        $slug = $base;
        $n = 1;

        while (UgcVideo::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}

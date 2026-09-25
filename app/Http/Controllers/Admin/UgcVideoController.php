<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\UgcVideo;
use App\Services\UgcMedia;
use App\Services\UgcPath;
use App\Services\UgcTranscoder;
use App\Support\TranslationInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    ) {}

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
                'teaser_seconds' => UgcTranscoder::TEASER_SECONDS,
                'teaser_size' => UgcTranscoder::TEASER_WIDTH.'x'.UgcTranscoder::TEASER_HEIGHT,
            ],
            'limits' => [
                'clip_mb' => (int) (UgcMedia::MAX_BYTES[UgcMedia::KIND_CLIP] / 1048576),
                'teaser_mb' => (int) (UgcMedia::MAX_BYTES[UgcMedia::KIND_TEASER] / 1048576),
                'poster_mb' => (int) (UgcMedia::MAX_BYTES[UgcMedia::KIND_POSTER] / 1048576),
            ],
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

        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(UgcMedia::KINDS)],
            'file' => ['required', 'file'],
        ]);

        $kind = (string) $validated['kind'];
        $result = $this->media->store($request->file('file'), $kind);

        if (! ($result['ok'] ?? false)) {
            return response()->json(['ok' => false, 'error' => $result['message']], 422);
        }

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
         * Never fails the upload: the clip is written and already being served,
         * and a video with a poster and no teaser is a supported, published,
         * working state.
         */
        if ($kind === UgcMedia::KIND_CLIP) {
            $video->save();

            $derived = $this->transcoder->derive($video);

            if ($derived['poster'] !== null) {
                $this->media->forget($video->poster_path);
                $video->poster_path = $derived['poster'];
                $video->poster_bytes = (int) @filesize(public_path(ltrim($derived['poster'], '/'))) ?: null;
                $video->width = $derived['width'] ?? $video->width;
                $video->height = $derived['height'] ?? $video->height;
            }

            if ($derived['teaser'] !== null) {
                $this->media->forget($video->teaser_path);
                $video->teaser_path = $derived['teaser'];
                $video->teaser_bytes = (int) @filesize(public_path(ltrim($derived['teaser'], '/'))) ?: null;
            }

            if ($derived['duration_ms'] !== null) {
                $video->duration_ms = $derived['duration_ms'];
            }

            $notes = $derived['notes'];
        }

        $video->save();

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

        $derived = $this->transcoder->derive($video, remakePoster: true, remakeTeaser: true);

        if ($derived['poster'] !== null) {
            $this->media->forget($video->poster_path);
            $video->poster_path = $derived['poster'];
            $video->poster_bytes = (int) @filesize(public_path(ltrim($derived['poster'], '/'))) ?: null;
            $video->width = $derived['width'] ?? $video->width;
            $video->height = $derived['height'] ?? $video->height;
        }

        if ($derived['teaser'] !== null) {
            $this->media->forget($video->teaser_path);
            $video->teaser_path = $derived['teaser'];
            $video->teaser_bytes = (int) @filesize(public_path(ltrim($derived['teaser'], '/'))) ?: null;
        }

        if ($derived['duration_ms'] !== null) {
            $video->duration_ms = $derived['duration_ms'];
        }

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

        $query = Product::query()
            ->visible()
            ->with('brand')
            ->orderBy('name')
            /*
             * A tie-breaker that cannot tie, because this query is SLICED.
             * Two products with the same name sit either side of the limit in
             * whatever order the engine feels like, so the thirtieth row moves
             * between identical requests. StableOrderingTest refuses a sliced
             * query whose last ORDER BY key can tie, and it is right to.
             */
            ->orderBy('id')
            ->limit(30);

        if ($term !== '') {
            // Bound before it reaches LIKE: an unbounded term is an unbounded
            // pattern, and `%` is a wildcard the operator did not type.
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_substr($term, 0, 60)).'%';
            $query->where('name', 'like', $like);
        }

        return response()->json([
            'ok' => true,
            'products' => $query->get()->map(fn (Product $p) => [
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

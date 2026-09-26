<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Services\UgcRail;
use App\Services\UgcSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Content → Shoppable video → Sections.
 *
 * The owner: "i need a full module, where i can create multiple sections of
 * videos contain, and can insert anywhere in the site, products and pages etc
 * via short code. when i create new section, it should give me proper list of
 * inside videos".
 *
 * So this is the section half: create one, get the ordered list of clips inside
 * it, reorder them, and copy the shortcode that renders it. The per-clip popup
 * editor on the same screen posts to the ENDPOINTS THAT ALREADY EXIST in
 * UgcVideoController — there is no second video editor here, and there must not
 * be, because a second one is a second set of validation rules that drift.
 *
 * ── EVERY VALUE THAT REACHES A COLUMN IS VALIDATED, NOT CAST ────────────────
 *
 *   handle      generated from the title, never accepted from the request. It ends
 *               up inside somebody else's syntax — `[kbb_videos section="x"]`,
 *               parsed by App\Support\Shortcodes with `[^"]*` inside the quotes —
 *               so a handle carrying a quote truncates the attribute and one
 *               carrying `]` truncates the whole shortcode. UgcSection::HANDLE_RE
 *               is lowercase letters, digits and hyphens, and uniqueness is a
 *               database constraint as well as a suffix loop.
 *   status      Rule::in. A select stores one of its own options or the default.
 *   columns     Rule::in over UgcSettings::COLUMNS, or null to follow the
 *               setting. The storefront ALSO re-checks it (UgcSettings::
 *               cssVariables falls back to the setting for an unknown value), so a
 *               row edited by hand on the box cannot emit a broken calc().
 *   locale      Rule::in, or null for both storefronts.
 *   max_tiles   an integer between 1 and UgcSection::MAX_TILES, and clamped again
 *               on read by tileCap(), because this shop has a shell now and a
 *               hand-run UPDATE could put 6000 in that column.
 *
 * ── EVERY WRITE DROPS THE RAIL CACHE ────────────────────────────────────────
 *
 * UgcRail caches a section's tiles for ten minutes. An operator who reorders a
 * rail and then reloads the shop to check has to see the new order, so every
 * method here that changes anything calls UgcRail::flush() — the TTL is the
 * backstop for a write nobody thought of, not the mechanism.
 */
class UgcSectionController extends Controller
{
    public function __construct(private UgcSettings $settings) {}

    /**
     * The list, plus everything the screen needs to draw itself without a second
     * request: the module's on/off state, the vocabularies for the selects, and a
     * thin index of the library so a section can be filled from it.
     */
    public function index(): JsonResponse
    {
        $sections = UgcSection::query()
            ->withCount('videos')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json([
            'sections' => $sections->map(fn (UgcSection $s) => $this->card($s))->values()->all(),
            /*
             * SAID OUT LOUD ON THE SCREEN, because it is the single most likely
             * reason an owner reports "I made a section and nothing shows". The
             * module ships OFF and a rail renders the empty string while it is —
             * so the header card says so and links to Store → Modules.
             */
            'module_on' => $this->settings->enabled(),
            'vocabulary' => [
                'status' => UgcSection::STATUSES,
                'locales' => UgcSection::LOCALES,
                'columns' => UgcSettings::COLUMNS,
                /*
                 * THE VIDEO's OWN VOCABULARIES TOO, because the popup editor on this
                 * screen edits a clip and a second request to /ugc-videos just to
                 * learn the words for three selects is a request for nothing. They
                 * are the same constants Admin\UgcVideoController::index() publishes,
                 * read from the model rather than restated — rule 5's "a select
                 * stores one of its own options" is only checkable if both ends name
                 * the same list.
                 */
                'statuses' => UgcVideo::STATUSES,
                'rights' => UgcVideo::RIGHTS,
                'platforms' => UgcVideo::PLATFORMS,
            ],
            'max_tiles' => UgcSection::MAX_TILES,
            'library' => UgcVideo::query()
                ->orderBy('position')
                ->orderBy('id')
                ->limit(300)
                ->get(['id', 'title', 'slug', 'status', 'rights_status', 'poster_path', 'creator_handle'])
                ->map(fn (UgcVideo $v) => [
                    'id' => $v->id,
                    'title' => (string) $v->title,
                    'handle' => (string) ($v->creator_handle ?? ''),
                    'poster' => \App\Services\UgcPath::stored($v->poster_path),
                    // So the screen can mark a clip that will not appear on the
                    // shop even once it is in a section. A draft clip in a
                    // published rail is not an error — it simply does not draw —
                    // and the operator should be able to see which is which.
                    'live' => $v->status === 'publish' && $v->rights_status === 'granted',
                ])->values()->all(),
        ]);
    }

    /** One section, with its clips in the owner's order. */
    public function show(string $id): JsonResponse
    {
        $section = $this->find($id);

        if ($section === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $section->loadCount('videos');

        return response()->json([
            'section' => $this->card($section) + [
                'videos' => $section->videos()->get()->map(fn (UgcVideo $v) => [
                    'id' => $v->id,
                    'title' => (string) $v->title,
                    'slug' => (string) $v->slug,
                    'handle' => (string) ($v->creator_handle ?? ''),
                    'poster' => \App\Services\UgcPath::stored($v->poster_path),
                    'status' => (string) $v->status,
                    'rights_status' => (string) $v->rights_status,
                    'media_state' => $v->mediaState(),
                    'blockers' => $v->publishBlockers(),
                    'products_count' => $v->products()->count(),
                    'position' => (int) $v->pivot->position,
                ])->values()->all(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->write($request, new UgcSection(), 1);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $section = $this->find($id);

        if ($section === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        return $this->write($request, $section, 0);
    }

    public function destroy(string $id): JsonResponse
    {
        $section = $this->find($id);

        if ($section === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        /*
         * The section only. The clips are NOT deleted and no file is touched:
         * `ugc_section_video` cascades on the section's id, so the pivot rows go
         * and every video stays in the library, in every other section it belongs
         * to. That is the whole reason this is a pivot — deleting "the homepage
         * rail" must not delete the sunscreen clip that is also on the sun-care
         * page.
         */
        $section->delete();

        UgcRail::flush();

        return response()->json(['ok' => true]);
    }

    /**
     * Set the ordered list of clips in this section.
     *
     * ── ONE sync(), NOT A LOOP ──────────────────────────────────────────────
     *
     * The whole list arrives and replaces the whole list, with `position` taken
     * from the array index. A per-row PATCH endpoint would let two drags
     * interleave into an order neither operator asked for, and the pivot's unique
     * index would make the failure a 500 halfway through rather than a refusal.
     *
     * Ids that are not real clips are DROPPED rather than refused, after being
     * checked against the table: a video deleted in another tab should not make
     * saving the order impossible. Duplicates are collapsed for the same reason —
     * the pivot's unique index would otherwise turn a double-click into a
     * constraint violation the operator cannot act on.
     */
    public function videos(Request $request, string $id): JsonResponse
    {
        $section = $this->find($id);

        if ($section === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $data = $request->validate([
            'videos' => ['present', 'array', 'max:'.UgcSection::MAX_TILES],
            'videos.*' => ['integer'],
        ]);

        $wanted = array_values(array_unique(array_map('intval', $data['videos'])));

        // One query, and it is what makes an id that is not a clip impossible to
        // write rather than merely unlikely.
        $real = UgcVideo::query()->whereIn('id', $wanted)->pluck('id')->all();

        $sync = [];
        $position = 0;

        foreach ($wanted as $videoId) {
            if (in_array($videoId, $real, true)) {
                $sync[$videoId] = ['position' => $position++];
            }
        }

        $section->videos()->sync($sync);

        UgcRail::flush();

        return response()->json(['ok' => true, 'count' => count($sync)]);
    }

    /**
     * The one write path, so create and save cannot disagree.
     */
    private function write(Request $request, UgcSection $section, int $created): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'heading' => ['nullable', 'string', 'max:191'],
            'subheading' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(UgcSection::STATUSES)],
            'columns' => ['nullable', Rule::in(array_keys(UgcSettings::COLUMNS))],
            'locale' => ['nullable', Rule::in(UgcSection::LOCALES)],
            'max_tiles' => ['nullable', 'integer', 'min:1', 'max:'.UgcSection::MAX_TILES],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        /*
         * ?? ON EVERY OPTIONAL KEY, and the first version of this method did not
         * have it. `validate()` returns only the keys the request actually carried,
         * so a POST of nothing but `title` — which is exactly what the New section
         * button sends — died on `Undefined array key "heading"` and answered 500
         * where a 201 was meant. Found by the test; it would have been found by the
         * owner pressing the button.
         */
        $section->title = (string) $data['title'];
        $section->heading = ($data['heading'] ?? null) === null ? null : (string) $data['heading'];
        $section->subheading = ($data['subheading'] ?? null) === null ? null : (string) $data['subheading'];
        $section->status = (string) ($data['status'] ?? $section->status ?? 'draft');
        $section->columns = $data['columns'] ?? null;
        $section->locale = $data['locale'] ?? null;
        $section->max_tiles = (int) ($data['max_tiles'] ?? $section->max_tiles ?? 12);
        $section->position = (int) ($data['position'] ?? $section->position ?? 0);

        /*
         * THE HANDLE IS GENERATED ONCE AND THEN NEVER MOVES.
         *
         * It is the shortcode. Regenerating it when the title is edited would
         * silently break every page the owner has already pasted
         * `[kbb_videos section="..."]` into, and those pages would then render
         * NOTHING AT ALL — because that is what an unresolvable shortcode
         * correctly does. A stale-looking handle is a much smaller problem than a
         * rail that vanished from four pages when somebody fixed a typo in a
         * heading.
         */
        if ((string) $section->handle === '') {
            $section->handle = $this->handle($section->title);
        }

        $section->save();

        UgcRail::flush();

        $section->loadCount('videos');

        return response()->json(['ok' => true, 'section' => $this->card($section)], $created ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function card(UgcSection $section): array
    {
        return [
            'id' => $section->id,
            'handle' => (string) $section->handle,
            'title' => (string) $section->title,
            'heading' => $section->heading,
            'subheading' => $section->subheading,
            'status' => (string) $section->status,
            'columns' => $section->columns,
            'locale' => $section->locale,
            'max_tiles' => $section->tileCap(),
            'position' => (int) $section->position,
            'videos_count' => (int) ($section->videos_count ?? 0),
            // The line the operator copies. Built by the model so the screen and
            // the parser can never disagree about the spelling.
            'shortcode' => $section->shortcode(),
        ];
    }

    private function find(string $id): ?UgcSection
    {
        /*
         * Typed string and cast here, not an int parameter. The route puts no
         * numeric constraint on {id} and this file declares strict_types, so an
         * int parameter turns /ugc-sections/abc into a TypeError and a 500 where
         * a 404 was meant — the fault Store\ReviewController::helpful() names in
         * its own docblock and UgcVideoController::find() already follows.
         */
        return UgcSection::query()->whereKey((int) $id)->first();
    }

    /**
     * A handle that is unique, from a title that may be anything.
     *
     * An Arabic-only title slugs to the empty string — Str::slug transliterates
     * nothing outside its map — and an empty handle is a shortcode that can never
     * resolve, so it falls back to a generated one rather than to ''. The same
     * fallback UgcVideoController::slug() makes, for the same reason.
     */
    private function handle(string $title): string
    {
        $base = Str::slug($title);

        if ($base === '' || preg_match(UgcSection::HANDLE_RE, $base) !== 1) {
            $base = 'section-'.Str::lower(Str::random(6));
        }

        $base = Str::limit($base, 80, '');
        $candidate = $base;
        $n = 2;

        // Bounded, and the bound matters: an unbounded loop against a unique
        // index is a request that never returns if something else is wrong.
        while ($n < 200 && UgcSection::query()->where('handle', $candidate)->exists()) {
            $candidate = $base.'-'.$n;
            $n++;
        }

        return $candidate;
    }
}

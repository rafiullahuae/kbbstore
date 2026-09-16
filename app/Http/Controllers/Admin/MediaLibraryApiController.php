<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Support\AggregatesQueries;
use App\Support\MediaBackfill;
use App\Support\MediaUsage;
use App\Support\SearchTerms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Content → Media Library. The grid, the search, the detail panel and delete.
 *
 * WHAT WAS HERE BEFORE. Nothing. The console's Media Library entry pointed at
 * `kbb-admin-media.html`, a standalone file this repo has never shipped, and a
 * previous lane replaced the resulting 404 with an honest "isn't installed yet"
 * card. That card is what the owner was looking at when they asked for this.
 *
 * WHERE THE ROWS COME FROM. `media` was created for the WooCommerce import and
 * had never been written to by anything — Media:: had zero call sites in the
 * whole tree. Two things fill it now, and neither is a new upload path:
 *
 *   - MediaUploadController::record(), on the existing /admin-api/media/upload,
 *     which is the one endpoint the product gallery, brand logos, category
 *     images and the SEO share image all already post to; and
 *   - the backfill in database/migrations/..._backfill_media_library.php, which
 *     walks public/uploads/ once so everything uploaded BEFORE this shipped
 *     appears too, rather than the library starting empty on a store that has
 *     been uploading for months.
 *
 * WRITES ARE NOT PART OF THIS CLASS beyond delete. Uploading is the other
 * controller's job and stays there.
 *
 * EVERY ROUTE HERE IS UNDER auth:admin. The table carries the store's whole
 * asset list and DELETE removes files from the public web root; CLAUDE.md's
 * standing rule is that /api/* is unauthenticated, so none of this may ever go
 * near routes/api.php. See the header of routes/media-library-admin.php.
 */
class MediaLibraryApiController extends Controller
{
    use AggregatesQueries;

    /** Page size. Matches the 4-across grid at desktop: six full rows. */
    private const PER_PAGE = 24;

    /**
     * The grid.
     *
     * Newest first, paginated, with the totals for the WHOLE filtered set —
     * computed through aggregateQuery() so that page two reports the same
     * totals as page one. CLAUDE.md records why: a surviving OFFSET on an
     * aggregate returns no row at all, so every total silently reads zero from
     * page two on while the endpoint still answers 200. That shipped twice.
     */
    public function index(Request $request): JsonResponse
    {
        $rows = $this->filtered($request);

        $page = max(1, (int) $request->query('page', '1'));

        $summary = $this->aggregate(
            $rows,
            'COUNT(*) as n, COALESCE(SUM(media.size), 0) as bytes'
        );

        $total = (int) ($summary->n ?? 0);

        $items = (clone $rows)
            ->orderByDesc('media.created_at')
            ->orderByDesc('media.id')
            ->forPage($page, self::PER_PAGE)
            ->get();

        // Usage is resolved for the 24 rows on THIS page only, not for the
        // whole table: the index() pass is the expensive part and the grid only
        // needs a badge per tile.
        $index = MediaUsage::index();

        return response()->json([
            'ok' => true,
            'items' => $items->map(fn (Media $m) => $this->tile($m, $index))->values(),
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'pages' => (int) max(1, (int) ceil($total / self::PER_PAGE)),
            'bytes' => (int) ($summary->bytes ?? 0),
            'types' => MediaUsage::TYPES,
        ]);
    }

    /** One image, with every place it is used. */
    public function show(Media $media): JsonResponse
    {
        $index = MediaUsage::index();

        return response()->json([
            'ok' => true,
            'item' => $this->tile($media, $index) + [
                'usage' => MediaUsage::verify($index, (string) $media->filename, (string) $media->path),
            ],
        ]);
    }

    /**
     * Delete an image — refusing while anything still points at it.
     *
     * A cluttered library is a nuisance; a broken image on a live product page
     * is a defect a shopper sees. So a referenced file is refused with 409 and
     * the exact list of what references it, and only an explicit `force=1` —
     * which the screen only sends after showing that list — gets past it.
     *
     * The check is MediaUsage::verify(), not the basename bucket the grid
     * filter uses, so the refusal is exact in both directions: it will not
     * refuse over a same-named file that is not actually this one, and it will
     * not let this one through because some other row shares its name.
     */
    public function destroy(Request $request, Media $media): JsonResponse
    {
        $usage = MediaUsage::verify(MediaUsage::index(), (string) $media->filename, (string) $media->path);

        $force = in_array((string) $request->query('force', ''), ['1', 'true', 'yes'], true);

        if ($usage !== [] && ! $force) {
            return response()->json([
                'ok' => false,
                'used' => true,
                'usage' => $usage,
                'message' => $this->refusal($usage),
            ], 409);
        }

        $path = (string) $media->path;

        /*
         * Only a file this application wrote is removed from disk.
         *
         * An `uploads/` path is an admin upload and lives in the public web
         * root this app owns. Anything else is an imported WordPress path under
         * /wp-content/uploads/, which on the server is a DIFFERENT directory
         * belonging to the old store — bootstrap/app.php's usePublicPath means
         * this tree cannot even see it. Forgetting the row is right; reaching
         * out of our own web root to unlink someone else's file is not.
         */
        $removed = false;

        if (str_starts_with(ltrim($path, '/'), 'uploads/')) {
            $full = public_path(ltrim($path, '/'));

            if (is_file($full)) {
                $removed = @unlink($full);
            }
        }

        $media->delete();

        return response()->json([
            'ok' => true,
            'file_removed' => $removed,
            'forced' => $usage !== [],
            'usage' => $usage,
        ]);
    }

    /**
     * Catalogue anything on disk that has no row yet.
     *
     * The library is only as complete as the table, and two things can leave
     * the table behind: an upload whose row failed to write — the endpoint
     * reports `recorded: false` rather than failing an upload whose file is
     * already being served — and a file put into public/uploads by any route
     * other than the endpoint, FTP being the obvious one on this host. Rather
     * than have the owner wonder why an image they can see in the product
     * editor is missing from the library, they press Rescan.
     *
     * Idempotent and additive; see MediaBackfill.
     */
    public function rescan(): JsonResponse
    {
        $added = MediaBackfill::run();

        return response()->json(['ok' => true, 'added' => $added]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * The filtered row query, before ordering and paging.
     *
     * @return Builder<Media>
     */
    private function filtered(Request $request): Builder
    {
        $query = Media::query();

        /*
         * ── by name ──────────────────────────────────────────────────────
         *
         * `original_name` is first and it is the one that matters. The upload
         * endpoint stores every file as `Ymd-His-<8 random>.ext` — deliberately,
         * so that a name the browser supplied never decides what lands in the
         * public web root — which means the operator who uploaded
         * `cosrx-snail-essence.jpg` would search for "cosrx" and get an empty
         * grid back off a library that holds the file. Searching only the
         * stored filename is a search that answers 200 and finds nothing, which
         * is the exact failure CLAUDE.md records for the product `status`
         * filter.
         *
         * SearchTerms::whereLike escapes % and _ and emits an explicit
         * ESCAPE '!' clause — a backslash is MySQL's DEFAULT escape and means
         * nothing to SQLite, so the same search returned different rows in CI
         * and in production before that helper existed.
         */
        $q = trim((string) $request->query('q', ''));

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                SearchTerms::whereLike($w, 'media.original_name', $q);
                SearchTerms::orWhereLike($w, 'media.filename', $q);
                SearchTerms::orWhereLike($w, 'media.path', $q);
                SearchTerms::orWhereLike($w, 'media.alt', $q);
            });
        }

        // ── by date ──────────────────────────────────────────────────────
        // Inclusive at both ends. `to` is pushed to the end of that day, or
        // "uploaded today" would match nothing, which is the shape of silent
        // empty filter this lane was told to avoid.
        $from = $this->date((string) $request->query('from', ''));

        if ($from !== null) {
            $query->where('media.created_at', '>=', $from.' 00:00:00');
        }

        $to = $this->date((string) $request->query('to', ''));

        if ($to !== null) {
            $query->where('media.created_at', '<=', $to.' 23:59:59');
        }

        // ── by what it is attached to ────────────────────────────────────
        // Derived, not joined; MediaUsage's class comment explains why the
        // schema leaves no other option and what recording it properly would
        // take. `attached_q` narrows by the OWNER's name — "every image on a
        // COSRX product" — which is the "by product or brand or by category"
        // half of what was asked for.
        $attached = (string) $request->query('attached', '');
        $ownerQuery = trim((string) $request->query('attached_q', ''));

        if ($attached === 'unused') {
            $used = MediaUsage::filenames(null, null);

            // whereNotIn over an empty list is a tautology in both dialects and
            // would return everything, which happens to be the right answer:
            // nothing references anything, so nothing is in use.
            if ($used !== []) {
                $query->whereNotIn('media.filename', $used);
            }
        } elseif ($attached === 'any' || in_array($attached, MediaUsage::TYPES, true)) {
            $names = MediaUsage::filenames(
                $attached === 'any' ? null : $attached,
                $ownerQuery !== '' ? $ownerQuery : null
            );

            // An empty list here means the store genuinely has no such
            // reference. whereIn([]) is `0 = 1` in Laravel, which is the
            // correct empty answer rather than an accidental match-all.
            $query->whereIn('media.filename', $names);
        }

        return $query;
    }

    /**
     * One tile's worth of an image.
     *
     * @param  array<string, list<array{type: string, id: int, name: string, field: string, path: string}>>  $index
     * @return array<string, mixed>
     */
    private function tile(Media $media, array $index): array
    {
        $usage = MediaUsage::verify($index, (string) $media->filename, (string) $media->path);

        return [
            'id' => (int) $media->id,
            'filename' => (string) $media->filename,
            // What the operator called it, falling back to the stored name for
            // every row the backfill catalogued off disk — those have no
            // original name to recover, and a blank tile title would be worse
            // than a generated one.
            'original_name' => (string) ($media->original_name ?: $media->filename),
            'path' => (string) $media->path,
            'url' => $media->url(),
            'mime' => $media->mime,
            'size' => $media->size === null ? null : (int) $media->size,
            'width' => $media->width === null ? null : (int) $media->width,
            'height' => $media->height === null ? null : (int) $media->height,
            'alt' => (string) $media->alt,
            'created_at' => optional($media->created_at)->toIso8601String(),
            'used' => $usage !== [],
            'used_count' => count($usage),
            // The distinct owner kinds, so a tile can be badged without the
            // screen having to walk the whole usage list.
            'used_types' => array_values(array_unique(array_map(fn ($u) => $u['type'], $usage))),
        ];
    }

    /** Y-m-d, or null. Anything else is ignored rather than turned into "now". */
    private function date(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $raw));

        return checkdate($m, $d, $y) ? $raw : null;
    }

    /**
     * The refusal, naming what is using the file rather than merely saying
     * something is.
     *
     * @param  list<array{type: string, id: int, name: string, field: string}>  $usage
     */
    private function refusal(array $usage): string
    {
        $names = [];

        foreach ($usage as $u) {
            $names[$u['type'].':'.$u['id']] = $u['type'].' “'.$u['name'].'”';
        }

        $names = array_values($names);
        $shown = array_slice($names, 0, 3);
        $rest = count($names) - count($shown);

        return 'This image is still used by '.implode(', ', $shown)
            .($rest > 0 ? ' and '.$rest.' more' : '')
            .'. Deleting it would leave a broken image on '
            .(count($names) === 1 ? 'that page' : 'those pages').'.';
    }
}

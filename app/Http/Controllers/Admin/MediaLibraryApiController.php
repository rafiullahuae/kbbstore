<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\MediaUsageRecord;
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
 * TWO SOURCES FOR "WHERE IS THIS IMAGE USED", AND THE SPLIT IS DELIBERATE.
 *
 *   - The GRID — its attachment filter and its per-tile badge — reads the
 *     `media_usages` table. It is fast, and it is exact where the old
 *     basename-gathering filter was broad.
 *   - The DETAIL PANEL and DELETE read App\Support\MediaUsage::verify(),
 *     which re-derives from the image URLs on every product, brand and
 *     category.
 *
 * The reason is what each one costs when it is wrong. A stale badge is a
 * cosmetic error on an admin screen. A stale answer in the delete guard
 * unlinks a file that a live product page is still pointing at, which is a
 * broken image a shopper sees — so that path never trusts a record that
 * something else was responsible for keeping up to date. show() is derived for
 * the same reason: it is the list the operator reads immediately before
 * pressing Delete, and a confirmation that disagreed with the refusal would be
 * worse than a slow one.
 *
 * App\Support\MediaUsageWriter keeps the table current and names the two
 * places it can still fall behind; `php artisan media:usages-reconcile
 * --check` reports any disagreement between the two.
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
     * The model behind each owner_type string in `media_usages`.
     *
     * The table stores 'product' / 'brand' / 'category' rather than a class
     * name so a dump of it reads without a class map; this is where that is
     * turned back into something to query.
     */
    private const MODELS = [
        'product' => \App\Models\Product::class,
        'brand' => \App\Models\Brand::class,
        'category' => \App\Models\Category::class,
    ];

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

        /*
         * Badges come from `media_usages`, one query for the 24 rows on this
         * page, where they used to come from a MediaUsage::index() pass over
         * every product, brand and category in the store on EVERY render of
         * this screen — not only a filtered one, which is what that class's
         * comment used to claim.
         *
         * A badge is the one place a slightly stale answer is affordable. The
         * delete guard below still asks MediaUsage::verify(), because there
         * the cost of being wrong is a broken image on the shop.
         */
        $usage = $this->usageFor($items->pluck('id')->all());

        return response()->json([
            'ok' => true,
            'items' => $items->map(fn (Media $m) => $this->tile($m, $usage))->values(),
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'pages' => (int) max(1, (int) ceil($total / self::PER_PAGE)),
            'bytes' => (int) ($summary->bytes ?? 0),
            'types' => MediaUsage::TYPES,
        ]);
    }

    /**
     * One image, with every place it is used.
     *
     * The detail panel is what the operator reads just before pressing Delete,
     * so the list it shows is the DERIVED one — the same answer destroy() will
     * act on. Showing a recorded list here and enforcing a derived one there
     * would mean the confirmation and the refusal could disagree, which is a
     * worse screen than a slow one.
     */
    public function show(Media $media): JsonResponse
    {
        $index = MediaUsage::index();
        $usage = MediaUsage::verify($index, (string) $media->filename, (string) $media->path);

        return response()->json([
            'ok' => true,
            'item' => $this->tile($media, [(int) $media->id => $usage]) + [
                'usage' => $usage,
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

        /*
         * And the phone-sized copies, which until now nothing ever removed.
         *
         * App\Support\ImageVariants writes up to two resized files per
         * photograph under public/img-cache/<width>/<the original's path>, and
         * generate() deliberately skips a width that already exists — so this
         * endpoint was unlinking an original and leaving its copies behind
         * permanently. They are gitignored and on BuildPackage::NEVER_SHIP, so
         * no package could clear them either, and the host has no shell: the
         * only way back was FTP. On the measured cost of a 1000x1000 JPEG,
         * 121KB per deleted photograph accumulates with nothing to bound it.
         *
         * UNCONDITIONAL, unlike the unlink above. That guard exists because an
         * imported /wp-content/ path belongs to the OLD store's directory and
         * is not ours to delete. img-cache is entirely ours whatever the
         * original's path was, so there is no file here this application did
         * not write, and no reason to leave one behind.
         *
         * AFTER the unlink, not before: if the unlink fails the original is
         * still being served, and it is better for its srcset candidates to be
         * gone — the browser simply loads `src` — than for the copies to
         * outlive an original this endpoint has already forgotten the row for.
         *
         * forget() never throws and returns how many files it took, which the
         * response carries for the same reason `file_removed` is there.
         */
        $variantsRemoved = \App\Support\ImageVariants::forget('/'.ltrim($path, '/'));

        $media->delete();

        return response()->json([
            'ok' => true,
            'file_removed' => $removed,
            'variants_removed' => $variantsRemoved,
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

        /*
         * ── by what it is attached to ────────────────────────────────────
         *
         * RECORDED now, not derived. This used to gather every referenced
         * FILENAME by walking the whole catalogue and match `media.filename`
         * against that list, which was broad on purpose: two rows sharing a
         * basename both matched, and MediaUsage's comment says so. Selecting
         * media ids out of `media_usages` is both narrower — those rows were
         * recorded through MediaUsage::matches(), which checks the full stored
         * path — and cheaper.
         *
         * `attached_q` narrows by the OWNER's name — "every image on a COSRX
         * product" — which is the "by product or brand or by category" half of
         * what was asked for.
         *
         * ONE KNOWN INCONSISTENCY, named here rather than left to be found. A
         * usage row whose owner was removed by a BULK Eloquent delete fires no
         * model event and survives (see MediaUsageWriter's header). Such a row
         * keeps its media out of `attached=unused`, while the badge built by
         * usageFor() skips it because the owner's name cannot be looked up —
         * so the tile can read "unused" and still be absent from the unused
         * filter. Both halves err towards "might still be in use", which is
         * the safe direction, and `media:usages-reconcile` clears the cause.
         */
        $attached = (string) $request->query('attached', '');
        $ownerQuery = trim((string) $request->query('attached_q', ''));

        if ($attached === 'unused') {
            // NOT EXISTS rather than whereNotIn over a gathered list: the
            // subquery cannot outgrow a placeholder ceiling and needs no pass
            // over the catalogue to build.
            $query->whereNotExists(
                MediaUsageRecord::query()
                    ->whereColumn('media_usages.media_id', 'media.id')
                    ->toBase()
            );
        } elseif ($attached === 'any' || in_array($attached, MediaUsage::TYPES, true)) {
            $rows = MediaUsageRecord::query()->whereColumn('media_usages.media_id', 'media.id');

            if ($attached !== 'any') {
                $rows->where('media_usages.owner_type', $attached);
            }

            /*
             * Narrowing by the OWNER's name — "every image on a COSRX product"
             * — is still a lookup against the owning table, because that is
             * where the name lives. It resolves to a list of ids per kind
             * rather than a polymorphic join, which neither dialect does
             * without a union.
             *
             * An owner search that matches nothing must yield NO media, not
             * all of it, so the empty case is made explicit: whereIn([]) is
             * `0 = 1` in Laravel and that is the honest answer here. A filter
             * that silently matched everything instead is the failure
             * CLAUDE.md records for the product `status` filter.
             */
            if ($ownerQuery !== '') {
                $rows->where(function ($w) use ($attached, $ownerQuery) {
                    foreach ($attached === 'any' ? MediaUsage::TYPES : [$attached] as $kind) {
                        /*
                         * 'site' has no owning row and therefore no name to
                         * search. Its usages are settings — the default share
                         * image and the organisation logo — so an owner-name
                         * search simply does not apply to them, and they are
                         * skipped rather than matched against nothing.
                         *
                         * Skipping is the correct half of the two: including
                         * them would make every owner search also return the
                         * site images, which is the "filter that quietly
                         * matches everything" this block's own comment above
                         * exists to warn against.
                         */
                        if (! isset(self::MODELS[$kind])) {
                            continue;
                        }

                        $w->orWhere(function ($k) use ($kind, $ownerQuery) {
                            $k->where('media_usages.owner_type', $kind)
                                ->whereIn('media_usages.owner_id', $this->ownerIds($kind, $ownerQuery));
                        });
                    }
                });
            }

            $query->whereExists($rows->toBase());
        }

        return $query;
    }

    /**
     * Where each of these media rows is used, from `media_usages`.
     *
     * One query for the whole page, shaped like MediaUsage::verify()'s answer
     * so that tile() cannot tell which of the two produced it — that is what
     * lets show() hand it the derived list instead.
     *
     * The owner's NAME is joined back on, because the badge names what is
     * using the file and a row here carries only a type and an id. Three
     * lookups, one per kind, for at most 24 tiles' worth of owners.
     *
     * @param  list<int>  $mediaIds
     * @return array<int, list<array{type: string, id: int, name: string, field: string}>>
     */
    private function usageFor(array $mediaIds): array
    {
        if ($mediaIds === []) {
            return [];
        }

        $rows = MediaUsageRecord::query()
            ->whereIn('media_id', $mediaIds)
            ->get(['media_id', 'owner_type', 'owner_id', 'field']);

        if ($rows->isEmpty()) {
            return [];
        }

        $names = [];

        foreach (MediaUsage::TYPES as $kind) {
            $ids = $rows->where('owner_type', $kind)->pluck('owner_id')->unique()->all();

            if ($ids === []) {
                continue;
            }

            $names[$kind] = self::MODELS[$kind]::query()
                ->whereIn('id', $ids)
                ->pluck('name', 'id')
                ->all();
        }

        $out = [];

        foreach ($rows as $row) {
            $kind = (string) $row->owner_type;
            $ownerId = (int) $row->owner_id;

            /*
             * A row whose owner has gone is SKIPPED rather than badged with a
             * blank name. A bulk Eloquent delete fires no model events, so
             * this table can hold rows for a product that no longer exists —
             * MediaUsageWriter's header says where that happens and
             * media:usages-reconcile clears it. Until then the grid agrees
             * with the derivation, which also cannot see a deleted owner.
             */
            if (! isset($names[$kind][$ownerId])) {
                continue;
            }

            $out[(int) $row->media_id][] = [
                'type' => $kind,
                'id' => $ownerId,
                'name' => (string) $names[$kind][$ownerId],
                'field' => (string) $row->field,
            ];
        }

        return $out;
    }

    /**
     * The ids of owners of one kind whose name matches the operator's search.
     *
     * SearchTerms::whereLike for the reason its own header gives: it escapes
     * % and _ and emits an explicit ESCAPE clause, because a backslash is
     * MySQL's default escape and means nothing to SQLite, and the same search
     * returned different rows in CI and in production before it existed.
     *
     * @return list<int>
     */
    private function ownerIds(string $kind, string $search): array
    {
        $query = self::MODELS[$kind]::query();

        SearchTerms::whereLike($query, 'name', $search);

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * One tile's worth of an image.
     *
     * @param  array<int, list<array{type: string, id: int, name: string, field: string}>>  $usage
     * @return array<string, mixed>
     */
    private function tile(Media $media, array $usage): array
    {
        $usage = $usage[(int) $media->id] ?? [];

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
            /*
             * The owners' NAMES, which verify() already computed and this
             * payload used to throw away.
             *
             * The tile printed the stored filename under the title — a string
             * like 20260916-054433-97xf6xZY.jpg, which the upload endpoint
             * generates and which tells the owner nothing they can act on.
             * The product, brand or category using the image is the thing they
             * actually recognise it by, and it is exact rather than guessed.
             *
             * Capped at three so one image used by forty products cannot turn
             * a tile into a wall of text; the count is already carried by
             * used_count and the full list is on the detail panel.
             */
            'used_names' => array_values(array_unique(array_map(
                static fn ($u) => (string) $u['name'],
                array_slice($usage, 0, 3),
            ))),
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Redirect;
use App\Services\Import\MediaAudit;
use App\Services\Import\MediaRewrite;
use App\Services\Import\RedirectMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Store → Import → "Addresses & pictures": the half of Phase 13 that has no
 * rows, driven from a browser because the owner has no shell.
 *
 * =============================================================================
 * WHY THIS EXISTS AT ALL, WHICH IS NOT "because a screen is nicer"
 * =============================================================================
 *
 * `kbb:import-redirects` and `kbb:import-media` were built, tested and written
 * up in the runbook, and **the owner of this shop cannot run either of them.**
 * There is no shell on the host. The runbook's §10 is a page of commands for a
 * person who has no way to type one. That is the whole gap: the URL map and the
 * media audit were not missing, they were unreachable.
 *
 * `ImportDriver` solved the same problem for the row import by doing one entity
 * per HTTP request. This does not need that machinery and deliberately does not
 * borrow it — see the next block.
 *
 * =============================================================================
 * WHY THERE IS NO STEP LOOP HERE, AND THE MEASUREMENT THAT SAYS SO
 * =============================================================================
 *
 * `ImportDriver` is built around a hard truth: 4,159 orders cannot be written
 * inside one request on a host whose `max_execution_time` cannot be discovered
 * from inside PHP. Neither of these two jobs is that shape.
 *
 *   THE URL MAP is O(categories). The shop's real export carries 59 of them
 *   (`docs/FV-IMPORT-AT-VOLUME.md` §2). The map is a handful of queries plus a
 *   route match per proposal.
 *
 *   THE MEDIA AUDIT is O(image references) — one `is_file()` each, about 2,600
 *   on this catalogue, answered from the kernel's dentry cache.
 *
 * A step loop for work of that size would be machinery with nothing to do, and
 * machinery with nothing to do is where the next resume bug lives. What this
 * does instead is state the cost honestly and put a `limit` on what it SENDS
 * back — the browser does not need 2,600 rows to draw a summary, and a 4MB JSON
 * response on a shared host is its own kind of timeout.
 *
 * If the catalogue ever grows to where this is not true, the thing that changes
 * is this comment and this class, not the two services behind it: both are pure
 * functions of the database and neither holds state between calls.
 *
 * =============================================================================
 * NOTHING HERE WRITES ON A GET
 * =============================================================================
 *
 * `status` and `map` are reads. The two endpoints that change the database are
 * POSTs with an explicit `action` in the body, for the reason
 * `routes/import-admin.php` gives at length: a GET that writes is fetched by a
 * link prefetcher, a browser history restore and the host's own cache warmer,
 * and each of those would re-point the catalogue's photographs with nobody
 * having asked.
 *
 * MOUNTING. `routes/urls-media-admin.php` says where. In short: inside the
 * existing `admin-api` group in `routes/web.php`, which carries `auth:admin`
 * and `NoStoreAdminApi`. `redirects` rows and image paths are not personal
 * data, but `apply` rewrites the catalogue, and an anonymous caller who can
 * re-point every product photograph on the shop is the same problem as one who
 * can import a product list.
 */
class UrlsMediaApiController extends Controller
{
    /** Rows returned to the browser per bucket. The counts are always complete. */
    private const SHOW = 50;

    public function __construct(
        private readonly RedirectMap $map = new RedirectMap,
        private readonly MediaAudit $audit = new MediaAudit,
        private readonly MediaRewrite $rewrite = new MediaRewrite,
    ) {}

    /**
     * Everything the screen draws itself from, in one call.
     *
     * One call rather than three, for the reason `ImportApiController::status()`
     * gives: a screen assembled from three requests can be half-fresh, and a
     * half-fresh screen about a destructive action is worse than a slow one.
     */
    public function status(): JsonResponse
    {
        $proposals = $this->map->propose();
        $diff = $this->map->diff($proposals);
        $media = $this->audit->audit();

        return response()->json([
            'ok' => true,
            'urls' => [
                'buckets' => $this->buckets($proposals),
                'diff' => [
                    'create' => count($diff['create']),
                    'update' => count($diff['update']),
                    'unchanged' => count($diff['unchanged']),
                    'conflict' => count($diff['conflict']),
                    'conflicts' => array_slice($diff['conflict'], 0, self::SHOW),
                ],
                /*
                 * Said on the screen, not only in a doc, because it is the
                 * single fact that decides whether any of this does anything:
                 * the redirects table is read from the 404 handler alone.
                 */
                'note' => 'A redirect only fires on an address that 404s. Rows whose address this shop already '
                    .'answers are in the discard and ask buckets with the reason on each one.',
            ],
            'media' => [
                'summary' => $this->audit->summarise($media),
                'hosts' => $this->rewrite->hostsSeen(),
                'remote' => array_slice(array_values(array_filter(
                    $media,
                    static fn (array $row): bool => $row['verdict'] === MediaAudit::REMOTE,
                )), 0, self::SHOW),
                'missing' => array_slice(array_values(array_filter(
                    $media,
                    static fn (array $row): bool => $row['verdict'] === MediaAudit::MISSING,
                )), 0, self::SHOW),
            ],
        ]);
    }

    /** The whole map, every bucket, as the spreadsheet the owner approves from. */
    public function map(): Response
    {
        $proposals = $this->map->propose();

        $handle = fopen('php://temp', 'w+b');

        fputcsv($handle, ['decision', 'subject', 'old address', 'new address', 'rule', 'why']);

        foreach ($proposals as $proposal) {
            fputcsv($handle, [
                $proposal['decision'],
                $proposal['subject'],
                $proposal['source'],
                $proposal['target'],
                $proposal['rule'],
                $proposal['reason'],
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="url-map.csv"',
        ]);
    }

    /**
     * Write the redirects the map proposes, or take them back out again.
     *
     * The write is the same one `kbb:import-redirects --write` makes, through
     * the same `diff()`: create what is missing, correct what this map wrote
     * before, and REFUSE to overrule a row an admin created by hand. That last
     * rule is why `conflict` is reported and not applied.
     */
    public function redirects(Request $request): JsonResponse
    {
        $request->validate(['action' => ['required', 'string', 'in:write,rollback']]);

        $proposals = $this->map->propose();

        if ($request->string('action')->toString() === 'rollback') {
            return response()->json(['ok' => true] + $this->rollback($proposals));
        }

        $diff = $this->map->diff($proposals);
        $written = 0;

        DB::transaction(function () use ($diff, &$written): void {
            foreach (array_merge($diff['create'], $diff['update']) as $proposal) {
                Redirect::query()->updateOrCreate(
                    ['source' => $proposal['source']],
                    [
                        'target' => $proposal['target'],
                        'code' => 301,
                        'enabled' => true,
                        'auto_created' => true,
                    ],
                );

                $written++;
            }
        });

        return response()->json([
            'ok' => true,
            'written' => $written,
            'conflict' => count($diff['conflict']),
            'conflicts' => array_slice($diff['conflict'], 0, self::SHOW),
        ]);
    }

    /**
     * Re-point the catalogue's photographs at this shop, or put them back.
     *
     * `hosts` is required and is not defaulted. See `MediaRewrite`: guessing
     * which host is the old shop is how a CDN or a supplier's photograph gets
     * rewritten into a local path that does not exist.
     */
    public function media(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'string', 'in:preview,apply,restore,rebase,rebase-apply'],
            /*
             * Not required for the two rebase actions, and required for the
             * other three. A host is what tells the rewrite which images are
             * the old shop's; a rebase involves no host at all, and demanding
             * one would mean inventing a value to satisfy a rule.
             */
            'hosts' => ['required_unless:action,rebase,rebase-apply', 'array', 'min:1', 'max:20'],
            'hosts.*' => ['string', 'max:253'],
        ]);

        /** @var list<string> $hosts */
        $hosts = array_values((array) $request->input('hosts', []));
        $action = $request->string('action')->toString();

        if ($action === 'rebase' || $action === 'rebase-apply') {
            $proposals = $this->rewrite->proposeRebase();

            return response()->json([
                'ok' => true,
                'summary' => $this->rewrite->summarise($proposals),
                'rewritten' => $action === 'rebase-apply' ? $this->rewrite->apply($proposals) : 0,
                'rows' => array_slice($proposals, 0, self::SHOW),
            ]);
        }

        if ($action === 'restore') {
            $restored = 0;
            $kept = [];

            foreach ($hosts as $host) {
                $result = $this->rewrite->restore((string) $host);
                $restored += $result['restored'];
                $kept = array_merge($kept, $result['kept']);
            }

            return response()->json([
                'ok' => true,
                'restored' => $restored,
                'kept' => array_slice($kept, 0, self::SHOW),
            ]);
        }

        $proposals = $this->rewrite->propose(array_map(strval(...), $hosts));
        $summary = $this->rewrite->summarise($proposals);

        if ($action === 'preview') {
            return response()->json([
                'ok' => true,
                'summary' => $summary,
                'rows' => array_slice($proposals, 0, self::SHOW),
            ]);
        }

        return response()->json([
            'ok' => true,
            'summary' => $summary,
            'rewritten' => $this->rewrite->apply($proposals),
            'absent' => array_slice(array_values(array_filter(
                $proposals,
                static fn (array $row): bool => $row['decision'] === MediaRewrite::ABSENT,
            )), 0, self::SHOW),
        ]);
    }

    /**
     * @param  list<array{source: string, target: string, decision: string}>  $proposals
     * @return array{removed: int, kept: list<string>}
     */
    private function rollback(array $proposals): array
    {
        $removed = 0;
        $kept = [];

        DB::transaction(function () use ($proposals, &$removed, &$kept): void {
            foreach ($proposals as $proposal) {
                if ($proposal['decision'] !== RedirectMap::MIGRATE) {
                    continue;
                }

                $existing = Redirect::query()->where('source', $proposal['source'])->first();

                if ($existing === null) {
                    continue;
                }

                if ((bool) $existing->auto_created === false) {
                    $kept[] = $proposal['source'].' — created by an admin, not by this map';

                    continue;
                }

                if ((string) $existing->target !== $proposal['target']) {
                    $kept[] = $proposal['source'].' — points at '.$existing->target.', not the '
                        .$proposal['target'].' this map writes, so somebody changed it';

                    continue;
                }

                $existing->delete();
                $removed++;
            }
        });

        return ['removed' => $removed, 'kept' => $kept];
    }

    /**
     * @param  list<array{decision: string}>  $proposals
     * @return array<string, array{count: int, rows: list<array<string, string>>}>
     */
    private function buckets(array $proposals): array
    {
        $out = [];

        foreach ([RedirectMap::MIGRATE, RedirectMap::ASK, RedirectMap::DISCARD] as $bucket) {
            $rows = array_values(array_filter(
                $proposals,
                static fn (array $p): bool => $p['decision'] === $bucket,
            ));

            $out[$bucket] = ['count' => count($rows), 'rows' => array_slice($rows, 0, self::SHOW)];
        }

        return $out;
    }
}

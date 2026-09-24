<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Redirect;
use App\Services\Import\DocumentMediaRewrite;
use App\Services\Import\MediaAudit;
use App\Services\Import\MediaIndex;
use App\Services\Import\MediaRewrite;
use App\Services\Import\RedirectDecisions;
use App\Services\Import\RedirectMap;
use App\Services\ImportConsole\ImportWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        /*
         * The other half of the same job. `posts.body` is an article, not a
         * column that holds one address, so its `<img>` tags are re-pointed by
         * a rewriter that edits documents — see DocumentMediaRewrite. Both run
         * from the one button, because "the pictures are off WordPress" is one
         * question and an answer that is true of products and false of the
         * Journal is not an answer.
         */
        private readonly DocumentMediaRewrite $journal = new DocumentMediaRewrite,
        private readonly ImportWorkspace $workspace = new ImportWorkspace,
        /*
         * The owner's answers to the map's questions. `RedirectMap::propose()`
         * already folds them in — every caller of the map sees the same
         * buckets — so this instance is only ever used to WRITE one.
         */
        private readonly RedirectDecisions $answers = new RedirectDecisions,
    ) {}

    /**
     * =========================================================================
     * THE ADDRESSES GROUP, WHICH IS WHY THE WORKSPACE IS IN HERE
     * =========================================================================
     *
     * `RedirectMap::fromPermalinks()` has read this exact shape since the day
     * it was written and NOTHING ON A SCREEN HAD EVER HANDED IT A FILE. The
     * only caller was `kbb:import-redirects --permalinks=…`, a command the
     * owner of this shop cannot run — the same gap this whole controller
     * exists to close, left open one level down.
     *
     * So the map was proposing addresses it had DERIVED (every category, at
     * both slash spellings, from this shop's own rows) and none of the ones
     * the old site actually published. `permalinks.csv` is the plugin's record
     * of every public URL that site serves, taken from inside WordPress with
     * every rewrite rule and every filter applied — which is the difference
     * between a map of where we think things were and a map of where they were.
     *
     * READ ON EVERY CALL, not held. This is the same rule
     * `ImportWorkspace::manifest()` follows: the file the owner uploaded thirty
     * seconds ago has to be the one that answers, and a map built from a stale
     * copy would write redirects for addresses a corrected re-export had
     * already removed.
     *
     * ALL THREE ENDPOINTS READ IT. `status` draws the buckets, `map.csv` is the
     * spreadsheet he approves from and `redirects` is what writes the rows, so
     * a version of this that fed the file to one of them would show him a map
     * and then write a different one.
     *
     * @return list<array<string, string>>
     */
    private function permalinks(): array
    {
        $out = [];

        foreach ($this->workspace->companionRows('permalinks') as $row) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Everything the screen draws itself from, in one call.
     *
     * One call rather than three, for the reason `ImportApiController::status()`
     * gives: a screen assembled from three requests can be half-fresh, and a
     * half-fresh screen about a destructive action is worse than a slow one.
     */
    public function status(): JsonResponse
    {
        $permalinks = $this->permalinks();
        $proposals = $this->map->propose($permalinks);
        $diff = $this->map->diff($proposals);
        $media = $this->audit->audit();
        $index = new MediaIndex($this->workspace->companionRows('media'));

        return response()->json([
            'ok' => true,
            /*
             * WHICH OF THE TWO COMPANION FILES THIS ANSWER WAS BUILT FROM.
             *
             * Said out loud because the failure it prevents is silent in both
             * directions. Without permalinks.csv this screen still draws a
             * perfectly confident map — of addresses derived from this shop's
             * own rows — and nothing distinguishes it from one built on the old
             * site's published URLs. The owner who downloaded the group, dropped
             * it in the box and got a refusal would have had no way to tell.
             */
            'sources' => $this->sources($permalinks, $index),
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
                /*
                 * THE ASK BUCKET, GROUPED INTO THE QUESTIONS IT IS ACTUALLY
                 * ASKING. See questions() below: a few hundred rows are a
                 * handful of questions asked a few hundred times, and this is
                 * what lets the screen offer one button per question instead of
                 * one checkbox per row.
                 */
                'questions' => $this->questions($proposals),
                'answers' => $this->answers($proposals),
                'note' => 'The redirects table is read before the router now, so a row here fires even on an '
                    .'address this shop already answers — which is why those are questions rather than '
                    .'discards. Every question below says what writing it would do; answer them in bulk or '
                    .'one at a time, and Undo puts any of them back.',
            ],
            'media' => [
                'summary' => $this->audit->summarise($media),
                'hosts' => $this->rewrite->hostsSeen(),
                /*
                 * The one thing media.csv knows and this shop cannot work out:
                 * the picture was already gone on WordPress when the export was
                 * taken. Copying wp-content across will not produce it and the
                 * sideloader will 404 on it forever, so `remote` never reaches
                 * zero and nothing says why.
                 */
                'gone_at_source' => $this->goneAtSource($media, $index),
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
        $proposals = $this->map->propose($this->permalinks());

        $handle = fopen('php://temp', 'w+b');

        /*
         * `question` and `your answer` are appended rather than inserted, so a
         * spreadsheet somebody already has open against the old shape still
         * reads every column it knew about at the position it knew it at.
         */
        fputcsv($handle, [
            'decision', 'subject', 'old address', 'new address', 'rule', 'why', 'question', 'your answer',
        ]);

        foreach ($proposals as $proposal) {
            fputcsv($handle, [
                $proposal['decision'],
                $proposal['subject'],
                $proposal['source'],
                $proposal['target'],
                $proposal['rule'],
                $proposal['reason'],
                (string) ($proposal['question'] ?? ''),
                (string) ($proposal['answered'] ?? ''),
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

        $proposals = $this->map->propose($this->permalinks());

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
            $journalRestored = 0;
            $journalDocuments = 0;

            foreach ($hosts as $host) {
                $result = $this->rewrite->restore((string) $host);
                $restored += $result['restored'];
                $kept = array_merge($kept, $result['kept']);

                $article = $this->journal->restore((string) $host);
                $journalRestored += $article['restored'];
                $journalDocuments += $article['documents'];
            }

            return response()->json([
                'ok' => true,
                'restored' => $restored,
                'journal' => ['restored' => $journalRestored, 'articles' => $journalDocuments],
                'kept' => array_slice($kept, 0, self::SHOW),
            ]);
        }

        $proposals = $this->rewrite->propose(array_map(strval(...), $hosts));
        $summary = $this->rewrite->summarise($proposals);

        $journal = $this->journal->propose(array_map(strval(...), $hosts));
        $journalSummary = $this->journal->summarise($journal);

        if ($action === 'preview') {
            return response()->json([
                'ok' => true,
                'summary' => $summary,
                'rows' => array_slice($proposals, 0, self::SHOW),
                /*
                 * REPORTED SEPARATELY AND NOT SUMMED INTO `summary`. The two
                 * counts answer different questions — "how many rows" and "how
                 * many pictures inside how many articles" — and a single number
                 * over both would be a number with no unit.
                 */
                'journal' => ['summary' => $journalSummary, 'rows' => array_slice($journal, 0, self::SHOW)],
            ]);
        }

        return response()->json([
            'ok' => true,
            'summary' => $summary,
            'rewritten' => $this->rewrite->apply($proposals),
            'journal' => [
                'summary' => $journalSummary,
                'articles' => $this->journal->apply($journal),
                'absent' => array_slice(array_values(array_filter(
                    $journal,
                    static fn (array $row): bool => $row['decision'] === DocumentMediaRewrite::ABSENT,
                )), 0, self::SHOW),
            ],
            'absent' => array_slice(array_values(array_filter(
                $proposals,
                static fn (array $row): bool => $row['decision'] === MediaRewrite::ABSENT,
            )), 0, self::SHOW),
        ]);
    }

    /**
     * What the two companion files contributed, and what is missing without
     * them.
     *
     * @param  list<array<string, string>>  $permalinks
     * @return array<string, array<string, mixed>>
     */
    private function sources(array $permalinks, MediaIndex $index): array
    {
        return [
            'permalinks' => [
                'file' => 'permalinks.csv',
                'present' => $permalinks !== [],
                'rows' => count($permalinks),
                'note' => $permalinks === []
                    ? 'Every address below was worked out from this shop\'s own rows. Upload permalinks.csv '
                        .'from the "Addresses and pictures" download and this becomes the list of addresses the '
                        .'old site really published, rather than the list of the ones we can derive.'
                    : count($permalinks).' address(es) the old site published, read from the export.',
            ],
            'media' => [
                'file' => 'media.csv',
                'present' => ! $index->isEmpty(),
                'rows' => $index->read(),
                'pictures' => $index->pictures(),
                'note' => $index->isEmpty()
                    ? 'Without media.csv there is no way to tell a picture that has not been copied across yet '
                        .'from one the old site had already lost.'
                    : $index->pictures().' picture(s) indexed; '.count($index->goneAtSource())
                        .' of them were already gone on the old site when the export was taken.',
            ],
        ];
    }

    /**
     * The catalogue's own image references that media.csv says were already
     * missing on the old site.
     *
     * Only the ones this shop is still waiting for. A picture that is PRESENT
     * here has arrived, whatever the old site's disk looked like on the day of
     * the export, and reporting it would be reporting a problem that has been
     * solved.
     *
     * @param  list<array{owner: string, field: string, url: string, path: string, verdict: string, decision: string, reason: string}>  $media
     * @return array{count: int, rows: list<array<string, string>>}
     */
    private function goneAtSource(array $media, MediaIndex $index): array
    {
        $rows = [];

        foreach ($media as $row) {
            if ($row['verdict'] === MediaAudit::PRESENT) {
                continue;
            }

            if ($index->existedAtSource($row['url']) !== false) {
                continue;
            }

            $rows[] = [
                'owner' => $row['owner'],
                'field' => $row['field'],
                'url' => $row['url'],
                'reason' => 'the export recorded this file as already missing on the old site, so copying '
                    .'wp-content across will not produce it and the sideloader will keep getting a 404. '
                    .'This picture has to be replaced, not moved.',
            ];
        }

        return ['count' => count($rows), 'rows' => array_slice($rows, 0, self::SHOW)];
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
     * Answer a question the map is asking, one row or a whole question at a
     * time.
     *
     * =========================================================================
     * WHY THIS ENDPOINT EXISTS
     * =========================================================================
     *
     * Phase 13's "Rafi approves any discard list" has been open since the map
     * was written, and the reason is not that nobody built a screen: there was
     * nowhere to PUT an approval. The ask bucket was re-derived on every load
     * and came back identical for ever, so the only way to act on a row was to
     * retype it on Store → Redirects. Then the bucket grew — see
     * `RedirectDecisions`' class comment and docs/GP-ADDRESSES-LAND.md §13.7.
     *
     * =========================================================================
     * EVERYTHING IT ACTS ON IS DERIVED HERE, NOT SENT
     * =========================================================================
     *
     * The request names ADDRESSES, or a question code. It never names a
     * destination. The map is re-derived on this request and an address that is
     * not a question in it is refused by name, so the worst a caller can do is
     * approve a redirect this shop was already proposing to write — which is
     * what the button says it does. Taking a target from the body would make
     * this an endpoint for pointing `/shop/` anywhere at all, behind a label
     * that says "approve".
     *
     * `routes/urls-media-admin.php` mounts it inside the `admin-api` group, so
     * it carries `auth:admin` and `NoStoreAdminApi`, and
     * `AdminCapabilities::RULES` already covers `admin-api/urls-media/**` with
     * `data.import` — the capability fails closed for anyone else.
     *
     * Store → Import → Addresses & pictures → Old addresses · Questions.
     */
    public function decisions(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'string', 'in:accept,reject,clear'],
            'question' => ['nullable', 'string', Rule::in(array_keys(RedirectMap::QUESTIONS))],
            /*
             * 191 is `redirect_decisions.source`'s width. An address longer
             * than that could not be stored in `redirects.source` either, so it
             * is refused here rather than silently truncated into an answer
             * about a different address.
             */
            'sources' => ['nullable', 'array', 'max:5000'],
            'sources.*' => ['string', 'max:191'],
        ]);

        $action = $request->string('action')->toString();
        $question = trim((string) $request->input('question', ''));
        /** @var list<string> $sources */
        $sources = array_values(array_unique(array_map(strval(...), (array) $request->input('sources', []))));

        if ($sources === [] && $question === '') {
            return response()->json([
                'ok' => false,
                'error' => 'Name either some addresses or one question. Answering everything at once is not a '
                    .'thing this offers, because the questions are not all the same question.',
            ], 422);
        }

        /*
         * A CLEAR BY QUESTION IS READ OFF THE STORED ANSWERS, not off the map.
         * "Undo all of these" has to reach an answer to a row the map has since
         * stopped proposing — those are precisely the answers nothing else can
         * get at.
         */
        if ($action === 'clear') {
            $removed = $question !== ''
                ? $this->answers->clearQuestion($question)
                : 0;

            $removed += $sources === [] ? 0 : $this->answers->clear($sources);

            return response()->json(['ok' => true, 'cleared' => $removed, 'refused' => []]);
        }

        $proposals = $this->map->propose($this->permalinks());

        if ($question !== '') {
            /*
             * The bulk path resolves to addresses HERE, off this request's map.
             * An accept over a question whose rows cannot be accepted resolves
             * to nothing rather than to a refusal per row: "accept all" on a
             * question where that is not possible is a button the screen does
             * not draw, and a refusal list three hundred lines long would be
             * the noise this whole grouping exists to remove.
             */
            foreach ($proposals as $proposal) {
                if ((string) ($proposal['question'] ?? '') !== $question) {
                    continue;
                }

                if ($proposal['decision'] !== RedirectMap::ASK) {
                    continue;
                }

                if ($action === RedirectDecisions::ACCEPT && ! RedirectMap::decidable($proposal)) {
                    continue;
                }

                $sources[] = (string) $proposal['source'];
            }

            $sources = array_values(array_unique($sources));
        }

        $result = $this->answers->record($proposals, $action, $sources, $this->who($request));

        return response()->json([
            'ok' => true,
            'recorded' => $result['recorded'],
            'refused' => array_slice($result['refused'], 0, self::SHOW),
        ]);
    }

    /**
     * Who is answering, for the record kept beside the answer.
     *
     * The email as it is now, denormalised — `audit_events` states the
     * reasoning and it holds here: the answer has to outlive the account, and
     * an answer that renders blank because an admin was deleted is an answer
     * nobody can trace.
     */
    private function who(Request $request): ?string
    {
        $admin = $request->user('admin');

        return is_object($admin) && isset($admin->email) ? (string) $admin->email : null;
    }

    /**
     * The ask bucket, grouped into the questions it is actually asking.
     *
     * =========================================================================
     * WHY GROUPED, WHICH IS THE WHOLE POINT OF THIS SCREEN
     * =========================================================================
     *
     * The bucket is a few hundred rows on a real export and they are NOT a few
     * hundred different questions: they are a handful of questions asked a few
     * hundred times, and the owner's answer to "this address still answers on
     * this shop — should the old address win?" is the same answer for every
     * category in the list. Grouping is what turns an unreadable list into
     * eight decisions, and `docs/FV-IMPORT-AT-VOLUME.md` §10 is explicit that a
     * question list which is mostly noise is one nobody finishes.
     *
     * The ORDER is `RedirectMap::QUESTIONS`' own, which puts the questions that
     * are a decision first and the ones that are somebody else's job last — a
     * count-descending order would move the work about between loads.
     *
     * A STALE ROW IS ASKING AGAIN and is counted in `asking`, as well as being
     * counted in `stale` so the screen can say why it came back.
     *
     * @param  list<array<string, mixed>>  $proposals
     * @return list<array<string, mixed>>
     */
    private function questions(array $proposals): array
    {
        $groups = [];

        foreach ($proposals as $proposal) {
            $code = (string) ($proposal['question'] ?? '');

            if ($code === '' || ! isset(RedirectMap::QUESTIONS[$code])) {
                continue;
            }

            $groups[$code] ??= [
                'question' => $code,
                'heading' => RedirectMap::QUESTIONS[$code]['heading'],
                'decidable' => RedirectMap::QUESTIONS[$code]['decidable'],
                'asking' => 0,
                'accepted' => 0,
                'rejected' => 0,
                'stale' => 0,
                'rows' => [],
            ];

            $answered = (string) ($proposal['answered'] ?? '');

            if ($answered === RedirectDecisions::ACCEPT) {
                $groups[$code]['accepted']++;

                continue;
            }

            if ($answered === RedirectDecisions::REJECT) {
                $groups[$code]['rejected']++;

                continue;
            }

            if ($answered === RedirectDecisions::STALE) {
                $groups[$code]['stale']++;
            }

            if ($proposal['decision'] !== RedirectMap::ASK) {
                continue;
            }

            $groups[$code]['asking']++;

            if (count($groups[$code]['rows']) < self::SHOW) {
                $groups[$code]['rows'][] = $proposal;
            }
        }

        $out = [];

        foreach (array_keys(RedirectMap::QUESTIONS) as $code) {
            if (isset($groups[$code])) {
                $out[] = $groups[$code];
            }
        }

        return $out;
    }

    /**
     * How much of the ask bucket has been answered, over all of it.
     *
     * The number that says whether the work list is shrinking, which is the one
     * thing a per-question count cannot say on its own.
     *
     * @param  list<array<string, mixed>>  $proposals
     * @return array{accepted: int, rejected: int, stale: int, asking: int}
     */
    private function answers(array $proposals): array
    {
        $out = ['accepted' => 0, 'rejected' => 0, 'stale' => 0, 'asking' => 0];

        foreach ($proposals as $proposal) {
            $answered = (string) ($proposal['answered'] ?? '');

            if ($answered === RedirectDecisions::ACCEPT) {
                $out['accepted']++;
            } elseif ($answered === RedirectDecisions::REJECT) {
                $out['rejected']++;
            } elseif ($answered === RedirectDecisions::STALE) {
                $out['stale']++;
            }

            if ($proposal['decision'] === RedirectMap::ASK) {
                $out['asking']++;
            }
        }

        return $out;
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

<?php

declare(strict_types=1);

namespace App\Services\ImportConsole;

use App\Services\Import\EntityReport;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drives the existing WooCommerce importer from a browser, one slice per HTTP
 * request.
 *
 * =============================================================================
 * THE TIMEOUT IS THE DESIGN PROBLEM. EVERYTHING BELOW IS AN ANSWER TO IT.
 * =============================================================================
 *
 * The owner has no shell. The import has to happen inside PHP-FPM requests on
 * shared hosting, which means a hard ceiling — max_execution_time,
 * request_terminate_timeout, or the proxy in front of them — that this code
 * cannot raise and cannot detect. There is NO QUEUE WORKER on this host, so
 * "dispatch a job" is not available either. A run therefore has to be made of
 * many short requests, and the only question that matters is what is true when
 * one of them is killed halfway.
 *
 * The importer already answers that. Each batch's rows and the advance of its
 * checkpoint commit in ONE transaction, so `import_checkpoints.processed` can
 * never describe work that was rolled back, and re-running the same options
 * continues from the last committed batch. This class does not re-implement any
 * of that. It calls ImportRunner with `limit` — which the runbook is explicit
 * is a RESUME POINT and not a truncation — and lets the checkpoints do the
 * remembering.
 *
 * So one browser step is:
 *
 *     ImportRunner::run(only: [one entity], limit: a few hundred rows)
 *
 * and the next step is the identical call. If the request is killed, the batches
 * that committed stay committed, the checkpoint matches them exactly, and the
 * next step picks up at the row after the last committed one. Nothing is
 * restarted and nothing is duplicated — the ONLY way to duplicate a row here
 * would be to insert it without its external id, and every importer refuses a
 * row that has none.
 *
 * ONE ENTITY PER STEP, not `limit` across all six, and that is not arbitrary:
 * `--only` filters the entity list but never reorders it, so stepping entity by
 * entity in the runner's own order still runs customers before orders. The
 * runbook's one genuinely dangerous sequence — orders imported before the
 * customers they name, which synthesises guest rows that then collide with the
 * real users — cannot be produced by this screen, because `currentEntity()`
 * walks ImportRunner::entityNames() in order and never skips forward.
 *
 * -----------------------------------------------------------------------------
 * THE PREVIEW IS DIFFERENT, AND THE DIFFERENCE IS NOT A BUG
 * -----------------------------------------------------------------------------
 * A dry run writes everything and rolls it all back inside ONE transaction —
 * that is the only way it can resolve an order's customer and a line's order
 * and tell the truth about foreign keys. A transaction cannot span two HTTP
 * requests, and a dry run deliberately advances no checkpoint, so there is
 * nothing to resume from. A preview therefore CANNOT be continued; it can only
 * be redone.
 *
 * What this class does instead is grow the slice. Step one previews the first
 * N rows of each file; step two previews the first 2N, from the top, in a fresh
 * transaction; and so on until the slice covers every file. Every step is a
 * complete, internally consistent preview of a prefix of the data — so a step
 * that gets killed costs nothing at all (nothing was written, and the previous
 * step's answer is still on screen and still true for its prefix), and the
 * owner watches the previewed depth climb rather than watching a spinner that
 * might never come back.
 *
 * The honest limitation, which the screen states in words: the LAST preview
 * step is the whole file in one request, so on a very large export the preview
 * can stall at a depth short of the end. That is survivable and the real import
 * is not blocked by it, because the real import resumes and the preview does
 * not. Rejections are overwhelmingly systematic — a decimal comma, a missing
 * column, a three-letter country code — so a preview of the first several
 * thousand rows finds them.
 *
 * -----------------------------------------------------------------------------
 * WHERE THE NUMBERS COME FROM
 * -----------------------------------------------------------------------------
 * For a live run the counts on screen are read from `import_checkpoints`, NOT
 * from the report objects this class accumulates. The checkpoint counters were
 * written inside the same transaction as the rows they count, so they survive a
 * killed request exactly; an in-memory tally does not, and a screen that
 * under-reports after a timeout is a screen that makes the owner re-run
 * something that already happened.
 *
 * Refusal DETAIL — line, id, reason — only exists in memory, so it is appended
 * to a CSV at the end of each step. A step killed before it finishes loses the
 * reasons for that slice but not the rows, which is why the step is small.
 *
 * `created_rows` and friends used not to be zeroed when a FINISHED entity was
 * re-run (only `--restart` zeroed them), so a second pass would otherwise show
 * the first pass's numbers added to its own. Each entity's counters are
 * therefore baselined the first time this run touches them — except when the
 * entity is mid-resume, where the earlier numbers belong to the same
 * interrupted import and are kept.
 *
 * SINCE LANE GJ THEY ARE ZEROED AT THE SOURCE. Checkpoint::open() zeroes the
 * four counters wherever it zeroes `processed`, because
 * EntityReport::verification() reads `rejected_rows` to reach a verdict on a
 * resumed run and a stale refusal in it understates the rows the table should
 * hold — turning a shortfall into "verified" on the delta import. So
 * baselineFor() returns zero for a finished entity, and the baseline is no
 * longer what undoes the carry-over there; it is kept for a checkpoint written
 * by the code that came before, which is what the owner's database holds
 * today.
 *
 * -----------------------------------------------------------------------------
 * ONE RUNNER AT A TIME
 * -----------------------------------------------------------------------------
 * Two browser tabs stepping at once would be worse than wasteful. Both would
 * read `processed = 100`, both would import rows 101-200, and both would
 * advance by 100 — leaving the checkpoint at 300 with rows 201-300 never read.
 * The run row is claimed with a conditional UPDATE before any work starts, and
 * the claim goes stale on its own after LOCK_SECONDS so a killed request does
 * not wedge the screen forever.
 *
 * No aggregate SQL is issued anywhere in this class. The checkpoint table holds
 * at most six rows for a run key, so the totals are summed in PHP — which side-
 * steps the aggregate-plus-bare-column defect App\Support\AggregatesQueries
 * exists for, rather than relying on getting it right a third time.
 */
final class ImportDriver
{
    public const TABLE = 'import_runs';

    /**
     * The checkpoint key this screen owns.
     *
     * Deliberately not 'default': someone running `php artisan kbb:import`
     * against a restored copy should not resume into, or be resumed into by,
     * whatever the admin screen last did.
     */
    public const RUN_KEY = 'admin';

    public const DEFAULT_STEP_ROWS = 400;

    /**
     * One row is a legitimate slice, not a guard failure.
     *
     * The screen never asks for it — its slider starts at 50 — but a row that
     * kills the request outright (a 40MB product description, a value that
     * exhausts memory_limit) can only be found by walking up to it one row at a
     * time, and that is a thing the owner has to be able to do without a shell.
     */
    public const MIN_STEP_ROWS = 1;

    public const MAX_STEP_ROWS = 25000;

    /**
     * Rows per committed transaction, and therefore how much a killed request
     * can cost. Smaller than the command's 500 default because the command is
     * killed by a person and this is killed by a timeout nobody sees.
     */
    public const BATCH_ROWS = 100;

    /** How long a claim survives a process that died holding it. */
    public const LOCK_SECONDS = 300;

    /** Refusals kept inline in the status payload; the CSV always has all of them. */
    private const INLINE_REJECTIONS = 200;

    /**
     * How the runner's count-based verification note begins.
     *
     * Matched rather than re-derived so that the screen and the console are
     * reading the same string; see mergeNotes() for why this one note is
     * treated differently from every other.
     */
    public const VERIFICATION_PREFIX = EntityReport::VERIFICATION_NOTE_PREFIX;

    public function __construct(
        private readonly ImportWorkspace $workspace = new ImportWorkspace,
        private readonly ImportLedger $ledger = new ImportLedger,
    ) {}

    /* ------------------------------------------------------------ the run */

    public function run(): ?object
    {
        return DB::table(self::TABLE)->where('run_key', self::RUN_KEY)->first();
    }

    /**
     * Begin a preview or a live run.
     *
     * @param  array<string, mixed>  $options
     */
    public function start(string $mode, array $options): object
    {
        $mode = $mode === 'preview' ? 'preview' : 'live';
        $settled = $this->settleOptions($options);

        $present = $this->presentEntities();

        if ($present === []) {
            throw new ImportDriverRefused(
                'There are no files to import yet. Upload at least one export above.'
            );
        }

        $existing = $this->run();

        if ($existing !== null && $existing->status === 'running' && ! (bool) ($options['force'] ?? false)) {
            throw new ImportDriverRefused(
                'A '.$existing->mode.' is already part-way through. Continue it, or stop it first.'
            );
        }

        $manifest = $this->workspace->manifest();

        /*
         * A MANIFEST THIS SHOP CANNOT READ STOPS THE IMPORT, and it stops a
         * preview too.
         *
         * The contract (docs/WP-EXPORT-CONTRACT.md) says `format` is checked and
         * anything else "is refused with a sentence naming what was found, not a
         * stack trace". The reason it is a refusal rather than a shrug is that
         * a manifest written to a format this shop does not speak describes a
         * FILE SET this shop may not read correctly either — the counts it would
         * be trusted for, the digests the duplicate guard would match on, and
         * the column names the importers parse all come from the same plugin.
         *
         * The escape hatch is one press and is named in the sentence: remove
         * manifest.json, and the import runs exactly as every export before the
         * plugin existed ran. Overriding it with a tick box was considered and
         * rejected — "ignore the thing you do not understand" is not a decision
         * anybody can make from this screen.
         */
        if ($manifest->refusal() !== null) {
            throw new ImportDriverRefused($manifest->refusal());
        }

        $verdict = $this->ledger->verdict($manifest, $this->presentFiles($manifest));

        /*
         * ONLY A LIVE RUN IS BLOCKED. A preview writes nothing and rolls itself
         * back, so refusing to LOOK at an export because it is already imported
         * would refuse the one operation that costs nothing — and "I am not sure
         * what is in this file" is the usual reason for pressing it. The verdict
         * is computed and returned for a preview all the same, so the screen
         * still says what it found.
         */
        if ($mode === 'live' && $verdict['blocking'] && ! (bool) ($options['confirm_duplicate'] ?? false)) {
            throw new ImportDriverRefused($verdict['sentence']);
        }

        $this->clearRejects($mode);

        $row = [
            'run_key' => self::RUN_KEY,
            'mode' => $mode,
            'status' => 'running',
            'options' => (string) json_encode($settled),
            'started_entities' => '[]',
            'done_entities' => '[]',
            'baselines' => '{}',
            'notes' => '{}',
            'preview_limit' => 0,
            'restart' => (bool) $settled['restart'],
            'message' => null,
            'report' => null,
            'locked_at' => null,
            'lock_token' => null,
            'started_at' => now(),
            'finished_at' => null,
            'updated_at' => now(),
        ];

        /*
         * The manifest is SNAPSHOTTED ONTO THE RUN, not re-read on every step.
         * A manifest.json replaced halfway through an import would otherwise
         * attach a different export id to the entities that came after it, and
         * the history would then claim rows were imported from an export they
         * were not imported from.
         */
        if ($this->runsCarry('run_uid')) {
            $row['run_uid'] = bin2hex(random_bytes(8));
        }

        if ($this->runsCarry('manifest')) {
            $row['manifest'] = $manifest->usable() ? (string) json_encode($manifest->snapshot()) : null;
        }

        if ($existing === null) {
            $row['created_at'] = now();
            DB::table(self::TABLE)->insert($row);
        } else {
            DB::table(self::TABLE)->where('id', $existing->id)->update($row);
        }

        return (object) $this->run();
    }

    public function stop(): void
    {
        $run = $this->run();

        if ($run === null) {
            return;
        }

        DB::table(self::TABLE)->where('id', $run->id)->update([
            'status' => 'stopped',
            'locked_at' => null,
            'lock_token' => null,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Do one slice of work.
     *
     * @return array<string, mixed>
     *
     * @throws ImportDriverRefused
     */
    public function step(int $rows): array
    {
        $run = $this->run();

        if ($run === null || $run->status !== 'running') {
            throw new ImportDriverRefused('Nothing is running. Start a preview or an import first.');
        }

        $rows = max(self::MIN_STEP_ROWS, min(self::MAX_STEP_ROWS, $rows));
        $token = bin2hex(random_bytes(8));

        $claimed = DB::table(self::TABLE)
            ->where('id', $run->id)
            ->where(function ($q) {
                $q->whereNull('locked_at')->orWhere('locked_at', '<', now()->subSeconds(self::LOCK_SECONDS));
            })
            ->update(['locked_at' => now(), 'lock_token' => $token, 'updated_at' => now()]);

        if ($claimed !== 1) {
            throw new ImportDriverRefused(
                'This import is already being worked on — another browser tab, or a step that has not '
                .'come back yet. Wait for it rather than starting a second one.'
            );
        }

        $began = microtime(true);

        try {
            $result = $run->mode === 'preview'
                ? $this->previewStep($run, $rows)
                : $this->liveStep($run, $rows);
        } catch (\RuntimeException $e) {
            /*
             * Checkpoint::open raises this, and only this, when the file behind
             * a part-finished entity is not the file the offset was recorded
             * against. It is the design working, not a crash: resuming by
             * position into a re-exported file would skip rows that are no
             * longer the ones already imported. The screen turns it into a
             * "start this file from row one" button rather than a red error,
             * because starting over is cheap — every write is an updateOrCreate
             * on an external id, so a redone row reports as unchanged.
             */
            $changed = str_contains($e->getMessage(), 'has changed since this run last stopped');

            $this->note($run->id, $this->firstLine($e->getMessage()));
            $result = ['ok' => false, 'needs_restart' => $changed, 'message' => $this->firstLine($e->getMessage())];
        } catch (\Throwable $e) {
            // Not swallowed and not fatal: the run stays resumable, and the
            // owner is told what the server said rather than watching a bar
            // that stopped moving.
            $this->note($run->id, $this->firstLine($e->getMessage()));
            $result = ['ok' => false, 'message' => $this->firstLine($e->getMessage())];
        } finally {
            DB::table(self::TABLE)
                ->where('id', $run->id)
                ->where('lock_token', $token)
                ->update(['locked_at' => null, 'lock_token' => null, 'updated_at' => now()]);
        }

        $result['elapsed_ms'] = (int) round((microtime(true) - $began) * 1000);
        $result['rows_asked'] = $rows;

        return $result;
    }

    /* ------------------------------------------------------------- status */

    /** @return array<string, mixed> */
    public function status(): array
    {
        $run = $this->run();
        $files = $this->workspace->files();
        $checkpoints = $this->checkpoints();

        /*
         * THE MANIFEST APPEARS IN THE FILE LIST, as a tenth card among the nine
         * entities, and that is not decoration.
         *
         * The refusal the owner sees when a manifest cannot be read tells him to
         * REMOVE IT — that is the whole escape hatch, and there is no other. The
         * console draws one card per row of this list, with a Remove button that
         * posts the row's `entity`, and `forget` answers to 'manifest'. Without
         * this row the instruction in the sentence would name a button that does
         * not exist on any screen he can reach.
         *
         * `rows` is the number of files the manifest describes, which is the
         * only count it has; the card's own label says so.
         */
        $manifestOnDisk = $this->workspace->manifest();

        $files[] = [
            'entity' => 'manifest',
            'label' => 'Export manifest',
            'file' => ImportManifest::FILE,
            'help' => 'Optional, and written by the export plugin. It says how many rows each file should '
                .'hold, which is what gives the progress bars a total that can be checked, and it identifies '
                .'the export so this shop can tell you when you are importing one it has already read. '
                .'Everything imports without it.',
            'id_columns' => [],
            'present' => $this->workspace->hasManifest(),
            'bytes' => is_file($this->workspace->manifestPath()) ? (int) filesize($this->workspace->manifestPath()) : 0,
            'rows' => count($manifestOnDisk->files()),
            'fingerprint' => null,
            'uploaded_at' => is_file($this->workspace->manifestPath())
                ? gmdate('c', (int) filemtime($this->workspace->manifestPath()))
                : null,
        ];

        $entities = [];
        $order = ImportRunner::entityNames();

        $manifest = $this->manifestFor($run);
        $onDisk = $manifestOnDisk;

        $barred = 0;
        $barTotal = 0;
        $barDone = 0;
        $everyDenominator = true;

        $baselines = $run !== null ? $this->decode($run->baselines) : [];
        $done = $run !== null ? $this->decode($run->done_entities) : [];
        $notes = $run !== null ? $this->decode($run->notes) : [];
        $preview = $run !== null && $run->mode === 'preview' ? $this->decode((string) $run->report) : [];

        foreach ($order as $entity) {
            $total = $this->workspace->rowCount($entity);
            $present = $this->workspace->has($entity);
            $cp = $checkpoints[$entity] ?? null;
            $base = $baselines[$entity] ?? ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0];

            $live = [
                'created' => $cp !== null ? max(0, (int) $cp->created_rows - (int) $base['created']) : 0,
                'updated' => $cp !== null ? max(0, (int) $cp->updated_rows - (int) $base['updated']) : 0,
                'unchanged' => $cp !== null ? max(0, (int) $cp->unchanged_rows - (int) $base['unchanged']) : 0,
                'rejected' => $cp !== null ? max(0, (int) $cp->rejected_rows - (int) $base['rejected']) : 0,
            ];

            $counts = $run !== null && $run->mode === 'preview'
                ? ($preview['entities'][$entity] ?? ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0])
                : $live;

            $processed = $cp !== null ? (int) $cp->processed : 0;

            $sourceChanged = $present
                && $cp !== null
                && $cp->finished_at === null
                && (int) $cp->processed > 0
                && $cp->source_fingerprint !== null
                && $cp->source_fingerprint !== $this->workspace->fingerprint($entity);

            $denominator = $this->denominator($entity, $manifest);

            /*
             * WHEN THERE IS A BAR, AND WHEN THERE IS DELIBERATELY NOT ONE.
             *
             * A percentage is drawn only when every one of these is true, and
             * each of them is a way the number would otherwise be a lie:
             *
             *   the file is here            — otherwise `processed` is about a
             *                                 file that is no longer on disk;
             *   a denominator exists        — Lane GD's 0/0 full green bar. A
             *                                 manifest that DISAGREES with the
             *                                 file is folded into this one:
             *                                 denominator() returns null rows
             *                                 for a disputed count, and it is
             *                                 the single place that decides.
             *                                 A second `mismatch === null`
             *                                 here read as belt and braces and
             *                                 was really dead text — a
             *                                 mutation that removed it stayed
             *                                 green, which is the shape
             *                                 Api\ProductController's dead
             *                                 status filter already cost this
             *                                 repository once;
             *   the denominator is not 0    — a header-only file is a real and
             *                                 legitimate export, and 0 of 0 is
             *                                 not a proportion;
             *   the checkpoint is about
             *   THIS file                   — `processed` counted rows of the
             *                                 file that was there before it was
             *                                 replaced, so processed/rows is
             *                                 two different files' arithmetic.
             */
            $percent = null;

            if ($present
                && $denominator['rows'] !== null
                && $denominator['rows'] > 0
                && ! $sourceChanged
            ) {
                $percent = (int) min(100, (int) round(100 * $processed / $denominator['rows']));

                $barred++;
                $barTotal += $denominator['rows'];
                $barDone += min($processed, $denominator['rows']);
            } elseif ($present) {
                $everyDenominator = false;
            }

            $entities[] = [
                'entity' => $entity,
                'label' => ImportWorkspace::meta($entity)['label'],
                'present' => $present,
                'rows_total' => $total,
                // The denominator and where it came from, so the screen can say
                // so rather than presenting every number as equally certain.
                'rows_expected' => $denominator['expected'],
                'rows_counted' => $denominator['counted'],
                'denominator' => $denominator['rows'],
                'denominator_source' => $denominator['source'],
                'rows_mismatch' => $denominator['mismatch'],
                'percent' => $percent,
                'in_manifest' => $manifest->usable()
                    ? $manifest->lists(ImportWorkspace::meta($entity)['file'])
                    : null,
                'processed' => $processed,
                'finished' => $cp !== null && $cp->finished_at !== null,
                'done_this_run' => in_array($entity, $done, true),
                'created' => (int) ($counts['created'] ?? 0),
                'updated' => (int) ($counts['updated'] ?? 0),
                'unchanged' => (int) ($counts['unchanged'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
                'notes' => $notes[$entity] ?? ($preview['notes'][$entity] ?? []),
                'source_changed' => $sourceChanged,
            ];
        }

        $rejects = $this->rejectionsInline($run?->mode ?? 'preview');

        /*
         * THE WHOLE-EXPORT BAR, which nothing had before a manifest existed.
         *
         * It is drawn only when EVERY present entity has a trustworthy
         * denominator of its own. One file short is not a smaller total, it is
         * a total that is wrong in the flattering direction: the bar would run
         * ahead of the work and settle at 100% with a whole entity still to
         * come. Nine separate honest bars beat one dishonest one.
         */
        $overall = [
            'done' => $barDone,
            'total' => $everyDenominator && $barred > 0 ? $barTotal : null,
            'percent' => $everyDenominator && $barred > 0 && $barTotal > 0
                ? (int) min(100, (int) round(100 * $barDone / $barTotal))
                : null,
            'files' => $barred,
            'why_no_bar' => $everyDenominator && $barred > 0
                ? null
                : ($barred === 0
                    ? 'No file here has a row count that can be believed yet.'
                    : 'One or more files have no row count that can be believed, so there is no total for '
                        .'the whole export. Each file that does have one has its own bar.'),
        ];

        $verdict = $this->ledger->verdict($onDisk, $this->presentFiles($onDisk));

        return [
            'ok' => true,
            'files' => $files,
            'entities' => $entities,
            'overall' => $overall,
            'manifest' => [
                'present' => $onDisk->present(),
                'usable' => $onDisk->usable(),
                'refusal' => $onDisk->refusal(),
                'export_id' => $onDisk->exportId(),
                'generated_at' => $onDisk->generatedAt(),
                'source' => $onDisk->source(),
                'label' => $onDisk->label(),
                'files' => $onDisk->files(),
                /*
                 * WHICH GROUPS THIS EXPORT CARRIES, and the one claim in it
                 * that nothing verified.
                 *
                 * Lane GK's export screen lets the owner tick named groups, and
                 * Lane GL downloads each as its own zip. `groups.selected` and
                 * `groups.skipped` are facts about the export. `assumed_already_imported`
                 * is not: it is what the operator ASSERTED on the WordPress
                 * screen — that a prerequisite group is already in this shop —
                 * and GK is explicit that the plugin cannot see this shop and
                 * did not check it.
                 *
                 * It goes out with the status because the person pressing
                 * Import is standing in front of the only shop that can answer
                 * it. GK's one `loses` edge — orders exported without customers,
                 * 14 of 80 customers lost on the measured rehearsal — is damage
                 * whose REPORT appears weeks later at a different button press,
                 * with nothing in it naming the export that caused it. Printing
                 * the claim here is the last moment the connection costs
                 * nothing to make.
                 *
                 * It is a NOTICE AND NOT A REFUSAL. GK refuses to let the
                 * export start with a warning unanswered, which is the right
                 * place for a refusal; refusing again here would refuse the
                 * partial import this whole console exists to make possible,
                 * and would be this shop overruling a decision it has strictly
                 * less information about than the person who made it.
                 */
                'groups' => $onDisk->usable() ? $onDisk->groups() : null,
                'merged_from' => $onDisk->usable() ? ($onDisk->raw()['merged_from'] ?? null) : null,
                /*
                 * Said on the screen, not only in a doc: an export with no
                 * manifest is not a broken export. Ten of the eleven files this
                 * importer reads existed before the plugin did.
                 */
                'note' => $onDisk->present()
                    ? 'This export carries a manifest, so the shop knows how many rows each file should hold '
                        .'and can recognise the export if it is uploaded again.'
                    : 'This export carries no manifest.json. It will import exactly as it always has — the '
                        .'row counts are taken from the files themselves, there is no total for the export '
                        .'as a whole, and the shop can only recognise a repeat by the contents of each file.',
            ],
            'duplicate' => $verdict,
            'run' => $run === null ? null : [
                'mode' => $run->mode,
                'status' => $run->status,
                'options' => $this->decode($run->options),
                'message' => $run->message,
                'restart' => (bool) $run->restart,
                'preview_limit' => (int) $run->preview_limit,
                'preview_complete' => $run->mode === 'preview' && $run->status === 'complete',
                'current_entity' => $run->status === 'running' && $run->mode === 'live'
                    ? $this->currentEntity($this->decode($run->done_entities))
                    : null,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
                'locked' => $run->locked_at !== null,
            ],
            'rejects' => [
                'count' => $this->rejectionCount($run?->mode ?? 'preview'),
                'shown' => $rejects,
                'truncated' => $this->rejectionCount($run?->mode ?? 'preview') > count($rejects),
            ],
            'limits' => $this->workspace->serverLimits() + [
                'default_step_rows' => self::DEFAULT_STEP_ROWS,
                'min_step_rows' => self::MIN_STEP_ROWS,
                'max_step_rows' => self::MAX_STEP_ROWS,
                'batch_rows' => self::BATCH_ROWS,
            ],
            'defaults' => $this->settleOptions([]),
            'timezones' => ['Asia/Dubai', 'UTC', 'Asia/Riyadh', 'Europe/London', 'Asia/Karachi', 'Asia/Kolkata'],
        ];
    }

    public function rejectsPath(string $mode): string
    {
        $dir = storage_path('app/import/runs');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir.'/'.($mode === 'preview' ? 'preview' : 'live').'-rejects.csv';
    }

    /* ------------------------------------------------------------- slices */

    /** @return array<string, mixed> */
    private function previewStep(object $run, int $rows): array
    {
        $present = $this->presentEntities();
        $deepest = 0;

        foreach ($present as $entity) {
            $deepest = max($deepest, $this->workspace->rowCount($entity));
        }

        $limit = (int) $run->preview_limit + $rows;
        $final = $limit >= $deepest;

        $report = (new ImportRunner)->run($this->options($run, [
            'dryRun' => true,
            'limit' => $limit,
            'batchSize' => min($rows, self::BATCH_ROWS),
        ]));

        // A preview step is a complete answer about the first $limit rows of
        // every file, so it REPLACES the stored one rather than being merged
        // into it. Merging would double-count the rows each step re-reads.
        $this->writeRejects('preview', $report, append: false);

        /*
         * A PREVIEW IS RECORDED TOO, and labelled as one.
         *
         * "You looked at this export on the 14th and wrote nothing" is a true
         * line of history and a useful one — it is what the owner did that
         * afternoon, and leaving it out would make the record say nothing
         * happened. Because a preview step re-reads from the top each time,
         * these counts REPLACE rather than accumulate, which is what record()
         * does for everything except the two lists it sums.
         */
        foreach ($report->entities() as $entityReport) {
            if (! ImportWorkspace::isEntity($entityReport->name)) {
                continue;
            }

            $this->recordHistory($run, $entityReport->name, [
                'mode' => 'preview',
                'processed' => $entityReport->touched() + $entityReport->rejectedCount(),
                'created' => $entityReport->created,
                'updated' => $entityReport->updated,
                'unchanged' => $entityReport->unchanged,
                'rejected' => $entityReport->rejectedCount(),
                // NEVER finished. A preview cannot finish an entity, and a
                // `finished` preview row would be matched by the duplicate
                // guard and would then refuse the real import of an export
                // nobody has imported.
                'finished' => false,
                'notes' => $this->mergeNotes([], $entityReport),
                'started_at' => $run->started_at,
            ] + ImportLedger::fromReport($entityReport));
        }

        DB::table(self::TABLE)->where('id', $run->id)->update([
            'preview_limit' => min($limit, max($deepest, 1)),
            'report' => (string) json_encode($this->serialise($report)),
            'status' => $final ? 'complete' : 'running',
            'message' => null,
            'finished_at' => $final ? now() : null,
            'updated_at' => now(),
        ]);

        return [
            'ok' => true,
            'mode' => 'preview',
            'depth' => min($limit, $deepest),
            'deepest' => $deepest,
            'complete' => $final,
        ];
    }

    /** @return array<string, mixed> */
    private function liveStep(object $run, int $rows): array
    {
        $done = $this->decode($run->done_entities);
        $started = $this->decode($run->started_entities);
        $baselines = $this->decode($run->baselines);
        $notes = $this->decode($run->notes);

        $entity = $this->currentEntity($done);

        if ($entity === null) {
            DB::table(self::TABLE)->where('id', $run->id)->update([
                'status' => 'complete',
                'finished_at' => now(),
                'message' => null,
                'updated_at' => now(),
            ]);

            return ['ok' => true, 'mode' => 'live', 'complete' => true, 'entity' => null];
        }

        $first = ! in_array($entity, $started, true);
        $restart = (bool) $run->restart && $first;

        if ($first) {
            $baselines[$entity] = $this->baselineFor($entity, $restart);
            $started[] = $entity;
        }

        $before = $this->checkpoints()[$entity] ?? null;

        $report = (new ImportRunner)->run($this->options($run, [
            'only' => [$entity],
            'dryRun' => false,
            'limit' => $rows,
            'batchSize' => min($rows, self::BATCH_ROWS),
            'restart' => $restart,
        ]));

        $this->writeRejects('live', $report, append: true);

        $entityReport = $report->for($entity);
        $notes[$entity] = $this->mergeNotes($notes[$entity] ?? [], $entityReport);

        $after = $this->checkpoints()[$entity] ?? null;
        $finished = $after !== null && $after->finished_at !== null;

        if ($finished) {
            $done[] = $entity;
        }

        $advanced = ((int) ($after->processed ?? 0)) - ((int) ($before->processed ?? 0));
        $complete = $finished && $this->currentEntity($done) === null;

        /*
         * THE RECORD THAT OUTLIVES THE RUN.
         *
         * Everything the owner is about to read on screen lives in two places
         * that are both about to be thrown away: `import_runs` holds exactly
         * one row per run key and the next run overwrites it in place, and
         * `import_checkpoints` is what the Reset button deletes. He has no
         * shell and no log. So the same numbers go into import_history, once
         * per entity per run, rewritten on every step of that entity.
         *
         * WRITTEN FROM THE CHECKPOINT AND NOT FROM THE REPORT, for the reason
         * the class comment gives about the screen's own counters: the
         * checkpoint's numbers were committed inside the same transaction as
         * the rows they count and a killed request cannot have inflated them.
         * The two things the checkpoint does not carry — the adjustments and
         * the discards — come off the step's report and are summed.
         */
        $this->recordHistory($run, $entity, [
            'mode' => 'live',
            'processed' => (int) ($after->processed ?? 0),
            'created' => max(0, (int) ($after->created_rows ?? 0) - (int) ($baselines[$entity]['created'] ?? 0)),
            'updated' => max(0, (int) ($after->updated_rows ?? 0) - (int) ($baselines[$entity]['updated'] ?? 0)),
            'unchanged' => max(0, (int) ($after->unchanged_rows ?? 0) - (int) ($baselines[$entity]['unchanged'] ?? 0)),
            'rejected' => max(0, (int) ($after->rejected_rows ?? 0) - (int) ($baselines[$entity]['rejected'] ?? 0)),
            'finished' => $finished,
            'notes' => $notes[$entity],
            'started_at' => $run->started_at,
        ] + ImportLedger::fromReport($entityReport));

        DB::table(self::TABLE)->where('id', $run->id)->update([
            'done_entities' => (string) json_encode(array_values(array_unique($done))),
            'started_entities' => (string) json_encode(array_values(array_unique($started))),
            'baselines' => (string) json_encode($baselines),
            'notes' => (string) json_encode($notes),
            'status' => $complete ? 'complete' : 'running',
            'finished_at' => $complete ? now() : null,
            'message' => null,
            'updated_at' => now(),
        ]);

        return [
            'ok' => true,
            'mode' => 'live',
            'entity' => $entity,
            'rows_done' => $advanced,
            'entity_finished' => $finished,
            'complete' => $complete,
        ];
    }

    /* --------------------------------------------------------------- guts */

    /**
     * The next entity to work on: the first one, in the runner's own dependency
     * order, that has a file and has not been finished by THIS run.
     *
     * Reading the order from ImportRunner rather than holding a copy is what
     * keeps the "customers before orders" guarantee true if a later lane ever
     * inserts a seventh entity into the graph.
     *
     * @param  list<string>  $done
     */
    private function currentEntity(array $done): ?string
    {
        foreach (ImportRunner::entityNames() as $entity) {
            if (! $this->workspace->has($entity)) {
                continue;
            }

            if (in_array($entity, $done, true)) {
                continue;
            }

            return $entity;
        }

        return null;
    }

    /**
     * How many rows each entity's file holds, for a caller that wants the
     * denominator and nothing else.
     *
     * SEPARATE FROM status() ON PURPOSE. status() reads the rejection CSV off
     * the disk to count it and to inline the first two hundred rows, and the
     * live progress page polls every three seconds while a run is going. A
     * stage that only needs nine integers must not drag that behind it.
     *
     * @return array<string, array{rows: ?int, source: string, mismatch: ?array<string, mixed>}>
     */
    public function denominators(): array
    {
        $manifest = $this->manifestFor($this->run());
        $out = [];

        foreach (ImportRunner::entityNames() as $entity) {
            $d = $this->denominator($entity, $manifest);

            $out[$entity] = ['rows' => $d['rows'], 'source' => $d['source'], 'mismatch' => $d['mismatch']];
        }

        return $out;
    }

    /**
     * Write one entity's line of history for this run.
     *
     * Silently does nothing when the run has no uid — a run started by the
     * version of this screen that shipped before import_history existed, and
     * still on the page when the package landed. That run keeps working and is
     * not recorded, which is better than 500ing on a key that is not there.
     *
     * @param  array<string, mixed>  $fields
     */
    private function recordHistory(object $run, string $entity, array $fields): void
    {
        $uid = $this->runsCarry('run_uid') ? (string) ($run->run_uid ?? '') : '';

        if ($uid === '') {
            return;
        }

        $manifest = $this->manifestFor($run);
        $file = ImportWorkspace::meta($entity)['file'];
        $source = $manifest->source();

        $this->ledger->record($uid, $entity, $fields + [
            'run_key' => self::RUN_KEY,
            'export_id' => $manifest->exportId(),
            'export_generated_at' => $manifest->generatedAt(),
            'source_site' => $source['site_url'],
            'source' => $source,
            'file_name' => $file,
            // The digest of the bytes that were read, taken from the same
            // sidecar `import_checkpoints.source_fingerprint` is compared
            // against, so "have I imported this file" is asked of one digest
            // and not two that could drift.
            'file_sha256' => $this->workspace->fingerprint($entity),
            'manifest_rows' => $manifest->usable() ? $manifest->rowsFor($file) : null,
            'manifest_sha256' => $manifest->usable() ? $manifest->sha256For($file) : null,
            'rows_counted' => $this->workspace->has($entity) ? $this->workspace->rowCount($entity) : null,
        ]);
    }

    /**
     * Does `import_runs` carry the column this lane added?
     *
     * An update package applies its migrations and its files in one go, but the
     * two halves of THIS screen are polled by a browser that may already be
     * open on the old page while the package lands. A status call that 500s
     * because a column is one request away from existing is the worst thing
     * this screen can do, so both new columns are optional at runtime and the
     * screen degrades to what it did before them.
     */
    private function runsCarry(string $column): bool
    {
        /*
         * DELIBERATELY NOT MEMOISED IN A STATIC. The obvious optimisation here
         * would be a function-local memo, and that is the exact shape CLAUDE.md names
         * as a landmine: a process-level memo does not see a schema that
         * changed after the first call, which under PHP-FPM is never and in a
         * test run or a queue worker is every migration. tests/Support/StaticMemos
         * exists because this repo has been bitten by it. Two schema lookups
         * per request is not a cost worth that.
         */
        return Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, $column);
    }

    /**
     * The manifest THIS RUN started under, falling back to the one on disk.
     *
     * A run started before this lane shipped has no snapshot, and reading the
     * current file for it is better than reading nothing: the export on disk is
     * the export it is importing.
     */
    private function manifestFor(?object $run): ImportManifest
    {
        if ($run !== null && $this->runsCarry('manifest') && ($run->manifest ?? null) !== null) {
            $snapshot = json_decode((string) $run->manifest, true);

            if (is_array($snapshot) && $snapshot !== []) {
                return ImportManifest::fromSnapshot($snapshot);
            }
        }

        return $this->workspace->manifest();
    }

    /**
     * Every entity that has a file, with the digest of the bytes on disk.
     *
     * @return array<string, array{sha256: ?string, file: string, label: string}>
     */
    private function presentFiles(?ImportManifest $manifest = null): array
    {
        // Read ONCE, not once per entity: this runs on the status endpoint the
        // screen polls, and manifest() opens and parses the file every call by
        // design (so a manifest the owner has just replaced is the one that
        // answers).
        $manifest ??= $this->workspace->manifest();

        $out = [];

        foreach ($this->presentEntities() as $entity) {
            $meta = ImportWorkspace::meta($entity);

            $out[$entity] = [
                'sha256' => $this->workspace->fingerprint($entity),
                'file' => $meta['file'],
                'label' => $meta['label'],
                /*
                 * Whether the file agrees with the manifest that describes it.
                 * The verdict needs this because "this file has changed since
                 * you imported it" is the right sentence for a correction and
                 * the WRONG one for a truncated upload — both are a digest that
                 * does not match, and only the row count tells them apart.
                 */
                'disputed' => $this->denominator($entity, $manifest)['mismatch'] !== null,
            ];
        }

        return $out;
    }

    /**
     * How many rows this entity's file holds, where that number came from, and
     * whether it can be believed.
     *
     * THREE SOURCES AND THEY ARE NOT INTERCHANGEABLE.
     *
     *   manifest — what the export plugin wrote down at the other end of the
     *     pipe. The only one that is INDEPENDENT of the file on this disk, and
     *     therefore the only one that can catch a truncated upload.
     *
     *   counted — ImportWorkspace read the file to the end when it arrived.
     *     Exactly right about the file that is here; cannot notice that the
     *     file that is here is half the file that was sent, because it is
     *     derived from it.
     *
     *   none — no manifest and no file. The state the live progress page's
     *     catalogue stage has always been in, and it still draws no bar in it.
     *
     * AND THE DISAGREEMENT IS THE POINT. When the manifest says 671 and the
     * file holds 412, the file on this disk is not the file the export
     * describes — an upload cut short, a spreadsheet round-trip, a partial
     * download. Drawing a bar over 412 would take that import to a confident
     * 100% with a third of the catalogue missing, which is the same species of
     * number as Lane GD's full green bar at 0/0. So: no bar, and a sentence.
     *
     * @return array{rows: ?int, source: string, expected: ?int, counted: ?int, mismatch: ?array<string, mixed>}
     */
    private function denominator(string $entity, ImportManifest $manifest): array
    {
        $present = $this->workspace->has($entity);
        $file = ImportWorkspace::meta($entity)['file'];

        $expected = $manifest->usable() ? $manifest->rowsFor($file) : null;
        $counted = $present ? $this->workspace->rowCount($entity) : null;

        $mismatch = null;

        if ($expected !== null && $counted !== null && $expected !== $counted) {
            $mismatch = [
                'expected' => $expected,
                'counted' => $counted,
                'sentence' => $file.' does not match the export it came with. The manifest says it holds '
                    .number_format($expected).' row'.($expected === 1 ? '' : 's').' and the file on this '
                    .'shop holds '.number_format($counted).'. '
                    .($counted < $expected
                        ? 'That is '.number_format($expected - $counted).' missing — an upload that did not '
                            .'finish, or a file opened and re-saved by a spreadsheet. Upload it again.'
                        : 'That is '.number_format($counted - $expected).' more than the export describes, so '
                            .'this is not the file the manifest is about.')
                    .' No progress bar is drawn for it, because a bar over the wrong file reaches 100% while '
                    .'rows are missing.',
            ];
        }

        if ($expected !== null && $mismatch === null) {
            return ['rows' => $expected, 'source' => 'manifest', 'expected' => $expected, 'counted' => $counted, 'mismatch' => null];
        }

        if ($mismatch !== null) {
            return ['rows' => null, 'source' => 'disputed', 'expected' => $expected, 'counted' => $counted, 'mismatch' => $mismatch];
        }

        if ($counted !== null) {
            return ['rows' => $counted, 'source' => 'counted', 'expected' => null, 'counted' => $counted, 'mismatch' => null];
        }

        return ['rows' => null, 'source' => 'none', 'expected' => null, 'counted' => null, 'mismatch' => null];
    }

    /** @return list<string> */
    private function presentEntities(): array
    {
        return array_values(array_filter(
            ImportRunner::entityNames(),
            fn (string $e): bool => $this->workspace->has($e),
        ));
    }

    /**
     * What this entity's checkpoint counters read before this run touched it.
     *
     * Zero when the entity is mid-resume — those counts describe rows this same
     * interrupted import already wrote, and subtracting them would report an
     * import that did half as much as it did.
     *
     * @return array{created: int, updated: int, unchanged: int, rejected: int}
     */
    private function baselineFor(string $entity, bool $restart): array
    {
        $zero = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0];

        if ($restart) {
            return $zero;
        }

        $cp = $this->checkpoints()[$entity] ?? null;

        if ($cp === null) {
            return $zero;
        }

        if ($cp->finished_at === null && (int) $cp->processed > 0) {
            return $zero;
        }

        /*
         * A FINISHED ENTITY IS ALSO ZERO NOW, and this is the half of a
         * two-sided change: App\Services\Import\Checkpoint::open() zeroes the
         * four counters wherever it zeroes `processed`, so a re-run of a
         * finished entity starts them from nothing and there is no earlier
         * pass's total left in them to subtract. Subtracting the pre-zero
         * values here as well would take the new pass's numbers off their own
         * baseline and show the owner nothing but noughts.
         *
         * The reason the counters are zeroed at all is
         * EntityReport::verification(): `processed - rejected_rows` is the
         * expected row count a RESUMED run needs, and a stale refusal in it
         * understates that count -- which turns a shortfall into "verified" on
         * the delta import. See Checkpoint::$resumedCountsTrusted.
         */
        if ($cp->finished_at !== null) {
            return $zero;
        }

        /*
         * What is left is a checkpoint at offset zero that is not finished --
         * in practice a row written by the code that came before the change
         * above. Baselined, so its stale counters do not land on this run.
         */
        return [
            'created' => (int) $cp->created_rows,
            'updated' => (int) $cp->updated_rows,
            'unchanged' => (int) $cp->unchanged_rows,
            'rejected' => (int) $cp->rejected_rows,
        ];
    }

    /** @return array<string, object> */
    private function checkpoints(): array
    {
        $out = [];

        // Six rows at most. Summed in PHP rather than with SQL aggregates; see
        // the class comment.
        foreach (DB::table('import_checkpoints')->where('run_key', self::RUN_KEY)->get() as $row) {
            $out[(string) $row->entity] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function options(object $run, array $overrides): ImportOptions
    {
        $stored = $this->settleOptions($this->decode($run->options));

        return new ImportOptions(
            directory: $this->workspace->directory(),
            files: [],
            only: $overrides['only'] ?? [],
            dryRun: (bool) ($overrides['dryRun'] ?? false),
            batchSize: max(1, (int) ($overrides['batchSize'] ?? self::BATCH_ROWS)),
            limit: max(0, (int) ($overrides['limit'] ?? 0)),
            runKey: self::RUN_KEY,
            restart: (bool) ($overrides['restart'] ?? false),
            synthesiseGuests: $stored['guests'] === 'synthesise',
            orderNumberFrom: $stored['order_number'],
            sourceTimezone: $stored['timezone'],
            adoptBySlug: (bool) $stored['adopt_by_slug'],
        );
    }

    /**
     * The four decisions the runbook says belong to the owner, defaulted to the
     * documented recommendation so that changing nothing does the right thing.
     *
     * @param  array<string, mixed>  $given
     * @return array{guests: string, order_number: string, timezone: string, adopt_by_slug: bool, restart: bool}
     */
    public function settleOptions(array $given): array
    {
        $guests = (string) ($given['guests'] ?? 'synthesise');
        $orderNumber = (string) ($given['order_number'] ?? 'number');
        $timezone = (string) ($given['timezone'] ?? 'Asia/Dubai');

        if (! in_array($guests, ['synthesise', 'unlinked'], true)) {
            $guests = 'synthesise';
        }

        if (! in_array($orderNumber, ['number', 'id'], true)) {
            $orderNumber = 'number';
        }

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'Asia/Dubai';
        }

        return [
            'guests' => $guests,
            'order_number' => $orderNumber,
            'timezone' => $timezone,
            'adopt_by_slug' => (bool) ($given['adopt_by_slug'] ?? false),
            'restart' => (bool) ($given['restart'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function serialise(ImportReport $report): array
    {
        $entities = [];
        $notes = [];

        foreach ($report->entities() as $entity) {
            $entities[$entity->name] = [
                'created' => $entity->created,
                'updated' => $entity->updated,
                'unchanged' => $entity->unchanged,
                'rejected' => $entity->rejectedCount(),
            ];

            $notes[$entity->name] = $this->mergeNotes([], $entity);
        }

        return ['entities' => $entities, 'notes' => $notes];
    }

    /**
     * Notes carried forward between steps.
     *
     * The runner's own "resumed: N rows were already committed" note is dropped:
     * it is emitted once per step, so after twenty steps it would read as twenty
     * separate observations, and the screen already says how far along the
     * entity is from the checkpoint itself.
     *
     * @param  array<string, int>  $existing
     * @return array<string, int>
     */
    private function mergeNotes(array $existing, EntityReport $entity): array
    {
        foreach ($entity->notes() as $note => $count) {
            if (str_starts_with($note, 'resumed: ')) {
                continue;
            }

            /*
             * THE VERIFICATION LINE REPLACES ITSELF, it does not accumulate.
             *
             * Every other note is a fact about rows -- "11 orders had no email"
             * -- and adding this step's eleven to the last step's is the right
             * answer. The count check is a fact about the WHOLE BUCKET, restated
             * with larger numbers on every slice, so accumulating it would leave
             * the screen showing forty verification sentences of which
             * thirty-nine are out of date and one is true, with nothing marking
             * which. The last one is the only one that means anything.
             */
            if (str_starts_with($note, self::VERIFICATION_PREFIX)) {
                foreach (array_keys($existing) as $seen) {
                    if (str_starts_with((string) $seen, self::VERIFICATION_PREFIX)) {
                        unset($existing[$seen]);
                    }
                }

                $existing[$note] = 1;

                continue;
            }

            $existing[$note] = (int) ($existing[$note] ?? 0) + $count;
        }

        return $existing;
    }

    private function writeRejects(string $mode, ImportReport $report, bool $append): void
    {
        $path = $this->rejectsPath($mode);
        $rejections = $report->allRejections();

        if ($append && $rejections === [] && is_file($path)) {
            return;
        }

        $fresh = ! $append || ! is_file($path);
        $handle = @fopen($path, $fresh ? 'wb' : 'ab');

        if ($handle === false) {
            return;
        }

        if ($fresh) {
            fputcsv($handle, ['entity', 'line', 'id', 'reason']);
        }

        foreach ($rejections as $rejection) {
            fputcsv($handle, [$rejection['entity'], $rejection['line'], $rejection['id'], $rejection['reason']]);
        }

        fclose($handle);
    }

    private function clearRejects(string $mode): void
    {
        $path = $this->rejectsPath($mode);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function rejectionCount(string $mode): int
    {
        $path = $this->rejectsPath($mode);

        if (! is_file($path)) {
            return 0;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        $n = -1; // the header

        try {
            while (fgetcsv($handle, 0, ',', '"', '') !== false) {
                $n++;
            }
        } finally {
            fclose($handle);
        }

        return max(0, $n);
    }

    /** @return list<array{entity: string, line: string, id: string, reason: string}> */
    public function rejectionsInline(string $mode): array
    {
        $path = $this->rejectsPath($mode);

        if (! is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $out = [];

        try {
            fgetcsv($handle, 0, ',', '"', ''); // header

            while (count($out) < self::INLINE_REJECTIONS && ($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($cells === [null] || count($cells) < 4) {
                    continue;
                }

                $out[] = [
                    'entity' => (string) $cells[0],
                    'line' => (string) $cells[1],
                    'id' => (string) $cells[2],
                    'reason' => (string) $cells[3],
                ];
            }
        } finally {
            fclose($handle);
        }

        return $out;
    }

    private function note(int $runId, string $message): void
    {
        DB::table(self::TABLE)->where('id', $runId)->update([
            'message' => mb_substr($message, 0, 900),
            'updated_at' => now(),
        ]);
    }

    /** @return array<mixed> */
    private function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function firstLine(string $message): string
    {
        $line = strtok($message, "\n");

        return $line === false ? $message : trim($line);
    }
}

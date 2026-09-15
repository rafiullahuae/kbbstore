<?php

declare(strict_types=1);

namespace App\Services\ImportConsole;

use App\Services\Import\EntityReport;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;
use Illuminate\Support\Facades\DB;

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
 * `created_rows` and friends are not zeroed when a FINISHED entity is re-run
 * (only `--restart` zeroes them), so a second pass would otherwise show the
 * first pass's numbers added to its own. Each entity's counters are therefore
 * baselined the first time this run touches them — except when the entity is
 * mid-resume, where the earlier numbers belong to the same interrupted import
 * and are kept.
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

    public function __construct(private readonly ImportWorkspace $workspace = new ImportWorkspace) {}

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

        $entities = [];
        $order = ImportRunner::entityNames();

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

            $entities[] = [
                'entity' => $entity,
                'label' => ImportWorkspace::meta($entity)['label'],
                'present' => $present,
                'rows_total' => $total,
                'processed' => $cp !== null ? (int) $cp->processed : 0,
                'finished' => $cp !== null && $cp->finished_at !== null,
                'done_this_run' => in_array($entity, $done, true),
                'created' => (int) ($counts['created'] ?? 0),
                'updated' => (int) ($counts['updated'] ?? 0),
                'unchanged' => (int) ($counts['unchanged'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
                'notes' => $notes[$entity] ?? ($preview['notes'][$entity] ?? []),
                'source_changed' => $present
                    && $cp !== null
                    && $cp->finished_at === null
                    && (int) $cp->processed > 0
                    && $cp->source_fingerprint !== null
                    && $cp->source_fingerprint !== $this->workspace->fingerprint($entity),
            ];
        }

        $rejects = $this->rejectionsInline($run?->mode ?? 'preview');

        return [
            'ok' => true,
            'files' => $files,
            'entities' => $entities,
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

<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One entity's place in one run, read and advanced through `import_checkpoints`.
 *
 * The contract is narrow on purpose: `processed` is the number of SOURCE ROWS
 * consumed and committed, and nothing else. Not rows written — a rejected row
 * and an unchanged row both advance it, because the offset has to describe the
 * input file, not the outcome. If it counted writes, a resume after a batch
 * containing three refusals would re-present those three rows plus three good
 * ones it had already done, and the offsets would drift further apart with
 * every interruption.
 *
 * `advance()` is deliberately NOT transactional on its own. It is called from
 * inside the batch transaction, so the rows and the progress commit together.
 * Calling it anywhere else is the bug this comment exists to prevent: a
 * checkpoint written outside the transaction that wrote the rows is a
 * checkpoint that can be true about a batch that was rolled back.
 */
final class Checkpoint
{
    public const TABLE = 'import_checkpoints';

    /**
     * REFUSALS AMONG THE ROWS AN EARLIER PROCESS ALREADY COMMITTED, which is
     * the one number a resumed run needs and cannot work out for itself.
     *
     * `EntityReport::verification()` compares `rows read - rows refused`
     * against a COUNT of the table. A resumed run has read only part of the
     * file in this process, so its own refusal tally covers only part of it,
     * and Lane FV withheld the verdict entirely rather than compare the wrong
     * two numbers (docs/FV-IMPORT-AT-VOLUME.md §7, fifth bullet). On the admin
     * screen EVERY step after the first resumes, so the verdict was never
     * reached there at all -- the owner had Phase 13's count check in name only
     * (docs/GF-IMPORT-REFINEMENT.md N2).
     *
     * `rejected_rows` is that missing number: it is incremented inside the same
     * transaction as the rows of the batch that produced it, so it survives a
     * killed request exactly the way `processed` does.
     *
     * BUT ONLY IF IT DESCRIBES THE SAME ROWS `processed` DOES, and it does not
     * always -- see $resumedCountsTrusted.
     */
    public readonly int $resumedRejected;

    /**
     * Whether the counters above genuinely describe the `processed` rows.
     *
     * THE INVARIANT. A row of a batch either moved this entity's tally
     * (created / updated / unchanged), or was refused, or moved nothing at all
     * -- and advance() adds exactly those deltas alongside the batch's row
     * count. So for a checkpoint whose counters describe its own `processed`
     * rows:
     *
     *     created + updated + unchanged + rejected  <=  processed
     *
     * with the shortfall being the rows that moved nothing, which is precisely
     * the defect this whole verification exists to find.
     *
     * The counters can EXCEED `processed`, and that is not a rounding
     * question, it is a different pass's numbers: `processed` is reset to zero
     * when a FINISHED entity is run again (the full -> delta -> cutover
     * sequence the runbook describes), and until this class was changed the
     * four counters were not reset with it. A second pass then carried the
     * first pass's refusals, `processed - rejected_rows` came out too SMALL,
     * and the verdict would have been "verified" over a table that was short.
     * A verdict that is wrong is worse than one that is absent.
     *
     * open() now zeroes the counters wherever it zeroes `processed`, so a
     * checkpoint written by this code always satisfies the invariant. The
     * check stays because a checkpoint written by the PREVIOUS code is sitting
     * in the owner's database right now: it is detected, the verdict is
     * withheld for that entity, and the reason is printed.
     */
    public readonly bool $resumedCountsTrusted;

    private function __construct(
        private readonly string $runKey,
        private readonly string $entity,
        public int $processed,
        public readonly ?string $fingerprint,
        int $resumedRejected = 0,
        bool $resumedCountsTrusted = true,
    ) {
        $this->resumedRejected = $resumedRejected;
        $this->resumedCountsTrusted = $resumedCountsTrusted;
    }

    /**
     * Load this entity's checkpoint, creating it if the entity has not run.
     *
     * @throws \RuntimeException when the source has changed under a part-finished run
     */
    public static function open(string $runKey, string $entity, string $fingerprint, string $label, bool $restart): self
    {
        $existing = DB::table(self::TABLE)
            ->where('run_key', $runKey)
            ->where('entity', $entity)
            ->first();

        if ($existing !== null && $restart) {
            DB::table(self::TABLE)->where('id', $existing->id)->update([
                'processed' => 0,
                'created_rows' => 0,
                'updated_rows' => 0,
                'unchanged_rows' => 0,
                'rejected_rows' => 0,
                'source_fingerprint' => $fingerprint,
                'source_label' => $label,
                'started_at' => now(),
                'finished_at' => null,
                'updated_at' => now(),
            ]);

            return new self($runKey, $entity, 0, $fingerprint);
        }

        if ($existing !== null) {
            $recorded = $existing->source_fingerprint;
            $processed = (int) $existing->processed;

            /*
             * A FINISHED entity has no partial progress to resume, so the next
             * run starts it again from row one.
             *
             * This is the difference between resuming and skipping, and getting
             * it wrong makes the importer useless for the job it exists to do.
             * The real sequence is: a full import, then a delta, then a cutover
             * delta on the night, each from a NEW export. If a completed
             * checkpoint kept its offset, the delta would skip every row the
             * full import had read — which is every row the delta actually
             * needs to re-present with its updated values — and report a
             * successful run that changed nothing.
             *
             * Re-running a completed entity costs one updateOrCreate per row
             * and reports them as unchanged, which is also the cheapest
             * available proof that the import was idempotent.
             */
            $counters = [];

            if ($existing->finished_at !== null) {
                $processed = 0;

                /*
                 * AND THE FOUR COUNTERS ARE ZEROED WITH IT. They count
                 * outcomes among the `processed` rows; leaving them behind
                 * while the offset goes back to zero makes them describe a
                 * pass that is over. That was not merely untidy: it is the
                 * arithmetic EntityReport::verification() now uses to reach a
                 * verdict on a resumed run, and a stale refusal in it
                 * understates the rows the table should hold -- turning a
                 * shortfall into "verified" on the SECOND import, which is the
                 * delta the owner runs on cutover night. See
                 * $resumedCountsTrusted.
                 *
                 * App\Services\ImportConsole\ImportDriver kept a per-run
                 * baseline to subtract these stale values for its own display;
                 * its baselineFor() is updated alongside this so the two agree
                 * rather than compensating twice.
                 */
                $counters = [
                    'created_rows' => 0,
                    'updated_rows' => 0,
                    'unchanged_rows' => 0,
                    'rejected_rows' => 0,
                ];
            }

            /*
             * Rows are resumed by POSITION, so an offset only means anything
             * against the exact file it was recorded for. A re-export with six
             * new orders at the top shifts every row, and continuing from 18,000
             * would skip 18,000 rows that are no longer the ones already done —
             * leaving a hole in the middle of the import that no count would
             * reveal, because the totals would look right.
             *
             * Refusing is safe and restarting is cheap: every write is an
             * updateOrCreate on an external id, so a redone row is an unchanged
             * row, and the report says so.
             */
            if ($processed > 0 && $recorded !== null && $recorded !== $fingerprint) {
                throw new \RuntimeException(
                    "The {$entity} source has changed since this run last stopped at row {$processed}. "
                    .'Resuming would skip rows that are no longer the ones already imported. '
                    .'Re-run with --restart to import this entity from the beginning '
                    .'(already-imported rows will simply report as unchanged).'
                );
            }

            DB::table(self::TABLE)->where('id', $existing->id)->update($counters + [
                'processed' => $processed,
                'source_fingerprint' => $fingerprint,
                'source_label' => $label,
                'started_at' => $existing->started_at ?? now(),
                'finished_at' => null,
                'updated_at' => now(),
            ]);

            $accounted = (int) $existing->created_rows + (int) $existing->updated_rows
                + (int) $existing->unchanged_rows;
            $rejected = (int) $existing->rejected_rows;

            return new self(
                $runKey,
                $entity,
                $processed,
                $fingerprint,
                $processed === 0 ? 0 : $rejected,
                // Zeroed above, or genuinely describing these rows.
                $processed === 0 || $accounted + $rejected <= $processed,
            );
        }

        DB::table(self::TABLE)->insert([
            'run_key' => $runKey,
            'entity' => $entity,
            'source_fingerprint' => $fingerprint,
            'source_label' => $label,
            'processed' => 0,
            'created_rows' => 0,
            'updated_rows' => 0,
            'unchanged_rows' => 0,
            'rejected_rows' => 0,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return new self($runKey, $entity, 0, $fingerprint);
    }

    /**
     * Record one committed batch.
     *
     * MUST be called from inside the batch's own transaction. See the class
     * comment — this is the property the whole resume design rests on.
     *
     * @param  list<string>  $droppedFields  fields this entity has declared it does
     *                                       not carry. UNLIKE the four counts, this
     *                                       is the whole set and not this batch's
     *                                       delta -- it is unioned, not summed.
     */
    public function advance(int $rows, int $created, int $updated, int $unchanged, int $rejected, array $droppedFields = []): void
    {
        $this->processed += $rows;

        $update = [
            'processed' => $this->processed,
            'created_rows' => DB::raw('created_rows + '.$created),
            'updated_rows' => DB::raw('updated_rows + '.$updated),
            'unchanged_rows' => DB::raw('unchanged_rows + '.$unchanged),
            'rejected_rows' => DB::raw('rejected_rows + '.$rejected),
            'updated_at' => now(),
        ];

        $merged = $this->mergeDroppedFields($droppedFields);

        if ($merged !== null) {
            $update['dropped_fields'] = $merged;
        }

        DB::table(self::TABLE)
            ->where('run_key', $this->runKey)
            ->where('entity', $this->entity)
            ->update($update);
    }

    /**
     * This slice's dropped fields, UNIONED with what earlier slices recorded.
     *
     * UNIONED AND NOT SUMMED, which is the whole reason this is a list of names
     * rather than a counter. A background import is many requests over one file
     * and each one reports the fields it saw; adding them would count `weight`
     * once per batch and tell the owner his catalogue lost three hundred fields.
     * A set unioned is idempotent, so a request that is killed and retried
     * cannot inflate it either -- which is the same property every other number
     * in this table has and the reason the table is believed.
     *
     * WRITTEN ONLY WHEN IT WOULD CHANGE SOMETHING. The common case is a second
     * batch of the same file reporting the same twenty names, and returning null
     * there keeps the column out of the UPDATE entirely.
     *
     * RETURNS NULL RATHER THAN THROWING WHEN THE COLUMN IS NOT THERE. This runs
     * on a shop whose migration may not have been applied yet -- the update
     * packages are applied by hand -- and an import that dies because a
     * progress-page nicety has no column is a far worse failure than a progress
     * page that cannot show one number. The column is the narrative; the rows
     * are the migration.
     *
     * @param  list<string>  $fields
     */
    /**
     * Whether `import_checkpoints` has the column, asked ONCE per process.
     *
     * Schema::hasColumn() is a real introspection query, and advance() runs once
     * per committed batch -- four hundred times over a catalogue this size. The
     * answer cannot change inside one request, so it is remembered.
     *
     * ONLY THE "YES" IS REMEMBERED, and that asymmetry is deliberate. A static
     * is exactly the shape CLAUDE.md warns about on Setting::map() -- a
     * process-level memo that cannot see a later change -- and caching a NO here
     * would reproduce that bug with a migration as the change: these packages
     * are applied by hand while the shop is running, and a queue worker that
     * happened to ask before the package landed would keep answering "no
     * column" for as long as it lived, silently leaving the number off the page
     * forever.
     *
     * Caching the YES has no such failure: a column is not removed under a
     * running process, and the cost of re-asking while the answer is still no is
     * one introspection query per batch on a shop that has not been updated yet
     * -- a transient state, and the cheap side of the trade.
     */
    private static bool $hasColumn = false;

    /**
     * Forget the schema answer. Tests only, and StaticMemosTest requires it.
     *
     * The memo above is correct in a request and wrong across a suite: one test
     * that runs with the column present leaves `true` behind for the next,
     * which may be asserting the pre-migration behaviour. Every process-level
     * memo in this application is reset from Tests\Support\StaticMemos for
     * exactly that reason, and a new one that is neither reset nor exempt fails
     * that test on purpose — which is how this was caught rather than by a
     * flaky import case weeks later.
     */
    public static function forgetSchema(): void
    {
        self::$hasColumn = false;
    }

    private static function columnExists(): bool
    {
        if (self::$hasColumn) {
            return true;
        }

        return self::$hasColumn = Schema::hasColumn(self::TABLE, 'dropped_fields');
    }

    private function mergeDroppedFields(array $fields): ?string
    {
        if ($fields === [] || ! self::columnExists()) {
            return null;
        }

        $stored = (string) (DB::table(self::TABLE)
            ->where('run_key', $this->runKey)
            ->where('entity', $this->entity)
            ->value('dropped_fields') ?? '');

        $known = array_filter(array_map('trim', explode(',', $stored)), static fn (string $f): bool => $f !== '');

        $all = array_values(array_unique([...$known, ...$fields]));
        sort($all);

        $line = implode(',', $all);

        return $line === $stored ? null : $line;
    }

    public function finish(): void
    {
        DB::table(self::TABLE)
            ->where('run_key', $this->runKey)
            ->where('entity', $this->entity)
            ->update(['finished_at' => now(), 'updated_at' => now()]);
    }
}

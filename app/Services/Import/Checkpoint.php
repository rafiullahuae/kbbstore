<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;

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

    private function __construct(
        private readonly string $runKey,
        private readonly string $entity,
        public int $processed,
        public readonly ?string $fingerprint,
    ) {}

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
            if ($existing->finished_at !== null) {
                $processed = 0;
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

            DB::table(self::TABLE)->where('id', $existing->id)->update([
                'processed' => $processed,
                'source_fingerprint' => $fingerprint,
                'source_label' => $label,
                'started_at' => $existing->started_at ?? now(),
                'finished_at' => null,
                'updated_at' => now(),
            ]);

            return new self($runKey, $entity, $processed, $fingerprint);
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
     */
    public function advance(int $rows, int $created, int $updated, int $unchanged, int $rejected): void
    {
        $this->processed += $rows;

        DB::table(self::TABLE)
            ->where('run_key', $this->runKey)
            ->where('entity', $this->entity)
            ->update([
                'processed' => $this->processed,
                'created_rows' => DB::raw('created_rows + '.$created),
                'updated_rows' => DB::raw('updated_rows + '.$updated),
                'unchanged_rows' => DB::raw('unchanged_rows + '.$unchanged),
                'rejected_rows' => DB::raw('rejected_rows + '.$rejected),
                'updated_at' => now(),
            ]);
    }

    public function finish(): void
    {
        DB::table(self::TABLE)
            ->where('run_key', $this->runKey)
            ->where('entity', $this->entity)
            ->update(['finished_at' => now(), 'updated_at' => now()]);
    }
}

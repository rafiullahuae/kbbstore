<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Services\Import\Entities\BrandImporter;
use App\Services\Import\Entities\CategoryImporter;
use App\Services\Import\Entities\CustomerImporter;
use App\Services\Import\Entities\EntityImporter;
use App\Services\Import\Entities\OrderImporter;
use App\Services\Import\Entities\OrderItemImporter;
use App\Services\Import\Entities\ProductImporter;
use App\Services\Import\Sources\CsvRowSource;
use App\Services\Import\Sources\RowSource;
use App\Services\Mail\OrderStatusMailPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Batching, transactions, checkpoints and the report. Everything the entity
 * importers deliberately do not have to think about.
 *
 * THE TRANSACTION SHAPE IS THE DESIGN, so it is worth stating exactly.
 *
 *   per BATCH   an explicit transaction. The batch's writes and the advance of
 *               the checkpoint commit together or not at all, so the recorded
 *               offset can never describe work that was rolled back. This is
 *               the only reason resume is trustworthy, and it is the reason
 *               Checkpoint::advance() must only ever be called from in here.
 *
 *   per ROW     a nested transaction, which Laravel issues as a SAVEPOINT. One
 *               row can write an order, two addresses, a synthesised customer
 *               and a line item; if the fourth of those fails, rolling back to
 *               the savepoint removes the first three. Without it a refused row
 *               would leave a half-written order behind inside a batch that
 *               then commits, which is the one outcome worse than rejecting it.
 *
 *   NOT the whole import. A two-hour import that rolls back entirely on row
 *   400,000 cannot be resumed, and on shared hosting the two-hour import is the
 *   one that gets killed.
 *
 * DRY RUN IS THE ONE EXCEPTION, and it inverts that. The whole run happens
 * inside a single outer transaction which is ALWAYS rolled back. That is how it
 * can tell the truth about what would happen: an order needs its customer's
 * primary key to exist, a line item needs its order's, so a preview that wrote
 * nothing could not resolve any of them and would report cascading failures
 * that are artefacts of the preview rather than of the data. Writing and then
 * discarding gives an exact answer, including every constraint the database
 * itself would refuse. Nothing survives — checkpoints included, which is why a
 * dry run never advances one.
 *
 * ORDER OF ENTITIES IS FIXED and not a user choice, because it is a dependency
 * graph and not a preference: products reference categories and brands, orders
 * reference customers, order lines reference both orders and products. --only
 * filters this list; it does not reorder it.
 */
final class ImportRunner
{
    /** @return list<EntityImporter> */
    public static function entities(): array
    {
        return [
            new CategoryImporter,
            new BrandImporter,
            new ProductImporter,
            new CustomerImporter,
            new OrderImporter,
            new OrderItemImporter,
        ];
    }

    /** @return list<string> */
    public static function entityNames(): array
    {
        return array_map(static fn (EntityImporter $e): string => $e->name(), self::entities());
    }

    /**
     * @param  callable(string, int, int): void|null  $progress  entity, rows done, rows this batch
     */
    public function run(ImportOptions $options, ?callable $progress = null): ImportReport
    {
        $report = new ImportReport($options->dryRun);
        $context = new ImportContext($options, $report);

        if (! $options->dryRun) {
            $this->execute($options, $context, $progress);

            return $report;
        }

        // See the class comment: write everything, resolve every foreign key,
        // then throw it all away.
        try {
            DB::transaction(function () use ($options, $context, $progress): void {
                $this->execute($options, $context, $progress);

                throw new DryRunComplete;
            });
        } catch (DryRunComplete) {
            // Expected. The transaction is rolled back and nothing was written.
        }

        return $report;
    }

    /**
     * @param  callable(string, int, int): void|null  $progress
     */
    private function execute(ImportOptions $options, ImportContext $context, ?callable $progress): void
    {
        foreach (self::entities() as $importer) {
            if (! $options->wants($importer->name())) {
                continue;
            }

            $path = $options->fileFor($importer->name(), $importer->conventionalFile());

            if ($path === null) {
                // Not an error. A delta pass that only carries new orders is a
                // normal thing to run, and demanding six files for it would
                // mean inventing five empty ones.
                continue;
            }

            $this->withCustomerMailHeld(
                $importer,
                $context,
                fn () => $this->runEntity($importer, new CsvRowSource($path), $options, $context, $progress),
            );
        }
    }

    /**
     * Run one entity with customer mail held back, when that entity says its
     * writes are not news to a customer — and say afterwards how many messages
     * that came to.
     *
     * AN IMPORT IS NOT AN EVENT IN A CUSTOMER'S LIFE. Saving an existing order
     * fires Eloquent's `updated` event, OrderMailObserver listens to it, and the
     * standing rule for `shipped` and `cancelled` is ON — so re-syncing the
     * statuses a Woo store has moved to since the last export mailed real
     * people about parcels that arrived years ago.
     *
     * WHY IT IS HERE AND NOT IN THE ENTITY. The observer sends through
     * DB::afterCommit(), so the message is decided when the BATCH COMMITS, not
     * when the row is written. A suppression wrapped around one row would
     * already have been lifted by the time it mattered. The entity boundary is
     * the smallest one that contains every commit the entity makes.
     *
     * WHAT IS SUPPRESSED is OrderStatusMailPolicy's business and is narrow:
     * status mail to the customer, nothing else. Refund mail, the merchant
     * copies and the invoice are untouched.
     *
     * AND THE OWNER IS TOLD. The count goes into the entity's report as an
     * ordinary note, which the console and the command both already print.
     * Silence nobody can see afterwards is indistinguishable from mail that
     * failed, and this import exists to be run on real customer data.
     */
    private function withCustomerMailHeld(EntityImporter $importer, ImportContext $context, callable $work): void
    {
        $because = $importer->suppressesCustomerMailBecause();

        if ($because === null) {
            $work();

            return;
        }

        $policy = app(OrderStatusMailPolicy::class);
        $before = $policy->suppressedCount();

        try {
            $policy->whileSuppressed($because, $work);
        } finally {
            $held = $policy->suppressedCount() - $before;

            if ($held > 0) {
                $context->report->for($importer->name())->note(
                    $held.' customer'.($held === 1 ? ' was' : 's were').' not emailed about a status this '
                    .'import changed, because '.$because
                );
            }
        }
    }

    /**
     * @param  callable(string, int, int): void|null  $progress
     */
    private function runEntity(
        EntityImporter $importer,
        RowSource $source,
        ImportOptions $options,
        ImportContext $context,
        ?callable $progress,
    ): void {
        $entity = $importer->name();
        $report = $context->report->for($entity);

        $checkpoint = Checkpoint::open(
            $options->runKey,
            $entity,
            $source->fingerprint(),
            $source->describe(),
            $options->restart,
        );

        $alreadyDone = $checkpoint->processed;
        $seen = 0;
        $consumed = 0;
        $batch = [];

        /*
         * Whether the source was read to the end.
         *
         * This is what makes --limit a resume point rather than a lie. A run
         * stopped by --limit has NOT finished the entity, and marking it
         * finished would make the next run start over from row one instead of
         * carrying on — turning the one flag that lets a shared host import
         * 5,000 rows per invocation into a flag that imports the same 5,000
         * rows forever.
         */
        $exhausted = true;

        if ($alreadyDone > 0) {
            $report->note(
                'resumed: '.$alreadyDone.' rows were already committed by an earlier run and were not re-read'
            );
        }

        try {
            foreach ($source->rows() as $line => $cells) {
                $seen++;

                // Resume by position. The fingerprint check in Checkpoint::open
                // is what makes that safe: a changed source is refused there
                // rather than skipped past here.
                if ($seen <= $alreadyDone) {
                    $report->skipped();

                    continue;
                }

                $batch[] = new Row($line, $cells);

                if (count($batch) >= $options->batchSize) {
                    $consumed += $this->commitBatch($importer, $batch, $context, $checkpoint);
                    $batch = [];

                    if ($progress !== null) {
                        $progress($entity, $alreadyDone + $consumed, $options->batchSize);
                    }
                }

                if ($options->limit > 0 && $consumed + count($batch) >= $options->limit) {
                    $exhausted = false;

                    break;
                }
            }
        } catch (RowRejected $e) {
            // The source itself could not be read past this point — a
            // misaligned CSV, not a bad value in an otherwise good row. The
            // rows already batched are still valid and are committed below, so
            // the checkpoint lands on the last row that really was imported and
            // a re-run after the file is fixed resumes from there.
            $report->reject('line '.($seen + 1), 'source', $e->getMessage());

            // The file could not be read to the end, so the entity is not
            // finished; a re-run after the file is fixed resumes here.
            $exhausted = false;
        }

        if ($batch !== []) {
            $consumed += $this->commitBatch($importer, $batch, $context, $checkpoint);

            if ($progress !== null) {
                $progress($entity, $alreadyDone + $consumed, count($batch));
            }
        }

        // The tree fix-up for categories, and nothing for anything else. Its own
        // transaction so it cannot enlarge a batch's.
        $this->inTransaction($context, fn () => $importer->finalise($context));

        if (! $context->dryRun() && $exhausted) {
            $checkpoint->finish();
        }
    }

    /**
     * One batch: every row inside one transaction, each row inside its own
     * savepoint, and the checkpoint advanced in the same commit.
     *
     * @param  list<Row>  $batch
     * @return int rows consumed
     */
    private function commitBatch(
        EntityImporter $importer,
        array $batch,
        ImportContext $context,
        Checkpoint $checkpoint,
    ): int {
        $report = $context->report->for($importer->name());

        $before = [
            'created' => $report->created,
            'updated' => $report->updated,
            'unchanged' => $report->unchanged,
            'rejected' => $report->rejectedCount(),
        ];

        DB::transaction(function () use ($importer, $batch, $context, $checkpoint, $report, $before): void {
            foreach ($batch as $row) {
                try {
                    // A SAVEPOINT, so one bad row cannot leave half of itself
                    // behind in a batch that then commits.
                    DB::transaction(fn () => $importer->import($row, $context));
                } catch (RowRejected $e) {
                    $report->reject($row->line, $importer->identify($row), $e->getMessage());
                } catch (\Illuminate\Database\QueryException $e) {
                    // The database refused something this importer's own checks
                    // did not anticipate. Reported with the driver's own words
                    // rather than swallowed: the alternative is an import that
                    // says it finished and is missing rows nobody can name.
                    $report->reject(
                        $row->line,
                        $importer->identify($row),
                        'the database refused this row: '.$this->firstLine($e->getMessage()),
                    );
                }
            }

            if (! $context->dryRun()) {
                $checkpoint->advance(
                    count($batch),
                    $report->created - $before['created'],
                    $report->updated - $before['updated'],
                    $report->unchanged - $before['unchanged'],
                    $report->rejectedCount() - $before['rejected'],
                );
            }
        });

        return count($batch);
    }

    private function inTransaction(ImportContext $context, callable $work): void
    {
        DB::transaction($work);
    }

    /**
     * Driver exception messages carry the whole SQL statement and every bound
     * value, which on an order row is the customer's address. The first line is
     * the part that says what went wrong.
     */
    private function firstLine(string $message): string
    {
        $line = strtok($message, "\n");

        return $line === false ? $message : trim($line);
    }
}

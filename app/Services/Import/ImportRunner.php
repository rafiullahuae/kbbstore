<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Services\Import\Entities\BrandImporter;
use App\Services\Import\Entities\CategoryImporter;
use App\Services\Import\Entities\CouponImporter;
use App\Services\Import\Entities\CustomerImporter;
use App\Services\Import\Entities\EntityImporter;
use App\Services\Import\Entities\OrderImporter;
use App\Services\Import\Entities\OrderItemImporter;
use App\Services\Import\Entities\OrderNoteImporter;
use App\Services\Import\Entities\RefundImporter;
use App\Services\Import\Entities\ReviewImporter;
use App\Services\Import\Entities\SeoImporter;
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
    /**
     * Every column name the current entity's source carried, mapped to the
     * first non-empty value seen in it, and every column anything asked for.
     * The difference is the discard list Phase 13's third bucket is made of.
     * Reset at the start of each entity.
     *
     * @var array<string, string>
     */
    private array $columnsSeen = [];

    /** @var array<string, true> */
    private array $columnsRead = [];

    /** @return list<EntityImporter> */
    public static function entities(): array
    {
        return [
            new CategoryImporter,
            new BrandImporter,
            new ProductImporter,
            /*
             * AFTER PRODUCTS AND CATEGORIES, BEFORE ORDERS, and both halves of
             * that are dependencies rather than preferences.
             *
             * After, because `coupons.product_ids` and `coupons.category_ids`
             * hold LOCAL primary keys -- CouponService::eligibleItems() tests
             * them against $product->id -- while the export carries WordPress
             * post and term ids. CouponImporter translates them through this
             * run's id maps, and a translation that finds nothing turns a
             * restricted coupon into one valid on the whole catalogue, which
             * it refuses rather than does.
             *
             * Before, because OrderImporter writes `orders.coupon_code` as a
             * bare string and Store -> Coupons' usage report joins the two. It
             * is not a foreign key, so nothing breaks the other way round --
             * but an order naming a code that is not yet a row is an order
             * whose discount cannot be looked at.
             */
            new CouponImporter,
            new CustomerImporter,
            new OrderImporter,
            new OrderItemImporter,
            /*
             * AFTER ORDERS, and that is the whole of the dependency. Both of
             * these attach to an order by `wc_order_id` through this run's id
             * map and reject a row whose order is not here -- `refunds.order_id`
             * and `order_notes.order_id` are both NOT NULL, so there is nothing
             * to attach an orphan to.
             *
             * NOT dependent on order-items, and placed after it only so the
             * order-shaped entities stay together on the Store -> Import screen,
             * which walks this array in order and steps one entity per request.
             *
             * Refunds are what stop an imported order reading as full revenue:
             * every total on this shop nets `SUM(refunds.amount)` where the
             * status is in PaymentRefunder::COUNTED, and with the table empty
             * the subtrahend was zero. See RefundImporter's header.
             */
            new RefundImporter,
            new OrderNoteImporter,
            /*
             * AFTER PRODUCTS, for the same reason as SEO below -- every review
             * names its product by the WooCommerce post id that ProductImporter
             * writes -- and after CUSTOMERS, because a review carries the
             * reviewer's WordPress user id and links to `customers` where this
             * shop has them. Registered before either, it rejects every row it
             * cannot attach, and an orphaned review is refused rather than
             * imported as a review of the shop.
             */
            new ReviewImporter,
            /*
             * LAST, and that is a dependency, not a preference. Every Yoast row
             * is matched on `wc_id`, which ProductImporter writes; registered
             * before it, this entity rejects the whole file on a fresh shop.
             * The order of this array is the order the runner walks it.
             */
            new SeoImporter,
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
        $this->reportUnreadFiles($options, $context);

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

        $this->columnsSeen = [];
        $this->columnsRead = [];

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

            /*
             * A RESUMED ROW IS STILL A ROW OF THIS FILE, and the verification
             * below is about the file and not about this invocation. Counting
             * only what this process re-read would make every resumed import
             * report a shortfall the size of the work the last one did -- which
             * on shared hosting, where every import is resumed, would be every
             * import. They are counted as read and as accounted for, because
             * the batch that committed them advanced the checkpoint in the same
             * transaction: their outcome is recorded in `import_checkpoints`
             * even though this process never saw it.
             */
            $report->read($alreadyDone);
            $report->accounted($alreadyDone);
            $report->resumedRows += $alreadyDone;
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

                $report->read();
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

        $this->reportIgnoredColumns($report, $source->describe());

        // The tree fix-up for categories, and nothing for anything else. Its own
        // transaction so it cannot enlarge a batch's.
        $this->inTransaction($context, fn () => $importer->finalise($context));

        if (! $context->dryRun() && $exhausted) {
            $checkpoint->finish();
        }

        $this->verify($importer, $report, $exhausted);
    }

    /**
     * Count-based verification for one bucket — Phase 13's own line, and the
     * only check in this report that does not take the importer's word for it.
     *
     * RUN EVEN WHEN THE BUCKET IS PART-WAY THROUGH, and marked as a slice when
     * it is. The admin screen imports one entity per HTTP request in slices of
     * a few hundred rows, so a verification that only ran on the final slice
     * would be a verification the owner never saw until the end -- and the
     * whole reason the screen exists is that the end may be an hour and forty
     * browser steps away.
     *
     * THE COUNT IS ONE AGGREGATE AND IT IS TAKEN AFTER finalise(), so the
     * category tree fix-up and the review aggregates have already run and the
     * number is the settled one.
     */
    private function verify(EntityImporter $importer, EntityReport $report, bool $exhausted): void
    {
        $report->verificationComplete = $exhausted;
        $report->inDatabase = $importer->countImported();

        $verification = $report->verification();

        /*
         * Put in the notes as well as in the structured fields, because the
         * notes are the one channel BOTH front ends already print: the console
         * table and the admin screen's per-entity panel. A verification that
         * only the console showed would be absent from the one screen the owner
         * actually runs this from.
         */
        $report->note(EntityReport::VERIFICATION_NOTE_PREFIX.$verification['sentence']);
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
                foreach ($row->all() as $column => $value) {
                    // The first non-empty value seen for the column, which is
                    // what turns "meta_delivery_instructions" from a name into
                    // a decision.
                    if (($this->columnsSeen[$column] ?? '') === '' && trim((string) $value) !== '') {
                        $this->columnsSeen[$column] = trim((string) $value);
                    } else {
                        $this->columnsSeen[$column] ??= '';
                    }
                }

                /*
                 * WHAT THIS ROW DID TO ITS OWN ENTITY'S TALLY, measured either
                 * side of the call rather than asked of the importer.
                 *
                 * An order row legitimately moves several tallies -- its own,
                 * `customers` when it synthesises a guest, `addresses` twice --
                 * so the question is deliberately narrow: did the ORDERS tally
                 * move? A row that moves nothing and throws nothing is a row
                 * that was read and then vanished, and it is the one outcome
                 * every other column in this report is blind to.
                 */
                $tallyBefore = $report->touched();

                try {
                    // A SAVEPOINT, so one bad row cannot leave half of itself
                    // behind in a batch that then commits.
                    DB::transaction(fn () => $importer->import($row, $context));

                    if ($report->touched() > $tallyBefore) {
                        $report->accounted();
                    } else {
                        $report->unaccountedFor($row->line, $importer->identify($row));
                    }
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
                } finally {
                    // Whatever the outcome. A rejected row still asked for the
                    // fields it got as far as, and a column asked for by one
                    // row is not an ignored column.
                    $this->columnsRead += $row->readKeys();
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

    /**
     * Files sitting in the export folder that no entity will ever open.
     *
     * THIS WAS THE LARGEST DISCARD IN THE WHOLE MIGRATION AND IT WAS THE ONE
     * NOTHING SAID. entities() was seven importers and there was no eighth:
     * this application has coupons and it has reviews, and neither had an entity
     * here. An owner who exported their WooCommerce store the obvious way got
     * coupons.csv and reviews.csv along with everything else, dropped the folder
     * in, read a report that said 4,166 orders imported, and had no reason at
     * all to suspect that two of the files they handed over were never opened.
     * Their coupon codes -- the ones printed on cards in outgoing parcels --
     * were simply not in the new shop, and they found out when a customer could
     * not use one.
     *
     * BOTH OF THOSE FILES ARE NOW READ: CouponImporter and ReviewImporter are
     * registered above. This method keeps doing its job for whatever the next
     * export carries that nothing here opens -- refunds.csv, order_notes.csv,
     * variations.csv, tags.csv -- because the failure was never about those two
     * filenames. It was about a file being ignored in silence.
     *
     * A run narrowed with --only is exempt, because there the unread files are
     * the point: `--only=orders` is supposed to ignore products.csv, and saying
     * so every time would train the owner to skim past the one message that
     * matters.
     *
     * Named, not read. Writing an importer for one of them is a different job,
     * and guessing at a mapping here would be worse than the silence.
     */
    private function reportUnreadFiles(ImportOptions $options, ImportContext $context): void
    {
        if ($options->only !== [] || $options->directory === '') {
            return;
        }

        $directory = rtrim($options->directory, '/');

        if (! is_dir($directory)) {
            return;
        }

        /*
         * permalinks.csv is read by `kbb:import-redirects`, which is a separate
         * command by design -- see RedirectMap's class comment -- so it is not
         * an unread file, it is a file read by the other half of Phase 13.
         * Naming it here would train the owner to ignore this list.
         */
        $claimed = [
            'permalinks.csv' => true,
            /*
             * manifest.json IS READ — App\Services\ImportConsole\ImportManifest
             * reads it for the progress denominator, the duplicate guard and the
             * record of which export this data came from. Before the export
             * contract existed it genuinely was a file nothing opened, and the
             * volume rehearsal duly named it in the discard list
             * (docs/FV-IMPORT-AT-VOLUME.md §10). Naming it now would be false,
             * and would train the owner to skim the one list that is only worth
             * anything if every line in it is true.
             */
            'manifest.json' => true,
        ];

        foreach (self::entities() as $importer) {
            $claimed[strtolower($importer->conventionalFile())] = true;
        }

        foreach ($options->files as $path) {
            $claimed[strtolower(basename($path))] = true;
        }

        $found = glob($directory.'/*.{csv,CSV,tsv,txt,json,xml}', GLOB_BRACE) ?: [];

        sort($found);

        foreach ($found as $path) {
            $name = basename($path);

            if (isset($claimed[strtolower($name)])) {
                continue;
            }

            $lines = max(0, $this->countLines($path) - 1);

            $context->report->for('export')->discarded(
                'a file in the export folder that no importer opens -- this application has no entity for '
                .'it, so nothing in it reaches the database and nothing else in this report mentions it',
                $name,
                'export',
                $name,
                $lines.' data '.($lines === 1 ? 'row' : 'rows').', read by nothing',
            );
        }
    }

    /**
     * Line count without loading the file. A WooCommerce order export is tens
     * of megabytes and this is only ever run to print a number.
     */
    private function countLines(string $path): int
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        $lines = 0;

        while (! feof($handle)) {
            $chunk = fread($handle, 1 << 16);

            if ($chunk === false) {
                break;
            }

            $lines += substr_count($chunk, "\n");
        }

        fclose($handle);

        return $lines;
    }

    /**
     * Name the columns of this export that no field in this importer reads.
     *
     * ONE ENTRY FOR THE WHOLE ENTITY AND NOT ONE PER ROW OR ONE PER COLUMN, and
     * the shape matters more than it looks. Per row, a WooCommerce order export
     * with nine ignored columns over 4,159 orders produces 37,431 identical
     * observations. Per column, the report's own sample cap then hides the tail
     * behind "and 9 more like it" -- and the tail is the part the owner needs,
     * because every one of those nine names is a different thing they are
     * losing and only they can say which ones matter. So: one entry, every name
     * in it, each with the first value the file actually held for it.
     *
     * "meta:_delivery_instructions" means nothing on its own.
     * "meta:_delivery_instructions = Ring the bell twice" is a decision.
     */
    private function reportIgnoredColumns(EntityReport $report, string $label): void
    {
        $ignored = array_diff_key($this->columnsSeen, $this->columnsRead);

        if ($ignored === []) {
            return;
        }

        ksort($ignored);

        $named = [];

        foreach ($ignored as $column => $sample) {
            $named[] = $sample === '' ? $column.' (always empty)' : $column.' = '.$sample;
        }

        $report->discarded(
            'columns in this export that no field of this importer reads -- they are in the file and '
            .'they will not be in the database, and nothing else in this report mentions them',
            basename($label),
            $report->name,
            count($ignored).' column'.(count($ignored) === 1 ? '' : 's'),
            implode(' | ', $named),
        );
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

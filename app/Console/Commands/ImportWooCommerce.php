<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\EntityReport;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;
use Illuminate\Console\Command;

/**
 * `kbb:import` — the WooCommerce importer.
 *
 * A COMMAND AND NOT A SCREEN, on purpose. This runs against a restored copy of
 * the live MySQL, takes as long as it takes, and is re-run until a mapping is
 * right. An admin screen for it is a later lane's job; what this one owes is a
 * report the owner can read and act on.
 *
 * THE REPORT IS THE DELIVERABLE, not the row count. "customers: 3,706 imported"
 * reads as success whether the export held 3,706 rows or 3,712, and the six
 * missing ones are the story. So every refused row is printed with its line
 * number, its id and a sentence saying why — and `--rejects=` writes all of
 * them to a CSV the owner can open next to the export.
 *
 * WHAT TO RUN, IN ORDER:
 *
 *   php artisan kbb:import --dir=storage/app/woo --dry-run
 *   php artisan kbb:import --dir=storage/app/woo --dry-run --rejects=storage/app/rejects.csv
 *   php artisan kbb:import --dir=storage/app/woo
 *
 * The dry run writes nothing: it does the entire import inside one transaction
 * and rolls it back, so its answer includes every foreign key resolved and
 * every constraint the database itself would refuse, and none of it survives.
 *
 * IF IT IS KILLED — and on shared hosting it will be — run exactly the same
 * command again. Progress is recorded in `import_checkpoints` in the same
 * transaction as the rows it describes, so the re-run continues from the last
 * committed batch rather than starting over or duplicating. Running it twice
 * over on purpose is also safe and is the best evidence there is that it
 * worked: the second pass should report every row unchanged.
 */
class ImportWooCommerce extends Command
{
    protected $signature = 'kbb:import
        {--dir= : directory holding the WooCommerce CSV exports}
        {--file=* : entity:path, overriding the conventional filename (e.g. --file=orders:/tmp/o.csv)}
        {--only=* : only these entities (categories, brands, products, tags, attributes, variations, coupons, customers, orders, order-items, reviews, seo)}
        {--dry-run : report what would change and write nothing}
        {--batch=500 : rows per committed transaction}
        {--limit=0 : stop after this many rows per entity, for a trial run}
        {--run=default : checkpoint key, so a trial run does not resume into the real one}
        {--restart : discard this run key\'s progress and import from the first row}
        {--guests=synthesise : synthesise|unlinked — what to do with a guest order (decision D3)}
        {--order-number=number : number|id — which value fills orders.order_number (decision D5)}
        {--timezone=Asia/Dubai : the WordPress site timezone the export\'s dates are written in}
        {--adopt-by-slug : claim an existing category/brand/product that has no WooCommerce id but holds the slug (the demo catalogue)}
        {--rejects= : write every refused row to this CSV}
        {--changes= : write the adjusted values and the discards to this CSV, with the before and the after}
        {--show-rejects=25 : how many refusals to print per entity}';

    protected $description = 'Import a WooCommerce CSV export: categories, brands, products, customers, orders and line items';

    public function handle(): int
    {
        $directory = (string) ($this->option('dir') ?? '');
        $files = $this->parseFileOverrides();

        if ($directory === '' && $files === []) {
            $this->error('Nothing to read. Pass --dir=<folder> or at least one --file=entity:path.');

            return self::FAILURE;
        }

        $only = array_values(array_filter(array_map('strval', (array) $this->option('only'))));
        $unknown = array_diff($only, ImportRunner::entityNames());

        if ($unknown !== []) {
            $this->error('Unknown entity: '.implode(', ', $unknown));
            $this->line('Known: '.implode(', ', ImportRunner::entityNames()));

            return self::FAILURE;
        }

        $guests = (string) $this->option('guests');

        if (! in_array($guests, ['synthesise', 'unlinked'], true)) {
            $this->error('--guests must be synthesise or unlinked.');

            return self::FAILURE;
        }

        $orderNumber = (string) $this->option('order-number');

        if (! in_array($orderNumber, ['number', 'id'], true)) {
            $this->error('--order-number must be number or id.');

            return self::FAILURE;
        }

        $options = new ImportOptions(
            directory: $directory,
            files: $files,
            only: $only,
            dryRun: (bool) $this->option('dry-run'),
            batchSize: max(1, (int) $this->option('batch')),
            limit: max(0, (int) $this->option('limit')),
            runKey: (string) $this->option('run'),
            restart: (bool) $this->option('restart'),
            synthesiseGuests: $guests === 'synthesise',
            orderNumberFrom: $orderNumber,
            sourceTimezone: (string) $this->option('timezone'),
            adoptBySlug: (bool) $this->option('adopt-by-slug'),
        );

        if ($options->dryRun) {
            $this->warn('DRY RUN — every write below is rolled back and nothing is kept.');
            $this->newLine();
        }

        $this->warnAboutInterleaving($options);

        try {
            $report = (new ImportRunner)->run($options, function (string $entity, int $done): void {
                $this->line('  '.$entity.': '.number_format($done).' rows');
            });
        } catch (\RuntimeException $e) {
            // Checkpoint::open raises this when the source has changed under a
            // part-finished run. It is not a crash, it is the design working.
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printReport($report);

        $this->writeRejectsCsv($report);
        $this->writeChangesCsv($report);

        /*
         * A DISCREPANCY FAILS THE COMMAND EVEN WITH NOTHING REFUSED, which is
         * the whole point of it: a refused row is one the importer knows it
         * does not have, and an unaccounted row is one it does not know it does
         * not have. The second is the worse of the two and it used to exit 0.
         */
        return $report->totalRejected() === 0 && ! $report->hasDiscrepancy()
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * --limit ACROSS A WHOLE EXPORT IMPORTS ORDERS BEFORE THEIR CUSTOMERS, and
     * that is the one sequence docs/IMPORT-RUNBOOK.md §2 says leaves a mess.
     *
     * WHAT HAPPENS, MEASURED RATHER THAN FEARED. --limit is a budget PER
     * ENTITY, so one invocation does N customers and then N orders and then N
     * line items. The orders in that slice name customers whose rows are still
     * further down customers.csv. Each one falls back to linking by billing
     * email and, under the default --guests=synthesise, creates a customer row
     * for that address with wp_user_id NULL. When the genuine user arrives on a
     * later pass carrying the same email, it finds the address already held by
     * a row with no WordPress id and is REFUSED as a collision.
     *
     * Running a 45-product/140-order/80-customer export in slices of 23 refused
     * 14 of the 80 customers this way. Nothing is corrupted and every refusal is
     * named — the count verification above shows the shortfall — but 14 people
     * are missing from the shop and the reason printed beside them points at
     * decision D2, which is a different problem entirely.
     *
     * WHY THIS IS A WARNING AND NOT A REFUSAL. --limit across every entity is
     * the shape the runbook documents and two other suites already exercise, and
     * turning it into an error from this lane would break work that is not
     * wrong, only unsafe in one combination. The admin screen does not have the
     * problem at all: it runs ONE entity per request and never starts the next
     * until the previous one is finished, which is the property this warning
     * tells the operator to go and get.
     */
    private function warnAboutInterleaving(ImportOptions $options): void
    {
        if ($options->limit === 0 || $options->dryRun || count($options->only) === 1) {
            return;
        }

        $this->newLine();
        $this->warn(
            '--limit is a budget PER ENTITY, so this run will import some orders before customers.csv has '
            .'been read to the end. Every order whose customer is further down that file will synthesise a '
            .'guest row for their email, and the genuine customer will then be REFUSED as a collision when '
            .'they arrive. Run one entity at a time instead — --only=customers until it finishes, then '
            .'--only=orders — or use Store -> Import, which does exactly that and cannot get the order wrong.'
        );
        $this->newLine();
    }

    /**
     * `--file=orders:/tmp/orders.csv`, repeatable.
     *
     * @return array<string, string>
     */
    private function parseFileOverrides(): array
    {
        $out = [];

        foreach ((array) $this->option('file') as $pair) {
            $pair = (string) $pair;
            $at = strpos($pair, ':');

            if ($at === false) {
                continue;
            }

            $out[substr($pair, 0, $at)] = substr($pair, $at + 1);
        }

        return $out;
    }

    private function printReport(ImportReport $report): void
    {
        $this->newLine();
        $this->info($report->isDryRun() ? 'Would import' : 'Imported');

        $rows = [];

        foreach ($report->entities() as $entity) {
            if ($entity->isEmpty()) {
                continue;
            }

            $rows[] = [
                $entity->name,
                number_format($entity->created),
                number_format($entity->updated),
                number_format($entity->unchanged),
                number_format($entity->skipped),
                number_format($entity->adjustedCount()),
                number_format($entity->discardedCount()),
                number_format($entity->rejectedCount()),
            ];
        }

        if ($rows === []) {
            $this->warn('No source files were found. Check --dir.');

            return;
        }

        $this->table(
            ['entity', 'created', 'updated', 'unchanged', 'skipped', 'ADJUSTED', 'DISCARDED', 'REJECTED'],
            $rows,
        );

        $this->printVerification($report);
        $this->printNotes($report);
        $this->printChanges($report);
        $this->printRejections($report);

        $this->newLine();

        if ($report->totalDiscarded() > 0 || $report->totalAdjusted() > 0) {
            $this->warn(
                $report->totalAdjusted().' value(s) '.($report->isDryRun() ? 'would go' : 'went').' in changed and '
                .$report->totalDiscarded().' thing(s) '.($report->isDryRun() ? 'would not go' : 'did not go')
                .' in at all. Phase 13 says the owner approves anything discarded — that list is above. '
                .($report->isDryRun()
                    ? 'Read it before the real run.'
                    : 'Re-read it: this run has already happened.')
            );
        }

        if ($report->totalRejected() === 0) {
            $this->info('No rows were refused.');

            return;
        }

        $this->error(
            $report->totalRejected().' rows were refused and are NOT in the database. '
            .'Each one is listed above with the reason.'
        );
    }

    /**
     * Count-based verification, per bucket — the thing Phase 13 asks for by
     * name and the only block in this report that is not the importer marking
     * its own homework.
     *
     * PRINTED FIRST, above the notes and the refusals, because it is the one
     * answer that decides whether the rest of the report is worth reading. A
     * bucket that cannot account for every row it read is not an import with a
     * few problems in it; it is an import whose totals mean nothing.
     */
    private function printVerification(ImportReport $report): void
    {
        $this->newLine();
        $this->line('<comment>Count-based verification — rows in against rows out, per bucket:</comment>');

        $rows = [];

        foreach ($report->entities() as $entity) {
            if ($entity->rowsRead === 0 && $entity->inDatabase === null) {
                continue;
            }

            $v = $entity->verification();

            $rows[] = [
                $entity->name,
                number_format($v['read']),
                number_format($v['accounted']),
                number_format($v['rejected']),
                $v['in_database'] === null ? '—' : number_format($v['in_database']),
                match ($v['verdict']) {
                    'verified' => '<info>VERIFIED</info>',
                    'discrepancy' => '<fg=red>DISCREPANCY</>',
                    'partial' => 'part-way',
                    'counted' => 'resumed — counted, not compared',
                    default => 'no table',
                },
            ];
        }

        if ($rows === []) {
            return;
        }

        $this->table(['bucket', 'rows read', 'accounted', 'refused', 'in database', 'verdict'], $rows);

        foreach ($report->entities() as $entity) {
            $v = $entity->verification();

            if ($v['verdict'] !== 'discrepancy') {
                continue;
            }

            $this->newLine();
            $this->error($entity->name.' — '.$v['sentence']);

            foreach ($entity->unaccountedRows() as $row) {
                $this->line('    line '.$row['line'].'  ['.$row['id'].']  read, and then neither imported nor refused');
            }
        }
    }

    private function printNotes(ImportReport $report): void
    {
        foreach ($report->entities() as $entity) {
            $notes = $entity->notes();

            if ($notes === []) {
                continue;
            }

            $this->newLine();
            $this->line('<comment>'.$entity->name.' — worth knowing:</comment>');

            foreach ($notes as $note => $count) {
                $this->line('  '.number_format($count).'x  '.$note);
            }
        }
    }

    /**
     * What went in changed, and what did not go in at all.
     *
     * Grouped by kind with the count and a few worked examples, which is the
     * shape the owner can act on: "173 unit prices were truncated, here are
     * three of them" is a decision. One line per affected row is 173 lines of
     * the same sentence and gets skipped, which is the same as not printing it.
     */
    private function printChanges(ImportReport $report): void
    {
        foreach ([
            ['adjustments', 'yellow', 'would be imported CHANGED', 'is imported CHANGED'],
            ['discards', 'red', 'would NOT be imported at all', 'is NOT imported'],
        ] as [$kind, $colour, $dryLabel, $realLabel]) {
            foreach ($report->entities() as $entity) {
                $groups = $kind === 'adjustments' ? $entity->adjustments() : $entity->discards();

                if ($groups === []) {
                    continue;
                }

                $this->newLine();
                $this->line(
                    '<fg='.$colour.'>'.$entity->name.' — '
                    .($report->isDryRun() ? $dryLabel : $realLabel).':</>'
                );

                foreach ($groups as $headline => $group) {
                    $this->line('  '.number_format($group['count']).'x  '.$headline);

                    foreach ($group['samples'] as $sample) {
                        $this->line(
                            '        '.$sample['line'].'  ['.$sample['id'].']  '.$sample['field']
                            .': '.$sample['before'].'  ->  '.$sample['after']
                        );
                    }

                    if ($group['count'] > count($group['samples'])) {
                        $this->line(
                            '        ... and '.number_format($group['count'] - count($group['samples']))
                            .' more like it'
                        );
                    }
                }
            }
        }
    }

    private function printRejections(ImportReport $report): void
    {
        $show = max(0, (int) $this->option('show-rejects'));

        foreach ($report->entities() as $entity) {
            if ($entity->rejectedCount() === 0) {
                continue;
            }

            $this->newLine();
            $this->line('<fg=red>'.$entity->name.' — '.$entity->rejectedCount().' refused:</>');

            $shown = 0;

            foreach ($entity->rejections() as $rejection) {
                if ($shown++ >= $show) {
                    $this->line(
                        '  ... and '.($entity->rejectedCount() - $show).' more '
                        .'(raise --show-rejects, or pass --rejects=<file> for all of them)'
                    );

                    break;
                }

                $this->line('  line '.$rejection['line'].'  ['.$rejection['id'].']  '.$rejection['reason']);
            }
        }
    }

    /**
     * The adjusted values and the discards, for the owner to read next to the
     * export.
     *
     * WHAT THIS FILE HOLDS AND WHAT IT DOES NOT, stated plainly rather than
     * promised and quietly not delivered. It carries every KIND with its full
     * count, and up to EntityReport::SAMPLES_PER_KIND worked examples of each.
     * It does NOT carry one line per affected row, and it must not: "a unit
     * price truncated by integer division" can be true of forty thousand line
     * items in a good import, and holding forty thousand before/after pairs in
     * memory to write a file nobody reads to the end is a cost with no buyer.
     *
     * The count is the number the owner acts on; the examples are how they
     * recognise what it is the count of. The rejections CSV is the one that is
     * complete, because a complete rejection list is small by construction —
     * the run is a failure if it is not.
     */
    private function writeChangesCsv(ImportReport $report): void
    {
        $path = (string) ($this->option('changes') ?? '');

        if ($path === '') {
            return;
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            $this->error('Could not write '.$path);

            return;
        }

        fputcsv($handle, ['bucket', 'entity', 'how_many', 'what', 'line', 'id', 'field', 'before', 'after']);

        $kinds = 0;

        foreach ($report->entities() as $entity) {
            foreach ([['adjust', $entity->adjustments()], ['discard', $entity->discards()]] as [$bucket, $groups]) {
                foreach ($groups as $headline => $group) {
                    $kinds++;

                    foreach ($group['samples'] as $sample) {
                        fputcsv($handle, [
                            $bucket,
                            $entity->name,
                            $group['count'],
                            $headline,
                            $sample['line'],
                            $sample['id'],
                            $sample['field'],
                            $sample['before'],
                            $sample['after'],
                        ]);
                    }
                }
            }
        }

        fclose($handle);

        $this->newLine();
        $this->line(
            $kinds.' kind(s) of change written to '.$path.' — each with its full count and up to '
            .EntityReport::SAMPLES_PER_KIND.' worked examples.'
        );
    }

    private function writeRejectsCsv(ImportReport $report): void
    {
        $path = (string) ($this->option('rejects') ?? '');

        if ($path === '') {
            return;
        }

        $rejections = $report->allRejections();

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            $this->error('Could not write '.$path);

            return;
        }

        fputcsv($handle, ['entity', 'line', 'id', 'reason']);

        foreach ($rejections as $rejection) {
            fputcsv($handle, [$rejection['entity'], $rejection['line'], $rejection['id'], $rejection['reason']]);
        }

        fclose($handle);

        $this->newLine();
        $this->line('Every refusal written to '.$path.' ('.count($rejections).' rows).');
    }
}

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
        {--only=* : only these entities (categories, brands, products, customers, orders, order-items)}
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

        return $report->totalRejected() === 0 ? self::SUCCESS : self::FAILURE;
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
                number_format($entity->rejectedCount()),
            ];
        }

        if ($rows === []) {
            $this->warn('No source files were found. Check --dir.');

            return;
        }

        $this->table(['entity', 'created', 'updated', 'unchanged', 'skipped', 'REJECTED'], $rows);

        $this->printNotes($report);
        $this->printRejections($report);

        $this->newLine();

        if ($report->totalRejected() === 0) {
            $this->info('No rows were refused.');

            return;
        }

        $this->error(
            $report->totalRejected().' rows were refused and are NOT in the database. '
            .'Each one is listed above with the reason.'
        );
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

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Redirect;
use App\Services\Import\RedirectMap;
use App\Services\Import\Sources\CsvRowSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `kbb:import-redirects` — build the old-WordPress-URL map, Phase 13's other
 * half.
 *
 * SEPARATE FROM `kbb:import` ON PURPOSE. The entity importers read a CSV and
 * write rows; this reads the rows that import produced and works out which
 * addresses Google already holds that would now 404. It therefore has to run
 * AFTER the catalogue is in, it needs no source file of its own, and it has
 * nothing to checkpoint — folding it into `ImportRunner` would mean giving that
 * class a file-less entity and a second notion of progress for the sake of
 * sharing a report format. See `App\Services\Import\RedirectMap` for the rules.
 *
 * READ-ONLY UNLESS TOLD OTHERWISE. With no flags it prints the map and writes
 * nothing, which is the mode to run first and the mode to run again afterwards
 * to confirm the answer stopped changing.
 *
 *   php artisan kbb:import-redirects
 *   php artisan kbb:import-redirects --csv=storage/app/url-map.csv
 *   php artisan kbb:import-redirects --permalinks=storage/app/woo/permalinks.csv
 *   php artisan kbb:import-redirects --write
 *   php artisan kbb:import-redirects --rollback
 *
 * REVERSIBLE, AND THIS ONE GENUINELY IS — which is worth saying because the row
 * import is not. A redirect is a new row with a unique `source` and nothing
 * else in the schema points at it, so removing it restores exactly the state
 * before. `--rollback` deletes only rows whose source is in the map the CURRENT
 * data produces AND whose target is still the one this command would write AND
 * which are flagged `auto_created`. A row an admin edited by hand fails the
 * second test, a row an admin created fails the third, and both survive.
 */
class ImportRedirects extends Command
{
    protected $signature = 'kbb:import-redirects
        {--permalinks= : CSV of addresses the old site really published (type, wc_id, permalink)}
        {--write : actually write the redirects; without this nothing is changed}
        {--rollback : delete the redirects a previous --write created, where they are untouched}
        {--csv= : write the whole map, all three buckets, to this file for the owner to read}
        {--show=25 : how many rows to print per bucket}';

    protected $description = 'Map old WooCommerce/WordPress URLs onto this shop\'s, and optionally write the redirects';

    public function handle(): int
    {
        $map = new RedirectMap;

        try {
            $permalinks = $this->permalinkRows();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $proposals = $map->propose($permalinks);

        if ($proposals === []) {
            $this->warn('Nothing to map. There are no categories in this database, so the import has not run yet.');

            return self::SUCCESS;
        }

        $this->writeCsv($proposals);

        if ($this->option('rollback')) {
            return $this->rollback($proposals);
        }

        $diff = $map->diff($proposals);

        $this->printBuckets($proposals);
        $this->printDiff($diff);

        if (! $this->option('write')) {
            $this->newLine();
            $this->warn('Nothing was written. Re-run with --write to apply the '.count($diff['create']).' new and '
                .count($diff['update']).' corrected redirect(s) above.');

            return self::SUCCESS;
        }

        return $this->write($diff);
    }

    /**
     * @return list<array<string, string>>
     */
    private function permalinkRows(): array
    {
        $path = (string) ($this->option('permalinks') ?? '');

        if ($path === '') {
            return [];
        }

        $out = [];

        foreach ((new CsvRowSource($path))->rows() as $cells) {
            $out[] = array_map(static fn ($cell): string => (string) $cell, $cells);
        }

        return $out;
    }

    /**
     * @param  list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>  $proposals
     */
    private function printBuckets(array $proposals): void
    {
        $show = max(0, (int) $this->option('show'));

        foreach ([RedirectMap::MIGRATE, RedirectMap::ASK, RedirectMap::DISCARD] as $bucket) {
            $rows = array_values(array_filter(
                $proposals,
                static fn (array $p): bool => $p['decision'] === $bucket,
            ));

            $this->newLine();
            $this->line('<comment>'.strtoupper($bucket).' — '.count($rows).'</comment>');

            if ($bucket === RedirectMap::DISCARD) {
                // Nothing to do by definition; the count is the whole message.
                $this->line('  addresses that need no redirect because they did not move.');

                continue;
            }

            foreach (array_slice($rows, 0, $show) as $row) {
                $this->line('  '.$row['source'].($row['target'] === '' ? '' : '  ->  '.$row['target']));
                $this->line('      <fg=gray>'.$row['subject'].' — '.$row['reason'].'</>');
            }

            if (count($rows) > $show) {
                $this->line('  ... and '.(count($rows) - $show).' more (raise --show, or pass --csv=<file>)');
            }
        }
    }

    /**
     * @param  array{create: list<array<string, string>>, update: list<array<string, string>>, unchanged: list<array<string, string>>, conflict: list<array<string, string>>}  $diff
     */
    private function printDiff(array $diff): void
    {
        $this->newLine();
        $this->info('Against the redirects already in this database');
        $this->table(
            ['new', 'corrected', 'already right', 'REFUSED'],
            [[
                count($diff['create']),
                count($diff['update']),
                count($diff['unchanged']),
                count($diff['conflict']),
            ]],
        );

        if ($diff['conflict'] === []) {
            return;
        }

        $this->newLine();
        $this->line('<fg=red>Refused — an admin pointed these somewhere else by hand, and this map does not overrule a person:</>');

        foreach ($diff['conflict'] as $row) {
            $this->line('  '.$row['source']);
            $this->line('      <fg=gray>points at '.$row['current'].'; this map would have sent it to '.$row['target'].'</>');
        }
    }

    /**
     * @param  array{create: list<array<string, string>>, update: list<array<string, string>>, unchanged: list<array<string, string>>, conflict: list<array<string, string>>}  $diff
     */
    private function write(array $diff): int
    {
        $written = 0;

        DB::transaction(function () use ($diff, &$written): void {
            foreach ([...$diff['create'], ...$diff['update']] as $row) {
                Redirect::query()->updateOrCreate(
                    ['source' => $row['source']],
                    [
                        'target' => $row['target'],
                        'code' => 301,
                        'enabled' => true,
                        // Bookkeeping derived from the import, not a person's
                        // decision about a URL. This is also what --rollback
                        // keys on, so it must stay true for rows this wrote.
                        'auto_created' => true,
                    ],
                );

                $written++;
            }
        });

        $this->newLine();
        $this->info($written.' redirect(s) written. Re-run without --write: it should report 0 new and 0 corrected.');

        return $diff['conflict'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>  $proposals
     */
    private function rollback(array $proposals): int
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
                    $kept[] = $proposal['source'].' — points at '.$existing->target
                        .', not the '.$proposal['target'].' this map writes, so somebody changed it';

                    continue;
                }

                $existing->delete();
                $removed++;
            }
        });

        $this->newLine();
        $this->info($removed.' redirect(s) removed.');

        foreach ($kept as $note) {
            $this->line('  <fg=yellow>kept</> '.$note);
        }

        return self::SUCCESS;
    }

    /**
     * Every row, every bucket, in a file the owner opens next to the export.
     *
     * @param  list<array{source: string, target: string, rule: string, decision: string, reason: string, subject: string}>  $proposals
     */
    private function writeCsv(array $proposals): void
    {
        $path = (string) ($this->option('csv') ?? '');

        if ($path === '') {
            return;
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            $this->error('Could not write '.$path);

            return;
        }

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

        fclose($handle);

        $this->line('Whole map written to '.$path.' ('.count($proposals).' rows).');
    }
}

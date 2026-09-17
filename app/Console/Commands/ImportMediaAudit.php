<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\MediaAudit;
use Illuminate\Console\Command;

/**
 * `kbb:import-media` — does every picture the imported catalogue names actually
 * exist?
 *
 * READ-ONLY, ALWAYS. There is no --write and there is deliberately no
 * downloader: see `App\Services\Import\MediaAudit` for why a half-successful
 * fetch would be worse than a list of filenames.
 *
 *   php artisan kbb:import-media
 *   php artisan kbb:import-media --csv=storage/app/missing-images.csv
 *   php artisan kbb:import-media --verdict=remote
 *
 * THE NUMBER TO WATCH IS `remote`, not `missing`. A missing file is visibly
 * broken and somebody will report it. A remote one renders perfectly until the
 * old shop is turned off.
 */
class ImportMediaAudit extends Command
{
    protected $signature = 'kbb:import-media
        {--verdict= : only present, missing or remote}
        {--csv= : write every reference and its verdict to this file}
        {--show=25 : how many to print per verdict}';

    protected $description = 'Check every image path in the imported catalogue against the files on disk';

    public function handle(): int
    {
        $audit = new MediaAudit;
        $rows = $audit->audit();

        if ($rows === []) {
            $this->warn('No image references at all. Either the catalogue is empty or no row names a picture.');

            return self::SUCCESS;
        }

        $filter = (string) ($this->option('verdict') ?? '');

        if ($filter !== '' && ! in_array($filter, [MediaAudit::PRESENT, MediaAudit::MISSING, MediaAudit::REMOTE], true)) {
            $this->error('--verdict must be present, missing or remote.');

            return self::FAILURE;
        }

        $this->writeCsv($rows);

        $counts = $audit->summarise($rows);

        $this->newLine();
        $this->info(count($rows).' image references');
        $this->table(
            ['on disk', 'MISSING', 'STILL ON THE OLD SITE'],
            [[$counts['present'], $counts['missing'], $counts['remote']]],
        );

        $show = max(0, (int) $this->option('show'));

        foreach ([MediaAudit::MISSING, MediaAudit::REMOTE, MediaAudit::PRESENT] as $verdict) {
            if ($filter !== '' && $filter !== $verdict) {
                continue;
            }

            if ($verdict === MediaAudit::PRESENT && $filter === '') {
                // The good case is a count, not a list.
                continue;
            }

            $matching = array_values(array_filter($rows, static fn (array $r): bool => $r['verdict'] === $verdict));

            if ($matching === []) {
                continue;
            }

            $this->newLine();
            $this->line('<comment>'.$verdict.' — '.count($matching).'</comment>');
            $this->line('  <fg=gray>'.$matching[0]['reason'].'</>');
            $this->newLine();

            foreach (array_slice($matching, 0, $show) as $row) {
                $this->line('  '.$row['owner'].'  ['.$row['field'].']');
                $this->line('      '.$row['url']);
            }

            if (count($matching) > $show) {
                $this->line('  ... and '.(count($matching) - $show).' more (raise --show, or pass --csv=<file>)');
            }
        }

        $this->newLine();

        if ($counts['missing'] === 0 && $counts['remote'] === 0) {
            $this->info('Every image this catalogue names is on disk.');

            return self::SUCCESS;
        }

        $this->error(
            ($counts['missing'] + $counts['remote']).' image references will not survive the old site being '
            .'switched off. Each one is named above.'
        );

        return self::FAILURE;
    }

    /**
     * @param  list<array{owner: string, field: string, url: string, path: string, verdict: string, decision: string, reason: string}>  $rows
     */
    private function writeCsv(array $rows): void
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

        fputcsv($handle, ['verdict', 'decision', 'owner', 'field', 'url', 'path', 'why']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['verdict'],
                $row['decision'],
                $row['owner'],
                $row['field'],
                $row['url'],
                $row['path'],
                $row['reason'],
            ]);
        }

        fclose($handle);

        $this->line('Every image reference written to '.$path.' ('.count($rows).' rows).');
    }
}

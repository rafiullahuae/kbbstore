<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\MediaAudit;
use Illuminate\Console\Command;

/**
 * `kbb:import-media` — does every picture the imported catalogue names actually
 * exist?
 *
 * READ-ONLY, ALWAYS. There is no --write here and there never will be: this
 * command's job is to count, and a counter that also changes what it counts is
 * a counter nobody can check.
 *
 * THE "NO DOWNLOADER" HALF OF THIS HEADER IS NO LONGER TRUE, and it is left
 * standing here rather than deleted because the objection it made was a good
 * one and deserves its answer on the record.
 *
 * It used to read: "there is deliberately no downloader: see MediaAudit for why
 * a half-successful fetch would be worse than a list of filenames." That is
 * true of a fetch that half-succeeds SILENTLY. It is not a property of
 * fetching. A half-finished download is worse than a list only when two things
 * are true of it — it cannot be resumed, so the half that failed has to be
 * guessed at, and it cannot be told apart from a finished one, so nobody knows
 * to look.
 *
 * `ImportRunner` met the identical objection for ROWS and answered it with
 * checkpoints; it was SIGKILLed mid-run eight times during the volume work
 * (`docs/FV-IMPORT-AT-VOLUME.md`) and resumed onto exactly the rows it had not
 * done. `App\Services\Import\MediaSideloader` is that answer for BYTES:
 *
 *   - the work list is re-derived from the catalogue on every request, so there
 *     is no queue to lose;
 *   - "already done" means `is_file(public_path($path))` — the file itself, not
 *     a row claiming there is one — and every fetch is validated in a temporary
 *     file and moved into place with a single rename(), so a killed request
 *     leaves a complete file or no file, never a truncated one;
 *   - "N still to go" is recomputed from the catalogue and the disk, never from
 *     a tally, so it is true after a killed request, after an FTP upload and
 *     after a database restore;
 *   - and IDLE, RUNNING and STALLED are three different states on the live
 *     progress page, so a run that died is visible as one rather than as a
 *     progress bar sitting at a stale number.
 *
 * So the audit still does not fetch, and that separation is still right — but
 * it is a division of labour now, not a prohibition. This command answers "is
 * every picture here?"; the sideloader answers "then go and get the ones that
 * are not", and the owner drives it from Store → Import because he has no
 * shell. See docs/GD-MEDIA-SIDELOADER.md for the security model, which is the
 * part that actually needed the care: it writes bytes the old host chose into
 * this shop's web root.
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

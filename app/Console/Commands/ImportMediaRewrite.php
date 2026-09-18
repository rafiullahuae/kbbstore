<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\MediaRewrite;
use Illuminate\Console\Command;

/**
 * `kbb:import-media-rewrite` — take the catalogue's photographs off the old
 * site, once the uploads folder has been copied across.
 *
 * `kbb:import-media` answers "do the pictures exist?" and is read-only by
 * design. This is the other half, and it is the one that stops the shop
 * depending on WordPress: `MediaAudit`'s `remote` verdict is the number the
 * runbook tells the owner to watch, and until this command existed there was
 * nothing that could bring it down except editing 671 products by hand.
 *
 *   php artisan kbb:import-media-rewrite                        # which hosts?
 *   php artisan kbb:import-media-rewrite --host=kbeautybliss.com
 *   php artisan kbb:import-media-rewrite --host=kbeautybliss.com --write
 *   php artisan kbb:import-media-rewrite --host=kbeautybliss.com --restore
 *
 * IT WRITES NOTHING WITHOUT --write, and it refuses to guess a host: run it
 * bare and it prints every host the catalogue points at, with a count, marking
 * this shop's own.
 *
 * THE OWNER CANNOT RUN THIS. There is no shell on the host. This command is for
 * CI, for the integrator and for a rehearsal on a copy; the owner's path is
 * Store → Import → "Addresses & pictures", which drives the same
 * `App\Services\Import\MediaRewrite` through
 * `Admin\UrlsMediaApiController::media()`. Both are listed here so that nobody
 * later reads this file as the only way in.
 */
class ImportMediaRewrite extends Command
{
    protected $signature = 'kbb:import-media-rewrite
        {--host=* : a host whose images are the old site\'s; repeatable}
        {--write : actually re-point the rows; without this nothing is changed}
        {--restore : put --host back in front of the paths this command localised}
        {--rebase : re-spell local upload paths for the subfolder this shop is served from now}
        {--show=25 : how many rows to print per outcome}';

    protected $description = 'Re-point imported image paths from the old WordPress host at this shop';

    public function handle(): int
    {
        $rewrite = new MediaRewrite;

        /** @var list<string> $hosts */
        $hosts = array_values(array_filter((array) $this->option('host')));

        if ($this->option('rebase')) {
            return $this->rebase($rewrite);
        }

        if ($hosts === []) {
            return $this->listHosts($rewrite);
        }

        if ($this->option('restore')) {
            return $this->restore($rewrite, $hosts);
        }

        $proposals = $rewrite->propose($hosts);
        $summary = $rewrite->summarise($proposals);

        if ($proposals === []) {
            $this->warn('No image in this catalogue points at '.implode(', ', $hosts).'.');

            return self::SUCCESS;
        }

        $show = max(0, (int) $this->option('show'));

        foreach ([MediaRewrite::REWRITE, MediaRewrite::ABSENT, MediaRewrite::SAME] as $decision) {
            $rows = array_values(array_filter(
                $proposals,
                static fn (array $row): bool => $row['decision'] === $decision,
            ));

            if ($rows === []) {
                continue;
            }

            $this->newLine();
            $this->line('<options=bold>'.$decision.'</> — '.count($rows));

            foreach (array_slice($rows, 0, $show) as $row) {
                $this->line('  '.$row['table'].' '.$row['id'].'.'.$row['field']);
                $this->line('    from '.$row['from']);
                $this->line('    to   '.$row['to']);
            }

            if (count($rows) > $show) {
                $this->line('  … and '.(count($rows) - $show).' more');
            }
        }

        $this->newLine();

        if (! $this->option('write')) {
            $this->warn('Nothing was written. Re-run with --write to re-point the '.$summary['rewrite'].' row(s) above.');

            /*
             * Non-zero while anything is ABSENT, the same convention
             * `kbb:import-media` uses: an absent file is the uploads folder not
             * being copied across yet, and that is exactly the state a script
             * must not read as finished.
             */
            return $summary['absent'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $written = $rewrite->apply($proposals);

        $this->info($written.' row(s) re-pointed at this shop.');

        if ($summary['absent'] > 0) {
            $this->warn($summary['absent'].' reference(s) left alone: the file is not under the web root yet. '
                .'Copy wp-content/uploads across and run this again.');
        }

        return $summary['absent'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The shop moved; the paths did not. No host is named because none is
     * involved — see MediaRewrite::proposeRebase().
     */
    private function rebase(MediaRewrite $rewrite): int
    {
        $proposals = $rewrite->proposeRebase();
        $summary = $rewrite->summarise($proposals);

        if ($proposals === []) {
            $this->info('Every local image path is already spelled for the subfolder this shop is served from.');

            return self::SUCCESS;
        }

        foreach (array_slice($proposals, 0, max(0, (int) $this->option('show'))) as $row) {
            $this->line('  '.$row['decision'].'  '.$row['table'].' '.$row['id'].'.'.$row['field']);
            $this->line('    from '.$row['from']);
            $this->line('    to   '.$row['to']);
        }

        $this->newLine();

        if (! $this->option('write')) {
            $this->warn('Nothing was written. Re-run with --write to re-spell the '.$summary['rewrite'].' row(s) above.');

            return $summary['absent'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->info($rewrite->apply($proposals).' row(s) re-spelled.');

        return $summary['absent'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function listHosts(MediaRewrite $rewrite): int
    {
        $hosts = $rewrite->hostsSeen();

        if ($hosts === []) {
            $this->info('No image in this catalogue points at another host. Nothing to rewrite.');

            return self::SUCCESS;
        }

        $this->line('Hosts this catalogue\'s images point at:');
        $this->newLine();

        foreach ($hosts as $host) {
            $this->line(sprintf(
                '  %-40s %6d reference(s)%s',
                $host['host'],
                $host['references'],
                $host['own'] ? '   <fg=green>— this shop\'s own host</>' : '',
            ));
        }

        $this->newLine();
        $this->warn('Name the old site with --host=… . This command will not guess which of these is WordPress: '
            .'a CDN, a supplier\'s photograph and a partner\'s banner all look the same from here.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $hosts
     */
    private function restore(MediaRewrite $rewrite, array $hosts): int
    {
        if (! $this->option('write')) {
            $this->warn('--restore changes rows, so it needs --write too. Nothing was done.');

            return self::SUCCESS;
        }

        foreach ($hosts as $host) {
            $result = $rewrite->restore($host);

            $this->info($result['restored'].' row(s) put back on '.$host.'.');

            foreach (array_slice($result['kept'], 0, max(0, (int) $this->option('show'))) as $note) {
                $this->line('  <fg=yellow>kept</> '.$note);
            }
        }

        return self::SUCCESS;
    }
}

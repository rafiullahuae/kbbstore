<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DomainMove\DomainReadiness;
use Illuminate\Console\Command;

/**
 * `kbb:domain-check` -- is this shop ready to move to another domain? (Lane DM)
 *
 *   php artisan kbb:domain-check kbeautybliss.com
 *   php artisan kbb:domain-check kbeautybliss.com --old=extrabeauty.ae
 *   php artisan kbb:domain-check kbeautybliss.com --csv=storage/app/domain-check.csv
 *
 * READ-ONLY. It writes nothing to the database, the cache or .env, and makes no
 * network request. Safe to run at any time, as often as you like, before and
 * after the switch. docs/DOMAIN-MOVE-KBEAUTYBLISS.md says when.
 *
 * Exit status is 0 only when nothing is RISK, so "zero left" is a number the
 * shell can check rather than a page somebody has to read. TODO lines (the
 * switch-day settings) do not fail it: they are expected until the day.
 */
class DomainCheck extends Command
{
    protected $signature = 'kbb:domain-check
        {new : the domain the shop is moving TO, e.g. kbeautybliss.com}
        {--old=* : a domain being left, e.g. extrabeauty.ae; repeatable. Default: every host the configuration names}
        {--samples=5 : example addresses printed per finding}
        {--csv= : also write every finding to this file}';

    protected $description = 'Read-only: what still depends on the old domain, and what breaks when the new one is switched over';

    public function handle(): int
    {
        $readiness = new DomainReadiness((string) $this->argument('new'), (array) $this->option('old'));

        if ($readiness->newHost() === '' || ! str_contains($readiness->newHost(), '.')) {
            $this->error('Give the new domain as a name, like kbeautybliss.com.');

            return self::INVALID;
        }

        $this->line('Moving to: <info>'.$readiness->newHost().'</info>   leaving: '
            .($readiness->oldHosts() === [] ? '(none named -- pass --old=)' : implode(', ', $readiness->oldHosts())));
        $this->newLine();

        $risk = 0;
        $todo = 0;
        $csv = [['section', 'level', 'what', 'detail', 'where / samples']];

        $this->line('<comment>Configuration</comment>');

        foreach ($readiness->checks() as $c) {
            $risk += $c['level'] === DomainReadiness::RISK ? 1 : 0;
            $todo += $c['level'] === DomainReadiness::TODO ? 1 : 0;
            $this->line(sprintf('  %-5s %-32s %s', strtoupper($c['level']), $c['what'], $c['detail']));

            if ($c['level'] !== DomainReadiness::OK && $c['where'] !== '') {
                $this->line(str_repeat(' ', 41).'→ '.$c['where']);
            }

            $csv[] = ['config', $c['level'], $c['what'], $c['detail'], $c['where']];
        }

        $this->newLine();
        $this->line('<comment>Stored addresses</comment> (every text column of every table)');

        $refs = $readiness->references(max(0, (int) $this->option('samples')));

        if ($refs === []) {
            $this->line('  OK    no link, picture, email or mention of '.implode(', ', $readiness->oldHosts())
                .', and no picture on '.$readiness->newHost().' this shop lacks');
        }

        $words = [
            'upload' => 'picture/video addresses',
            'upload_here' => 'picture/video addresses this shop already holds',
            'link' => 'links',
            'email' => 'email addresses',
            'text' => 'plain mentions',
            'history' => 'rows in a record of the past (nothing to do)',
            'unreadable' => 'could not be read -- run the check again, and tell the developer if it persists',
        ];

        foreach ($refs as $r) {
            $risk += $r['level'] === DomainReadiness::RISK ? 1 : 0;
            $todo += $r['level'] === DomainReadiness::TODO ? 1 : 0;

            $this->line(sprintf('  %-5s %s.%s  %d %s on %s', strtoupper($r['level']), $r['table'], $r['column'],
                $r['count'], $words[$r['kind']] ?? $r['kind'], $r['host']));

            foreach ($r['samples'] as $sample) {
                $this->line('          '.$sample);
            }

            $csv[] = ['content', $r['level'], $r['table'].'.'.$r['column'], $r['count'].' '.($words[$r['kind']] ?? $r['kind']).' on '.$r['host'],
                implode(' | ', $r['samples'])];
        }

        $this->newLine();
        $this->line(sprintf('RISK %d   TODO %d', $risk, $todo));
        $this->line($risk === 0
            ? 'Nothing found that breaks on the switch. TODO lines are the switch-day steps.'
            : 'RISK lines break on the switch, or keep depending on the old domain. Fix them first.');

        if (($path = (string) $this->option('csv')) !== '') {
            $handle = @fopen($path, 'w');

            if ($handle === false) {
                $this->error('Could not write '.$path);
            } else {
                foreach ($csv as $line) {
                    fputcsv($handle, $line);
                }

                fclose($handle);
                $this->line('Written: '.$path);
            }
        }

        return $risk === 0 ? self::SUCCESS : self::FAILURE;
    }
}

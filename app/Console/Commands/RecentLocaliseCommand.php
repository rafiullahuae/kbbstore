<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ConcernSync\ConcernSync;
use App\Services\ConcernSync\ConcernSyncCredentials;
use App\Services\RecentLocalise\RecentConcerns;
use App\Services\RecentLocalise\RecentLocalise;
use Illuminate\Console\Command;

/**
 * Catalog -> Translate & categorise, from SSH. (Lane LT)
 *
 *   php artisan kbb:recent-localise --dry                 what is missing, and the estimate; spends nothing
 *   php artisan kbb:recent-localise --translate           Arabic DRAFTS for the scope (Claude API)
 *   php artisan kbb:recent-localise --approve             publish the scope's drafts (one deliberate step)
 *   php artisan kbb:recent-localise --concerns            print skin-concern suggestions with reasons
 *   php artisan kbb:recent-localise --concerns --apply-concerns   ...and add them (only adds)
 *
 * Scope: --count=100 (newest N, max 500) or --days=14 (added in the last X
 * days). --max-usd stops before a translation run whose estimate is higher.
 */
final class RecentLocaliseCommand extends Command
{
    protected $signature = 'kbb:recent-localise
        {--count=100 : the newest N published products (max 500)}
        {--days= : only products added within the last X days}
        {--dry : list what is missing and the estimate; send nothing}
        {--translate : translate the missing fields into Arabic drafts}
        {--max-usd=5 : refuse a translation run estimated above this}
        {--approve : publish the drafts of the scoped products}
        {--concerns : suggest skin-concern collections for the scoped products}
        {--apply-concerns : with --concerns, add the suggested memberships (only adds)}';

    protected $description = 'Arabic translation and skin-concern suggestions for the newest products.';

    public function handle(): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;
        $ids = RecentLocalise::scope((int) $this->option('count'), $days);
        $creds = ConcernSyncCredentials::read();

        $report = RecentLocalise::report($ids);
        $estimate = RecentLocalise::estimate(RecentLocalise::pending($ids), $creds['model']);
        $t = $report['totals'];

        $this->info(count($ids).' product(s) in scope ('.($days ? 'added in the last '.$days.' days, ' : '').'newest first).');
        foreach ($t['missing'] as $field => $n) {
            $this->line(sprintf('  %-18s %d without Arabic', $field, $n));
        }
        $this->line('  drafts waiting for approval: '.$t['draft'].' · published: '.$t['published'].' · in no concern collection: '.$t['no_concern']);
        $this->line(sprintf('Estimate (%s): %d fields, %s characters, %d requests, ~%s in / ~%s out tokens, about USD %.2f%s.',
            $estimate['model'], $estimate['fields'], number_format($estimate['characters']), $estimate['requests'],
            number_format($estimate['input_tokens']), number_format($estimate['output_tokens']), $estimate['usd'],
            $estimate['too_long'] ? ' ('.$estimate['too_long'].' field(s) too long to send, type those by hand)' : ''));

        if ($this->option('dry')) {
            foreach (array_slice(RecentLocalise::pending($ids), 0, 200) as $p) {
                $this->line('  would send #'.$p['item_id'].' '.$p['field'].' ('.mb_strlen($p['english']).' chars)');
            }
            $this->line('Dry run: nothing was sent, written or published.');

            return self::SUCCESS;
        }

        if ($this->option('translate')) {
            if ($creds['key'] === null) {
                $this->error('No Claude API key saved. Add it in Catalog → Shop by Concern → Claude API key & model.');

                return self::FAILURE;
            }
            if ($estimate['usd'] > (float) $this->option('max-usd')) {
                $this->error('The estimate is above --max-usd='.$this->option('max-usd').'. Raise it or narrow --count.');

                return self::FAILURE;
            }

            $done = 0;
            // A step always takes at least one field, so a run never needs
            // more steps than there were fields; the cap keeps a fault that
            // re-offers the same fields from looping (and spending) forever.
            $steps = count(RecentLocalise::pending($ids)) + 1;
            do {
                $step = RecentLocalise::step($ids, $creds['key'], $creds['model']);
                $done += $step['translated'];
                foreach ($step['rejected'] as $r) {
                    $this->warn('  rejected #'.$r['product_id'].' '.$r['field'].': '.$r['why']);
                }
                $this->line('  '.$done.' drafted, '.$step['remaining'].' remaining');
                if ($step['error'] !== null) {
                    $this->error('Stopped: '.$step['error'].'. Run again to resume.');

                    return self::FAILURE;
                }
            } while ($step['remaining'] > 0 && ($step['translated'] > 0 || $step['rejected'] !== []) && --$steps > 0);

            $this->info($done.' Arabic draft(s) written. Nothing is on the shop until approved (--approve, or Approve all on the screen).');
        }

        if ($this->option('approve')) {
            $r = RecentLocalise::approve($ids);
            $this->info('Published '.$r['published'].' translation(s) on '.$r['products'].' product(s).'.($r['stale'] ? ' Skipped '.$r['stale'].' draft(s) whose English changed since.' : ''));
        }

        if ($this->option('concerns')) {
            $concerns = ConcernSync::concerns();
            if ($concerns === []) {
                $this->warn('This shop has none of the Shop by Concern collections.');

                return self::SUCCESS;
            }

            $added = 0;
            foreach (array_chunk($ids, RecentConcerns::PAGE) as $page) {
                $got = RecentConcerns::suggest($page, $concerns, $creds['key'], $creds['model']);
                if ($got['note']) {
                    $this->warn($got['note']);
                }
                $links = [];
                foreach ($got['products'] as $p) {
                    foreach ($p['suggestions'] as $s) {
                        $this->line('  #'.$p['id'].' '.$p['name'].' → '.$s['slug'].' — '.$s['reason'].' ['.$p['source'].']');
                        $links[$p['id']][] = $s['slug'];
                    }
                }
                if ($this->option('apply-concerns') && $links !== []) {
                    $added += RecentConcerns::apply($concerns, $links)['added'];
                }
            }

            $this->info($this->option('apply-concerns') ? 'Added '.$added.' membership(s). Nothing was removed.' : 'Suggestions only. Add --apply-concerns to add them.');
        }

        return self::SUCCESS;
    }
}

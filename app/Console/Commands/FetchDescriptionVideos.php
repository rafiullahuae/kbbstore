<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\DescriptionVideoFetcher;
use Illuminate\Console\Command;

/**
 * `kbb:fetch-description-videos` -- bring the video clips product copy names
 * off the old WordPress, before kbeautybliss.com is pointed here. (Lane PD)
 *
 *   php artisan kbb:fetch-description-videos --plan   # say what is left
 *   php artisan kbb:fetch-description-videos          # fetch it
 *
 * Each clip lands at the same path under this web root it had on WordPress
 * (wp-content/uploads/2024/11/…webm), and the product page plays that copy
 * from the next request on -- no row is rewritten. Safe to re-run: a clip
 * already here is skipped. Every guard is in App\Services\Import\
 * DescriptionVideoFetcher. Exit 0 when nothing is left on the old site.
 */
class FetchDescriptionVideos extends Command
{
    protected $signature = 'kbb:fetch-description-videos
        {--plan : list every clip and where it stands; fetch nothing}';

    protected $description = 'Copy the video clips product descriptions name from the old site into this shop';

    public function handle(): int
    {
        $fetcher = app(DescriptionVideoFetcher::class);
        $plan = $fetcher->plan();

        if ($plan === []) {
            $this->info('No product description or HTML Block names a video file. Nothing to fetch.');

            return self::SUCCESS;
        }

        $left = 0;

        foreach ($plan as $row) {
            if ($row['state'] !== 'todo' || $this->option('plan')) {
                $this->line(str_pad(strtoupper($row['state']), 8) . $row['url'] . '  -- ' . $row['reason']);
                $left += $row['state'] === 'todo' ? 1 : 0;

                continue;
            }

            $result = $fetcher->fetch($row['url'], (string) $row['path']);

            if ($result['ok']) {
                $this->info('FETCHED ' . $row['url'] . '  -> ' . $row['path'] . ' (' . number_format($result['bytes']) . ' bytes)');
            } else {
                $left++;
                $this->error('FAILED  ' . $row['url'] . '  -- ' . $result['reason']);
            }
        }

        $this->newLine();
        $this->line($left === 0
            ? 'Every clip is in this shop. The product pages play their own copies now.'
            : $left . ' clip(s) are still only on the old site. ' . ($this->option('plan') ? 'Run without --plan to fetch them.' : 'Fix what is named above and run again.'));

        return $left === 0 ? self::SUCCESS : self::FAILURE;
    }
}

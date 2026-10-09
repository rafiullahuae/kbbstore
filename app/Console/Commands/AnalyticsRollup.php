<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\Rollup;
use Illuminate\Console\Command;

/**
 * Analytics: roll today's raw hits into the daily summaries and prune hits
 * older than 48 hours (Lane AN). App\Services\Analytics\Rollup says what it
 * costs; with no new hits it is one MAX(id) and the prune.
 */
final class AnalyticsRollup extends Command
{
    protected $signature = 'kbb:analytics-rollup {--force : rebuild today even with no new hits}';

    protected $description = 'Analytics: roll raw page views into the daily summaries and prune old ones';

    public function handle(): int
    {
        $days = Rollup::runDue((bool) $this->option('force'));
        $this->line('Analytics: rebuilt '.$days.' day(s).');

        return self::SUCCESS;
    }
}

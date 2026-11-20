<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pixels\ServerEvents;
use Illuminate\Console\Command;

/**
 * Re-send Meta Conversions API events that failed for a reason worth
 * retrying. (Lane AP-API)
 *
 *     php artisan kbb:capi-retry
 *
 * Run every minute by the scheduler cron (routes/console.php). There is no
 * queue worker on this host, so this is the retry path. One indexed read when
 * nothing is due. ServerEvents::retryDue() carries the rules: at most 20 per
 * run, backoff 5 min .. 12 h after the first try, never past 47 h.
 */
final class CapiRetry extends Command
{
    protected $signature = 'kbb:capi-retry {--limit=20}';

    protected $description = 'Re-send Meta server events that failed (within the 48 h dedup window).';

    public function handle(ServerEvents $events): int
    {
        $out = $events->retryDue(max(1, min(100, (int) $this->option('limit'))));

        if ($out['due'] > 0) {
            $this->line("due {$out['due']}, sent {$out['sent']}, failed {$out['failed']}, dropped {$out['dropped']}");
        }

        return self::SUCCESS;
    }
}

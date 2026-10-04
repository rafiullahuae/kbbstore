<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CartTracking\CartTrackingPrune;
use App\Services\CartTracking\CartTrackingTick;
use Illuminate\Console\Command;

/**
 * Growth & Marketing → Cart Tracking: delete events past their retention,
 * counting them into the all-time product totals first. (Lane CT)
 */
class CartTrackingPruneCommand extends Command
{
    protected $signature = 'kbb:cart-tracking-prune {--batches=50 : at most this many batches of 2,000 events}';

    protected $description = 'Delete Cart Tracking events older than the retention setting (carts are kept).';

    public function handle(CartTrackingPrune $prune): int
    {
        @touch(CartTrackingTick::markerPath());

        $done = $prune->run(max(1, min(1000, (int) $this->option('batches'))));

        $this->info(sprintf('Deleted %d events and %d long-expired blocks.', $done['events'], $done['blocks']));

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NewArrivals;
use Illuminate\Console\Command;

/**
 * The New Arrivals safety net (Lane NA): every minute from routes/console.php.
 *
 * Product writes already sync when their request ends; this is what ages a
 * product out when it turns older than the window, lets a scheduled product in
 * when its publish time comes, and puts right anything a mass update wrote
 * without model events. A cache read when no category keeps itself; two reads
 * and no writes when nothing changed.
 */
class SyncNewArrivals extends Command
{
    protected $signature = 'kbb:new-arrivals-sync';

    protected $description = 'Keep automatic New Arrivals categories to their rule (newest N, age window, include/exclude).';

    public function handle(): int
    {
        foreach (NewArrivals::syncAll() as $id => $r) {
            $this->line("Category {$id}: +{$r['added']} -{$r['removed']} moved {$r['moved']}.");
        }

        return self::SUCCESS;
    }
}

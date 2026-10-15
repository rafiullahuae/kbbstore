<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\OrderEmirate;
use Illuminate\Console\Command;

/**
 * Store the emirate of every order that does not carry one yet (Lane EM).
 *
 * Scheduled every minute (routes/console.php), so an order placed while nobody
 * has the Orders list open is counted under its emirate within a minute. The
 * checkout does not write the column -- the payment path is locked -- so this,
 * the admin list and the owner app's list are what fill it. One indexed
 * `ship_emirate IS NULL` query when there is nothing to do.
 */
class FillOrderEmirates extends Command
{
    protected $signature = 'kbb:order-emirates {--limit=2000 : most orders to read in this run}';

    protected $description = 'Store the emirate (DXB, AUH, ALAIN, SHJ ...) of orders that do not carry one yet.';

    public function handle(): int
    {
        $n = OrderEmirate::fillMissing(max(1, (int) $this->option('limit')));

        $this->line(OrderEmirate::ready() ? "Read {$n} order(s)." : 'The ship_emirate column is not there yet; run the migrations.');

        return self::SUCCESS;
    }
}

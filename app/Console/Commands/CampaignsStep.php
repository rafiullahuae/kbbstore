<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Marketing\CampaignTick;
use Illuminate\Console\Command;

/**
 * Driver B of Marketing Emails (Lane MK): start the scheduled campaigns that
 * are due and carry on the ones that are sending. Every minute from
 * routes/console.php once the server's cron line is installed; see
 * App\Services\Marketing\CampaignTick.
 */
class CampaignsStep extends Command
{
    protected $signature = 'kbb:campaigns-step';

    protected $description = 'Start due marketing campaigns and send the next batch of every campaign that is sending.';

    public function handle(CampaignTick $tick): int
    {
        $r = $tick->run();

        $this->line("Started {$r['started']} campaign(s), ran {$r['steps']} step(s).");

        return self::SUCCESS;
    }
}

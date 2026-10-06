<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Push\PushTick;
use Illuminate\Console\Command;

/**
 * Growth & Marketing → Push Notifications (Lane PN): start due campaigns, run
 * the automations and send what is queued. Every minute from
 * routes/console.php; see App\Services\Push\PushTick.
 */
class PushStep extends Command
{
    protected $signature = 'kbb:push-step';

    protected $description = 'Send due push campaigns and the shop app\'s automatic notifications.';

    public function handle(PushTick $tick): int
    {
        $r = $tick->run();
        $this->line("Started {$r['started']} campaign(s), queued {$r['queued']}, sent {$r['sent']}, stepped {$r['steps']}.");

        return self::SUCCESS;
    }
}

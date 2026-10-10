<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Marketing\Bounces\BounceReader;
use Illuminate\Console\Command;

/**
 * Read the "KBB Bounces" Gmail label and file every bounce, complaint and
 * mailto: unsubscribe in it (Lane EB). Every five minutes from
 * routes/console.php; does nothing — opens no connection — while Marketing
 * Emails → Bounces & unsubscribes → Settings → "Read bounces" is off.
 */
class BouncesRead extends Command
{
    protected $signature = 'kbb:bounces-read';

    protected $description = 'Read bounce reports from the Gmail bounce label and remove bounced addresses from marketing lists.';

    public function handle(BounceReader $reader): int
    {
        $r = $reader->run();

        if (! ($r['ran'] ?? false)) {
            $this->line('Bounce reading is off or not set up.');

            return self::SUCCESS;
        }

        if (! ($r['ok'] ?? false)) {
            $this->warn('Bounce mailbox: ' . ($r['why'] ?? 'failed'));

            return self::FAILURE;
        }

        $this->line("Read {$r['read']} report(s): {$r['hard']} hard, {$r['soft']} soft, {$r['delay']} delayed, {$r['complaint']} complaint(s), {$r['unsubscribe']} unsubscribe(s), {$r['ignored']} ignored.");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mail\OrderReminders;
use Illuminate\Console\Command;

/**
 * The scheduler's half of the "Complete your order" reminders (Lane RL).
 *
 * Runs every minute from routes/console.php when the server's cron line is
 * installed; OrderReminderTick runs the same sweep after page requests when it
 * is not. Both claim each send in `order_emails`, so the two never double up.
 */
class SendOrderReminders extends Command
{
    protected $signature = 'kbb:order-reminders {--limit=50 : most messages to send in this run}';

    protected $description = 'Send the due "Complete your order" reminders (30 minutes and 24 hours after an unpaid order).';

    public function handle(OrderReminders $reminders): int
    {
        $sent = $reminders->sweep(max(1, (int) $this->option('limit')));

        $this->line("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}

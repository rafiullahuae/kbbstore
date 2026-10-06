<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Instagram\InstagramCredentials;
use App\Services\Instagram\InstagramSync;
use Illuminate\Console\Command;

/**
 * kbb:instagram-sync — the daily fetch of OUR Instagram posts.  (Lane SG, 2.60.417)
 *
 * The same InstagramSync::run() as the Refresh button on Content → Instagram,
 * given minutes instead of the button's seconds, so a first sync of hundreds of
 * posts finishes its pictures overnight and the selected posts' shares stay
 * fresh without anybody pressing anything. Scheduled in routes/console.php,
 * driven by the one existing cron line (`php artisan schedule:run`).
 *
 * --unattended: "not connected" is a no-op (exit 0), not a failure — a shop that
 * has not connected Instagram should not fail a scheduled job every day.
 */
class InstagramSyncCommand extends Command
{
    protected $signature = 'kbb:instagram-sync {--seconds=600 : how long pictures and insights may take} {--unattended}';

    protected $description = 'Fetch every post of our Instagram account, its pictures, and the shares of the posts on the Spotted page.';

    public function handle(InstagramSync $sync): int
    {
        if (! InstagramCredentials::hasToken()) {
            $this->line('Instagram is not connected; nothing to fetch.');

            return $this->option('unattended') ? self::SUCCESS : self::FAILURE;
        }

        $result = $sync->run(max(10, (int) $this->option('seconds')));

        if (! ($result['ok'] ?? false)) {
            $this->error((string) ($result['error'] ?? 'Instagram could not be reached.'));

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d posts over %d pages, %d pictures held, %d still to fetch, %d removed, shares read for %d. %s',
            (int) $result['stored'], (int) $result['pages'], (int) $result['pictures'], (int) $result['pending'],
            (int) $result['pruned'], (int) $result['insights'], (string) $result['insights_note'],
        ));

        return self::SUCCESS;
    }
}

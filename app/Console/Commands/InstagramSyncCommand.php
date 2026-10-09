<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * kbb:instagram-sync — RETIRED.                                   (Lane IGR)
 *
 * This was the daily fetch of our Instagram posts and the refresh of the
 * Instagram API token (Lane SG, 2.60.417). The owner, 9 October 2026: "the old
 * instagram api etc will be discontinue from the app". Its schedule line in
 * routes/console.php is gone and the credentials it used were deleted by
 * 2027_10_15_140100_forget_instagram_api_credentials.
 *
 * WHY THE FILE IS STILL HERE. Update packages add and replace files but cannot
 * delete one (`kbb:package` selects `--diff-filter=ACMR`), and Laravel discovers
 * every command in this directory. Left as it was, the server's copy would still
 * be a command that can call Instagram; replaced by this, it calls nothing.
 */
class InstagramSyncCommand extends Command
{
    protected $signature = 'kbb:instagram-sync {--seconds=600} {--unattended}';

    protected $description = 'Retired: the Instagram API module was removed. Instagram on the shop is Content → Instagram embeds.';

    public function handle(): int
    {
        $this->line('The Instagram API module was retired. Nothing to sync: use Content → Instagram embeds.');

        return self::SUCCESS;
    }
}

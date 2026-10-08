<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\ComingSoon;
use Illuminate\Console\Command;

/**
 * Appearance -> Coming Soon page, from the shell (Lane CS).
 *
 *     php artisan kbb:coming-soon off       the emergency switch
 *     php artisan kbb:coming-soon on        back on, same address and wording
 *     php artisan kbb:coming-soon           what it is doing now
 *
 * The admin path is never behind the page, so this should never be needed; it
 * exists so that "I cannot get in" has a one-line answer over SSH whatever
 * else has gone wrong. It writes through SettingsService::set(), which clears
 * the cached settings maps, so the next request of every PHP worker sees it.
 */
final class ComingSoonCommand extends Command
{
    protected $signature = 'kbb:coming-soon {action=status : on, off or status}';

    protected $description = 'Turn the Coming Soon page (Appearance → Coming Soon page) on or off, or show what it is doing';

    public function handle(SettingsService $settings): int
    {
        $action = strtolower((string) $this->argument('action'));

        if (! in_array($action, ['on', 'off', 'status'], true)) {
            $this->error('Use: php artisan kbb:coming-soon off   (or on, or status)');

            return self::INVALID;
        }

        if ($action !== 'status') {
            $settings->set(ComingSoon::KEY_ON, $action === 'on' ? '1' : '0', false);
            Setting::flushMap();
        }

        $status = ComingSoon::status(Setting::map());
        $this->line(($action === 'status' ? '' : 'Done. ').$status['line']);

        foreach ($status['warnings'] as $warning) {
            $this->warn($warning);
        }

        if ($action === 'off') {
            $this->line('If Varnish still shows the page, purge it in Cloudways (Application Settings → Varnish → Purge).');
        }

        return self::SUCCESS;
    }
}

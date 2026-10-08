<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Security\CountryDb;
use App\Services\Security\Firewall;
use App\Services\Security\FirewallConfig;
use App\Services\Security\FirewallStore;
use App\Services\Security\GoodBots;
use App\Services\Security\IpBlockList;
use Illuminate\Console\Command;

/**
 * Store → Security → Firewall from the shell.                      (Lane FW)
 *
 *   php artisan kbb:firewall off          EMERGENCY: stop the firewall now
 *   php artisan kbb:firewall monitor      log only
 *   php artisan kbb:firewall on           enforce
 *   php artisan kbb:firewall status
 *   php artisan kbb:firewall unban 203.0.113.7      (or a /24, /64)
 *   php artisan kbb:firewall data         download the country database and
 *                                         the good-bot lists now
 *   php artisan kbb:firewall data --auto  only what is due (the schedule)
 *   php artisan kbb:firewall data --file=dbip-country-lite.csv.gz
 *
 * `off` writes the setting AND recompiles the request-path file even if the
 * database write fails, so the off switch works on a sick database too.
 */
class FirewallCommand extends Command
{
    protected $signature = 'kbb:firewall {action : off|monitor|on|status|unban|data} {target? : for unban: an address or range}
        {--auto : data: only when due (countries monthly, bots weekly)}
        {--file= : data: build the country database from a DB-IP CSV or CSV.GZ already on disk}';

    protected $description = 'Firewall: switch off / monitor / on, show status, lift a ban, or refresh its data';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'off', 'monitor', 'on' => $this->mode($action === 'on' ? 'enforce' : $action),
            'status' => $this->status(),
            'unban' => $this->unban((string) $this->argument('target')),
            'data' => $this->data(),
            default => $this->fail('Unknown action "'.$action.'". Use off, monitor, on, status, unban or data.'),
        };
    }

    private function mode(string $mode): int
    {
        try {
            FirewallConfig::save(['mode' => $mode], 'console');
        } catch (\Throwable $e) {
            $this->warn('The database write failed ('.class_basename($e).'); switching the compiled file directly.');
            $data = IpBlockList::compiled();
            $data['fw']['mode'] = $mode;
            $path = IpBlockList::path();
            @file_put_contents($path, '<?php return '.var_export($data, true).';'."\n", LOCK_EX);

            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
        }

        IpBlockList::forget();
        $this->info('Firewall is now '.strtoupper(IpBlockList::compiled()['fw']['mode']).'.');
        $this->line('PHP-FPM may cache the old file for a moment; if it still acts, reload PHP-FPM.');

        return self::SUCCESS;
    }

    private function status(): int
    {
        $fw = IpBlockList::compiled()['fw'];
        FirewallStore::use((string) $fw['store']);
        $country = CountryDb::verify();

        $this->line('Mode:            '.$fw['mode']);
        $this->line('Scope:           '.$fw['scope']);
        $this->line('Limits:          '.$fw['ip_10s'].'/10 s, '.$fw['ip_60s'].'/60 s per address; '.$fw['net_60s'].'/60 s per range; prefetch '.$fw['prefetch_10s'].'/10 s');
        $this->line('Countries:       '.(json_encode($fw['countries']) ?: '{}'));
        $this->line('Counter store:   '.FirewallStore::name());
        $this->line('Country data:    '.($country['ok'] ? $country['v4'].' IPv4 + '.$country['v6'].' IPv6 ranges, built '.$country['date'] : 'MISSING — '.$country['error'].' (run: php artisan kbb:firewall data)'));
        $this->line('Active bans:     '.count(Firewall::bans()));

        return self::SUCCESS;
    }

    private function unban(string $target): int
    {
        $subject = str_contains($target, '/') ? $target : bin2hex((string) \App\Support\IpRange::pack($target));
        FirewallStore::use((string) IpBlockList::compiled()['fw']['store']);

        if ($subject === '' || ! Firewall::unban($subject)) {
            $this->warn('No ban found for '.$target.'.');

            return self::FAILURE;
        }

        $this->info('Ban lifted for '.$target.'.');

        return self::SUCCESS;
    }

    private function data(): int
    {
        $file = $this->option('file');

        if (is_string($file) && $file !== '') {
            $r = CountryDb::build([$file], CountryDb::path(), (int) date('Ymd'));
            $this->info('Country database: '.$r['v4'].' IPv4 + '.$r['v6'].' IPv6 ranges.');

            return self::SUCCESS;
        }

        $auto = (bool) $this->option('auto');
        $country = CountryDb::verify();
        $countryDue = ! $country['ok'] || filemtime(CountryDb::path()) < time() - 32 * 86400;
        $botsAt = GoodBots::ranges()['meta']['at'] ?? null;
        $botsDue = $botsAt === null || strtotime((string) $botsAt) < time() - 7 * 86400;
        $failed = FirewallStore::get('fw:data:failed');

        if ($auto && $failed !== null) {
            return self::SUCCESS; // a failure in the last six hours: do not hammer
        }

        $ok = true;

        if (! $auto || $countryDue) {
            $r = CountryDb::download();
            $r['ok'] ? $this->info('Country database: '.$r['v4'].' IPv4 + '.$r['v6'].' IPv6 ranges.')
                : $this->error('Country database not downloaded: '.$r['error']);
            $ok = $ok && $r['ok'];
        }

        if (! $auto || $botsDue) {
            foreach (GoodBots::refresh() as $family => $r) {
                $this->line(str_pad($family, 11).($r['ok'] ? $r['v4'].' IPv4 + '.$r['v6'].' IPv6 ranges' : 'kept the old list: '.$r['error']));
            }
        }

        if (! $ok) {
            FirewallStore::put('fw:data:failed', 1, 6 * 3600);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}

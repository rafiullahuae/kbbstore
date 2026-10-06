<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Push\PushGeo;
use App\Services\Push\PushRules;
use Illuminate\Console\Command;

/**
 * The optional IP → emirate table for Push Notifications (Lane PN): DB-IP's
 * free "IP to City Lite", UAE rows only. See App\Services\Push\PushGeo.
 *
 *   php artisan kbb:push-geo-import                 download this month's file now
 *   php artisan kbb:push-geo-import --file=x.csv.gz import a file already on disk
 *   php artisan kbb:push-geo-import --auto          what the scheduler runs hourly:
 *                                                   only when the switch is on and the
 *                                                   table is empty or over 32 days old,
 *                                                   and not within 6 hours of a failure
 */
class PushGeoImport extends Command
{
    protected $signature = 'kbb:push-geo-import {--file= : a DB-IP City Lite CSV or CSV.GZ already on disk} {--auto : only when due}';

    protected $description = 'Import the UAE rows of DB-IP City Lite for locating shop-app subscribers by IP.';

    public function handle(PushRules $rules): int
    {
        $file = $this->option('file');
        if (is_string($file) && $file !== '') {
            $n = PushGeo::import($file);
            PushGeo::meta(['rows' => $n, 'month' => 'file', 'at' => now()->toIso8601String(), 'error' => null]);
            $this->info("Imported {$n} UAE ranges.");

            return self::SUCCESS;
        }

        if ($this->option('auto')) {
            if (! $rules->get('geo_ip')) {
                return self::SUCCESS;
            }
            $s = PushGeo::status();
            $fresh = ($s['ranges'] ?? 0) > 0 && isset($s['at']) && strtotime((string) $s['at']) > time() - 32 * 86400;
            $failedRecently = isset($s['tried_at']) && strtotime((string) $s['tried_at']) > time() - 6 * 3600;
            if ($fresh || $failedRecently) {
                return self::SUCCESS;
            }
        }

        try {
            $n = PushGeo::download();
        } catch (\Throwable $e) {
            $this->error('Not imported: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Imported {$n} UAE ranges.");

        return self::SUCCESS;
    }
}

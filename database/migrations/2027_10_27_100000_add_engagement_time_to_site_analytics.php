<?php

declare(strict_types=1);

use App\Services\Analytics\Rollup;
use App\Support\StoreTime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Analytics, "Time on site & engagement" (Lane AT).
 *
 * Raw hits live 48 hours, so time on site has to be banked by the minute
 * rollup into the summaries the board reads for 7 / 30 / 90 days:
 *
 *   an_days.timed  sessions with two or more page views (the measurable ones)
 *   an_days.secs   their active seconds, summed
 *   an_days.tvis   visitors with two or more hits that day (pages or carts)
 *   an_days.vsecs  their active seconds, summed
 *   an_dims.timed  the same "measurable" count per dimension value
 *   an_dims.secs   the same seconds per dimension value
 *
 * Four integers on a ~one-row-per-day table and two on a capped one; nothing
 * on an_hits, so the shop's beacon writes exactly what it wrote before.
 *
 * Then it rebuilds today and yesterday from the raw hits still held, so the
 * board has figures (and a "vs yesterday") the moment the package lands.
 * Only those two days: the day before yesterday is already partly pruned, and
 * rebuilding it would LOWER its totals. A rebuild is one transaction per day
 * and idempotent; one that fails leaves the old rows as they were, and the
 * minute rollup builds today again within a minute anyway.
 *
 * And the console's compiled views go, so the board's new block is served.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('an_days') && ! Schema::hasColumn('an_days', 'timed')) {
            Schema::table('an_days', function (Blueprint $t) {
                $t->unsignedInteger('timed')->default(0);
                $t->unsignedInteger('secs')->default(0);
                $t->unsignedInteger('tvis')->default(0);
                $t->unsignedInteger('vsecs')->default(0);
            });
        }

        if (Schema::hasTable('an_dims') && ! Schema::hasColumn('an_dims', 'timed')) {
            Schema::table('an_dims', function (Blueprint $t) {
                $t->unsignedInteger('timed')->default(0);
                $t->unsignedInteger('secs')->default(0);
            });
        }

        $rebuilt = 0;
        if (Schema::hasTable('an_hits')) {
            foreach ([StoreTime::now()->subDay()->format('Y-m-d'), StoreTime::now()->format('Y-m-d')] as $day) {
                [$a, $b] = Rollup::minutes($day);
                // A day with no raw hits left has nothing to rebuild from.
                if (! DB::table('an_hits')->where('m', '>=', $a)->where('m', '<', $b)->exists()) {
                    continue;
                }
                try {
                    Rollup::rollDay($day);
                    $rebuilt++;
                } catch (\Throwable) {
                    // Its own transaction rolled back: the day's rows are as they were.
                }
            }
        }

        $cleared = 0;
        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Rebuilt {$rebuilt} day(s) with time on site; cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('an_days') && Schema::hasColumn('an_days', 'timed')) {
            Schema::table('an_days', fn (Blueprint $t) => $t->dropColumn(['timed', 'secs', 'tvis', 'vsecs']));
        }
        if (Schema::hasTable('an_dims') && Schema::hasColumn('an_dims', 'timed')) {
            Schema::table('an_dims', fn (Blueprint $t) => $t->dropColumn(['timed', 'secs']));
        }
    }
};

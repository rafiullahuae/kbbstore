<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane LT: Catalog -> Translate & categorise adds admin routes
 * (admin-api/recent-localise/*), a console command (kbb:recent-localise), new
 * classes under App\Services\RecentLocalise and a changed
 * ClaudeConcernSuggester, so the compiled route cache is cleared with views,
 * config and the event/command cache, and opcache reset -- otherwise the
 * sidebar row renders but every request to the new paths 404s and PHP keeps
 * the old bytecode. Literals only: no application class is loaded. Nothing in
 * the database moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/events.php'),
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

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Translate & categorise: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};

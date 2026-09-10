<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Warms config and route caching for this release specifically.
 *
 * UpdateRunner.php now does this automatically after every future update —
 * but the version of it that actually runs *this* update is still the old
 * one already loaded into memory for this request, since PHP does not
 * hot-swap a class mid-execution just because the file on disk changed
 * underneath it. Without this migration, the benefit would only start
 * showing up on the release after this one. This runs it once, directly,
 * so it takes effect immediately.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['config:cache', 'route:cache'] as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable) {
                // Left uncached is the same behaviour every release before
                // this one already had — never worth failing the update over.
            }
        }
    }

    public function down(): void
    {
        // Nothing to reverse — these are just compiled artifacts.
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.601: Tabby auto-capture adds a console command (payments:tabby-capture),
 * a schedule entry in routes/console.php and a new gateway settings field, so
 * the compiled view/config/event/route caches are cleared and opcache reset —
 * otherwise a server that boots from a cached config or event map would run the
 * old schedule and the payments screen would render the cached Tabby settings
 * without the new switch. Literals only: no application class is loaded.
 * Nothing in the database moves here (the On default is a separate migration).
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
            echo "Tabby auto-capture: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};

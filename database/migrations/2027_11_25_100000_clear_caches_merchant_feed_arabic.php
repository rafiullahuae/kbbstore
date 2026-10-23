<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * The Arabic Google Shopping feed (the owner, 23 October 2026) adds one GET,
 * for it until it is cleared (CLAUDE.md, "How this ships").
 * Literals only: no application class is loaded. Nothing in the database
 * moves; the English feed is unchanged.
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
            echo "Arabic Merchant feed: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};

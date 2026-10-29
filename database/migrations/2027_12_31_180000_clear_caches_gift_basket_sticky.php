<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.625 (gift box): the "Your basket" range box stays in sight in the
 * Gifts Criteria range row, whichever range is chosen -- it sticks to the
 * row's edge instead of sliding out. Box views only (CSS). Views, config,
 * routes and the event cache are cleared and opcache reset. Literals only.
 * Nothing in the database moves.
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
            echo "Gift basket box: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};

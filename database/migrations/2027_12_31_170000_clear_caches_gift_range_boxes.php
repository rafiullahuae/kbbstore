<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.624 (gift box): the range boxes in the Gifts Criteria tabs popup take
 * the owner's pick "B2 + T5" -- the gift count under each amount, a green
 * outline and tick on the chosen one, "Your basket" as a pink pill on the
 * box's top line. FreeGifts (a new extras key, default T5) and the box views
 * changed, so views, config, routes and the event cache are cleared and
 * opcache reset. Literals only. Nothing in the database moves: the new key
 * reads as its default.
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
            echo "Gift range boxes: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};

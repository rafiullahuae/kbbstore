<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the blank admin screens fix.
 *
 * VIEWS, and only views. One Blade file changed —
 * resources/views/admin/app.blade.php — and it already exists on the server,
 * which is exactly the case that does not self-correct: compiled Blade is keyed
 * by path, so the admin console would keep rendering the previous compiled copy
 * and every one of the ten screens would stay blank while the package reported
 * success. That failure mode is especially bad here, because the whole point of
 * the change is that the owner stops seeing blank screens; shipping it without
 * this clear would look identical to not shipping it at all.
 *
 * No route was added, so there is no route-cache concern beyond the routine
 * clear below — but the clear is kept whole rather than trimmed to views,
 * because a half-cleared bootstrap/cache is how you get a config and a routes
 * file that disagree with each other.
 *
 * No new PHP class ships with this one. The only other file in the change is a
 * test, which the server never runs.
 *
 * No schema change, and nothing here positions a column with an AFTER clause —
 * the thing that made nine earlier migrations in this repo silent no-ops on
 * MySQL.
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
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};

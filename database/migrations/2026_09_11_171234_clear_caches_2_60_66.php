<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for 2.60.66 — specifically a defensive re-clear of
 * bootstrap/cache/routes-*.php.
 *
 * The demo-content routes added in 2.60.64 are confirmed correct in every
 * shipped package (verified byte-for-byte against routes/web.php in the
 * actual .zip), yet the live site returned "route could not be found" —
 * the exact failure mode this codebase already has documented history
 * with (see 2026_09_10_100000_clear_caches_2_60_48): a stale compiled
 * routes file takes absolute priority over the real routes/web.php the
 * moment one exists on disk. UpdateRunner's own clearCaches() step already
 * runs route:clear on every update, so this should not be reachable in
 * the normal path — this migration exists as a second, independent way to
 * force it, in case something on the specific server (a permissions
 * failure that let unlink() fail silently, a separate optimisation step,
 * anything else clearCaches() alone couldn't account for) let a stale
 * cache survive that step.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ($this->targets() as $pattern) {
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

    private function targets(): array
    {
        return [
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/events.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ];
    }

    public function down(): void {}
};

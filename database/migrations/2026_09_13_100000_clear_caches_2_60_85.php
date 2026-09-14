<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for 2.60.85.
 *
 * 2.60.82 added POST /checkout/gift and referenced it by name from the
 * checkout view. A stale bootstrap/cache/routes-*.php takes absolute priority
 * over routes/web.php once it exists, so route('checkout.gift') raised
 * RouteNotFoundException and every checkout page returned 500 -- the same
 * failure this codebase already hit in 2.60.48 and again in 2.60.66.
 *
 * Both of those shipped a migration exactly like this one. 2.60.72 through
 * .84 did not, which is why the fault came back the moment a package added a
 * route. Compiled views matter here too: 2.60.76 onward changed blade files
 * that are compiled and cached.
 *
 * UpdateRunner::clearCaches() already runs on every update; this is the
 * second, independent path, for the case where that step cannot delete the
 * files (a permissions failure letting unlink() fail quietly, a separate
 * optimisation step putting them back).
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

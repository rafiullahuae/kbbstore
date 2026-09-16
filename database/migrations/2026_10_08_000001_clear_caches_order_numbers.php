<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the order-number allocator. (Lane BA)
 *
 * NO NEW ROUTES in this package, so the usual headline reason does not apply —
 * but three of the other four caches do, and each would fail differently and
 * quietly.
 *
 * SERVICES AND PACKAGES are the sharp edge here. This package adds a class,
 * App\Services\Orders\OrderNumbers, and injects it into two constructors:
 * Store\CheckoutController and ManualOrderBuilder. The container resolves both
 * through the compiled service manifest, and on a host where that manifest and
 * OPcache both predate the new file, the injection fails at the moment a
 * shopper presses Place order — the one code path in the application where an
 * error costs a sale. Clearing it is the difference between the package
 * working and the checkout 500ing on the first real order.
 *
 * VIEWS go because resources/views/admin/app.blade.php changed, and that is
 * exactly the case that does not self-correct: compiled Blade is keyed by path
 * with no content check, and the file ALREADY EXISTS on the server, so the
 * console would go on serving the previous compiled copy. The change is the
 * product editor's save no longer truncating AED 99.50 to AED 99 — a silent
 * wrong number, so a stale copy would look like the package had simply not
 * fixed anything.
 *
 * CONFIG goes with them, and OPcache is reset for the reason packages
 * 2.60.102-.106 are still cited in CLAUDE.md: this host cannot be restarted.
 *
 * SCHEMA belongs to 2026_10_08_000000_create_order_number_sequence, which runs
 * first and seeds the sequence above every number already in `orders`. Nothing
 * here positions a column with an AFTER clause, the thing that made nine
 * earlier migrations in this repo silent no-ops on MySQL.
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

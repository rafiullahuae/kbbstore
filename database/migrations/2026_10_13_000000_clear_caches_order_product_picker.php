<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the shared order product picker.
 *
 * Three Blade files are involved: a new partial,
 * admin/partials/product-picker.blade.php, and the two screens that now use it
 * — admin/app.blade.php and admin/partials/manual-order-screen.blade.php. The
 * new partial is @included from app.blade.php, and a COMPILED app.blade.php
 * carries no include: the compiler resolves it at compile time. So without this
 * the server would keep serving the old compiled console, window
 * .kbbProductPicker would never be defined, and both pickers would silently do
 * nothing at all — a worse failure than the one being fixed.
 *
 * No route changed, so the route cache is correct either way; it is cleared with
 * the rest because the cost is nil and a half-cleared cache is the harder thing
 * to reason about.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets go
 * of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md. AdminOrderController changed too (the
 * type-ahead's column allowlist, and the eager load behind the line item
 * images), which is exactly that case.
 *
 * Nothing is read through Setting::map() by either picker, so there is no cache
 * key to forget here.
 *
 * No schema change, and nothing here positions a column with an AFTER clause,
 * the thing that made nine earlier migrations silent no-ops on MySQL.
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

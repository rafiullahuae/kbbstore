<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Reviews screens pass — Lane CG.
 *
 * SIX Blade files changed and one is new:
 *
 *   admin/app.blade.php                            NAV, TITLES, the All Reviews
 *                                                  header and toolbar, the reply
 *                                                  modal, one new @include
 *   admin/partials/reviews-io-screen.blade.php
 *   admin/partials/review-assign-screen.blade.php
 *   admin/partials/review-bulk-screens.blade.php
 *   admin/partials/review-settings-screen.blade.php
 *   admin/partials/review-capsule-screen.blade.php
 *   admin/partials/review-queue-badge.blade.php     (new)
 *
 * The console is ONE compiled view: app.blade.php pulls every partial in with
 * @include, so the compiled file in storage/framework/views holds all of them
 * inlined. Laravel recompiles when the PARENT's mtime moves, and a package that
 * rewrote only a partial would leave the old copy of that partial serving from
 * the parent's cached compile. app.blade.php changed in this pass as well, so
 * that particular trap is not armed here — but the next Reviews package may
 * touch only a partial, and this migration is what that one will be copied
 * from. It clears the compiled views unconditionally for that reason.
 *
 * NO ROUTE CHANGED. Everything in this pass is client-side: markup, CSS and the
 * order in which the screens repaint themselves. The route cache is cleared with
 * the rest anyway, because the cost is nil and a half-cleared cache is the
 * harder thing to reason about — the same reasoning as
 * 2026_10_12_000001_clear_caches_review_badge_parity.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets go
 * of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md.
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

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the admin permissions layer.
 *
 * No route was added — the layer attaches to the `web` middleware group in
 * bootstrap/app.php and finds admin routes by the auth:admin guard they already
 * declare — so this is not the usual "a route will not exist until the route
 * cache is cleared" case. It ships anyway, and for a sharper reason.
 *
 * bootstrap/cache/routes-*.php serialises each route's middleware list as it
 * stood when the cache was written. A server still holding a route cache from
 * before this package would dispatch admin routes through a pipeline that has
 * never heard of EnforceAdminCapability: the files would be on disk, the tests
 * would have passed, and `support` would still be able to promote itself to
 * owner. A security layer that is silently inert is worse than one that is
 * visibly missing, so the route cache goes.
 *
 * config.php goes with it because the web group is assembled while the
 * application is configured, and services.php/packages.php because a
 * half-cleared bootstrap cache is the harder thing to reason about than an
 * empty one. The view cache is untouched: no Blade file changed.
 *
 * OPcache is the other half, as always on this host. The server cannot be
 * restarted or shelled into, so the PHP a package writes is not the PHP the
 * server runs until OPcache lets go of the old copy — the standing reason
 * behind the withdrawn packages 2.60.102-.106 recorded in CLAUDE.md. Here that
 * matters more than usual: the old copy of bootstrap/app.php is the version
 * with no permission layer in it.
 *
 * No schema change. Nothing here positions a column with an AFTER clause, the
 * thing that made nine earlier migrations silent no-ops on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/config.php'),
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

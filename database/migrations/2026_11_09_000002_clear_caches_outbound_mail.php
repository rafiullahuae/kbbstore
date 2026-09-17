<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Drop the compiled route cache, because this package adds routes (Lane EE).
 *
 * CLAUDE.md states the rule and the reason: the server has no shell, so nothing
 * can run `php artisan route:clear` there. A route added in a PHP file that
 * `routes/web.php` requires does not exist until the serialised route table
 * under bootstrap/cache is gone, and until then the five new addresses below
 * 404 — which on a confirmation link means every newsletter signup made after
 * this update leads to a dead page in the shopper's inbox.
 *
 * FIVE NEW ROUTES:
 *
 *   GET  /newsletter/confirm/{id}/        routes/newsletter-public.php
 *   POST /newsletter/confirm
 *   GET  /newsletter/unsubscribe/{id}/
 *   POST /newsletter/unsubscribe
 *   GET  /admin-api/mail/log              routes/mail-admin.php
 *
 * THE COMPILED VIEWS GO TOO, and not merely for tidiness. This package changes
 * emails/newsletter-confirm.blade.php and three new store/newsletter/* views,
 * and — more to the point — Blade compiles to files named by a hash of the
 * view's PATH, not its contents. A stale compiled view is served happily
 * forever. The order-email views are unchanged, so nothing there is at risk;
 * dropping the lot is simply cheaper than enumerating which.
 *
 * `config.php` goes because config/mail.php's resolution is read through it and
 * this package's provider now registers two event listeners at boot.
 *
 * NO SCHEMA CHANGE HERE. The two migrations beside this one carry those, and
 * neither uses ->after() — see tests/Feature/MigrationConventionTest.php for
 * why that matters on this host.
 *
 * Best-effort throughout, as the other clear_caches migrations are: a file that
 * cannot be unlinked mid-update must not fail the package and strand the site
 * half-updated. Anything left behind is a stale cache, which is a visible bug;
 * a failed migration is an outage.
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
            echo "Cleared {$cleared} compiled files; the newsletter and mail-log routes are now reachable.\n";
        }
    }

    public function down(): void {}
};

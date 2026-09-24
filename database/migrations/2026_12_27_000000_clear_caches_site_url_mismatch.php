<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the APP_URL mismatch banner.
 *
 * TWO NEW ROUTES. `GET /admin-api/site-url` and `POST /admin-api/site-url/adopt`
 * arrive in routes/site-url-admin.php. The router dispatches against
 * bootstrap/cache/routes-*.php, so until that file is gone both answer 404 and
 * the banner on Platform → Site address reports "could not load".
 *
 * A NEW ADMIN VIEW PARTIAL. resources/views/admin/partials/site-url-banner.blade.php
 * is @included by admin/app.blade.php, and a compiled Blade view is only
 * recompiled when the file it came from is newer than it. An update package
 * copies files with whatever timestamps the archive carries, so the compiled
 * copy can win. Clearing storage/framework/views is the reason this migration
 * globs it.
 *
 * ▲ AND THE CONFIG CACHE, WHICH IS THE WHOLE POINT OF THE FEATURE. The thing
 * this banner writes is APP_URL in .env, and **.env is not read at all while
 * bootstrap/cache/config.php exists**. That is this repository's oldest
 * landmine — it is why KBB_NOINDEX read false for its entire life — and it is
 * the reason App\Support\SiteUrl::writeEnv() deletes the compiled config as
 * part of every write rather than beside it. Clearing it here as well means the
 * shop the package lands on is already in the state the feature assumes.
 *
 * NOTHING CHANGES ON A SHOP SERVED FROM THE ADDRESS IT IS CONFIGURED WITH.
 * SiteUrl::mismatch() returns null when APP_URL and the served address agree,
 * the banner does not draw, and nothing writes anything. extrabeauty.ae is
 * served from extrabeauty.ae, so applying this moves nothing there.
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
            echo "Cleared {$cleared} compiled files. Platform -> Site address now warns when this\n"
                ."shop is served from an address that is not APP_URL, and offers one button to\n"
                ."write the new one. It draws nothing while the two agree, which is the case here.\n";
        }
    }

    public function down(): void {}
};

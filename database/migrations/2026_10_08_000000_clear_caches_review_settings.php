<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Review Settings package (Lane BB).
 *
 * WHY THIS PACKAGE NEEDS ONE — all three caches are live.
 *
 *   - ROUTES. routes/review-settings-admin.php adds two routes
 *     (GET and PUT /admin-api/reviews/settings). CLAUDE.md is explicit: a route
 *     added to this application does not take effect until the compiled route
 *     cache is cleared, because the server has no shell and the cache is only
 *     ever rebuilt by a migration like this one. Without it the screen renders
 *     perfectly and every request it makes returns 404 — which reads as "the
 *     package did not apply" when in fact it did. The screen says exactly that
 *     when it sees a 404, but the cure is here.
 *
 *   - VIEWS. Two Blades changed and one is new:
 *     resources/views/partials/reviews.blade.php now reads its defaults through
 *     App\Support\ReviewSettings, resources/views/admin/app.blade.php gained the
 *     include and the LIVE_RENDERED entry, and
 *     resources/views/admin/partials/review-settings-screen.blade.php is new.
 *     Compiled Blade is keyed by path, so a stale copy of a file that ALREADY
 *     EXISTS is the case that never self-corrects: the storefront would go on
 *     running the previous compiled reviews partial — the one with the hard-coded
 *     empty-state string and no clamping — for ever, and the console would go on
 *     drawing the "isn't installed yet" card over a screen that is now installed.
 *
 *   - OPCACHE. App\Support\ReviewSettings and
 *     App\Http\Controllers\Admin\ReviewSettingsApiController are new classes,
 *     which OPcache handles cleanly on its own. The trap is the two EXISTING
 *     files that changed to read them — Store\ProductController and
 *     Store\ReviewController. A worker holding a stale compiled copy of either
 *     would go on hard-coding ->latest(), limit(200) and a five-an-hour ceiling
 *     while the settings table says otherwise, which is the "I saved it and
 *     nothing happened" report this screen exists to make impossible.
 *
 * NO SCHEMA CHANGE, AND NO DATA WRITE EITHER. The eleven `sr_*` keys are not
 * seeded. Every default in ReviewSettings::SCHEMA is the literal the storefront
 * already hard-coded, and SettingsService::get() falls back to it when the row
 * is absent, so an untouched store behaves identically before and after this
 * package and a store that HAS saved the screen keeps what it chose. Writing
 * defaults here would be the one way to overwrite an owner's decision.
 *
 * Nothing here positions a column with an AFTER clause: that is the clause that
 * made nine earlier migrations in this repo silent no-ops on MySQL, where an
 * ALTER naming a column that does not exist yet is an error and the hasColumn
 * guards around them made that error look clean.
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

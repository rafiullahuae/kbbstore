<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Views for the instant step rail on Content → Shoppable video → All clips.
 *
 * NO ROUTE IS ADDED, so there is no route cache to worry about. Every change is
 * inside one existing Blade partial —
 * resources/views/admin/partials/ugc-library-screen.blade.php — plus one query
 * change in an existing controller.
 *
 * THE VIEW CACHE IS WHY THIS EXISTS. `storage/framework/views` keys a compiled
 * view by the PATH of its source and decides staleness on file times, and an
 * unzip's timestamps are not reliably newer than what is already on disk. A shop
 * that kept its compiled console would apply this package, report it applied,
 * and go on running the old screen — which is the one where moving between the
 * five steps waits for four round trips.
 *
 * ── WHAT IT CHANGES, AND WHAT IT LEAVES ALONE ──────────────────────────────
 *
 * Moving between the five steps of a clip now paints from what is already in
 * the browser: no request, and the video is no longer thrown away and rebuilt
 * on the way past. Moving forward still saves — that has not changed — but the
 * save happens behind the paint instead of in front of it, and a save that
 * FAILS now says so in the footer until it succeeds instead of in a toast that
 * has gone by the time anybody looks up. Unsaved typing is kept in the browser
 * and OFFERED on the way back in; it is never applied without being asked for.
 *
 * Step 4's product search now says which kind of empty it is — still looking,
 * nothing matched, or nothing typed yet — and shows the newest products before
 * anybody types. A term that matches only drafts says so.
 *
 * No setting added, no default changed, no row written, and nothing a shopper
 * sees is touched: every change is inside the admin console.
 * StorefrontEnglishUnchangedTest does not move.
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
            echo "Cleared {$cleared} compiled files. Moving between the five steps of a clip is\n"
                ."now instant and makes no request, and the video is no longer re-fetched and\n"
                ."restarted on the way past. Unsaved typing survives a reload and is offered\n"
                ."back rather than applied behind your back. The product search on step 4 now\n"
                ."says when nothing matched, says when what matched is a draft, and shows the\n"
                ."newest products before you type. No setting changed and nothing on the\n"
                ."storefront moved.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};

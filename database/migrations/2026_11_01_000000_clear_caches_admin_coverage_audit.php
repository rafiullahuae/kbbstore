<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the admin coverage audit — Lane DD.
 *
 * ONE BLADE FILE AND ONE PHP CLASS CHANGED, and both have to be the new copy on
 * the server or the package lands and the defects it fixes go on looking
 * unfixed. That is the quiet failure this repo has paid for before: no error,
 * no warning, the old compiled view served out of storage/framework/views from
 * a source file that is no longer on disk.
 *
 *   resources/views/admin/app.blade.php
 *
 *       Four statements the console was making that nothing behind them did.
 *
 *       - The amber strip under the top bar told the owner that while the
 *         Live / Sandbox switch read Sandbox his changes were held back from
 *         the live store until a deploy. There is one database; the switch sets
 *         an attribute on <body> and nothing else reads it. It now says so, and
 *         so does the toast the switch raises.
 *
 *       - Safety → Sandbox & Deploy offered five pre-flight checks lit green, a
 *         diff summary, a Deploy to Live button and a Rollback button, and
 *         promised a backup of the live database on every deploy. The checks
 *         were string literals and the deploy handler was a setTimeout. The
 *         screen now points at Core Updates, which really does verify a
 *         package, record the release and keep a restore point.
 *
 *       - The dashboard's "Recent activity" card was labelled a live feed over
 *         three invented events dated "just now", and hydrateDash() only
 *         replaced them when the store had recent orders to replace them with.
 *
 *       - Store → SEO & Meta described its Verification & tracking section as
 *         changing nothing about the storefront, while one of its fields loads
 *         Google's tag on every page and another writes a key no page reads.
 *
 *   app/Support/Seo.php
 *
 *       The Pinterest and Baidu verification tokens now reach the <head>. Both
 *       have always been accepted by PUT /admin-api/settings and both were
 *       saved; neither was ever rendered, so the owner could not verify with
 *       either service and nothing on the screen said why. OPcache is the half
 *       of this that matters for a PHP change on a host with no shell — the PHP
 *       a package writes is not the PHP the server runs until OPcache lets go,
 *       which is the standing reason behind the withdrawn packages
 *       2.60.102-.106 recorded in CLAUDE.md.
 *
 * NO ROUTE CHANGED and no schema changed. The route cache is dropped with the
 * rest anyway: the cost is nil and a half-cleared cache is the harder thing to
 * reason about.
 *
 * `meta_pixel` IS DELIBERATELY NOT DELETED from the settings table. After this
 * change it still has no reader on any storefront page — the pixel that fires
 * is App\Services\MarketingPixels' `meta_id` — but which of the two boxes the
 * shop keeps is the owner's decision, and a migration that deletes an ID he
 * typed in is not recoverable while a row nothing reads is merely dead. The
 * same restraint Lane CZ applied to `free_shipping_threshold`.
 *
 * Nothing here positions a column with an AFTER clause, the thing that made
 * nine earlier migrations silent no-ops on MySQL.
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

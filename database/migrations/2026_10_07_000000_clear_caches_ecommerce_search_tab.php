<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clear compiled caches for the Ecommerce Search tab removal and the admin
 * phone-width fixes, and delete the settings rows the removed tab wrote.
 *
 * VIEWS are the reason this migration exists at all.
 * resources/views/admin/app.blade.php changed and already exists on the server,
 * which is exactly the case that does not self-correct: compiled Blade is keyed
 * by path, so the admin would keep serving the previous compiled copy. Four
 * screens would still scroll sideways on a phone and the Homepage demo-content
 * toggle would still ask to hide when it meant show, while the package reported
 * success. Packages 2.60.102-.106 are the standing reminder of what that costs
 * on a host with no shell.
 *
 * NO ROUTE WAS ADDED by this change, so the route cache clear below is the
 * routine one rather than the reason for it.
 *
 * NO SCHEMA CHANGE. Nothing here positions a column with an AFTER clause, the
 * thing that made nine earlier migrations in this repo silent no-ops on MySQL.
 *
 * ---------------------------------------------------------------------------
 * THE SETTINGS ROWS, and why they are deleted rather than migrated.
 *
 * Store -> Ecommerce -> Search wrote four TOP-LEVEL settings rows through
 * SettingsService. Everything that reads those names reads them from inside the
 * `header_settings` blob instead, via App\Services\HeaderSettings, so no row
 * below was ever read by anything. They are debris, and leaving them invites
 * the next person to assume they mean something.
 *
 * They are NOT copied into header_settings. A value in one of these rows is a
 * setting the operator believed they had applied and never had; writing it
 * across now would change live search behaviour at upgrade time, silently, in
 * whichever direction they happened to have typed. The honest move is to drop
 * the rows and leave the search settings exactly as Site Search has them.
 *
 * `search_results_max` is deliberately absent from this list: it is a real Site
 * Search field, lives in header_settings, and nothing here should touch it.
 */
return new class extends Migration
{
    /**
     * Written by the removed tab, read by nothing.
     *
     * Every one of these names also exists as a key INSIDE the header_settings
     * blob, where it is live. Deleting the top-level row of the same name does
     * not touch the blob.
     */
    private const DEAD_KEYS = [
        'search_limit_products',
        'search_min_chars',
        'search_limit_categories',
        'search_limit_brands',
    ];

    public function up(): void
    {
        $removed = 0;

        if (DB::getSchemaBuilder()->hasTable('settings')) {
            $removed = DB::table('settings')->whereIn('key', self::DEAD_KEYS)->delete();
        }

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
            echo "Cleared {$cleared} compiled files, removed {$removed} dead search settings rows.\n";
        }
    }

    /**
     * Deliberately empty. The rows this dropped were unreachable by every
     * reader in the application, so there is nothing a rollback could restore
     * that anything would go on to read.
     */
    public function down(): void {}
};

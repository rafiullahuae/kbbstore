<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Category headers read "hide" on the live shop. (Integrator, 2.60.350)
 *
 * The owner, on /collections/skincare/ with "hide" as the heading: "on all
 * banners just coming this thing. i wanted the category name, with 2 lines
 * description, that's it".
 *
 * Exporter 1.11.0 finds a category's title override by term-meta key NAME, and
 * on his shop that key held the old theme's "hide the title" switch, so the
 * import wrote "hide" into header_title on every category. TitleHeader no
 * longer imports title_override / subtitle at all; this clears what the import
 * already wrote, on categories and brands, so every header reads its name.
 * A custom title belongs to Catalog → Categories → Edit → Category header,
 * which existed for about an hour before this ran.
 *
 * It also lets "Description lines" reach its new default of 2 on a shop that
 * saved the old 3.
 */
return new class extends Migration
{
    private const RESET = ['layout_cat_header_lines'];

    public function up(): void
    {
        foreach (['categories', 'brands'] as $table) {
            try {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'header_title')) {
                    $n = DB::table($table)->whereNotNull('header_title')->orWhereNotNull('header_subtitle')
                        ->update(['header_title' => null, 'header_subtitle' => null]);

                    if (app()->runningInConsole()) {
                        echo "Category header: cleared the imported title on {$n} {$table} row(s).\n";
                    }
                }
            } catch (\Throwable $e) {
                if (app()->runningInConsole()) {
                    echo "Category header: could not clear {$table} ({$e->getMessage()}).\n";
                }
            }
        }

        try {
            if (Schema::hasTable('settings')) {
                DB::table('settings')->whereIn('key', self::RESET)->delete();
            }
        } catch (\Throwable $e) {
            if (app()->runningInConsole()) {
                echo 'Category header: could not read the settings table ('.$e->getMessage().").\n";
            }
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

        // Best effort: on a host with no cache table or a cold store this is a
        // no-op, and every one of these values is re-read from the database.
        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings', 'kbb.shop.cats', 'kbb.shop.brands'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
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

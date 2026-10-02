<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switch Quick view OFF on this shop. (Integrator, 2.60.354)
 *
 * The owner, 2 October: "turn off the quick view option by default". Store →
 * Modules → Quick view turns it back on, and when it is on the pill now sits in
 * the middle of the photograph (kbb.css, the rule at the end of the file).
 *
 * NOT IN THE TEST SUITE. Its database is built by running these migrations,
 * and the English walk pins every product card WITH the button, so the suite
 * keeps the registry's default and this writes the off on the shop alone --
 * the same split 2027_07_16_000100_random_light_box_on makes. Written once:
 * a migration runs once, so turning it back on stays on.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            if (! app()->runningUnitTests() && Schema::hasTable('module_toggles')) {
                DB::table('module_toggles')->updateOrInsert(
                    ['module' => 'quick_view'],
                    ['enabled' => false, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        } catch (\Throwable $e) {
            if (app()->runningInConsole()) {
                echo 'Quick view: could not switch it off ('.$e->getMessage().").\n";
            }
        }

        // The card and the layout read the module through compiled Blade and
        // the cached module snapshot; both go.
        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
            }
        }
    }

    public function down(): void {}
};

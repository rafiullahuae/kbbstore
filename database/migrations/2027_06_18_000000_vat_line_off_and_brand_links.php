<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The VAT line ships off, and the compiled views go.               (2.60.336)
 *
 * The owner, pointing at his own product page: "turned off the vat line on
 * product page by default." Under CLAUDE.md's 30-September reversal that is the
 * shop's new state rather than a switch for him to find, so the default on
 * `ProductSections::REGISTRY` moved true -> false and this migration carries the
 * change to a shop that has already saved that screen.
 *
 * ▲ WHY THE KEY IS REMOVED RATHER THAN WRITTEN false, which is the whole
 *   decision in this file. `ProductSections::all()` reads
 *   `$row['desktop'] ?? $default`, so a STORED value wins over the registry for
 *   ever. Writing `false` would turn "follows the shipped default" into "pinned
 *   to false by a migration" -- the same class of harm Lane GRID found on
 *   Product styles this week, where a save wrote nine keys at their current
 *   values and quietly froze every one of them against future defaults.
 *   Deleting the entry leaves the row following `REGISTRY`, which now says
 *   false, and the screen stores a value again the moment he chooses one.
 *
 * ▲ AND IT TOUCHES NOTHING ELSE IN THAT SETTING. `product_sections` holds one
 *   entry per section and the other sixteen are his; only `vat` is unset, and
 *   only when it is there. A shop that never saved the screen has no row at all
 *   and needs nothing -- the registry default is already false for it.
 *
 * THE CACHE CLEAR IS THE OTHER HALF AND IS NOT OPTIONAL: the same release makes
 * the brand name on the product page a link to `/brands/{slug}/`, which is a
 * change to `resources/views/store/product.blade.php`. Blade serves
 * `storage/framework/views` in preference to the template it compiled from, so
 * without this the brand name stays dead text with the package reporting as
 * applied. `Setting::map()` also memoises in a process-level static as well as
 * the cache (CLAUDE.md), so the setting cache is dropped here too.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('settings')->where('key', 'product_sections')->first();

        $changed = false;

        if ($row !== null) {
            $saved = json_decode((string) $row->value, true);

            if (is_array($saved) && array_key_exists('vat', $saved)) {
                unset($saved['vat']);

                DB::table('settings')
                    ->where('key', 'product_sections')
                    ->update(['value' => json_encode($saved)]);

                $changed = true;
            }
        }

        /* The settings snapshot is cached and memoised; a stale one would keep
           answering with the entry this migration just removed. */
        try {
            /* The literal keys, read out of SettingsService rather than
               guessed: CACHE_KEY, MODULES_KEY, MODULE_SETTINGS_KEY. The first
               draft of this file invented 'kbb.settings.all' and
               'kbb.settings.map', neither of which this application has ever
               written -- a forget() that names nothing throws nothing and
               clears nothing, so it would have read as done and left the stale
               snapshot in place. */
            \Illuminate\Support\Facades\Cache::forget('kbb.settings');
            \Illuminate\Support\Facades\Cache::forget('kbb.modules');
            \Illuminate\Support\Facades\Cache::forget('kbb.module_settings');
        } catch (\Throwable) {
            // A cache store that is unreachable must not fail an update.
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
            $note = $changed
                ? "The saved 'VAT line' choice was removed so it follows the new default."
                : "No saved 'VAT line' choice to remove; it already follows the default.";

            echo "Cleared {$cleared} compiled files. {$note}\n"
                ."The 'Inclusive of 5% VAT' line under the price is now OFF. Put it back at\n"
                ."Appearance -> Product page -> Sections -> VAT line.\n"
                ."The brand name above the product title is now a link to that brand's page.\n";
        }
    }

    public function down(): void {}
};

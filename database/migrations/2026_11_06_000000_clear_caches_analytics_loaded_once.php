<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane DP — one analytics identity per network, loaded exactly once.
 *
 * TWO JOBS, and the data one matters more than the cache one.
 *
 * ── 1. MOVE THE DUPLICATE IDS ────────────────────────────────────────────
 *
 * There were two admin boxes for the Google measurement ID and two for the
 * Meta pixel ID, saved in two different tables:
 *
 *     settings.ga           <->  module_settings(marketing_pixels, ga4_id)
 *     settings.meta_pixel   <->  module_settings(marketing_pixels, meta_id)
 *
 * and two separate emitters read them, in the same <head>, fifty lines apart.
 * A shop with both Google boxes filled in loaded Google's tag twice and called
 * gtag('config', …) twice on every page, which is two page_view hits per page
 * view: sessions and users roughly doubled, conversion rate halved, and every
 * per-session number in the account wrong in the same direction.
 *
 * This moves each legacy value into the canonical module key and deletes the
 * legacy row, so exactly one value per network survives.
 *
 * WHAT IT REFUSES TO DO. It never overwrites a canonical key that already
 * holds something: if both boxes were filled in with DIFFERENT ids, the
 * Marketing Pixels one is the live one today (it is the one with the events
 * hanging off it) and it stays live. The legacy row is still deleted in that
 * case, and the id it held is written into the migration's console output
 * first, because leaving a second id in the table is how the owner ends up
 * looking at two numbers again with nothing saying which is live.
 *
 * WHY THE MODULE GETS SWITCHED ON. Before this change `settings.ga` was read
 * by App\Support\Seo, which is not gated by the Marketing Pixels toggle: an
 * owner who had only ever used the SEO screen had a live Google tag with that
 * module off. Moving his id under the module without switching the module on
 * would have silently switched his analytics off. So a migrated id turns it
 * on. Nothing else starts firing: a pixel with no id is not an event source.
 *
 * ── 2. DROP THE COMPILED CACHES ──────────────────────────────────────────
 *
 * Six Blade files changed (the store layout and the five standalone documents
 * that render their own <head>), so the compiled views must go, and the PHP
 * that changed is not the PHP the server runs until OPcache lets go — the
 * standing reason behind the withdrawn packages 2.60.102-.106 in CLAUDE.md.
 * No route changed; the route cache is dropped with the rest anyway, because
 * the cost is nil and a half-cleared cache is the harder thing to reason
 * about.
 *
 * Nothing here positions a column with an AFTER clause, the thing that made
 * nine earlier migrations silent no-ops on MySQL.
 */
return new class extends Migration
{
    /** legacy settings key => canonical module_settings key. */
    private const MOVES = [
        'ga' => 'ga4_id',
        'meta_pixel' => 'meta_id',
    ];

    private const MODULE = 'marketing_pixels';

    public function up(): void
    {
        $this->mergeDuplicateIds();
        $this->clearCaches();
    }

    private function mergeDuplicateIds(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasTable('module_settings')) {
            return;
        }

        $moved = 0;
        $dropped = [];

        foreach (self::MOVES as $legacyKey => $moduleKey) {
            $legacy = DB::table('settings')->where('key', $legacyKey)->value('value');
            $legacy = trim((string) ($legacy ?? ''));

            if ($legacy === '') {
                // Nothing to move. The row may still exist as a blank the SEO
                // screen wrote; it is a duplicate box either way, so it goes.
                DB::table('settings')->where('key', $legacyKey)->delete();

                continue;
            }

            $current = DB::table('module_settings')
                ->where('module', self::MODULE)
                ->where('key', $moduleKey)
                ->value('value');
            $current = trim((string) ($current ?? ''));

            if ($current === '') {
                $this->writeModuleSetting($moduleKey, mb_substr($legacy, 0, 60));
                $this->enableModule();
                $moved++;
            } elseif ($current !== $legacy) {
                // Both filled, and differently. Say so out loud rather than
                // dropping an id the owner typed without a word.
                $dropped[] = $legacyKey . '="' . $legacy . '" (kept ' . $moduleKey . '="' . $current . '")';
            }

            DB::table('settings')->where('key', $legacyKey)->delete();
        }

        if (app()->runningInConsole()) {
            echo "Analytics ids merged: {$moved} moved to the Marketing Pixels module.\n";

            foreach ($dropped as $line) {
                echo "  superseded duplicate removed: {$line}\n";
            }
        }
    }

    private function writeModuleSetting(string $key, string $value): void
    {
        $existing = DB::table('module_settings')
            ->where('module', self::MODULE)
            ->where('key', $key)
            ->exists();

        if ($existing) {
            DB::table('module_settings')
                ->where('module', self::MODULE)
                ->where('key', $key)
                ->update(['value' => $value]);

            return;
        }

        $row = ['module' => self::MODULE, 'key' => $key, 'value' => $value];

        // Timestamps only if the table carries them, so this works against
        // either shape of the table without a schema assumption.
        if (Schema::hasColumn('module_settings', 'created_at')) {
            $row['created_at'] = now();
            $row['updated_at'] = now();
        }

        DB::table('module_settings')->insert($row);
    }

    private function enableModule(): void
    {
        if (! Schema::hasTable('module_toggles')) {
            return;
        }

        $exists = DB::table('module_toggles')->where('module', self::MODULE)->exists();

        if ($exists) {
            DB::table('module_toggles')->where('module', self::MODULE)->update(['enabled' => true]);

            return;
        }

        $row = ['module' => self::MODULE, 'enabled' => true];

        if (Schema::hasColumn('module_toggles', 'created_at')) {
            $row['created_at'] = now();
            $row['updated_at'] = now();
        }

        DB::table('module_toggles')->insert($row);
    }

    private function clearCaches(): void
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

        // The settings, module and module-settings snapshots all just moved
        // underneath their caches. Clearing the files without clearing these
        // leaves the storefront reading the ids from before the merge.
        try {
            app(\App\Services\SettingsService::class)->flush();
            \App\Models\Setting::flushMap();
        } catch (\Throwable $e) {
            // A cache store that is not reachable during a migration must not
            // stop the migration; the next write flushes it anyway.
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    /**
     * Not reversible, on purpose. Down would have to put an id back in a box
     * that no longer has a reader and re-create the duplicate this exists to
     * remove. The canonical value is untouched, so rolling the code back
     * leaves the Marketing Pixels screen holding the id and firing it.
     */
    public function down(): void {}
};

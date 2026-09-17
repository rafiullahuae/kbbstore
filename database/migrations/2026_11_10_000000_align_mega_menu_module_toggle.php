<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the `mega_menu` switch agree with what the storefront has been doing.
 *
 * Exactly the correction 2026_10_20_000000 made for `brands`, and
 * 2026_09_17_000000 before it for `seo_engine` and `product_sorting`: the same
 * reason and the same one-time shape.
 *
 * `mega_menu` was marked `live` in ModuleRegistry — "something on the
 * storefront reads moduleEnabled() for this key" — while the only reader
 * anywhere was MegaMenuApiController's `module_on`, a flag the admin screen
 * prints about itself. partials/nav-bar.blade.php rendered its dropdown panels
 * and partials/mobile-menu-item.blade.php its expandable sections
 * unconditionally. This package adds the reads that make the switch real;
 * without this migration, adding them would take the header dropdowns off a
 * running store the moment it applied, on every install that was seeded
 * `false`.
 *
 * Why the stored value can be overwritten at all: a toggle that has never been
 * consulted cannot carry a decision. `mega_menu` was seeded false by
 * ModuleSeeder along with the other registry defaults and nothing has ever read
 * it, so nobody has chosen "off" for the mega menu in any sense that changed a
 * page. An owner who looked at Store → Modules, saw the switch off and saw the
 * panels drop anyway had no reason to read that switch as their decision.
 *
 * The plugin's own default (off) is deliberately NOT what ModuleRegistry now
 * carries either — that row is `true`, with the reasoning written on it. This
 * migration only overrides a stored value that was never read; a genuinely
 * fresh install with no row here gets the registry default, and whatever the
 * owner chooses afterwards on Store → Modules stands, because nothing here runs
 * again.
 *
 * NO SCHEMA CHANGE, and no ->after() — see
 * tests/Feature/MigrationConventionTest.php for why that matters on this host.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('module_toggles')) {
            return;
        }

        $row = DB::table('module_toggles')->where('module', 'mega_menu')->first();

        if ($row === null) {
            DB::table('module_toggles')->insert([
                'module' => 'mega_menu',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif (! (bool) $row->enabled) {
            DB::table('module_toggles')
                ->where('module', 'mega_menu')
                ->update(['enabled' => true, 'updated_at' => now()]);
        }

        // moduleEnabled() reads through a rememberForever cache; without this
        // the site keeps answering from the pre-migration value indefinitely
        // and the package looks like it did nothing.
        Cache::forget('kbb.modules');
    }

    /**
     * Reversing this would mean guessing whether the owner had since chosen
     * "off" on purpose, so it does nothing rather than guess wrong.
     */
    public function down(): void {}
};

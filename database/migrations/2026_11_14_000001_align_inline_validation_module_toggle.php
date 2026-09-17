<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the `inline_validation` switch agree with what the checkout has been
 * doing — the same one-time correction 2026_11_10_000000 made for `mega_menu`,
 * 2026_10_20_000000 for `brands` and 2026_09_17_000000 for `seo_engine` and
 * `product_sorting`, pointed the other way.
 *
 * ── WHAT IS BEING CORRECTED ─────────────────────────────────────────────────
 *
 * ModuleSeeder seeds `inline_validation => true`, copied from the plugin along
 * with the rest of the checkout group, and it has been seeding it since the
 * table was created. Nothing has ever read the key: the registry row said
 * `todo` and grep over app/, resources/views/ and routes/ found no
 * moduleEnabled('inline_validation') anywhere, which is what
 * ModuleFrameworkGuardTest asserts about a `todo` row.
 *
 * This package adds the reader. Without this migration, adding it would switch
 * live field marking ON — on the one form the shop is paid through — on every
 * install the seeder has ever touched, the moment the package applied, because
 * moduleEnabled() returns the STORED row whenever one exists and only falls
 * back to the registry default when it does not. The registry row now reads
 * `false` and says why; this is what makes that default reach the installs that
 * already have a row.
 *
 * ── WHY THE STORED VALUE MAY BE OVERWRITTEN AT ALL ──────────────────────────
 *
 * A toggle that has never been consulted cannot carry a decision. Until this
 * package, Store → Modules printed this row as "Not ported yet" with no switch
 * to flip and no settings screen behind it, so no owner has chosen "on" for
 * inline validation in any sense that changed a page. The value in the column
 * is the seeder's, not anybody's.
 *
 * It is written only where the row says `true`, and only once: a fresh install
 * with no row gets the registry default, and whatever the owner chooses
 * afterwards on Store → Modules stands, because nothing here runs again.
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

        $row = DB::table('module_toggles')->where('module', 'inline_validation')->first();

        /*
         * An ABSENT row is left absent, unlike the mega_menu migration which
         * inserted one. Absent is already the state this module wants: it means
         * moduleEnabled() answers with the registry default, which is now false,
         * and inserting a row to say the same thing would only be one more value
         * to get wrong later. The mega_menu case inserted because its wanted
         * default (true) differed from what a store seeded before the fix would
         * have; here the wanted default and the fallback are the same.
         */
        if ($row !== null && (bool) $row->enabled) {
            DB::table('module_toggles')
                ->where('module', 'inline_validation')
                ->update(['enabled' => false, 'updated_at' => now()]);
        }

        // moduleEnabled() reads through a rememberForever cache; without this
        // the site keeps answering from the pre-migration value indefinitely
        // and the package looks like it did nothing.
        Cache::forget('kbb.modules');
    }

    /**
     * Reversing this would mean guessing whether the owner had since chosen
     * "on" on purpose, so it does nothing rather than guess wrong.
     */
    public function down(): void {}
};

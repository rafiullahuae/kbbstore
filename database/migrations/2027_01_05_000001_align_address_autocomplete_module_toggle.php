<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the `address_autocomplete` switch agree with what the checkout has been
 * doing — the mirror of 2026_11_14_000001 (`inline_validation`), and the same
 * one-time correction 2026_11_10_000000 made for `mega_menu`.
 *
 * ── WHAT IS BEING CORRECTED ─────────────────────────────────────────────────
 *
 * ModuleSeeder seeded `address_autocomplete => true`, copied from the plugin
 * along with the rest of the checkout group, and has been seeding it since the
 * table was created. Nothing has ever read the key: the registry row said
 * `todo`, and no moduleEnabled('address_autocomplete') existed anywhere in
 * app/, resources/views/ or routes/.
 *
 * This package adds the reader. Without this migration, adding it would switch
 * address autocomplete ON — on the one form the shop is paid through — on every
 * install the seeder has ever touched, the moment the package applied, because
 * moduleEnabled() returns the STORED row whenever one exists and only falls back
 * to the registry default when it does not.
 *
 * docs/FI-PHASE3-MODULE-INVENTORY.md called this out in advance and in as many
 * words: "The same landmine is still armed for `address_autocomplete`. It is
 * seeded true, its registry default is true, and nothing reads it. Whoever ports
 * it must ship an alignment migration in the same package, or that module comes
 * on by itself on every existing store." This is that migration.
 *
 * ── AND IT MATTERS MORE HERE THAN IT DID THERE ──────────────────────────────
 *
 * `inline_validation` coming on by itself would have coloured in a form. This
 * module, when it runs, sends every character a shopper types into the address
 * box to Google. Coming on by itself would be a privacy change to a live shop
 * that nobody chose.
 *
 * In practice the module would still have rendered nothing, because it also
 * needs a stored API key and an explicit "yes" to the consent question and
 * neither exists on any install today. That is a second lock, not a reason to
 * skip the first: the switch would have read ON on Store → Modules, which is
 * the shop telling its owner something untrue about what it does with customer
 * data, and it would have started sending the day a key was pasted in for any
 * other reason.
 *
 * ── WHY THE STORED VALUE MAY BE OVERWRITTEN AT ALL ──────────────────────────
 *
 * A toggle that has never been consulted cannot carry a decision. Until this
 * package, Store → Modules printed this row as "Not ported yet" with no switch
 * to flip and no settings screen behind it, so no owner has chosen "on" for
 * address autocomplete in any sense that changed a page. The value in the column
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

        $row = DB::table('module_toggles')->where('module', 'address_autocomplete')->first();

        /*
         * An ABSENT row is left absent. Absent already means what this module
         * wants: moduleEnabled() answers with the registry default, which is now
         * false. Inserting a row to say the same thing would only be one more
         * value to get wrong later.
         */
        if ($row !== null && (bool) $row->enabled) {
            DB::table('module_toggles')
                ->where('module', 'address_autocomplete')
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

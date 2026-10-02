<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switch "Buy these together" ON for this shop.               (Lane RB, 2.60.35x)
 *
 * The owner: "I want one new section called Buy these together [...] plan it
 * well and develop without disturbing anything" — and CLAUDE.md's 30 September
 * rule: what he asked for is the shop's new state, not a switch he has to find.
 *
 * App\Services\BuyTogetherSettings ships `on` OFF, so a fresh install and the
 * test suite draw every product page exactly as before. This writes the ON he
 * asked for on a shop that has categories (never under the test runner) — and
 * ONLY where no value is stored: a shop that has already set the switch keeps
 * what it set.
 *
 * What ON reaches:
 *   laptops  the "Buy these together" row of Appearance → Product page →
 *            Sections (key `fbt`) — Desktop ships ON, so the section shows.
 *   phones   the "Buy these together" row of Mobile sections. Never saved
 *            there, that row FOLLOWS this switch (ProductMobileSections::
 *            togetherDefault()), so it shows too. A Mobile sections layout he
 *            has already saved keeps whatever it says for that row — this does
 *            not write over it.
 *
 * Appearance → Product page → Buy these together → "Show “Buy these
 * together”" turns it off again.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $isShop = ! app()->runningUnitTests() && Schema::hasTable('categories') && DB::table('categories')->exists();

            if ($isShop && Schema::hasTable('settings') && ! DB::table('settings')->where('key', 'bt_on')->exists()) {
                DB::table('settings')->insert([
                    'key' => 'bt_on', 'value' => '1', 'autoload' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                \App\Models\Setting::flushMap();
            }
        } catch (\Throwable $e) {
            if (app()->runningInConsole()) {
                echo 'Buy these together: could not write the switch ('.$e->getMessage().").\n";
            }
        }
    }

    public function down(): void {}
};

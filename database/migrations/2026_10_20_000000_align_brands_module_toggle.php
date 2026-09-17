<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the `brands` switch agree with what the storefront has been doing.
 *
 * Exactly the correction 2026_09_17_000000 made for `seo_engine` and
 * `product_sorting`, for the same reason and with the same one-time shape.
 *
 * `brands` was marked `todo` in ModuleRegistry — "the feature is not ported
 * yet; a switch would do nothing" — while the brand directory at
 * /korean-skincare-brands/, the per-brand landing pages, the 301s from /brands/
 * and /brand/{slug}/ and the home-page brand strip have all been serving since
 * 2.60.109. This package adds the reads that make the switch real; without this
 * migration, adding them would take three live URL families off a running store
 * the moment it applied.
 *
 * Why the stored value can be overwritten at all: a toggle that has never been
 * consulted cannot carry a decision. `brands` was seeded false along with the
 * other registry defaults and nothing has ever read it, so nobody has chosen
 * "off" for brands in any sense that changed anything.
 *
 * The plugin's own default (off) is deliberately NOT what ModuleRegistry now
 * carries either — that row is `true`, with the reasoning written on it. This
 * migration only overrides a stored value that was never read; a genuinely
 * fresh install with no row here gets the registry default, and whatever the
 * owner chooses afterwards on Store → Modules stands, because nothing here runs
 * again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('module_toggles')) {
            return;
        }

        $row = DB::table('module_toggles')->where('module', 'brands')->first();

        if ($row === null) {
            DB::table('module_toggles')->insert([
                'module' => 'brands',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif (! (bool) $row->enabled) {
            DB::table('module_toggles')
                ->where('module', 'brands')
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

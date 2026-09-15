<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the two switches that have never done anything agree with what the
 * storefront has actually been doing.
 *
 * `seo_engine` and `product_sorting` were both marked `live` in ModuleRegistry
 * — which that file defines as "something on the storefront reads
 * moduleEnabled() for this key" — and nothing anywhere read either one. The
 * features themselves are real and have been running unconditionally. This
 * package adds the two missing reads; without this migration, adding them
 * would silently change a live store.
 *
 * Why the stored values can be overwritten at all: a toggle that has never
 * been consulted cannot carry a decision. Nobody chose "off" for SEO Engine in
 * any meaningful sense, because choosing it changed nothing and showed nothing.
 * The honest starting point is the behaviour the site already has.
 *
 *   seo_engine      -> on. App\Support\Seo is the only thing in this app that
 *                      writes a <head>. Leaving the seeded `false` in place
 *                      would strip the title template, meta description,
 *                      canonical, Open Graph, Twitter card and every JSON-LD
 *                      node off every page of a live catalogue the moment this
 *                      package applied — with no visible symptom on any page.
 *
 *   product_sorting -> on. ShopController's "Featured" sort has had
 *                      `position` in its ORDER BY all along, so the shop and
 *                      every category page are already showing the curated
 *                      order today. Off would reorder a live catalogue on
 *                      apply.
 *
 * The plugin's own default for `product_sorting` (off) is kept in
 * ModuleRegistry, where it governs a genuinely fresh install that has no row
 * here. This only overrides a stored value that was never read.
 *
 * A first attempt tried to be cleverer — turn `product_sorting` on only where
 * some product had a non-zero `position`, as evidence a human had used the
 * Reorder screen. It is not evidence: DemoCatalogueSeeder writes `position =>
 * $i` for all 24 demo products, so the "curated" test fires on an untouched
 * install. Left here because a heuristic that looks this reasonable is worth
 * one line of warning.
 *
 * Runs once, like any migration, which is the point: it is a one-time
 * correction of two values that were never meaningful, not a policy that keeps
 * reasserting itself. Whatever the owner chooses afterwards on Store → Modules
 * stands, and nothing here runs again to undo it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('module_toggles')) {
            return;
        }

        $this->set('seo_engine', true);
        $this->set('product_sorting', true);

        // moduleEnabled() reads through a rememberForever cache. Without this
        // the site keeps serving the previous answer until something else
        // happens to clear it.
        Cache::forget('kbb.modules');
    }

    /**
     * Reversing this would mean guessing which of the two values an owner had
     * since chosen on purpose, so it does nothing rather than guess wrong.
     */
    public function down(): void {}

    private function set(string $module, bool $enabled): void
    {
        $row = DB::table('module_toggles')->where('module', $module)->first();

        if ($row === null) {
            DB::table('module_toggles')->insert([
                'module' => $module,
                'enabled' => $enabled,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ((bool) $row->enabled === $enabled) {
            return;
        }

        DB::table('module_toggles')
            ->where('module', $module)
            ->update(['enabled' => $enabled, 'updated_at' => now()]);
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Compiled code, and the four DATA caches that hold hydrated products. (Lane SG)
 *
 * ── WHY THIS ONE ALSO TOUCHES THE DATA CACHE, WHICH MOST DO NOT ───────────
 *
 * This release adds three columns to fourteen SELECT lists. The class files
 * change, so OPcache has to go — that is the ordinary half below. But four
 * caches in this application store HYDRATED ELOQUENT MODELS, serialised, and a
 * model serialised before this release carries the OLD column list:
 *
 *   kbb.home.rails                the homepage's five product rails   (600s)
 *   kbb.home.routine              the six routine steps' picks        (900s)
 *   kbb.search.starter.products   the search panel's opening list     (900s)
 *   kbb.sc.* via Shortcodes       every [kbb_products] grid on a page (600s)
 *
 * Left alone they are correct again within fifteen minutes. But "correct within
 * fifteen minutes" is the owner refreshing the homepage after applying a
 * package, seeing the price he was told this release fixes, and reasonably
 * concluding it did not. Four forgets cost nothing and remove the window.
 *
 * ▲ FOUR NAMED KEYS AND Shortcodes::flush(), NEVER Cache::flush(). The store
 *   here holds sessions on some drivers, and emptying it logs every signed-in
 *   customer out — which is the note Shortcodes::flush() carries for exactly
 *   this reason. Its own index key is how the md5-suffixed shortcode entries
 *   are reached without a wildcard the cache API does not have.
 *
 * ── NOTHING ELSE MOVES ────────────────────────────────────────────────────
 *
 * NO SETTING IS WRITTEN AND NO COLUMN IS ADDED. The three columns this release
 * selects were added by 2027_04_02_000000_set_pricing_columns and
 * 2027_04_06_000000_set_price_basis and are NULL on every set an operator has
 * not deliberately priced, so a shop with no rule-priced set renders
 * byte-identically — StorefrontEnglishUnchangedTest is green across this
 * release. What changes is a set whose members were marked down after it was
 * priced: the grids, the basket, the checkout and the public feed now quote the
 * figure its own product page has always quoted.
 *
 * ▲ THE `migrations` FLAG IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT
 *   ALL — UpdateRunner::hasMigrations() never looks at the files. Build the
 *   package with `php artisan kbb:package <version> --since=<ref>`, never a
 *   hand-rolled script. Five packages built by one in an afternoon shipped
 *   eight migrations that were copied to the live server and never ran.
 */
return new class extends Migration
{
    public function up(): void
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

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        /*
         * Guarded, and it may not fail the update. A cache store that is
         * unreachable during an update is a nuisance; an update that dies
         * half-applied because of one is the shape CLAUDE.md records against
         * UpdateRunner::recordManifest(). Every one of these is rebuilt by the
         * next request that needs it, so a failure here costs fifteen minutes,
         * not correctness.
         */
        try {
            foreach (['kbb.home.rails', 'kbb.home.routine', 'kbb.search.starter.products'] as $key) {
                Cache::forget($key);
            }

            \App\Support\Shortcodes::flush();
        } catch (\Throwable) {
            // See above.
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files. A SET priced by a rule -- a percentage\n"
                ."off what is in the box, an amount off it, or a typed price anchored to it\n"
                ."-- now shows the same figure on every grid in the shop that it has always\n"
                ."shown on its own page, and the basket takes that figure rather than the\n"
                ."one the editor last saved.\n"
                ."\n"
                ."A shop with no rule-priced set is byte-identical, and no basket or order\n"
                ."that already exists is repriced.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};

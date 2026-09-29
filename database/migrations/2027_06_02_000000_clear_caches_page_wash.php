<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Appearance → Page background.              (Lane BG)
 *
 * ── WHY THIS IS OWED, AND IT IS OWED THREE TIMES OVER ──────────────────────
 *
 * 1. A NEW ROUTE FILE. routes/page-wash-admin.php is required into the
 *    `admin-api` group by routes/web.php in the same package. A route added by
 *    a package does nothing at all until the compiled route table is gone, and
 *    the failure is the quiet one CLAUDE.md records twice: the screen renders
 *    perfectly and every control on it 404s.
 *
 * 2. TWO CHANGED BLADES. resources/views/layouts/store.blade.php gains the
 *    wash block, and resources/views/admin/app.blade.php gains the screen.
 *    Both are compiled into storage/framework/views, a compiled copy is keyed
 *    by PATH rather than by contents, and the freshness check is a filemtime
 *    compare that an unzip's timestamps do not reliably win. A stale compiled
 *    layout here means the package lands complete and the storefront never
 *    calls PageWash::css() — so the owner switches the wash on, saves, sees the
 *    screen say so, and the shop does not change. Nothing 500s and nothing is
 *    logged.
 *
 * 3. THE CONFIG CACHE, because App\Support\AdminCapabilities gains
 *    `pagewash.manage` and a RULES row for `admin-api/page-wash`. That map
 *    FAILS CLOSED: an endpoint it does not know about is refused. A host still
 *    serving the old compiled bootstrap would answer 403 on a screen that had
 *    just been installed, which reads as a permissions problem and is a stale
 *    cache.
 *
 * ── WHAT CHANGED ───────────────────────────────────────────────────────────
 *
 *   app/Services/PageWash.php        the schema, the four preview treatments,
 *                                    the colour arithmetic and the stylesheet.
 *                                    `on` ships FALSE and css() returns the
 *                                    empty string while it is.
 *
 *   app/Http/Controllers/Admin/PageWashApiController.php
 *   routes/page-wash-admin.php       GET and POST /admin-api/page-wash, behind
 *                                    `pagewash.manage`.
 *
 *   resources/views/layouts/store.blade.php
 *                                    one block, arranged to emit zero bytes
 *                                    while the wash is off.
 *
 *   resources/views/admin/partials/page-wash-screen.blade.php
 *                                    the screen, including the live preview:
 *                                    four treatments across five real
 *                                    storefront pages, in frames.
 *
 * ── NOTHING ON THE SHOP MOVES WHEN THIS IS APPLIED ─────────────────────────
 *
 * This is the whole of rule 1 for this package and it is checkable rather than
 * promised. `wash_on` is absent, absent means the shipped default, and the
 * shipped default is FALSE; css() returns '' for a shop in that state, and the
 * layout block emits not one byte — not even a newline — when it does. Every
 * storefront page is byte-identical before and after, which is what
 * StorefrontEnglishUnchangedTest asserts and what PageWashTest asserts again
 * against the rendered <head> of five pages.
 *
 * NO SETTING ROWS ARE WRITTEN by this migration. The screen's own defaults are
 * read from the schema when nothing is stored, so there is nothing to seed and
 * nothing to clear.
 *
 * NO SCHEMA CHANGE. Nine keys, all of them in the existing `settings` table,
 * all of them written by this module's own endpoint.
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

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files. Appearance -> Page background is now\n"
                ."on the menu, under Appearance, between Section dividers and Cart panel.\n"
                ."\n"
                ."NOTHING ON THE SHOP HAS CHANGED. The colour wash ships OFF and the shop\n"
                ."renders exactly the background it rendered before this package.\n"
                ."\n"
                ."Open the screen and press Preview: it shows four treatments across five\n"
                ."of your own pages -- the home page, the shop listing, a product, the cart\n"
                ."and the journal -- with the real catalogue behind them. Nothing is saved\n"
                ."by looking, and the preview is visible only to somebody signed in here.\n"
                ."\n"
                ."docs/BG-PAGE-BACKGROUND.md is the walkthrough, the contrast table and the\n"
                ."performance numbers.\n";
        }
    }

    public function down(): void {}
};

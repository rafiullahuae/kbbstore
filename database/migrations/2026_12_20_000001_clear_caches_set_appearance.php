<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Appearance → Set.                        (Lane SA)
 *
 * TWO KINDS OF STALENESS, and this package carries both.
 *
 *   A NEW ROUTE FILE. routes/set-appearance-admin.php is required from
 *   routes/web.php, and the router dispatches against
 *   bootstrap/cache/routes-*.php rather than against the source. Without this
 *   the package lands complete and Appearance → Set draws a screen whose every
 *   request answers 404 — which the screen itself says out loud, in those
 *   words, rather than drawing an empty panel, but which is still a dead
 *   screen.
 *
 *   A CHANGED BLADE. resources/views/layouts/store.blade.php gains one
 *   `@include`, and resources/views/admin/app.blade.php gains one more. Both
 *   are compiled into storage/framework/views, a compiled copy is keyed by
 *   PATH rather than by contents, and the freshness check is a filemtime
 *   compare — an unzip's timestamps are not reliably newer than what is
 *   already on disk. A stale compiled layout is a shop that ignores every
 *   slider on the new screen, which is the exact failure mode this screen
 *   exists to make impossible.
 *
 * What changed:
 *
 *   app/Services/SetAppearance.php  the schema, the argument for every default,
 *                                   and the CSS. Answers the EMPTY STRING while
 *                                   every value is at its shipped default, so
 *                                   applying this package emits not one byte on
 *                                   any storefront page.
 *
 *   resources/views/partials/set-appearance-css.blade.php
 *   resources/views/layouts/store.blade.php
 *                                   the one storefront emission, and the one
 *                                   line that includes it. Its position in the
 *                                   <head> is load-bearing — see the partial's
 *                                   own header for the checkout popup rule it
 *                                   must not outrank.
 *
 *   app/Http/Controllers/Admin/SetAppearanceApiController.php
 *   routes/set-appearance-admin.php
 *   app/Support/AdminCapabilities.php
 *                                   the three endpoints, and
 *                                   `setappearance.manage` — owner, manager and
 *                                   editor, its own capability so narrowing it
 *                                   and narrowing cartpage.manage stay separate
 *                                   decisions.
 *
 *   resources/views/admin/partials/set-appearance-screen.blade.php
 *   resources/views/admin/previews/set-appearance.blade.php
 *   resources/views/admin/app.blade.php
 *                                   the screen with its Desktop and Mobile
 *                                   tabs, its buffered Save, and the iframe
 *                                   preview that redraws the REAL set partial
 *                                   from what has been typed. It registers its
 *                                   own sidebar row inside Appearance and wraps
 *                                   window.go, so one include, one TITLES row
 *                                   and one LATE_RENDERED entry are the whole
 *                                   of the change to the console.
 *
 * NO SETTING ROWS ARE WRITTEN. SetAppearance::SCHEMA carries every default, an
 * absent row and a row holding the default are the same thing, and seeding
 * would make "never touched" indistinguishable from "set back to the default"
 * — which is also precisely what storefrontCss() keys the empty answer off.
 *
 * NO SCHEMA CHANGE. Integers, booleans and hex colours, in `settings`, which
 * has held keys like these since it existed.
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
            echo "Cleared {$cleared} compiled files; Appearance -> Set can now size the\n"
                ."fanned member circles, the What's inside popup and the saving, with a\n"
                ."separate value per measurement on the Desktop and Mobile tabs.\n"
                ."Nothing on the shop has moved: every control ships at the value the\n"
                ."page already draws, and the storefront emits no stylesheet at all\n"
                ."until one of them is changed.\n";
        }
    }

    public function down(): void {}
};

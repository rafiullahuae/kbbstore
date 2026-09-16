<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the storefront-claims sweep — Lane DL.
 *
 * ── WHAT CHANGED ────────────────────────────────────────────────────────────
 *
 * TWO BLADE FILES, AND NOTHING ELSE. No PHP class, no route, no column.
 *
 *   store/home.blade.php        The promo ticker's first chip carried an
 *                               invented anniversary sale and an invented
 *                               discount code as the DEFAULT of `home_ticker`.
 *                               Nothing in this application writes that key —
 *                               it is in neither AdminController::SETTING_RULES
 *                               nor EcommerceApiController's schema nor
 *                               SettingsSeeder — so the default was the shipped
 *                               and only value, and every visitor to every
 *                               fresh shop was shown a percentage off under a
 *                               code that has never existed, twice a loop, with
 *                               no screen anywhere to change or remove it.
 *
 *                               The chip is now shown only when the owner has
 *                               written one, exactly like the delivery and
 *                               free-delivery chips beside it, and a ticker
 *                               with no chips at all is not rendered rather
 *                               than rendered empty.
 *
 *   partials/footer.blade.php   The social row linked three literals while
 *                               `social_instagram`, `social_tiktok` and
 *                               `social_facebook` were real, validated settings
 *                               already feeding the schema.org `sameAs` node.
 *                               An owner who corrected a profile URL changed
 *                               the structured data and not the link shoppers
 *                               click. The footer reads those keys now, with
 *                               the shipped literals as the fallback.
 *
 * WITHOUT THIS THE PACKAGE LANDS AND NOTHING MOVES. The server renders Blade
 * out of storage/framework/views and has no shell to clear it from, so the home
 * page goes on being drawn from a compiled copy of a file that is no longer on
 * disk — the invented code still scrolling — and the footer goes on linking the
 * profiles the template was written with. OPcache is the same story for the
 * PHP: the host cannot be restarted, so what a package writes is not what the
 * server runs until OPcache lets go.
 *
 * THERE IS NO STALE-PAIRING HAZARD HERE. Neither file talks to an endpoint and
 * no key's write path changed, so a half-applied package is merely the old
 * page, not a screen posting keys a controller will silently drop. That is
 * unusual for this repo and worth saying plainly rather than leaving the reader
 * to check.
 *
 * ── NOTHING IS WRITTEN TO `settings` ────────────────────────────────────────
 *
 * Deliberately. `home_ticker` is left ABSENT rather than seeded with a
 * replacement sentence: there is nothing true to put in it that this
 * application records, and inventing a milder claim to stand where the false
 * one stood is the same defect one notch quieter. A shop that wants a ticker
 * line will have a screen to write one on; until then the strip carries the two
 * chips that are backed by the shipping configuration, or it carries nothing.
 *
 * The three `social_*` keys are likewise NOT seeded. SettingsSeeder does not
 * install them and this does not either, because the fallback in the template
 * is the value the footer has always printed — so a shop that applies this
 * package and never opens Store → Search appearance shows the same three links,
 * to the byte.
 *
 * Nothing here positions a column with an AFTER clause, the thing that made
 * nine earlier migrations silent no-ops on MySQL.
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
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};

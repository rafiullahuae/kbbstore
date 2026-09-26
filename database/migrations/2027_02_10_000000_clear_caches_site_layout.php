<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Appearance → Site layout.   (Lane W1)
 *
 * A NEW ROUTE FILE. routes/site-layout-admin.php is required from
 * routes/web.php by the integrator, and the router dispatches against
 * bootstrap/cache/routes-*.php rather than against the source. Without this the
 * screen draws and both of its requests answer 404 — a dead screen, on the shop
 * the owner asked for this for.
 *
 * TWO CHANGED BLADES, compiled into storage/framework/views and keyed by PATH
 * rather than by contents: the freshness check is a filemtime compare, and an
 * unzip's timestamps are not reliably newer than what is already on disk. A
 * stale copy here is an admin console with no Site layout row in its sidebar
 * and a storefront still emitting nothing when a slider has been moved.
 *
 * What changed:
 *
 *   app/Services/SiteLayout.php     the schema, the nine defaults, and the
 *                                   stylesheet a non-default shop sends. A shop
 *                                   at its defaults sends an EMPTY STRING, so
 *                                   applying this package adds no bytes to any
 *                                   page.
 *   app/Http/Controllers/Admin/SiteLayoutApiController.php
 *   routes/site-layout-admin.php
 *   app/Support/AdminCapabilities.php
 *                                   the screen, behind the new
 *                                   `sitelayout.manage` capability.
 *   resources/views/admin/app.blade.php
 *   resources/views/admin/partials/site-layout-screen.blade.php
 *                                   Appearance → Site layout.
 *   resources/views/layouts/store.blade.php
 *                                   emits <style id="kbb-layout"> — and only
 *                                   when something has been moved.
 *
 * ── THE STOREFRONT DOES MOVE, AND IT IS ONE NUMBER ──────────────────────────
 *
 * The site width becomes 1680px, which the owner asked for in as many words
 * ("site width max i need 1680 px"). Before this, fourteen page-container
 * declarations disagreed six ways — 1400 on a generic page, 1352 on the home
 * page, 1240 on /shop, 1180 on a product page, 1160 on the Journal, 1080 on the
 * review wall. They are one number now, in one place, and that number is 1680.
 *
 * The cart page, the checkout and the slim footer are NOT on it: all three keep
 * their own existing width sliders, because all three are pages asking for
 * money and a narrow ledger there is a deliberate decision. So this package
 * does not widen the checkout.
 *
 * A product row also gains a column where there is room for one: five at
 * 1680px where there were four. That is the second half of the same sentence
 * from the owner. docs/W1-SITE-WIDTH.md has the count at all seventeen widths,
 * before and after, and names every cell that moved.
 *
 * NO SETTING ROWS ARE WRITTEN by this migration, on purpose. Every one of the
 * nine keys is absent until somebody saves the screen, and SiteLayout::all()
 * answers the shipped default for an absent key — so there is no row to align
 * and nothing that could come on by itself. The 1680 lives in kbb.css's `:root`
 * and in SiteLayout::SCHEMA, and SiteLayoutDefaultsMatchCssTest pins that those
 * two agree.
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
            echo "Cleared {$cleared} compiled files; the site width is now one number\n"
                ."(1680px) under Appearance -> Site layout. The cart, the checkout and the\n"
                ."slim footer keep their own widths.\n";
        }
    }

    public function down(): void {}
};

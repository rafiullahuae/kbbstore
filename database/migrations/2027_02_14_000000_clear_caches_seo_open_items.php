<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the SEO open items.   (Lane S8)
 *
 * ONE CHANGED BLADE, and Blade compiles into storage/framework/views keyed by
 * PATH rather than by contents: the freshness check is a filemtime compare, and
 * an unzip's timestamps are not reliably newer than what is already on disk. A
 * stale copy here is the whole point of this file -- see "the storefront does
 * not move" below for what a stale copy would leave switched on.
 *
 * NO NEW ROUTE. Nothing in this package adds or renames a route, so the route
 * cache is dropped only because this migration drops the whole set and a route
 * cache rebuilt from an unchanged routes/web.php is identical to the one it
 * replaced.
 *
 * What changed:
 *
 *   app/Support/ConcernCollections.php
 *                                   ENABLED holds all eight concern slugs
 *                                   instead of one.
 *   app/Services/Translation/InterfaceStrings.php
 *                                   a written English title and intro for the
 *                                   seven concerns that had none.
 *   app/Services/Seo/SeoSettings.php
 *                                   org_type ships at OnlineStore.
 *   app/Support/Seo.php             shippingDetails() -- shipping and returns
 *                                   are two independent answers now.
 *   app/Support/SeoAudit.php        the business-type check reports opening
 *                                   hours that cannot be published, not only
 *                                   coordinates.
 *   app/Http/Controllers/Admin/AdminController.php
 *                                   the `aed` rule accepts a blank.
 *   resources/views/admin/app.blade.php
 *                                   Store -> SEO & Meta -> Settings: the
 *                                   shipping-cost box no longer arrives holding
 *                                   a zero, and the business-type select reads
 *                                   in English.
 *
 * ── TWO DEFAULTS MOVE, AND BOTH WERE ASKED FOR IN AS MANY WORDS ────────────
 *
 * CLAUDE.md rule 1 says anything new ships at the value the page already has,
 * and that the one exception is a default the owner asked for outright, called
 * out rather than buried. This is the calling out.
 *
 * 1. `org_type` becomes `OnlineStore`. His words: "we are open 24/7, we don't
 *    have any physical shop, we operate only online." The observable effect is
 *    ONE property of ONE node on every page -- "@type":"Organization" becomes
 *    "@type":"OnlineStore" -- and nothing else. No address, telephone, emirate,
 *    map pin or opening hours is published, because he has given none and asked
 *    to enter the address later; nothing nags him for one either. 24/7 is
 *    deliberately NOT published: openingHoursSpecification is a property of
 *    schema.org Place and describes a door, so there is no valid way to state it
 *    for a shop with no premises.
 *
 * 2. The Shipping cost box ships BLANK rather than holding `0`. This one is a
 *    BUG FIX as much as a default: `0` in that box was published to Google as
 *    "shippingRate":{"value":"0.00","currency":"AED"} the moment the merchant
 *    switch was turned on -- free delivery to the whole UAE, on a shop that has
 *    never quoted a delivery rate, printed beside the price to a shopper. The
 *    box arrived holding a zero because the `aed` validation rule REFUSED a
 *    blank, so an empty box would have failed the whole save. All three halves
 *    ship together: the rule accepts blank, the box ships blank, and the emitter
 *    publishes no shippingDetails until a rate has actually been entered. A
 *    typed 0 still publishes free delivery, which is a real answer.
 *
 * ── THE STOREFRONT DOES NOT OTHERWISE MOVE ─────────────────────────────────
 *
 * All eight concern pages are enabled and every one of them is still a 404,
 * because a concern page needs ConcernCollections::MIN_PRODUCTS live, in-stock
 * products tagged for it and nothing on this shop is tagged. No /concern/ entry
 * enters the sitemap and nothing links to one. The pages appear one at a time as
 * the owner's tagging at Catalog -> Build my routine crosses the floor for each
 * concern -- so he no longer needs a package to publish his second concern page.
 * ConcernCollectionsTest measures both halves.
 *
 * NO SETTING ROWS ARE WRITTEN by this migration, on purpose. Every key involved
 * is absent until somebody saves a screen, and the readers answer the shipped
 * default for an absent key -- so there is no row to align and nothing that
 * could come on by itself. The merchant listing is still off.
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
            echo "Cleared {$cleared} compiled files. All eight skin-concern pages are now\n"
                ."enabled with their English copy written -- each stays a 404 until three\n"
                ."products are tagged for it at Catalog -> Build my routine. The business type\n"
                ."now says Online store, and the Shipping cost box on Store -> SEO & Meta ships\n"
                ."BLANK instead of 0, so turning the merchant listing on no longer publishes\n"
                ."free delivery. Nothing on the storefront moved.\n";
        }
    }

    public function down(): void {}
};

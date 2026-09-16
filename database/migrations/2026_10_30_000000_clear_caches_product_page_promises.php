<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the product page's delivery promises — Lane CT.
 *
 * ONE BLADE FILE CHANGED AND IT IS THE ONE THAT DECIDES WHAT A SHOPPER IS
 * PROMISED, which is why this migration is not optional here:
 *
 *   store/product.blade.php   two delivery claims that were made without asking
 *                             where the shopper is standing. The trust chip
 *                             under Add to cart no longer reads a single global
 *                             string; it reads the per-country wording through
 *                             App\Support\DeliveryLine, the same reader the home
 *                             page and the checkout already share. The dispatch
 *                             countdown no longer prints an arrival date built
 *                             from the store country's transit time to a shopper
 *                             in another country; it keeps the half that is true
 *                             about the warehouse and drops the half that is not.
 *
 * WITHOUT THIS THE PACKAGE LANDS AND NOTHING CHANGES. The server serves Blade
 * from storage/framework/views, so the old compiled product page would go on
 * printing the old unconditional promise out of a file that is no longer on
 * disk — and the symptom is not an error, it is the defect continuing to look
 * unfixed. That is the shape this repo has been bitten by before.
 *
 * NO ROUTE CHANGED and no schema changed. `delivery_texts` is an existing row in
 * the existing `settings` table with an existing admin screen (Store → Delivery
 * & Shipping → Delivery lines) and an existing validation rule. The route cache
 * is dropped with the rest anyway, because the cost is nil and a half-cleared
 * cache is the harder thing to reason about.
 *
 * OPcache is the other half, and it matters more than usual here because two
 * controllers change and one of them is a storefront controller:
 * Store\ProductController::cutoff() now takes the request and resolves the
 * shopper's country, and Admin\EcommerceApiController drops the
 * `trust_delivery_text` field from Store → Ecommerce → Product page → Trust row
 * so that exactly one screen writes the delivery sentence. The host cannot be
 * restarted or shelled into, so the PHP a package writes is not the PHP the
 * server runs until OPcache lets go of the old copy — the standing reason behind
 * the withdrawn packages 2.60.102-.106 recorded in CLAUDE.md. A server left
 * running the old EcommerceApiController would go on offering a field the
 * storefront no longer reads, which is the silent-inert-setting shape this
 * project has already had to clear out twice.
 *
 * `trust_delivery_text` IS DELIBERATELY NOT DELETED FROM THE SETTINGS TABLE. It
 * ships blank, so on this deployment there is nothing in it; and a migration
 * that deletes a row the owner may have typed into is not recoverable, while a
 * row nothing reads is merely dead. ProductPagePromisesTest pins that a value
 * left there by an older build cannot reach the page.
 *
 * Nothing here positions a column with an AFTER clause, the thing that made nine
 * earlier migrations silent no-ops on MySQL.
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

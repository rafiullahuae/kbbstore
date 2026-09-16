<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three coupon fields the editor drew greyed out, given somewhere to live.
 *
 * Store → Coupons showed "Limit usage to X items" as an explained gap and
 * "Allow free shipping" as a disabled box, because CouponService enforced
 * neither. Brands were not offered at all. These columns are the storage half;
 * CouponService::discountFor(), eligibleItems() and CartService::totals() are
 * the enforcement half.
 *
 * SAFE ON THE LIVE DATA, AND THAT IS THE WHOLE DESIGN OF IT. Every coupon on
 * this shop arrived in the WooCommerce import and none of them carries a value
 * for any of these. So every column added here has a default that means
 * "carry on exactly as before":
 *
 *   - limit_usage_to_x_items NULL -> no cap. discountFor() reads NULL as "no
 *     limit" and prices every eligible unit, which is what it did yesterday.
 *   - brand_ids NULL              -> no brand restriction. eligibleItems()
 *     tests these with the same plain truthiness the four existing id lists
 *     use, so NULL and [] both mean "no restriction".
 *   - excluded_brand_ids NULL     -> nothing excluded.
 *
 * coupons.free_shipping is NOT touched: it already exists, the import filled
 * it, and its existing default of false is already the behaviour it produces.
 *
 * tests/Feature/CouponItemLimitAndBrandsTest.php pins this from the other
 * direction — a coupon written with none of these fields set prices to exactly
 * the figure it priced to before the migration existed.
 *
 * No ->after(). It is MySQL-only syntax that SQLite's grammar ignores, so the
 * column order would differ between the suite and production for no gain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $t) {
            // WooCommerce's "limit_usage_to_x_items": how many matching UNITS
            // one redemption may discount. NULL is no cap, which is what every
            // existing row holds and what the import never wrote.
            $t->unsignedInteger('limit_usage_to_x_items')->nullable();

            // Brand include/exclude, the same shape as product_ids and
            // category_ids beside them: a JSON list of ids, NULL for "no
            // restriction". products.brand_id has existed since the initial
            // schema; nothing until now read it from a coupon.
            $t->json('brand_ids')->nullable();
            $t->json('excluded_brand_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $t) {
            $t->dropColumn(['limit_usage_to_x_items', 'brand_ids', 'excluded_brand_ids']);
        });
    }
};

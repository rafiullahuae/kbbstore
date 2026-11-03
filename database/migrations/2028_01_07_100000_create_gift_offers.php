<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Marketing -> Gift offers (Lane GO): one extra full-size gift at AED 0, by
 * cart-value range, offered in the abandoned-cart email, the two pending-order
 * reminders and (off by default) an exit popup. App\Services\GiftOffers.
 *
 *   gift_offer_ranges   the cart-value ranges (min, max/open, enabled, sort)
 *   gift_offer_items    per range one primary and any number of backups, each
 *                       with its OWN allocation for this offer and how many
 *                       of it were given (`used`): at allocation the next
 *                       backup is used
 *   gift_offer_claims   one row per offer made (an email sent, a popup
 *                       claimed): the claim token's SHA-256 only, never the
 *                       token; its window; the cart or order it went to
 *   gift_offer_stats    one row per store day x place x range x event, the
 *                       Free gifts clicks precedent (gift_clicks): a report
 *                       over a year reads a few hundred rows
 *   carts.gift_offer_claim_id   the claim this basket holds, read with the
 *                       cart row every page already fetches, so a basket
 *                       without one costs no query to know it
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gift_offer_ranges')) {
            Schema::create('gift_offer_ranges', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('min_fils')->default(0);
                $t->unsignedInteger('max_fils')->nullable();
                $t->boolean('enabled')->default(true);
                $t->unsignedSmallInteger('sort')->default(0);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('gift_offer_items')) {
            Schema::create('gift_offer_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('range_id')->index();
                $t->unsignedBigInteger('product_id');
                $t->unsignedBigInteger('variant_id')->nullable();
                $t->string('role', 8)->default('primary');
                $t->unsignedSmallInteger('sort')->default(0);
                $t->unsignedInteger('allocation')->default(0);
                $t->unsignedInteger('used')->default(0);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('gift_offer_claims')) {
            Schema::create('gift_offer_claims', function (Blueprint $t) {
                $t->id();
                $t->char('token_hash', 64)->unique();
                $t->string('place', 12);
                $t->unsignedBigInteger('range_id')->nullable();
                $t->unsignedBigInteger('item_id')->nullable();
                $t->unsignedBigInteger('product_id');
                $t->unsignedBigInteger('variant_id')->nullable();
                $t->unsignedInteger('value_fils')->default(0);
                $t->unsignedBigInteger('cart_id')->nullable()->index();
                $t->unsignedBigInteger('order_id')->nullable()->index();
                $t->string('status', 10)->default('issued');
                $t->boolean('is_test')->default(false);
                $t->string('locale', 5)->default('en');
                $t->dateTime('expires_at');
                $t->dateTime('claimed_at')->nullable();
                $t->dateTime('used_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('gift_offer_stats')) {
            Schema::create('gift_offer_stats', function (Blueprint $t) {
                $t->date('day');
                $t->string('place', 12);
                $t->unsignedBigInteger('range_id')->default(0);
                $t->string('event', 12);
                $t->unsignedBigInteger('n')->default(0);

                $t->primary(['day', 'place', 'range_id', 'event']);
            });
        }

        if (! Schema::hasColumn('carts', 'gift_offer_claim_id')) {
            Schema::table('carts', fn (Blueprint $t) => $t->unsignedBigInteger('gift_offer_claim_id')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['gift_offer_ranges', 'gift_offer_items', 'gift_offer_claims', 'gift_offer_stats'] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasColumn('carts', 'gift_offer_claim_id')) {
            Schema::table('carts', fn (Blueprint $t) => $t->dropColumn('gift_offer_claim_id'));
        }
    }
};

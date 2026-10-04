<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cart Tracking, part two: one row per add, remove or quantity change.
 *                                                                 (Lane CT)
 *
 * The cart's history timeline, and the Added / Removed products tabs.
 *
 *   type        1 add · 2 remove · 3 quantity change
 *   qty         the change, signed (+2 added, -1 taken off, -3 removed)
 *   qty_after   the line's quantity after it
 *   unit_price  the line's unit price in fils at that moment
 *   ip/country  only when they DIFFER from the cart's own (a phone moving from
 *               wifi to mobile data) — otherwise null, so a million events do
 *               not carry a million copies of the same address
 *
 * product_id and variant_id carry no foreign key on purpose: a product deleted
 * next year must not delete the history of the carts it was in. cart_id does
 * cascade: deleting a cart from the Cart Tracking screen deletes its history.
 *
 * INDEXED FOR THE THREE READS AND NOTHING ELSE:
 *   (cart_id, id)                            a cart's timeline
 *   (type, created_at, product_id, cart_id)  top products per period, covering;
 *                                            also the retention sweep's range
 *   (product_id, cart_id)                    "carts containing <product>" search
 *
 * No updated_at: an event is never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cart_events')) {
            return;
        }

        Schema::create('cart_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('type');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('variant_id')->nullable();
            $t->smallInteger('qty')->default(0);
            $t->unsignedSmallInteger('qty_after')->default(0);
            $t->integer('unit_price')->default(0);
            $t->string('ip', 45)->nullable();
            $t->string('country', 2)->nullable();
            $t->timestamp('created_at')->nullable();

            $t->index(['cart_id', 'id'], 'cart_events_cart_index');
            $t->index(['type', 'created_at', 'product_id', 'cart_id'], 'cart_events_period_index');
            $t->index(['product_id', 'cart_id'], 'cart_events_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_events');
    }
};

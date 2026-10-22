<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lane FG -- Free gifts (Marketing -> Free gifts).
 *
 *   free_gift_ranges        one spend band: min (fils), max (fils, NULL = "and
 *                           above"), its wording in English and Arabic, order,
 *                           on/off and optional start/end dates.
 *   free_gift_range_items   what a band gives: product_id and quantity.
 *   products.gift_only      "Gift only (not for sale)". Product::saving() keeps
 *                           such a product's is_visible false, so every place the
 *                           shop already hides an invisible product hides it.
 *   order_items.is_gift     a gift line on an order, at price 0. Order::items()
 *                           leaves these out, so every payment gateway, the
 *                           totals and the reports read exactly what they read
 *                           before; Order::gifts() reads them.
 *   order_items.gift_range_id / gift_range_label   the band it came from, kept
 *                           as a label too so an order still says so after the
 *                           band is edited or deleted.
 *   orders.free_gift_count  gift units on the order. 0 on every order before
 *                           this package, so a page showing an old order never
 *                           asks for its gifts.
 *
 * Guarded both ways, safe to run twice. Literals and framework calls only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('free_gift_ranges')) {
            Schema::create('free_gift_ranges', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('min_fils');
                $t->unsignedInteger('max_fils')->nullable();
                $t->string('label_en', 160)->default('');
                $t->string('label_ar', 160)->default('');
                $t->unsignedSmallInteger('sort')->default(0);
                $t->boolean('enabled')->default(true);
                $t->dateTime('starts_at')->nullable();
                $t->dateTime('ends_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('free_gift_range_items')) {
            Schema::create('free_gift_range_items', function (Blueprint $t) {
                $t->id();
                $t->foreignId('range_id')->constrained('free_gift_ranges')->cascadeOnDelete();
                $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $t->unsignedSmallInteger('quantity')->default(1);
                $t->unsignedSmallInteger('sort')->default(0);
                $t->timestamps();
                $t->unique(['range_id', 'product_id']);
            });
        }

        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'gift_only')) {
            Schema::table('products', function (Blueprint $t) {
                $t->boolean('gift_only')->default(false);
            });
        }

        if (Schema::hasTable('order_items') && ! Schema::hasColumn('order_items', 'is_gift')) {
            Schema::table('order_items', function (Blueprint $t) {
                $t->boolean('is_gift')->default(false);
                $t->unsignedBigInteger('gift_range_id')->nullable();
                $t->string('gift_range_label', 160)->nullable();
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'free_gift_count')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->unsignedSmallInteger('free_gift_count')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'free_gift_count')) {
            Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('free_gift_count'));
        }

        if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'is_gift')) {
            Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn(['is_gift', 'gift_range_id', 'gift_range_label']));
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'gift_only')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('gift_only'));
        }

        Schema::dropIfExists('free_gift_range_items');
        Schema::dropIfExists('free_gift_ranges');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cart Tracking, part three: what the retention sweep keeps.       (Lane CT)
 *
 * Events are deleted after `keep_days` (default 180). "All time" on the Added
 * and Removed products tabs must still mean all time, so before a batch of
 * events is deleted it is counted into this table, per product. The tab adds
 * this to what is still in cart_events. Zero cost per event: it is written
 * only by the sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cart_product_totals')) {
            return;
        }

        Schema::create('cart_product_totals', function (Blueprint $t) {
            $t->unsignedBigInteger('product_id')->primary();
            $t->unsignedInteger('added')->default(0);
            $t->unsignedInteger('added_carts')->default(0);
            $t->unsignedInteger('ordered_carts')->default(0);
            $t->unsignedInteger('removed')->default(0);
            $t->unsignedInteger('removed_carts')->default(0);
            $t->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_product_totals');
    }
};

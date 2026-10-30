<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2.60.630: Store -> New Order -> "Save as draft". The form as it stood, kept
 * to finish later. NOT an order: no number, no stock, no coupon, no revenue,
 * nothing on the Orders list. Removed when the order is created from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('manual_order_drafts')) {
            Schema::create('manual_order_drafts', function (Blueprint $t) {
                $t->id();
                $t->string('title', 160);
                $t->unsignedSmallInteger('item_count')->default(0);
                $t->unsignedBigInteger('value_fils')->default(0);
                $t->longText('payload');
                $t->string('author', 160)->nullable();
                $t->timestamps();

                $t->index('updated_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_order_drafts');
    }
};

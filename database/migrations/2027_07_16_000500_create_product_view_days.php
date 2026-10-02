<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per product per day: how many times its page was looked at. (Lane RB)
 *
 * The "Most viewed" rule of Buy these together reads it; App\Support\
 * ProductViews carries what it costs and where. No foreign key, deliberately:
 * a counter must never be the reason a product cannot be deleted, and a row
 * for a deleted product is simply never joined to anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_view_days')) {
            return;
        }

        Schema::create('product_view_days', function (Blueprint $t) {
            $t->unsignedBigInteger('product_id');
            $t->date('day');
            $t->unsignedInteger('views')->default(0);
            $t->primary(['product_id', 'day']);
            $t->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_view_days');
    }
};

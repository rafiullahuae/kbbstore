<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record what gift wrapping actually cost on each order.
 *
 * fee_total already carries it, but lumped in with any cash-on-delivery fee,
 * so the admin could only show a combined "Fees" figure. Recomputing the split
 * later from the current setting would be wrong the moment the merchant
 * changes the price: an order placed when wrapping was AED 15 would start
 * reporting whatever it costs today.
 *
 * Stored in fils, like every other money column here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (! Schema::hasColumn('orders', 'gift_fee')) {
                $t->integer('gift_fee')->default(0)->after('gift_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (Schema::hasColumn('orders', 'gift_fee')) {
                $t->dropColumn('gift_fee');
            }
        });
    }
};

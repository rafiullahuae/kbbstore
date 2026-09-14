<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gift flag and gift message on orders.
 *
 * `customer_note` already existed and needed no column -- what it lacked was
 * anything writing to it or showing it. A gift message is a separate thing
 * from an order note: it is addressed to the recipient, printed on a card,
 * and packers treat it differently, so it gets its own column rather than
 * being concatenated into the note and parsed back out later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (! Schema::hasColumn('orders', 'is_gift')) {
                $t->boolean('is_gift')->default(false)->after('customer_note');
            }

            if (! Schema::hasColumn('orders', 'gift_note')) {
                $t->text('gift_note')->nullable()->after('is_gift');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            foreach (['gift_note', 'is_gift'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Quiqup P1: the delivery stage on the order row itself, so the Orders list,
 * My account and the emails can read it with ZERO extra queries
 * (QUIQUP-PLAN §4.1). Written only by App\Services\Quiqup\ShipmentSync.
 * Guarded like add_ship_emirate_to_orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (! Schema::hasColumn('orders', 'ship_state')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('ship_state', 32)->nullable();
                $table->timestamp('ship_state_at')->nullable();
                $table->index('ship_state', 'orders_ship_state_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'ship_state')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_ship_state_index');
                $table->dropColumn(['ship_state', 'ship_state_at']);
            });
        }
    }
};

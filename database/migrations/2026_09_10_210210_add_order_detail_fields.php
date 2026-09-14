<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the new detailed admin order page (Rafi's reference screenshot):
 * customer IP at checkout, and soft deletes so "Move to trash" is a real,
 * reversible action rather than a permanent one — hard-deleting financial
 * records on a live store is not something an admin action should be able
 * to do by accident.
 *
 * Deliberately does NOT add a refunded-amount column — a real `refunds`
 * table (order_id, amount, reason, refunded_by) already exists in the
 * original schema and was simply never used anywhere. Summing it is the
 * correct source of truth; a denormalized column alongside it would just
 * be a second place for the number to drift out of sync with the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (!Schema::hasColumn('orders', 'ip_address')) {
                $t->string('ip_address')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('orders', 'deleted_at')) {
                $t->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (Schema::hasColumn('orders', 'deleted_at')) {
                $t->dropSoftDeletes();
            }
            if (Schema::hasColumn('orders', 'ip_address')) {
                $t->dropColumn('ip_address');
            }
        });
    }
};

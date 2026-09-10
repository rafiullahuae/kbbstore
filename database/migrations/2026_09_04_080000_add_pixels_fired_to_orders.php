<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing Pixels needs to fire the Purchase event exactly once per order —
 * the plugin does this with an order meta flag; here it's a real column,
 * matching how this app already prefers columns to meta blobs elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'pixels_fired_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $t) {
            $t->timestamp('pixels_fired_at')->nullable()->after('fee_total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn('pixels_fired_at');
        });
    }
};

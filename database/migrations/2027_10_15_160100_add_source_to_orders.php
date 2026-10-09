<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Where an order came from (Lane AN). Columns ON `orders`, not a side table:
 * the Orders list already reads its rows in one query, so two nullable columns
 * cost it nothing, where a side table would cost a join or a query. NULL is
 * "Unknown" -- every order placed before this shipped, every import and every
 * manual order.
 *
 *   src_channel   the last non-direct touch's channel (GA's default model),
 *                 or the first touch's when every touch was direct. Indexed
 *                 for the Orders list's Source filter.
 *   src_campaign  that touch's utm_campaign.
 *   src_attr      JSON: first and last touch (channel, source, medium,
 *                 campaign, click-id TYPE, landing page) and days to order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (! Schema::hasColumn('orders', 'src_channel')) {
                $t->string('src_channel', 16)->nullable()->index();
            }
            if (! Schema::hasColumn('orders', 'src_campaign')) {
                $t->string('src_campaign', 100)->nullable();
            }
            if (! Schema::hasColumn('orders', 'src_attr')) {
                $t->text('src_attr')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            foreach (['src_channel', 'src_campaign', 'src_attr'] as $c) {
                if (Schema::hasColumn('orders', $c)) {
                    if ($c === 'src_channel') {
                        $t->dropIndex(['src_channel']);
                    }
                    $t->dropColumn($c);
                }
            }
        });
    }
};

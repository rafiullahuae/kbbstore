<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2.60.627: how often shoppers use the free gift box's controls (View all,
 * Hide, Gifts Criteria, a range in it, a gift photo) -- App\Services\
 * CartTracking\GiftClicks.
 *
 * `gift_clicks` is one row per store day x control x place x range: the taps
 * and the carts that made them. A few hundred rows a month at most, never
 * pruned, so the report reads a year as cheaply as a week.
 *
 * `carts.ct_gifts` is the cart's own five counts ("2,0,1,3,1"), written in
 * the UPDATE Cart Tracking already makes, so each cart shows them with no
 * extra query.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gift_clicks')) {
            Schema::create('gift_clicks', function (Blueprint $t) {
                $t->date('day');
                $t->unsignedSmallInteger('code');
                $t->string('label', 40)->default('');
                $t->unsignedInteger('clicks')->default(0);
                $t->unsignedInteger('carts')->default(0);

                $t->primary(['day', 'code', 'label']);
            });
        }

        if (! Schema::hasColumn('carts', 'ct_gifts')) {
            Schema::table('carts', fn (Blueprint $t) => $t->string('ct_gifts', 40)->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_clicks');

        if (Schema::hasColumn('carts', 'ct_gifts')) {
            Schema::table('carts', fn (Blueprint $t) => $t->dropColumn('ct_gifts'));
        }
    }
};

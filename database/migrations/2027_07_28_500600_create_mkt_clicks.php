<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per click on a campaign link (Lane MK, plan §2.2, E4). For "Most
 * clicked" on the report. There is no open-tracking pixel anywhere (the
 * owner's D10): clicks and orders are the honest numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mkt_clicks')) {
            return;
        }

        Schema::create('mkt_clicks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('send_id');
            $t->unsignedBigInteger('link_id');
            $t->timestamp('clicked_at')->nullable();

            $t->index('send_id');
            $t->index('link_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mkt_clicks');
    }
};

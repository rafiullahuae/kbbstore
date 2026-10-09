<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Analytics, "Online now" (Lane AN2). ONE row per visitor who is on the shop,
 * keyed by the same daily-salted visitor hash an_hits carries -- so two tabs
 * are one row, and a page change moves the row rather than adding one. Upserted
 * by the page-view beacon and the visible-tab heartbeat, marked `gone` by the
 * leave beacon, and pruned by the minute rollup ten minutes after last_seen, so
 * the table never holds more than the last ten minutes' visitors.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('an_online')) {
            Schema::create('an_online', function (Blueprint $t) {
                $t->char('v', 16)->primary();
                $t->unsignedInteger('t');                  // last seen, epoch seconds
                $t->unsignedTinyInteger('gone')->default(0);
                $t->string('path', 191)->default('');
                $t->string('title', 120)->default('');
                $t->string('dev', 8)->default('');
                $t->char('cc', 2)->default('');
                $t->index('t');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('an_online');
    }
};

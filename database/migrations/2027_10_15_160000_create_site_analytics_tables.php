<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Site analytics (Lane AN). Three tables, chosen for what each one is read by.
 *
 * an_hits   ONE row per opened page (and per add-to-cart), written by the
 *           /api/viewed beacon with a single INSERT. Raw, short-lived: rows
 *           older than 48 h are pruned by the minute rollup. ONE secondary
 *           index, on the minute, because every reader is a minute range (the
 *           live slice, the day rollup, the prune) and every extra index is a
 *           write the beacon pays on every page. No IP, no user agent, no
 *           cookie: `v` is a 16-hex slice of sha256(daily salt + IP + UA).
 * an_days   one row per shop day: the totals the strip shows.
 * an_dims   one row per (day, dimension, value), capped per dimension per day
 *           by App\Services\Analytics\Rollup so a day's size is bounded however
 *           many pages exist. Read by (dim, day) ranges: the unique index's
 *           order is (day, dim, val) for the rollup's delete-and-rewrite of a
 *           day; the second index is (dim, day) for the dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('an_hits')) {
            Schema::create('an_hits', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('m');                 // epoch minute, UTC
                $t->char('v', 16);                        // visitor hash
                $t->char('s', 16);                        // session hash
                $t->unsignedTinyInteger('k')->default(0); // 0 page, 1 add to cart, 2 checkout page
                $t->unsignedTinyInteger('e')->default(0); // first page of a session
                $t->string('path', 191)->default('');
                $t->string('title', 120)->default('');
                $t->string('ref', 100)->default('');      // referrer domain, '' internal/none
                $t->string('ch', 16)->default('direct');  // channel key (Channels::MAP)
                $t->string('src', 60)->default('');
                $t->string('med', 40)->default('');
                $t->string('cmp', 100)->default('');
                $t->string('dev', 8)->default('');
                $t->string('br', 12)->default('');
                $t->string('os', 12)->default('');
                $t->char('cc', 2)->default('');
                $t->char('lang', 2)->default('');
                $t->index('m');
            });
        }

        if (! Schema::hasTable('an_days')) {
            Schema::create('an_days', function (Blueprint $t) {
                $t->date('day')->primary();
                $t->unsignedInteger('views')->default(0);
                $t->unsignedInteger('visitors')->default(0);
                $t->unsignedInteger('sessions')->default(0);
                $t->unsignedInteger('bounces')->default(0);
                $t->unsignedInteger('carts')->default(0);
                $t->unsignedInteger('checkouts')->default(0);
                $t->timestamp('rolled_at')->nullable();
            });
        }

        if (! Schema::hasTable('an_dims')) {
            Schema::create('an_dims', function (Blueprint $t) {
                $t->id();
                $t->date('day');
                $t->string('dim', 10);
                $t->string('val', 191);
                $t->string('label', 120)->default('');
                $t->unsignedInteger('views')->default(0);
                $t->unsignedInteger('visitors')->default(0);
                $t->unsignedInteger('sessions')->default(0);
                $t->unsignedInteger('bounces')->default(0);
                $t->unique(['day', 'dim', 'val']);
                $t->index(['dim', 'day']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('an_dims');
        Schema::dropIfExists('an_days');
        Schema::dropIfExists('an_hits');
    }
};

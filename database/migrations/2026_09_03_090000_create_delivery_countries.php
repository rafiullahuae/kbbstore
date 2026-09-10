<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extended delivery — one row per country the shop delivers to.
 *
 * A table rather than a JSON setting: there are 249 possible rows, and a blob
 * is rewritten whole on every save, so two admins saving at once would lose one
 * another's edits silently.
 *
 * Empty until Extended is switched on, and the switch is off by default, so
 * this migration changes nothing on an existing shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_countries')) {
            return;
        }

        Schema::create('delivery_countries', function (Blueprint $t) {
            $t->id();
            $t->string('code', 2)->unique();          // ISO 3166-1 alpha-2
            $t->boolean('enabled')->default(true);
            $t->integer('charge')->default(0);        // fils
            // Null means this country has no free delivery at any value, which
            // is different from zero — zero would mean everything ships free.
            $t->integer('free_from')->nullable();     // fils
            $t->string('eta')->nullable();            // "1–3 days"
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_countries');
    }
};

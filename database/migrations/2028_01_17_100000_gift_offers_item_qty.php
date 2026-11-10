<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Marketing -> Gift offers, "Gives per order ×N" (Lane GQ). The owner, 10
 * November 2026: "i need x2 in gift offers ... we need to give 2 pcs for this
 * product".
 *
 *   gift_offer_items.qty    how many units one claim gives (1..5, default 1).
 *                           `allocation` and `used` count UNITS, so a ×2 item
 *                           spends 2 of its allocation per order.
 *   gift_offer_claims.qty   the quantity the claim was ISSUED with, so an email
 *                           that promised two still gives two after the owner
 *                           changes the item, and never more.
 *
 * Both default 1: every existing range and claim gives exactly what it gave.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['gift_offer_items', 'gift_offer_claims'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'qty')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedTinyInteger('qty')->default(1));
            }
        }
    }

    public function down(): void
    {
        foreach (['gift_offer_items', 'gift_offer_claims'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'qty')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('qty'));
            }
        }
    }
};

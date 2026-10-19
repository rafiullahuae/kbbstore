<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lane VPA: the option a line holds, in the customer's language.
 *
 * `order_items.variant_attributes` is the English snapshot ("Shade": "#13
 * Bright Beige") the operator's documents print. This is the same snapshot in
 * the language the order was placed in, written by OrderLocale::listen() next
 * to `name_localised` and read by the customer's documents -- the emails, the
 * invoice, the delivery note, the order page. NULL on every English order and
 * on every line without options, which is every line before this package.
 *
 * Guarded both ways, safe to run twice. Literals and framework calls only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_items') || Schema::hasColumn('order_items', 'variant_localised')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $t) {
            $t->json('variant_localised')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'variant_localised')) {
            Schema::table('order_items', function (Blueprint $t) {
                $t->dropColumn('variant_localised');
            });
        }
    }
};

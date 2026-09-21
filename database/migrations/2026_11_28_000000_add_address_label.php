<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `addresses.label` — the Home / Office pill on the cart page's address sheet.
 *
 * WHY A COLUMN AND NOT A REUSE. The owner asked for "Home, Office option to
 * mark the address". The table has `type`, and `type` already means something
 * else: AddressController::TYPES pins it to billing|shipping and the checkout
 * branches on it. Overloading it would make a shipping address that is marked
 * "Home" unrepresentable. `company` was the other candidate and is worse — it
 * is printed on invoices.
 *
 * NULLABLE, with no default. A null label is every address that existed before
 * this package, and the reader treats it as "home" for display without writing
 * that back. Nothing is migrated, so nothing can be migrated wrongly, and a
 * rollback loses only the pills.
 *
 * NO `after()`. Nine earlier migrations in this project were silent no-ops on
 * MySQL because they positioned a column that way; the column order in this
 * table is not load-bearing and is not worth the risk.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('addresses', 'label')) {
            return;
        }

        Schema::table('addresses', function (Blueprint $t) {
            $t->string('label', 16)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('addresses', 'label')) {
            return;
        }

        Schema::table('addresses', function (Blueprint $t) {
            $t->dropColumn('label');
        });
    }
};

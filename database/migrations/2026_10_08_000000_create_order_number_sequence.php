<?php

declare(strict_types=1);

use App\Services\Orders\OrderNumbers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The order-number sequence. (Lane BA)
 *
 * One row, holding the next number to hand out. App\Services\Orders\
 * OrderNumbers advances it with a compare-and-swap from outside the placing
 * transaction; its class comment carries the full reasoning, including why
 * there is no lock here and why the old MAX()-inside-the-transaction
 * allocation lost a customer's order whenever two checkouts overlapped.
 *
 * SEEDED ABOVE EVERYTHING ALREADY IN `orders`, soft-deleted rows included.
 * Imported WooCommerce orders keep the numbers they arrived with — nothing in
 * this package renumbers an existing row — so the only safe place for a new
 * sequence to start is past the highest number the table holds. The rule is
 * OrderNumbers::seedValue()'s rather than a copy of it, so the migration and
 * the allocator cannot drift apart.
 *
 * Note that the seed deliberately does NOT use SQL's MAX(order_number):
 * the column is a VARCHAR and that maximum is lexical, so '9999' beats
 * '50002'. seedValue() compares the numbers as integers.
 *
 * `next_number` is a plain signed integer, matching the width the numbers are
 * eventually compared against; the store is six orders of magnitude away from
 * the ceiling.
 *
 * IDEMPOTENT. The table is created only if missing and the row is inserted
 * with insertOrIgnore, so re-applying the package — which this host does more
 * often than anyone would like — cannot reset a sequence that has already
 * moved on and start re-issuing numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(OrderNumbers::TABLE)) {
            Schema::create(OrderNumbers::TABLE, function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedInteger('next_number');
            });
        }

        // Only ever seeded once. An existing row has already handed numbers
        // out; overwriting it would re-issue them.
        DB::table(OrderNumbers::TABLE)->insertOrIgnore([
            'id' => OrderNumbers::ROW_ID,
            'next_number' => OrderNumbers::seedValue(),
        ]);

        if (app()->runningInConsole()) {
            $next = DB::table(OrderNumbers::TABLE)
                ->where('id', OrderNumbers::ROW_ID)
                ->value('next_number');

            echo "Order numbers continue from {$next}.\n";
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(OrderNumbers::TABLE);
    }
};

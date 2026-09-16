<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the tax RATE and BASIS on the order — Lane CU (tax engine).
 *
 * WHY THE ORDER HAS TO CARRY THEM. `orders.tax_total` has existed from the
 * start and has always been written 0, because VAT was a display line (D-64).
 * The owner overturned that on 2026-09-16 and the column now carries what was
 * actually charged. A figure on its own is not enough: an invoice reprinted a
 * year later has to state the rate it was charged at, and the only place that
 * rate can be read from safely is the order itself. Looked up at read time
 * instead, a shop that moves Saudi Arabia from 5% to 15% next year would have
 * every one of last year's Saudi invoices silently reprint at 15%. That is a
 * misstatement of a tax figure on a document people file, and it is the single
 * failure this migration exists to make impossible.
 *
 * NULLABLE, WITH NO BACKFILL, AND THAT IS THE POINT. Every order already in the
 * table was placed under D-64: nothing was charged, nothing was recorded, and
 * `tax_total` is 0 (or, for an order imported from WooCommerce, that order's
 * own real tax). NULL here means exactly "this order has no tax engine record",
 * and every reader — InvoiceDocument, OrderEmailPresenter, the admin order
 * screen — branches on it to behave precisely as it did before. Backfilling a
 * rate onto orders that never had one would be inventing a tax record, which is
 * worse than having none.
 *
 * GUARDED WITH hasColumn(), like 2026_09_15_020000_repair_order_tables: the
 * server's schema is the durable record here and a package may be applied to a
 * database that has already seen part of it. Running twice must be a no-op
 * rather than an error the owner sees in Store -> Core Updates.
 *
 * NO `AFTER` CLAUSE. Nine earlier migrations in this repo were silent no-ops on
 * MySQL because they positioned a column against one that was not there.
 * Column order is not a fact this application depends on.
 *
 * decimal(6,3) matches `tax_rates.rate`, which this schema has carried since
 * the first migration, so the two places a percentage is stored agree on its
 * precision. The screen offers two decimals; the column holds three.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $t) {
            if (! Schema::hasColumn('orders', 'tax_rate')) {
                $t->decimal('tax_rate', 6, 3)->nullable();
            }

            if (! Schema::hasColumn('orders', 'tax_basis')) {
                $t->string('tax_basis', 16)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $t) {
            foreach (['tax_rate', 'tax_basis'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};

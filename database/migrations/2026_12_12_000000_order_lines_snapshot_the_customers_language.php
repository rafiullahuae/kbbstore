<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second snapshot of the line name, in the language the order was placed in.
 * (Lane F)
 *
 * ── THE ASYMMETRY THIS CLOSES ───────────────────────────────────────────────
 *
 * `orders.locale` records the language an order was placed in, and
 * App\Support\OrderLocale::render() replays it for everything that happens
 * afterwards. Two families of document read it and they were deliberately
 * split:
 *
 *   CUSTOMER-FACING -- the invoice (Admin\InvoiceController) and every order
 *   email (Services\Mail\OrderMailer) -- render INSIDE OrderLocale::render(),
 *   so an Arabic order's paperwork has Arabic headings, Arabic labels and
 *   Arabic totals.
 *
 *   OPERATOR-FACING -- the packing slip, the delivery note and the bulk print
 *   -- are NOT wrapped, and resources/views/invoices/document.blade.php says so
 *   in as many words: "the other three documents are not wrapped, so they
 *   render in the operator's language".
 *
 * That split is right. The customer reads their language and the operator reads
 * theirs, and the admin is deliberately not localised at all (App\Support\Locale
 * says why: it is one operator and it is not indexed).
 *
 * But BOTH families read one column, `order_items.name`, and that column is
 * written from `$product->name` -- the English column, always. So an Arabic
 * customer browsed /ar/shop, put a product in the bag under its Arabic name,
 * saw that Arabic name in the basket drawer, on the cart page and in the
 * checkout summary, pressed Pay, and then received a confirmation email and an
 * invoice that call it something else. Every other word on those two documents
 * is in their language.
 *
 * ── WHY A SECOND COLUMN AND NOT A TRANSLATED ONE ────────────────────────────
 *
 * Because one column cannot answer two readers, and whichever language it held,
 * one of the two documents would be wrong. Translating `name` in place would
 * put Arabic product names on the packing slip the owner picks and packs from.
 *
 * And NOT by reading the live product when a document is drawn.
 * Services\Invoices\InvoiceDocument states the rule this migration obeys: "the
 * lines are the snapshot, never the live product ... an invoice is a record of
 * a transaction that happened; reprinting today's catalogue onto it makes it a
 * record of nothing". A read-time translation would also be undefined for the
 * lines whose product has since been deleted (`product_id` is nullable and
 * Product soft-deletes), and would put a query on every document, every email
 * and the bulk printer.
 *
 * So: a second snapshot, taken once, at the same moment and by the same rule as
 * the first. Immutable, defined for every line, and free to read.
 *
 * ── NOTHING MOVES WHEN THIS IS APPLIED ──────────────────────────────────────
 *
 * Nullable with no default and no backfill. Every existing row keeps a NULL,
 * every reader falls back to `name`, and the writer in OrderLocale::listen()
 * returns before it does anything at all while this shop serves one language --
 * which is how it ships. Applying this package changes no document.
 *
 * No AFTER clause, per the standing note in this directory about the nine
 * migrations that were silent no-ops on MySQL because of one.
 *
 * No route is added, so no clear_caches_* migration is owed alongside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_items') || Schema::hasColumn('order_items', 'name_localised')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $t) {
            /*
             * Named for what it is rather than for a language. App\Support\Locale
             * is built so that no code reads a locale code literally -- adding
             * French is a row in its LOCALES table -- and a column called
             * `name_ar` would be the first line in this application to break
             * that.
             */
            $t->string('name_localised')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'name_localised')) {
            Schema::table('order_items', function (Blueprint $t) {
                $t->dropColumn('name_localised');
            });
        }
    }
};

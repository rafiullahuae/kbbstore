<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcile the tables that a broken ALTER chain can have left incomplete.
 *
 * Checkout returned 500 with SQLSTATE[42S22] Unknown column 'is_gift'. Adding
 * the three gift columns did not end it, because MySQL reports only the FIRST
 * unknown column in an INSERT: a table missing five columns fails five times,
 * one release apart. Patching the reported column is a loop, not a fix.
 *
 * The cause is a chain of migrations that position each new column with
 * ->after(), naming the column the previous migration was supposed to create.
 * On MySQL, ALTER ... AFTER a column that does not exist is an error; the
 * Schema::hasColumn guards wrapped around them then make that failure look like
 * a clean no-op, so the migration records as run having changed nothing. SQLite
 * ignores AFTER entirely, which is why a green test suite sat alongside a
 * checkout that could not take an order.
 *
 * Nine migrations in this project use ->after(), touching orders, order_items,
 * product_variants, menu_items, update_releases, redirects, not_found_log,
 * payments and payment_events. All nine are reconciled here rather than waiting
 * for each to surface as its own outage.
 *
 * The column list is generated from a database migrated from scratch, so it is
 * what the schema actually produces rather than what reading the migrations
 * suggests. Every add is guarded and no ->after() appears anywhere, so this is
 * safe on a complete database and safe to run twice.
 *
 * Columns are added nullable even where the schema marks them NOT NULL: these
 * tables already hold rows, and MySQL will not add a NOT NULL column without a
 * default to a populated table. A nullable column that accepts orders beats a
 * strict one that cannot be added at all.
 *
 * No down(): these columns hold real data, and dropping them would recreate the
 * outage this exists to end.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (self::schema() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $missing = array_filter(
                $columns,
                static fn (string $column): bool => ! Schema::hasColumn($table, $column),
                ARRAY_FILTER_USE_KEY,
            );

            if ($missing === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($missing): void {
                foreach ($missing as $define) {
                    $define($t);
                }
            });

            if (app()->runningInConsole()) {
                echo 'Repaired '.$table.': '.implode(', ', array_keys($missing))."\n";
            }
        }
    }

    public function down(): void {}

    /** @return array<string, array<string, callable(Blueprint): void>> */
    private static function schema(): array
    {
        return [
            'orders' => [
                'wc_order_id' => fn (Blueprint $t) => $t->unsignedBigInteger('wc_order_id')->nullable(),
                'order_number' => fn (Blueprint $t) => $t->string('order_number')->nullable(),
                'invoice_number' => fn (Blueprint $t) => $t->integer('invoice_number')->nullable(),
                'invoiced_at' => fn (Blueprint $t) => $t->timestamp('invoiced_at')->nullable(),
                'customer_id' => fn (Blueprint $t) => $t->unsignedBigInteger('customer_id')->nullable(),
                'email' => fn (Blueprint $t) => $t->string('email')->nullable(),
                'phone' => fn (Blueprint $t) => $t->string('phone')->nullable(),
                'status' => fn (Blueprint $t) => $t->string('status')->default('pending'),
                'currency' => fn (Blueprint $t) => $t->string('currency')->default('AED'),
                'billing_address' => fn (Blueprint $t) => $t->json('billing_address')->nullable(),
                'shipping_address' => fn (Blueprint $t) => $t->json('shipping_address')->nullable(),
                'subtotal' => fn (Blueprint $t) => $t->integer('subtotal')->default(0),
                'discount_total' => fn (Blueprint $t) => $t->integer('discount_total')->default(0),
                'shipping_total' => fn (Blueprint $t) => $t->integer('shipping_total')->default(0),
                'fee_total' => fn (Blueprint $t) => $t->integer('fee_total')->default(0),
                'tax_total' => fn (Blueprint $t) => $t->integer('tax_total')->default(0),
                'total' => fn (Blueprint $t) => $t->integer('total')->default(0),
                'shipping_method' => fn (Blueprint $t) => $t->string('shipping_method')->nullable(),
                'payment_method' => fn (Blueprint $t) => $t->string('payment_method')->nullable(),
                'payment_method_title' => fn (Blueprint $t) => $t->string('payment_method_title')->nullable(),
                'transaction_id' => fn (Blueprint $t) => $t->string('transaction_id')->nullable(),
                'coupon_code' => fn (Blueprint $t) => $t->string('coupon_code')->nullable(),
                'whatsapp_optin' => fn (Blueprint $t) => $t->boolean('whatsapp_optin')->default(false),
                'customer_note' => fn (Blueprint $t) => $t->text('customer_note')->nullable(),
                'origin' => fn (Blueprint $t) => $t->string('origin')->nullable(),
                'paid_at' => fn (Blueprint $t) => $t->timestamp('paid_at')->nullable(),
                'completed_at' => fn (Blueprint $t) => $t->timestamp('completed_at')->nullable(),
                'pixels_fired_at' => fn (Blueprint $t) => $t->timestamp('pixels_fired_at')->nullable(),
                'ip_address' => fn (Blueprint $t) => $t->string('ip_address')->nullable(),
                'is_gift' => fn (Blueprint $t) => $t->boolean('is_gift')->default(false),
                'gift_note' => fn (Blueprint $t) => $t->text('gift_note')->nullable(),
                'gift_fee' => fn (Blueprint $t) => $t->integer('gift_fee')->default(0),
            ],
            'order_items' => [
                'order_id' => fn (Blueprint $t) => $t->unsignedBigInteger('order_id')->nullable(),
                'product_id' => fn (Blueprint $t) => $t->unsignedBigInteger('product_id')->nullable(),
                'product_variant_id' => fn (Blueprint $t) => $t->unsignedBigInteger('product_variant_id')->nullable(),
                'name' => fn (Blueprint $t) => $t->string('name')->nullable(),
                'brand' => fn (Blueprint $t) => $t->string('brand')->nullable(),
                'sku' => fn (Blueprint $t) => $t->string('sku')->nullable(),
                'variant_attributes' => fn (Blueprint $t) => $t->json('variant_attributes')->nullable(),
                'quantity' => fn (Blueprint $t) => $t->integer('quantity')->default(1),
                'unit_price' => fn (Blueprint $t) => $t->integer('unit_price')->default(0),
                'subtotal' => fn (Blueprint $t) => $t->integer('subtotal')->default(0),
                'total' => fn (Blueprint $t) => $t->integer('total')->default(0),
                'tax_total' => fn (Blueprint $t) => $t->integer('tax_total')->default(0),
            ],
            'product_variants' => [
                'product_id' => fn (Blueprint $t) => $t->unsignedBigInteger('product_id')->nullable(),
                'wc_id' => fn (Blueprint $t) => $t->unsignedBigInteger('wc_id')->nullable(),
                'sku' => fn (Blueprint $t) => $t->string('sku')->nullable(),
                'price' => fn (Blueprint $t) => $t->integer('price')->nullable(),
                'sale_price' => fn (Blueprint $t) => $t->integer('sale_price')->nullable(),
                'manage_stock' => fn (Blueprint $t) => $t->boolean('manage_stock')->default(false),
                'stock' => fn (Blueprint $t) => $t->integer('stock')->nullable(),
                'stock_status' => fn (Blueprint $t) => $t->string('stock_status')->default('instock'),
                'image' => fn (Blueprint $t) => $t->string('image')->nullable(),
                'position' => fn (Blueprint $t) => $t->integer('position')->default(0),
                'tag' => fn (Blueprint $t) => $t->string('tag')->nullable(),
            ],
            'menu_items' => [
                'menu_id' => fn (Blueprint $t) => $t->unsignedBigInteger('menu_id')->nullable(),
                'parent_id' => fn (Blueprint $t) => $t->unsignedBigInteger('parent_id')->nullable(),
                'label' => fn (Blueprint $t) => $t->string('label')->nullable(),
                'url' => fn (Blueprint $t) => $t->string('url')->nullable(),
                'target_type' => fn (Blueprint $t) => $t->string('target_type')->nullable(),
                'target_id' => fn (Blueprint $t) => $t->unsignedBigInteger('target_id')->nullable(),
                'icon' => fn (Blueprint $t) => $t->string('icon')->nullable(),
                'badge' => fn (Blueprint $t) => $t->string('badge')->nullable(),
                'position' => fn (Blueprint $t) => $t->integer('position')->default(0),
                'source_post_id' => fn (Blueprint $t) => $t->unsignedBigInteger('source_post_id')->nullable(),
                'highlight_color' => fn (Blueprint $t) => $t->string('highlight_color')->nullable(),
                'visibility' => fn (Blueprint $t) => $t->string('visibility')->default('always'),
                'new_tab' => fn (Blueprint $t) => $t->boolean('new_tab')->default(false),
                'columns' => fn (Blueprint $t) => $t->integer('columns')->nullable(),
            ],
            'update_releases' => [
                'name' => fn (Blueprint $t) => $t->string('name')->nullable(),
                'version' => fn (Blueprint $t) => $t->string('version')->nullable(),
                'status' => fn (Blueprint $t) => $t->string('status')->default('running'),
                'backup_id' => fn (Blueprint $t) => $t->string('backup_id')->nullable(),
                'file_count' => fn (Blueprint $t) => $t->integer('file_count')->default(0),
                'notes' => fn (Blueprint $t) => $t->text('notes')->nullable(),
                'migration_output' => fn (Blueprint $t) => $t->text('migration_output')->nullable(),
                'error' => fn (Blueprint $t) => $t->text('error')->nullable(),
                'applied_by' => fn (Blueprint $t) => $t->integer('applied_by')->nullable(),
                'completed_at' => fn (Blueprint $t) => $t->timestamp('completed_at')->nullable(),
                'archive_path' => fn (Blueprint $t) => $t->string('archive_path')->nullable(),
                'superseded_by' => fn (Blueprint $t) => $t->string('superseded_by')->nullable(),
            ],
            'redirects' => [
                'source' => fn (Blueprint $t) => $t->string('source')->nullable(),
                'target' => fn (Blueprint $t) => $t->string('target')->nullable(),
                'code' => fn (Blueprint $t) => $t->integer('code')->default(301),
                'enabled' => fn (Blueprint $t) => $t->boolean('enabled')->default(true),
                'hits' => fn (Blueprint $t) => $t->integer('hits')->default(0),
                'last_hit_at' => fn (Blueprint $t) => $t->timestamp('last_hit_at')->nullable(),
                'auto_created' => fn (Blueprint $t) => $t->boolean('auto_created')->default(false),
            ],
            'not_found_log' => [
                'path' => fn (Blueprint $t) => $t->string('path')->nullable(),
                'hits' => fn (Blueprint $t) => $t->integer('hits')->default(1),
                'referer' => fn (Blueprint $t) => $t->string('referer')->nullable(),
                'first_seen_at' => fn (Blueprint $t) => $t->timestamp('first_seen_at')->nullable(),
                'last_seen_at' => fn (Blueprint $t) => $t->timestamp('last_seen_at')->nullable(),
            ],
            'payments' => [
                'order_id' => fn (Blueprint $t) => $t->unsignedBigInteger('order_id')->nullable(),
                'provider' => fn (Blueprint $t) => $t->string('provider')->nullable(),
                'provider_ref' => fn (Blueprint $t) => $t->string('provider_ref')->nullable(),
                'amount' => fn (Blueprint $t) => $t->integer('amount')->nullable(),
                'currency' => fn (Blueprint $t) => $t->string('currency')->nullable(),
                'status' => fn (Blueprint $t) => $t->string('status')->nullable(),
                'failure_code' => fn (Blueprint $t) => $t->string('failure_code')->nullable(),
            ],
            'payment_events' => [
                'payment_id' => fn (Blueprint $t) => $t->string('payment_id')->nullable(),
                'type' => fn (Blueprint $t) => $t->string('type')->nullable(),
                'payload' => fn (Blueprint $t) => $t->json('payload')->nullable(),
                'received_at' => fn (Blueprint $t) => $t->timestamp('received_at')->nullable(),
                'provider' => fn (Blueprint $t) => $t->string('provider')->nullable(),
                'external_id' => fn (Blueprint $t) => $t->string('external_id')->nullable(),
            ],
        ];
    }
};

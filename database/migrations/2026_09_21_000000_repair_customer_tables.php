<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcile the customer-side tables, for the same reason
 * 2026_09_15_020000_repair_order_tables reconciled the order-side ones.
 *
 * Store → Customers returned 500 on the live server while the identical code
 * answered 200 here. Everything that can be checked remotely was checked and
 * came back clean: the routes register in the right order, all eight carry
 * auth:admin, the derived-table subqueries are ONLY_FULL_GROUP_BY-safe, and no
 * clause references a SELECT alias in WHERE. What is left is the difference
 * between the two databases — this sandbox runs a SQLite migrated from
 * scratch, the server runs a MySQL that spent most of this project's life
 * never being migrated at all, because the updater's `migrations` flag was
 * never set until 2.60.114.
 *
 * That is exactly how the checkout outage happened: SQLSTATE[42S22] Unknown
 * column 'is_gift', on a table the migrations believed they had already
 * altered. The order-side repair fixed nine tables and deliberately stopped
 * there. `customers`, `addresses` and `carts` were never in that list, and the
 * Customers screen is the first thing to read all three at once — a single
 * SELECT naming 22 columns across them, which fails entirely if any one is
 * absent.
 *
 * The column lists below are generated from a database migrated from scratch,
 * so they are what the schema actually produces rather than what reading the
 * migrations suggests. Every add is guarded, nothing uses ->after() (an ALTER
 * ... AFTER a column that does not exist is an error on MySQL and silently
 * ignored on SQLite — the original cause), and the whole thing is a no-op on a
 * complete database and safe to run twice.
 *
 * Columns are nullable even where the schema marks them NOT NULL: these tables
 * hold rows already, and MySQL will not add a NOT NULL column without a default
 * to a populated table.
 *
 * It echoes what it repaired. If it prints nothing, the schema was already
 * complete and the 500 is something else — which is worth knowing just as much.
 *
 * No down(): these columns hold real data.
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

    /** @return array<string, array<string, callable(Blueprint):mixed>> */
    private static function schema(): array
    {
        return [
            'customers' => [
                'wp_user_id' => fn (Blueprint $t) => $t->unsignedBigInteger('wp_user_id')->nullable(),
                'name' => fn (Blueprint $t) => $t->string('name')->nullable(),
                'first_name' => fn (Blueprint $t) => $t->string('first_name')->nullable(),
                'last_name' => fn (Blueprint $t) => $t->string('last_name')->nullable(),
                'email_verified_at' => fn (Blueprint $t) => $t->timestamp('email_verified_at')->nullable(),
                'password' => fn (Blueprint $t) => $t->string('password')->nullable(),
                'legacy_password' => fn (Blueprint $t) => $t->string('legacy_password')->nullable(),
                'remember_token' => fn (Blueprint $t) => $t->string('remember_token', 100)->nullable(),
                'phone' => fn (Blueprint $t) => $t->string('phone')->nullable(),
                'whatsapp_optin' => fn (Blueprint $t) => $t->boolean('whatsapp_optin')->default(false),
                'notes' => fn (Blueprint $t) => $t->text('notes')->nullable(),
                'orders_count' => fn (Blueprint $t) => $t->integer('orders_count')->default(0),
                'total_spent' => fn (Blueprint $t) => $t->integer('total_spent')->default(0),
                'last_order_at' => fn (Blueprint $t) => $t->timestamp('last_order_at')->nullable(),
                'deleted_at' => fn (Blueprint $t) => $t->timestamp('deleted_at')->nullable(),
            ],
            'addresses' => [
                'customer_id' => fn (Blueprint $t) => $t->unsignedBigInteger('customer_id')->nullable(),
                'type' => fn (Blueprint $t) => $t->string('type')->nullable(),
                'is_default' => fn (Blueprint $t) => $t->boolean('is_default')->default(false),
                'first_name' => fn (Blueprint $t) => $t->string('first_name')->nullable(),
                'last_name' => fn (Blueprint $t) => $t->string('last_name')->nullable(),
                'company' => fn (Blueprint $t) => $t->string('company')->nullable(),
                'line1' => fn (Blueprint $t) => $t->string('line1')->nullable(),
                'line2' => fn (Blueprint $t) => $t->string('line2')->nullable(),
                'city' => fn (Blueprint $t) => $t->string('city')->nullable(),
                'state' => fn (Blueprint $t) => $t->string('state')->nullable(),
                'postcode' => fn (Blueprint $t) => $t->string('postcode')->nullable(),
                'country' => fn (Blueprint $t) => $t->string('country')->nullable(),
                'phone' => fn (Blueprint $t) => $t->string('phone')->nullable(),
            ],
            'carts' => [
                'token' => fn (Blueprint $t) => $t->string('token')->nullable(),
                'customer_id' => fn (Blueprint $t) => $t->unsignedBigInteger('customer_id')->nullable(),
                'currency' => fn (Blueprint $t) => $t->string('currency')->nullable(),
                'coupon_id' => fn (Blueprint $t) => $t->unsignedBigInteger('coupon_id')->nullable(),
                'shipping_country' => fn (Blueprint $t) => $t->string('shipping_country')->nullable(),
                'shipping_state' => fn (Blueprint $t) => $t->string('shipping_state')->nullable(),
                'shipping_method_id' => fn (Blueprint $t) => $t->unsignedBigInteger('shipping_method_id')->nullable(),
                'status' => fn (Blueprint $t) => $t->string('status')->default('active'),
                'last_activity_at' => fn (Blueprint $t) => $t->timestamp('last_activity_at')->nullable(),
                'converted_at' => fn (Blueprint $t) => $t->timestamp('converted_at')->nullable(),
            ],
        ];
    }
};

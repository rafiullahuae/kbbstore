<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Buy these together" bundle discount: where a group lives and what it was. (Lane RE)
 *
 * The owner: "give option to give discount upon 5 products purchse, 4 products
 * and 3. [...] and if any product removed from the cart, the other products
 * prices will become normal without buy together discount."
 *
 *   cart_items.bt_group    the group the SERVER made in CartController::
 *                          addTogether() — a random 32-character handle, never
 *                          one a browser sent. NULL on every ordinary line.
 *   cart_items.bt_size     how many lines that request put in the group. The
 *                          group earns its tier only while exactly that many
 *                          lines still carry the handle: a line removed is a
 *                          group dissolved (App\Services\BuyTogetherPricing).
 *
 *   orders.bundle_discount        the part of `discount_total` that was the
 *                                 bundle, so the order says what was charged
 *                                 AND why: discount_total = coupon + bundle,
 *                                 which keeps OrderTax, Tabby and Tamara on the
 *                                 sums they already make.
 *   order_items.bundle_discount   that line's share, in fils
 *   order_items.bundle_group      the group handle, so the lines of one bundle
 *                                 can be read back together
 *   order_items.bundle_percent    the tier that was applied, on the day
 *
 * Every column is nullable or defaults to 0, so every existing cart, order and
 * order line reads exactly as it did. Guarded by hasColumn() so a package
 * re-applied over itself is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cart_items')) {
            Schema::table('cart_items', function (Blueprint $t) {
                if (! Schema::hasColumn('cart_items', 'bt_group')) {
                    $t->string('bt_group', 32)->nullable()->index();
                }
                if (! Schema::hasColumn('cart_items', 'bt_size')) {
                    $t->unsignedTinyInteger('bt_size')->nullable();
                }
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'bundle_discount')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->integer('bundle_discount')->default(0);
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $t) {
                if (! Schema::hasColumn('order_items', 'bundle_discount')) {
                    $t->integer('bundle_discount')->default(0);
                }
                if (! Schema::hasColumn('order_items', 'bundle_group')) {
                    $t->string('bundle_group', 32)->nullable();
                }
                if (! Schema::hasColumn('order_items', 'bundle_percent')) {
                    $t->unsignedTinyInteger('bundle_percent')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'cart_items' => ['bt_group', 'bt_size'],
            'orders' => ['bundle_discount'],
            'order_items' => ['bundle_discount', 'bundle_group', 'bundle_percent'],
        ] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, function (Blueprint $t) use ($table, $column) {
                        if ($table === 'cart_items' && $column === 'bt_group') {
                            $t->dropIndex(['bt_group']);
                        }
                        $t->dropColumn($column);
                    });
                }
            }
        }
    }
};

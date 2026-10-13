<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2.60.494 (Lane CJ): where a shopper with a basket went and where they left.
 *
 * `cart_trail` is one row per page a basket-holder opened and per meaningful
 * tap on the bag panel, the cart page and the checkout (App\Services\
 * CartTracking\CartTrail::CODES). It holds a code and a path, never a typed
 * value. Kept apart from `cart_events` on purpose: the Added / Removed product
 * reports and their roll-ups read cart_events and must not see page views.
 *
 * Four summary columns on `carts` let the Carts list show "Left at" and the
 * furthest checkout step without a join: the furthest step reached, the last
 * page and when it was seen, and when the shopper closed or left a page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cart_trail')) {
            Schema::create('cart_trail', function (Blueprint $t) {
                $t->id();
                $t->foreignId('cart_id')->constrained()->cascadeOnDelete();
                $t->unsignedSmallInteger('code');
                $t->string('path', 160)->nullable();
                $t->timestamp('created_at')->nullable();

                $t->index(['cart_id', 'id'], 'cart_trail_cart_index');
                $t->index(['created_at'], 'cart_trail_created_index');
            });
        }

        $cols = [
            'ct_stage' => fn (Blueprint $t) => $t->unsignedTinyInteger('ct_stage')->default(0),
            'ct_seen_at' => fn (Blueprint $t) => $t->timestamp('ct_seen_at')->nullable(),
            'ct_seen_path' => fn (Blueprint $t) => $t->string('ct_seen_path', 160)->nullable(),
            'ct_left_at' => fn (Blueprint $t) => $t->timestamp('ct_left_at')->nullable(),
        ];

        foreach ($cols as $name => $add) {
            if (! Schema::hasColumn('carts', $name)) {
                Schema::table('carts', $add);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_trail');

        foreach (['ct_stage', 'ct_seen_at', 'ct_seen_path', 'ct_left_at'] as $name) {
            if (Schema::hasColumn('carts', $name)) {
                Schema::table('carts', fn (Blueprint $t) => $t->dropColumn($name));
            }
        }
    }
};

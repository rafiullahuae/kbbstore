<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane CAP: the email a shopper types at checkout, recorded before the order.
 * The owner, on Cart Tracking's "Guest, no email": "when user enter their
 * email, i want to record that email in our system ... even user don't create
 * account or place the order."
 *
 *   carts.ct_email              the address last typed on this cart's checkout
 *   carts.ct_email_customer_id  the customers row it was filed under (NOT
 *                               carts.customer_id -- that column decides whose
 *                               basket a signed-in account sees, and a typed
 *                               address proves nothing; see CheckoutEmailCapture)
 *   carts.ct_email_n            how many different addresses this cart has sent
 *   customers.origin            how a row came to exist; 'checkout_email' for a
 *                               row this capture made. NULL for every other row.
 *
 * LITERALS AND FRAMEWORK CALLS ONLY. No app class is referenced here (the
 * 2.60.512 rollback); CheckoutEmailCaptureTest pins that with token_get_all.
 * Every step is guarded, so a re-run, or a half-applied run, is harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('carts')) {
            if (! Schema::hasColumn('carts', 'ct_email')) {
                Schema::table('carts', function (Blueprint $t) {
                    $t->string('ct_email', 191)->nullable();
                });
            }

            if (! Schema::hasColumn('carts', 'ct_email_customer_id')) {
                Schema::table('carts', function (Blueprint $t) {
                    $t->unsignedBigInteger('ct_email_customer_id')->nullable()->index('carts_ct_email_customer_idx');
                });
            }

            if (! Schema::hasColumn('carts', 'ct_email_n')) {
                Schema::table('carts', function (Blueprint $t) {
                    $t->unsignedTinyInteger('ct_email_n')->default(0);
                });
            }
        }

        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'origin')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->string('origin', 20)->nullable();
            });
        }

        // The columns are there: say so ONCE, in a setting, so the code asks
        // the settings map it already holds instead of the schema on every
        // request (two Schema::hasColumn calls cost the customer page two
        // queries -- AdminCustomersTest's budget of 8 caught it). Literal key.
        if (Schema::hasTable('settings')
            && Schema::hasColumn('carts', 'ct_email_customer_id') && Schema::hasColumn('customers', 'origin')) {
            DB::table('settings')->updateOrInsert(['key' => 'checkout_email_capture_schema'], ['value' => '1', 'autoload' => true]);
            \Illuminate\Support\Facades\Cache::forget('kbb.settings');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('carts')) {
            if (Schema::hasColumn('carts', 'ct_email_customer_id')) {
                Schema::table('carts', function (Blueprint $t) {
                    $t->dropIndex('carts_ct_email_customer_idx');
                });
                Schema::table('carts', function (Blueprint $t) {
                    $t->dropColumn('ct_email_customer_id');
                });
            }

            foreach (['ct_email', 'ct_email_n'] as $name) {
                if (Schema::hasColumn('carts', $name)) {
                    Schema::table('carts', fn (Blueprint $t) => $t->dropColumn($name));
                }
            }
        }

        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'origin')) {
            Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('origin'));
        }
    }
};

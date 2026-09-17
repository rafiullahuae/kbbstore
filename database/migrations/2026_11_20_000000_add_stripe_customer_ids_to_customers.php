<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a saved card is hung from — Lane FY.
 *
 * "Save this card for future purchases" cannot be honoured without a Stripe
 * Customer: `setup_future_usage` on a PaymentIntent is refused outright unless
 * one is named, because a saved card has to belong to somebody. This column is
 * the shop's record of which Stripe Customer belongs to which of its own.
 *
 * A MAP, NOT AN ID, AND THE REASON IS A CHECKOUT OUTAGE AVOIDED. A `cus_...`
 * minted with test keys does not exist to an account using live keys. Held as
 * one value, the first real order from a customer who had also ordered while
 * the shop was in test mode would send Stripe a customer it has never heard of
 * — and Stripe refuses the whole PaymentIntent, not merely the saving of the
 * card, so that shopper simply cannot pay. `{"test": "cus_…", "live": "cus_…"}`
 * keeps the two apart and makes throwing that switch cost nothing. See
 * Customer::stripeCustomerId().
 *
 * `text` rather than `json` for the reason `products.routine_concerns` is text:
 * this schema ships to SQLite in the tests and MySQL in production, and
 * Eloquent's `array` cast reads either on both.
 *
 * NULLABLE, and every existing row stays null — which is the truth about all of
 * them. Nothing reads this column except the card-saving path, and a null there
 * means "no Stripe Customer yet", which is answered by making one.
 *
 * NOT unique and not indexed. It is read by primary key from the order being
 * paid for, never searched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'stripe_customer_ids')) {
                $table->text('stripe_customer_ids')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'stripe_customer_ids')) {
                $table->dropColumn('stripe_customer_ids');
            }
        });
    }
};

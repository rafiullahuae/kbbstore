<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An address a shopper gave us before they bought anything (Lane EN).
 *
 * This is the consent-bearing table, and it is worth being blunt about what it
 * is: a list of people who put something in a basket, typed an email address
 * and did not pay. Every row is somebody this shop has a reason to chase and no
 * order from. Nothing is written here unless the shopper explicitly asked for
 * the reminder — see App\Services\CartRecovery::capture(), which takes an
 * address and a tick and refuses without both.
 *
 * ---------------------------------------------------------------------------
 * unique (cart_id) — "ONE SEQUENCE PER CART, NEVER RESTARTED BY A RELOAD"
 * ---------------------------------------------------------------------------
 * The rule the brief sets is that a reload must not restart the sequence. The
 * tempting implementation is "look for a row, insert if absent", and that is
 * check-then-write: two tabs, two requests, two rows, two sequences, two emails
 * about one basket.
 *
 * So the uniqueness is the database's. capture() does an INSERT and treats a
 * duplicate-key violation as success — the row that is already there is the
 * answer, and `consented_at` and `stage` keep the values the FIRST capture
 * wrote. A shopper who reloads the cart page forty times has one row whose
 * clock started once.
 *
 * `cart_id` cascades: a cart deleted by the cleanup job takes its recovery row
 * with it, which is the correct retention answer as well as the tidy one.
 *
 * ---------------------------------------------------------------------------
 * `stage` — "NOTHING SENDS TWICE", THE SECOND HALF
 * ---------------------------------------------------------------------------
 * `stage` counts the messages already sent: 0 means none. The sender does not
 * read it and then write it. It does
 *
 *     UPDATE cart_recoveries SET stage = N+1 ... WHERE id = ? AND stage = N
 *
 * and sends only if that statement changed exactly one row. Two processes
 * sweeping the same second both try to move 0 -> 1; one succeeds and one
 * reports zero rows affected and sends nothing. It is a compare-and-swap in a
 * single statement, it needs no lock held across an SMTP conversation, and it
 * behaves identically on SQLite and MySQL.
 *
 * ---------------------------------------------------------------------------
 * `cancelled_at` — THE ONE THAT MATTERS
 * ---------------------------------------------------------------------------
 * A recovery email for an order already placed is worse than sending nothing.
 * `cancelled_at` is the flag that stops it and it is set from three directions:
 *
 *   'ordered'      an order was created carrying this address. Written by
 *                  App\Services\Mail\OrderMailObserver's `created` hook, INSIDE
 *                  the checkout's own transaction, so it commits with the order
 *                  or not at all.
 *   'unsubscribed' the recipient pressed the link in the message.
 *   'converted'    the sweep itself found the cart no longer `active`.
 *
 * Once set it is never cleared. A cancelled row cannot claim a stage, because
 * the compare-and-swap above also requires `cancelled_at IS NULL`.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS STORED, AND HOW SOMEBODY GETS OUT
 * ---------------------------------------------------------------------------
 *   email         the address they typed, lower-cased.
 *   consented_at  when they ticked the box. The evidence; never moved.
 *   source        which form took it ('cart', 'checkout'), 20 chars, from a
 *                 closed list — never free text off a request.
 *
 * No basket contents are copied here. The cart rows are already the basket and
 * a second copy is a second thing to delete when somebody asks to be forgotten.
 * Getting out is the unsubscribe link every message carries, which sets
 * `cancelled_at` on this row AND writes the address to `outbound_optouts` so a
 * later capture is refused rather than merely stopped.
 *
 * No ->after() — this creates a table. See MigrationConventionTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cart_recoveries')) {
            return;
        }

        Schema::create('cart_recoveries', function (Blueprint $t) {
            $t->id();

            $t->foreignId('cart_id')->constrained()->cascadeOnDelete();

            $t->string('email', 191);
            $t->string('source', 20)->default('cart');

            /*
             * Unsigned small integer rather than a boolean per message: the
             * number of messages in the sequence is the owner's to choose (the
             * schedule box takes a list of hours), so nothing here may assume
             * there are two of them.
             */
            $t->unsignedSmallInteger('stage')->default(0);

            $t->timestamp('consented_at')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 20)->nullable();

            $t->timestamps();

            /* THE CONSTRAINT. One recovery sequence per cart, for the life of
             * the cart, enforced here and not in a controller. */
            $t->unique('cart_id', 'cart_recoveries_one_per_cart');

            /*
             * The sweep asks for "live rows whose next message is due", which
             * filters on cancelled_at and orders by consented_at. The email
             * index serves the cancel-on-order hook, which is the hot one: it
             * runs inside the checkout transaction on every order placed.
             */
            $t->index(['cancelled_at', 'stage']);
            $t->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_recoveries');
    }
};

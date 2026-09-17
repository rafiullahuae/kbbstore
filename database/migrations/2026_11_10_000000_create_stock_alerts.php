<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Tell me when this is back" — one row per request (Lane EN).
 *
 * A row here is a shopper's consent to receive EXACTLY ONE EMAIL ABOUT EXACTLY
 * ONE PRODUCT. It is not a subscription, it is not a marketing permission and
 * it never joins the newsletter list: `subscribers` is a different table with a
 * different round trip, and NewsletterList::marketable() — the only query
 * anything may send marketing from — cannot see this table at all. That
 * separation is the whole consent story for this feature and it is enforced by
 * the schema rather than by a rule somebody has to remember.
 *
 * ---------------------------------------------------------------------------
 * THE UNIQUE INDEX, WHICH IS THE "CANNOT ASK TWICE" GUARANTEE
 * ---------------------------------------------------------------------------
 *
 *     unique (product_id, product_variant_id, email, slot)
 *
 * `slot` is the trick and it is worth spelling out, because the obvious designs
 * do not work on both engines.
 *
 * What is wanted is "at most one UNSENT request per person per shelf, and any
 * number of spent ones". A partial index (`WHERE notified_at IS NULL`) says
 * that directly and SQLite supports it; MySQL does not, and this repo's whole
 * testing rule is that a constraint green on one engine and absent on the other
 * is a defect waiting for production. A nullable column does not work either:
 * both engines treat NULLs in a unique index as distinct from each other, so
 * "many NULLs allowed" is exactly the wrong way round.
 *
 * So `slot` is NOT NULL and carries a value in both states:
 *
 *   'pending'    while the request is unsent. The index then permits ONE such
 *                row per (product, variant, email) — a shopper who presses the
 *                button five times still has one request, and the fifth press
 *                is an INSERT the database refuses rather than a count this
 *                application did before writing.
 *   'sent:<id>'  once it has been sent. Unique by construction (it contains the
 *                row's own primary key), so a spent row never collides with
 *                anything, and the shopper is free to ask again next time the
 *                product sells out.
 *
 * `product_variant_id` is NOT NULL, default 0, and has no foreign key. 0 means
 * "the product itself, not one of its options". This is the second half of the
 * same portability problem: a NULLable variant column would make every
 * product-level request distinct from every other product-level request in the
 * unique index — on BOTH engines — and the constraint above would silently
 * permit unlimited duplicates for exactly the commonest case. A sentinel is
 * uglier than NULL and it is the only version that is actually enforced.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS STORED
 * ---------------------------------------------------------------------------
 *   email         lower-cased at the point of write. Stored, not digested,
 *                 because the entire purpose of the row is to send a message to
 *                 it. Nothing outside the admin demand list and the sender ever
 *                 reads it.
 *   requested_at  when consent was given. Never moved afterwards — it is the
 *                 evidence, and a value that creeps forward on every re-press
 *                 is not evidence of anything.
 *   notified_at   when the one message went. NULL means owed.
 *
 * No IP, no user agent and no customer id. CLAUDE.md records `reviews` leaking
 * `author_email` and `ip` through a public endpoint; the cheapest way not to
 * leak a column is not to have it.
 *
 * No ->after() — this creates a table. See MigrationConventionTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_alerts')) {
            return;
        }

        Schema::create('stock_alerts', function (Blueprint $t) {
            $t->id();

            /*
             * Cascade on delete. A request to be told about a product that no
             * longer exists cannot be honoured, and leaving the row would leave
             * an address behind for no reason anybody could later justify.
             */
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Sentinel-bearing, so NOT a foreign key. See the header.
            $t->unsignedBigInteger('product_variant_id')->default(0);

            $t->string('email', 191);
            $t->string('slot', 32)->default('pending');

            $t->timestamp('requested_at')->nullable();
            $t->timestamp('notified_at')->nullable();

            $t->timestamps();

            /* THE CONSTRAINT. Named, because the code that inserts through it
             * catches the violation by intent and a generated name differs
             * between engines. */
            $t->unique(
                ['product_id', 'product_variant_id', 'email', 'slot'],
                'stock_alerts_one_pending_per_shelf'
            );

            /*
             * The sweep's only query is "pending rows whose shelf is back in
             * stock", which reads every unsent row and joins products. Indexing
             * `notified_at` keeps that cheap once the spent rows outnumber the
             * live ones, which they will within a month.
             */
            $t->index('notified_at');

            // The admin demand list groups by product.
            $t->index(['product_id', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alerts');
    }
};

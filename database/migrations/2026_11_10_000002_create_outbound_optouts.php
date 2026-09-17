<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Do not send me these." The suppression list for the two shopper-triggered
 * emails (Lane EN).
 *
 * WHY A LIST OF ADDRESSES AND NOT A FLAG ON EACH ROW.
 *
 * Both features store a request, and both let somebody make another one. A flag
 * on the request stops THAT request; it does nothing about the next one. So an
 * unsubscribe that only cancelled the row it came from would be a button that
 * says "stop" and means "stop this one" — and the shopper who pressed it, and
 * then asked to be told about a different product last week, would be mailed
 * again. That is the failure the word "honours" in the brief is about.
 *
 * An address here is refused at the point of CAPTURE (a new notify-me request
 * or a new cart reminder is silently declined) and excluded at the point of
 * SEND (both due-queries filter on it), so a row already sitting in a table
 * from before the opt-out never goes out either. Two independent halves,
 * because one of them is bound to be forgotten in a later change and the other
 * will still hold.
 *
 * WHY IT IS NOT `subscribers`.
 *
 * `subscribers` is the marketing list and it is governed by double opt-in:
 * NewsletterList::marketable() requires `status='subscribed'` AND
 * `confirmed_at IS NOT NULL`. Writing a stock-alert opt-out into that table
 * would mean an address appearing in the marketing list's record that never
 * asked to be in it, and it would make the two consents readable as one — which
 * is exactly backwards, because they are deliberately separate. A shopper may
 * want the newsletter and not want basket reminders, and both of those must be
 * expressible.
 *
 * NO REASON COLUMN AND NO SOURCE COLUMN. What is recorded is that this address
 * asked not to receive these messages. Which button they pressed is not
 * information this shop needs about a person who has just told it to stop.
 *
 * `email` is the primary key in effect (unique), lower-cased by the only writer.
 *
 * No ->after() — this creates a table. See MigrationConventionTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outbound_optouts')) {
            return;
        }

        Schema::create('outbound_optouts', function (Blueprint $t) {
            $t->id();

            /*
             * Unique, so the writer is an idempotent insert-or-ignore rather
             * than a read followed by a write. Somebody pressing the button
             * twice, or a mail client prefetching a form post it should not
             * have, must not be able to produce two rows or an error page.
             */
            $t->string('email', 191)->unique();

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_optouts');
    }
};

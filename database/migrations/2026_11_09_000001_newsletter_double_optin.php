<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turn `subscribers` into a list that knows who actually asked to be on it.
 *
 * The table was created with a `confirmed_at` column and a comment saying it
 * "is unused today and exists so double opt-in can be added later without a
 * second migration against a table that by then has rows in it". This is that
 * later. No new column is needed, which is the whole point of that foresight --
 * and it matters more here than usual, because an ALTER on this host is the
 * class of change that took checkout down once already (see
 * tests/Feature/MigrationConventionTest.php).
 *
 * ---------------------------------------------------------------------------
 * WHAT THE STATUSES NOW MEAN
 * ---------------------------------------------------------------------------
 *   pending       signed up, has not clicked the link. NOT on the list. Must
 *                 never receive marketing.
 *   subscribed    confirmed, or grandfathered -- see below. On the list.
 *   unsubscribed  asked to be removed. Off the list, and the row is KEPT rather
 *                 than deleted so that a later signup can tell "new address"
 *                 from "someone who opted out", and so the owner cannot
 *                 accidentally re-import them.
 *
 * ---------------------------------------------------------------------------
 * THE GRANDFATHER DECISION, STATED PLAINLY BECAUSE IT IS A JUDGEMENT CALL
 * ---------------------------------------------------------------------------
 * Every row already in this table was collected under SINGLE opt-in: somebody
 * typed an address into the homepage form and was added immediately. None of
 * them has ever been asked to confirm, because nothing has ever been able to
 * send them anything.
 *
 * There are two defensible things to do with them and this migration does the
 * reversible one. It stamps `confirmed_at` from `created_at` and leaves them
 * `subscribed`, so the owner's existing list survives this package.
 *
 * The alternative -- moving them all to `pending` -- is the stricter reading of
 * double opt-in, and it silently empties the only marketing list the business
 * has, on an update the owner applied to fix password reset. That is a
 * commercial decision belonging to the owner, not a side effect of a migration,
 * and it is one he can still make afterwards: the addresses are all still here
 * and a single UPDATE moves them. The reverse is not true. Nothing recreates a
 * list that a migration deleted.
 *
 * What this does NOT do is pretend they confirmed. `confirmed_at` is set to the
 * signup time, not to now, so the record says "this address has been on the
 * list since then" rather than "this address confirmed today". Anyone auditing
 * the list later can tell the grandfathered rows from the confirmed ones: a
 * grandfathered row's `confirmed_at` equals its `created_at` to the second.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscribers')) {
            return;
        }

        /*
         * Only rows that are on the list and have never been confirmed.
         *
         * Guarded on `confirmed_at IS NULL` rather than applied blindly so that
         * re-running this migration -- which happens on this host, because
         * packages are applied by hand and have been applied twice before --
         * cannot rewrite the confirmation time of somebody who has since
         * genuinely confirmed.
         */
        DB::table('subscribers')
            ->where('status', 'subscribed')
            ->whereNull('confirmed_at')
            ->update([
                // The column the row already carries, not now(). See the header.
                'confirmed_at' => DB::raw('created_at'),
            ]);
    }

    /**
     * Deliberately empty.
     *
     * Rolling back would mean blanking `confirmed_at` on every row, including
     * rows that confirmed for real after this package landed -- turning real
     * consent into no consent, permanently, to undo a migration. The column
     * existed before this ran and still exists after; there is nothing here
     * whose removal is safer than its absence.
     */
    public function down(): void {}
};

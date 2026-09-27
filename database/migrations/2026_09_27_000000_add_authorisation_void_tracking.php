<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Releasing an authorisation — the third verb, and the two columns it needs.
 *
 * Tabby and Tamara AUTHORISE at checkout and hold the funds for weeks. The
 * shop could take that money (`captured_at`, `capture_ref` — the September 17
 * migration) and give it back once taken (`refunds`), but it could not GIVE THE
 * AUTHORISATION BACK. A cancelled order left the buyer's instalment plan live
 * on their Tamara account for up to 180 days, and the shop had no way to close
 * it and no record of having tried.
 *
 * App\Services\Payments\VoidsAuthorisation carries the full reasoning. These
 * are the two columns App\Services\Payments\PaymentVoider writes:
 *
 *   voided_at   doubles as the idempotency guard, exactly as `captured_at`
 *               does for capture and `paid_at` does for confirmation.
 *               PaymentVoider claims it with one conditional UPDATE against
 *               NULL before it calls the provider and RELEASES it if the call
 *               fails — so two clicks cannot both become a cancel call, and a
 *               failed call leaves the order retryable rather than marked as
 *               released when it is not.
 *   void_ref    the provider's own cancellation id (Tamara returns
 *               `cancel_id`). Nothing downstream needs it — unlike
 *               `capture_ref`, which a refund has to point at — so it is kept
 *               purely so the release can be found in the provider's dashboard
 *               from the order, which is the question asked when a customer
 *               says they are still being billed.
 *
 * NULLABLE AND WITH NO DEFAULT, so applying this package changes nothing about
 * any existing order: every row reads "no release has been attempted", which is
 * the truth. No backfill, because there is nothing to backfill — before this
 * migration no release could have happened.
 *
 * No ->after() anywhere, per the note on the September 17 migration: column
 * order is cosmetic and an ALTER ... AFTER a column that does not exist is how
 * nine tables on the live database ended up incomplete while SQLite kept the
 * suite green.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'voided_at')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->timestamp('voided_at')->nullable();
            });
        }

        if (! Schema::hasColumn('orders', 'void_ref')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->string('void_ref')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['voided_at', 'void_ref']);
        });
    }
};

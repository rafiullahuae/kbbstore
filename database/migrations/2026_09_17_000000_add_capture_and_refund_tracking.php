<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Capture and refund — the half of payments that was recorded as missing.
 *
 * Four gateways take money. None of them could take the AUTHORISED money, and
 * none of them could give any of it back. Tabby and Tamara both auto-void an
 * authorisation that is never captured, so the gap was not cosmetic: goods
 * shipped, `paid_at` said paid, and the authorisation quietly lapsed.
 *
 * ORDERS gain the three columns capture needs:
 *
 *   captured_at     doubles as the idempotency guard. PaymentCapturer claims
 *                   it with one conditional UPDATE against NULL before it
 *                   calls the provider and releases it if the call fails, so
 *                   two clicks cannot both become a capture. Exactly the
 *                   pattern `paid_at` and `pixels_fired_at` already use.
 *   captured_total  fils, integer, like every other money column here. This
 *                   and not `total` is the ceiling a refund is checked
 *                   against — capturing less than the order total is a thing
 *                   providers allow, and refunding against the order total
 *                   after a short capture is refunding money nobody took.
 *   capture_ref     the provider's capture id. Tabby and Tamara both refund
 *                   against a capture rather than against a payment, so
 *                   without this a refund has nothing to point at.
 *
 * REFUNDS gain what turns a log line into a ledger:
 *
 *   status          pending | succeeded | failed. Only the first two count
 *                   toward the refunded total; a failed row is evidence money
 *                   did NOT move, and counting it would quietly reduce what
 *                   can still be refunded.
 *   idempotency_key unique, nullable. This index is the whole double-click
 *                   guard — a second submission of the same refund is refused
 *                   by the database rather than by a check that could be raced
 *                   between two PHP processes. Nullable because a FAILED
 *                   attempt releases its key so a genuine retry is possible,
 *                   and because every row that predates this migration has
 *                   none. NULLs do not collide in a unique index on either
 *                   SQLite or MySQL, which is what makes both of those work.
 *   provider,
 *   provider_ref,
 *   failure_code    which gateway, its id for the refund, and its reason for
 *                   refusing. Enough to find the transaction in the provider's
 *                   own dashboard; nothing that would be a breach if leaked.
 *
 * Existing `refunds` rows are backfilled to `succeeded`. They were written by
 * the old admin endpoint, which logged a refund the merchant had already made
 * by hand — they are real, settled money, and defaulting them to `pending`
 * would silently reserve refund headroom that has already been spent.
 *
 * No ->after() anywhere. Column order is cosmetic and a chain of ALTER ...
 * AFTER a column that does not exist is how nine tables on the live database
 * ended up incomplete while SQLite kept the suite green.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'captured_at')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->timestamp('captured_at')->nullable();
            });
        }

        if (! Schema::hasColumn('orders', 'captured_total')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->integer('captured_total')->default(0);      // fils
            });
        }

        if (! Schema::hasColumn('orders', 'capture_ref')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->string('capture_ref')->nullable();
            });
        }

        if (! Schema::hasColumn('refunds', 'status')) {
            Schema::table('refunds', function (Blueprint $t) {
                // Defaults to succeeded so any path that forgets to set it
                // still records real money rather than a reservation that
                // never clears. PaymentRefunder always sets it explicitly.
                $t->string('status')->default('succeeded')->index();
            });
        }

        if (! Schema::hasColumn('refunds', 'provider')) {
            Schema::table('refunds', function (Blueprint $t) {
                $t->string('provider')->nullable();
            });
        }

        if (! Schema::hasColumn('refunds', 'provider_ref')) {
            Schema::table('refunds', function (Blueprint $t) {
                $t->string('provider_ref')->nullable();
            });
        }

        if (! Schema::hasColumn('refunds', 'failure_code')) {
            Schema::table('refunds', function (Blueprint $t) {
                $t->string('failure_code')->nullable();
            });
        }

        if (! Schema::hasColumn('refunds', 'idempotency_key')) {
            Schema::table('refunds', function (Blueprint $t) {
                // 191, not the 255 default: this is a unique index, and 255
                // utf8mb4 characters overflow the 767-byte index limit on the
                // older MySQL this host could still be running. The keys are
                // 69 characters.
                $t->string('idempotency_key', 191)->nullable();
                $t->unique('idempotency_key', 'refunds_idempotency_key_unique');
            });
        }

        // Rows written before this migration are settled money, logged by hand.
        DB::table('refunds')->whereNull('status')->update(['status' => 'succeeded']);
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $t) {
            $t->dropUnique('refunds_idempotency_key_unique');
        });

        Schema::table('refunds', function (Blueprint $t) {
            $t->dropColumn(['status', 'provider', 'provider_ref', 'failure_code', 'idempotency_key']);
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['captured_at', 'captured_total', 'capture_ref']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — make double payment confirmation impossible at the storage layer
 * as well as in PaymentConfirmer.
 *
 * PaymentConfirmer already guards on `orders.paid_at` with a conditional
 * UPDATE, which is what stops a repeated webhook applying twice. This adds the
 * belt to that pair of braces: one `payments` row per (provider, reference),
 * so even a code path that forgot to go through the confirmer cannot leave two
 * rows claiming the same provider transaction.
 *
 * `payment_events` gains the two columns needed to trace an event back to the
 * provider without joining through `payments`, which matters when a webhook
 * arrives for an order we cannot find.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A pre-existing duplicate would fail the index and take the whole
        // update package down. Nothing writes these rows yet, so in practice
        // the table is empty -- but this is a production migration and the
        // check costs nothing.
        $this->dropDuplicatePayments();

        Schema::table('payments', function (Blueprint $t) {
            $t->unique(['provider', 'provider_ref'], 'payments_provider_ref_unique');
        });

        if (! Schema::hasColumn('payment_events', 'provider')) {
            Schema::table('payment_events', function (Blueprint $t) {
                $t->string('provider')->nullable()->index()->after('payment_id');
            });
        }

        if (! Schema::hasColumn('payment_events', 'external_id')) {
            Schema::table('payment_events', function (Blueprint $t) {
                $t->string('external_id')->nullable()->index()->after('provider');
            });
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropUnique('payments_provider_ref_unique');
        });

        Schema::table('payment_events', function (Blueprint $t) {
            $t->dropColumn(['provider', 'external_id']);
        });
    }

    /** Keep the newest row of each (provider, provider_ref) pair. */
    private function dropDuplicatePayments(): void
    {
        $dupes = \Illuminate\Support\Facades\DB::table('payments')
            ->select('provider', 'provider_ref')
            ->whereNotNull('provider')
            ->whereNotNull('provider_ref')
            ->groupBy('provider', 'provider_ref')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $dupe) {
            $keep = \Illuminate\Support\Facades\DB::table('payments')
                ->where('provider', $dupe->provider)
                ->where('provider_ref', $dupe->provider_ref)
                ->orderByDesc('created_at')
                ->value('id');

            \Illuminate\Support\Facades\DB::table('payments')
                ->where('provider', $dupe->provider)
                ->where('provider_ref', $dupe->provider_ref)
                ->where('id', '!=', $keep)
                ->delete();
        }
    }
};

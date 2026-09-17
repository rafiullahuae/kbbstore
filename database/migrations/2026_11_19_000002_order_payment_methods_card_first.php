<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put the card first at checkout, then Tabby, then Tamara, with Cash on
 * delivery last — the owner's own order, asked for by name.
 *
 * WHY A MIGRATION AND NOT A SEEDER EDIT. `PaymentProviderSeeder` only ever
 * writes these rows on FIRST CREATION (firstOrCreate), so editing it changes
 * nothing on a shop that already exists — which is every shop that matters
 * here. The seeder is updated too, so a fresh install agrees with this.
 *
 * WHAT IT WILL NOT DO. It only moves a row whose position is still the one the
 * seeder wrote (cod 0, tabby 1, tamara 2, stripe 3). If any row has been moved
 * to something else, the whole update is skipped and nothing is touched: an
 * owner who has arranged his own order — through whatever control exists by
 * then — must not have it silently rearranged by an update. That check is the
 * reason this is safe to ship to a shop that has already gone live.
 *
 * `position` is read by GatewayRegistry's `orderBy('position')`, which is the
 * one place the checkout list is ordered, so this is the whole change.
 */
return new class extends Migration
{
    /** The order the seeder has always written. */
    private const SEEDED = ['cod' => 0, 'tabby' => 1, 'tamara' => 2, 'stripe' => 3];

    /** What the owner asked for. */
    private const WANTED = ['stripe' => 0, 'tabby' => 1, 'tamara' => 2, 'cod' => 3];

    public function up(): void
    {
        $rows = DB::table('payment_providers')->pluck('position', 'id')->all();

        foreach (self::SEEDED as $id => $position) {
            // A provider that is not installed at all is not a reason to stop —
            // only one that is installed and has been moved.
            if (array_key_exists($id, $rows) && (int) $rows[$id] !== $position) {
                if (app()->runningInConsole()) {
                    echo "Payment order left alone: '{$id}' is already at position {$rows[$id]}, which is not\n";
                    echo "the seeded default, so this shop's own arrangement is kept.\n";
                }

                return;
            }
        }

        foreach (self::WANTED as $id => $position) {
            DB::table('payment_providers')->where('id', $id)->update(['position' => $position]);
        }

        if (app()->runningInConsole()) {
            echo "Checkout now lists Card, Tabby, Tamara, Cash on delivery, in that order.\n";
        }
    }

    /**
     * Reversible, and only from the state this migration created. Same
     * reasoning as up(): if the order has moved on since, leave it.
     */
    public function down(): void
    {
        $rows = DB::table('payment_providers')->pluck('position', 'id')->all();

        foreach (self::WANTED as $id => $position) {
            if (array_key_exists($id, $rows) && (int) $rows[$id] !== $position) {
                return;
            }
        }

        foreach (self::SEEDED as $id => $position) {
            DB::table('payment_providers')->where('id', $id)->update(['position' => $position]);
        }
    }
};

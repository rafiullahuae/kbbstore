<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coupon redemptions: one row per order, and clear the compiled caches.
 *
 * ---------------------------------------------------------------------------
 * THE INDEX
 * ---------------------------------------------------------------------------
 *
 * coupon_redemptions gains UNIQUE (coupon_id, order_id).
 *
 * Recording a redemption is now part of order placement, and placement is a
 * thing that gets retried — by a shopper hitting Place Order twice, by a
 * gateway webhook arriving more than once, and by any future code that decides
 * to record a redemption from a second place as well as the first. A retry that
 * creates a NEW order is a genuinely new redemption and this index does not
 * stand in its way, because the order_id differs. What it makes impossible is
 * the same coupon being recorded twice against the SAME order, which is
 * double-counting: usage_count moves two and the shopper had one discount.
 *
 * The database is the right place for that rule. A check in PHP holds only as
 * long as every writer remembers to perform it, and the writers are now on two
 * different placement paths.
 *
 * NULL order_id is left free to repeat. Both MySQL and SQLite exempt NULLs from
 * a unique index, which is what we want: a redemption not tied to an order has
 * no identity to collide on. Nothing in the application writes one today —
 * both callers pass a real id — but the column is nullable and the import path
 * could yet use it.
 *
 * Existing duplicates are collapsed before the index is added, because an
 * ALTER that fails halfway leaves a half-migrated table on a shared host with
 * no shell to repair it from. On a live database there are none: until this
 * package nothing ever inserted into this table at all.
 *
 * ---------------------------------------------------------------------------
 * THE CACHES
 * ---------------------------------------------------------------------------
 *
 * No route is added by this package, so the route table is unchanged — but
 * OpCache very much is not. CouponService::recordRedemption() changed its
 * signature (it returns a CouponRedemption now, and throws CouponExhausted),
 * gained two private helpers and a releaseRedemptions() sibling, and there is a
 * new class, App\Services\CouponExhausted, that did not exist before.
 * Store\CheckoutController::place() and ManualOrderBuilder both call into it.
 *
 * A worker holding the previous compiled copies is the bad case: it would run
 * the old recordRedemption() body — the one with no limit check and no lock —
 * against the new callers, which is the original bug still live on some
 * requests and fixed on others, depending on which PHP worker answered. That
 * is worse than the bug, because it is intermittent.
 *
 * The compiled service and package manifests go too: a new class in app/
 * Services is not itself discovered, but these are cheap to rebuild and
 * leaving one stale is how a class_exists() check answers a question about a
 * tree that no longer exists.
 *
 * Views are cleared for the Store -> Coupons screen, which ships in this same
 * package as resources/views/admin/partials/coupon-usage-screen.blade.php and
 * is @included from admin/app.blade.php. Compiled Blade is keyed by template
 * path, so a stale compile of app.blade.php renders the console without the
 * new screen and the sidebar entry simply never appears.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addUniqueIndex();
        $this->clearCaches();
    }

    private function addUniqueIndex(): void
    {
        if (! Schema::hasTable('coupon_redemptions')) {
            return;
        }

        // Idempotent: update packages get re-applied by hand on this host more
        // often than anyone would like, and adding an index that is already
        // there is an error on both engines.
        foreach (Schema::getIndexes('coupon_redemptions') as $index) {
            if (($index['name'] ?? '') === 'coupon_redemptions_coupon_id_order_id_unique') {
                return;
            }
        }

        $this->collapseDuplicates();

        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->unique(['coupon_id', 'order_id']);
        });
    }

    /**
     * Keep the earliest row of each (coupon_id, order_id) pair and delete the
     * rest, so the unique index can be created.
     *
     * Written as a select-then-delete rather than a DELETE ... JOIN because the
     * two engines spell that join differently and this runs on SQLite in CI and
     * MySQL in production. The row counts here are tiny — on the live database
     * this table is empty.
     */
    private function collapseDuplicates(): void
    {
        $keep = DB::table('coupon_redemptions')
            ->selectRaw('MIN(id) AS keep_id')
            ->whereNotNull('order_id')
            ->groupBy('coupon_id', 'order_id')
            ->pluck('keep_id')
            ->all();

        DB::table('coupon_redemptions')
            ->whereNotNull('order_id')
            ->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep))
            ->delete();
    }

    private function clearCaches(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Clear the compiled caches for the Place-order overlay. (Lane PLC)
 *
 * ── WHAT IS STALE IN THIS PACKAGE ─────────────────────────────────────────
 *
 *   A ROUTE. routes/checkout-return.php replaces the one-line closure that
 *   answered GET /checkout/pending, which is where Tabby and Tamara send a
 *   shopper whose payment was declined or abandoned. The compiled route cache
 *   holds the OLD closure, so without this the shopper goes on being bounced to
 *   an unexplained checkout and the new controller is never reached — the
 *   silent half of the failure, because nothing errors.
 *
 *   TWO NEW BLADES AND THREE EDITED ONES. partials/checkout/placing-overlay and
 *   partials/checkout/placed-tick are new; store/checkout, store/checkout-
 *   success and partials/checkout/stripe-elements include them. On this host
 *   the compiled views outlive the files they came from, and a cached copy of
 *   the checkout is a copy with no overlay in it.
 *
 *   NO STYLESHEET MOVED. Both partials carry their own scoped <style>, for the
 *   reason partials/checkout/express-wallets states at length: this host serves
 *   BUILT assets and has no Node, so anything added under resources/css ships
 *   inert until somebody rebuilds the bundle off-server. config:clear is run
 *   anyway — it costs nothing and a half-cleared cache is the confusing state.
 *
 * ▲ AND `migrations` IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT ALL.
 *   UpdateRunner::hasMigrations() reads that flag and never looks at the files.
 *   Build the package with `php artisan kbb:package <version> --since=<ref>`.
 *
 * The guard wraps a call that writes no model state, so nothing is left dirty
 * for a later save to re-send — the distinction CLAUDE.md's swallowed-exception
 * landmine turns on.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['view:clear', 'config:clear', 'route:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable) {
                // A cache that cannot be cleared is one the next deploy clears.
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: this migration has no schema and no data.
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the release-the-hold control.              (Lane OD)
 *
 * ── WHY THIS SHIPS WITH A PACKAGE THAT ADDS NO ROUTE ────────────────────────
 *
 * It does not add one, and that is worth saying first. GET and POST
 * /admin-api/orders/{id}/void have been live, mounted inside the `admin-api`
 * group by routes/payments-void.php, and mapped in AdminCapabilities to
 * `orders.money` since the release endpoint shipped. The defect was that
 * NOTHING IN THE CONSOLE CALLED EITHER OF THEM:
 *
 *     grep -rn -F "'/void'" resources/views/admin/ resources/js/  ->  nothing
 *
 * while POST /admin-api/orders/{id}/capture, its sibling on the same card, is
 * called from app.blade.php. A cancelled Tamara order therefore left the
 * buyer's instalment plan live at Tamara for up to 180 days, with no button
 * anywhere in this shop that could give it back.
 *
 * The staleness here is the OTHER kind, the one
 * 2026_12_20_000001_clear_caches_set_appearance.php sets out: A CHANGED BLADE.
 * resources/views/admin/app.blade.php gains one `@include` and
 * resources/views/admin/partials/order-release-hold.blade.php arrives beside
 * it. Both are compiled into storage/framework/views, a compiled copy is keyed
 * by PATH rather than by contents, and the freshness check is a filemtime
 * compare — an unzip's timestamps are not reliably newer than what is already
 * on disk.
 *
 * A stale compiled console here is this lane's own defect arriving a second
 * time: the package lands complete and the admin draws yesterday's order
 * screen, with no release control on it. Nothing 500s and nothing is logged,
 * which is exactly how the original went unnoticed for a whole round.
 *
 * bootstrap/cache/routes-*.php goes with it. Not because a route changed, but
 * because the compiled route table is what decides whether
 * /admin-api/orders/{id}/void answers at all on this host, and this is the
 * first package in which anything a human can press depends on that. If the
 * gateway packages' own clear_caches migrations were applied to a host whose
 * cache did not in fact get rebuilt, the panel 404s while rendering perfectly —
 * which routes/payments-void.php's header says has shipped twice on this
 * project. The panel says so in those words rather than drawing an empty box,
 * which is a good failure and still a dead control.
 *
 * ── WHAT CHANGED ───────────────────────────────────────────────────────────
 *
 *   resources/views/admin/partials/order-release-hold.blade.php
 *                                   the control. It appends one panel to the
 *                                   Items card of Orders → (an order) from
 *                                   outside app.blade.php, asks
 *                                   GET /admin-api/orders/{id}/void before it
 *                                   offers anything, shows the server's own
 *                                   reason where a hold cannot be released, and
 *                                   confirms in place before it releases one.
 *                                   It is not a screen: no sidebar row, no
 *                                   `go()` id, no wrapper — so ONE `@include`
 *                                   is the whole of the change to the console.
 *
 *   app/Services/Payments/PaymentVoider::confirmation()
 *   app/Http/Controllers/Admin/PaymentVoidController::show()
 *                                   the facts a confirmation needs — the order
 *                                   number that id really names, the amount in
 *                                   integer fils, the holder, and one sentence
 *                                   saying what a release costs the buyer — on
 *                                   a new nested `confirm` key. Every top-level
 *                                   field that endpoint has ever answered is
 *                                   unchanged, and PaymentVoider::status(),
 *                                   which is embedded in the order-detail and
 *                                   settlement payloads too, is untouched.
 *
 *   app/Console/Commands/ReleaseHold.php
 *                                   the same job from the Cloudways shell, for
 *                                   the day the console or its route cache is
 *                                   the broken thing. It calls
 *                                   PaymentVoider::void() rather than
 *                                   reimplementing it, and refuses a
 *                                   non-interactive run outright.
 *
 * NO SETTING ROWS ARE WRITTEN and no default is moved. Applying this package
 * leaves the storefront byte-identical, leaves every gateway exactly as
 * configured as it was, and releases nothing: the panel writes only when the
 * owner presses its confirm button.
 *
 * NO SCHEMA CHANGE. `voided_at` and `void_ref` have been on `orders` since the
 * release endpoint shipped.
 */
return new class extends Migration
{
    public function up(): void
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
            echo "Cleared {$cleared} compiled files. A cancelled Tamara or Tabby order can\n"
                ."now have its authorisation released from Orders -> (the order) -> Items.\n"
                ."Until now nothing in this shop could: the endpoint was live and had no\n"
                ."button, so a cancelled order left the buyer's payment plan alive at the\n"
                ."provider for up to 180 days.\n"
                ."\n"
                ."Nothing on the shop has moved and nothing is released until you press the\n"
                ."button. docs/OD-RELEASE-THE-HOLD.md is the walkthrough.\n";
        }
    }

    public function down(): void {}
};

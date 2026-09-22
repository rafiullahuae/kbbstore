<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the signed-out shopper's three addresses.
 *
 * A NEW ROUTE IN AN ALREADY-MOUNTED FILE, which is the case that catches
 * people out. routes/cart-address.php is required from routes/web.php and has
 * been since 2026_11_28_000001 — so nothing here is "new routes" in the sense
 * of a file the integrator still has to wire. It is one more route INSIDE a
 * file the router has already compiled, and the router dispatches against
 * bootstrap/cache/routes-*.php rather than against the source. Without this,
 * the package lands complete and every tap on one of a guest's saved addresses
 * answers 404 — or worse, 405, which reads like a bug in the sheet's script.
 *
 * AND A CHANGED BLADE, which matters as much. resources/views/store/
 * cart-squeeze.blade.php is compiled into storage/framework/views; a stale
 * compiled copy is a shop where the server happily returns three addresses and
 * the sheet goes on drawing the one it drew before. That is this project's
 * signature failure — the fix lands and nothing changes — and it is why the
 * view cache is on the list below rather than only the route table.
 *
 * What changed:
 *
 *   routes/cart-address.php           POST /cart/address/guest/{handle}/choose
 *                                     added. No auth and no database: it
 *                                     resolves a handle against the caller's
 *                                     own session. The existing {id} route is
 *                                     untouched, still whereNumber, still
 *                                     behind auth:customer.
 *
 *   app/Support/CartAddressState.php  the session key holds a LIST of up to
 *                                     three addresses instead of one, each
 *                                     named by a handle; adopt() moves them
 *                                     into the account on sign-in, once and
 *                                     deduplicated. Reads the old
 *                                     single-address shape, so a session open
 *                                     when this lands keeps its address.
 *
 *   app/Http/Controllers/Store/CartAddressController.php
 *                                     store() appends instead of overwriting —
 *                                     the reported bug — and chooseGuest() is
 *                                     the one endpoint that takes a handle.
 *
 *   app/Services/CartPage.php         sheet_guest_note, on the Address popup
 *                                     tab: the line that tells a signed-out
 *                                     shopper with three addresses that the
 *                                     next one replaces the oldest.
 *
 *   resources/views/store/cart-squeeze.blade.php
 *                                     the sheet lists all three, each on its
 *                                     own endpoint, plus the .cpg-note rule.
 *
 *   resources/views/admin/partials/cart-page-screen.blade.php
 *                                     the live preview draws three and the
 *                                     note, because a preview showing two is a
 *                                     preview whose height caps were set
 *                                     against a shorter list than the real one.
 *
 * NO SETTING ROWS ARE WRITTEN, for the reason 2026_11_28_000001 states at
 * length: CartPage::SCHEMA carries the default, an absent row and a row
 * holding the default are the same thing, and seeding would make "never
 * touched" indistinguishable from "set back to the default".
 *
 * NO SCHEMA CHANGE. A guest's addresses are in the session and touch no table;
 * the ones that move into an account on sign-in go into `addresses`, which has
 * every column they need already.
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
            echo "Cleared {$cleared} compiled files; a shopper who is not signed in now keeps\n"
                . "three delivery addresses instead of one, and they move into the account\n"
                . "when that shopper signs in.\n";
        }
    }

    public function down(): void {}
};

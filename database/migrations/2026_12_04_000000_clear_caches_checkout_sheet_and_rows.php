<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clear compiled caches for the address sheet's placeholder, the four-sided
 * row padding, and the checkout header's empty space — and carry one setting
 * forward.
 *
 * ── THE ONE THING THIS MIGRATION WRITES ─────────────────────────────────────
 *
 * `checkoutpage_d_row_pad` and `checkoutpage_m_row_pad` are gone. They were a
 * single "space above and below" applied as `padding: <value> 0`; the owner
 * asked for four sides, so each is now four keys. A shop that had already set
 * one would otherwise silently drop back to 10px, so the saved value is copied
 * into that surface's TOP and BOTTOM keys and the old row is removed.
 *
 * Only where a row actually exists. An absent row and a row holding the default
 * are the same thing everywhere else in this service, and seeding the new keys
 * for a shop that never touched the old one would make "never touched"
 * indistinguishable from "set back to the default".
 *
 * ── AND WHY THE REST OF IT IS A CACHE CLEAR ─────────────────────────────────
 *
 * FIVE CHANGED BLADES, compiled into storage/framework/views and keyed by PATH
 * rather than by contents — the freshness check is a filemtime compare and an
 * unzip's timestamps are not reliably newer than what is on disk. A stale copy
 * here is the address popup still saying "Loading your addresses", the
 * delivery-notes box still drawn, and the opt-in still arriving ticked.
 *
 * A REBUILT STYLESHEET, which is a new file with a new hash named by a
 * rewritten manifest. The bundle needs no invalidating; the compiled Blade that
 * prints its <link> does.
 *
 * What changed:
 *
 *   resources/views/partials/address-sheet.blade.php
 *                                   THE REPORTED BUG. The sheet's placeholder
 *                                   rules lived in store/cart-squeeze.blade
 *                                   .php — the squeezed cart page's own
 *                                   stylesheet — so on the checkout `.cpg-sk`,
 *                                   `.cpg-skcard` and `.cpg-skline` had no
 *                                   rules at all and `.cpg-vh`, which hides a
 *                                   status line from the screen while leaving
 *                                   it for a screen reader, did not exist. The
 *                                   result was exactly what was photographed:
 *                                   "Loading your addresses" as a line of body
 *                                   text with no bars under it. The rules now
 *                                   live in the file whose markup needs them.
 *
 *                                   AND THE FIRST OPEN NO LONGER FETCHES.
 *                                   GET /cart/address answers with exactly
 *                                   CartAddressState::all(), so that is
 *                                   rendered into the page and the sheet opens
 *                                   straight into the real list. The
 *                                   placeholder stays for the case a seed
 *                                   cannot cover, and is now shaped like the
 *                                   row it stands in for.
 *
 *   resources/views/store/cart-squeeze.blade.php
 *                                   those rules removed, and a note saying
 *                                   where they went. No other change; the
 *                                   squeezed cart renders identically, because
 *                                   it includes the sheet that now carries
 *                                   them.
 *
 *   resources/views/store/checkout.blade.php
 *                                   the notices band is drawn only when there
 *                                   is a notice — it rendered on every load and
 *                                   its 16px of top padding was permanently
 *                                   empty space under the header. The
 *                                   delivery-notes box and the order-updates
 *                                   opt-in are behind their new switches.
 *
 *   resources/css/kbb/kbb-checkout.css
 *                                   the row's padding became four sides; the
 *                                   placeholder got a size of its own, in `em`
 *                                   so it follows the field; and
 *                                   `.co-head .logo` opts out of kbb.css's
 *                                   site-wide 44px touch target, which was
 *                                   making the checkout's bar 73px tall on a
 *                                   phone against 58 on a desktop. That is the
 *                                   other half of the empty space, and no
 *                                   padding control could reach it because it
 *                                   was inside the logo.
 *
 *   app/Services/CheckoutPage.php   four padding keys per surface, a
 *                                   placeholder size, three switches, a usable
 *                                   range for the mobile header width, and the
 *                                   SQUEEZE list behind "Squeeze everything".
 *
 * TWO DEFAULTS CHANGE THE PAGE, both asked for in as many words: the
 * delivery-notes box is off, and the order-updates tick arrives unticked.
 * Everything else still reproduces the page exactly.
 *
 * NO SCHEMA CHANGE.
 */
return new class extends Migration
{
    /** old key => the two keys its value now drives. */
    private const CARRIED = [
        'checkoutpage_d_row_pad' => ['checkoutpage_d_row_pt', 'checkoutpage_d_row_pb'],
        'checkoutpage_m_row_pad' => ['checkoutpage_m_row_pt', 'checkoutpage_m_row_pb'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            foreach (self::CARRIED as $old => $new) {
                $row = DB::table('settings')->where('key', $old)->first();

                if ($row === null) {
                    continue;
                }

                foreach ($new as $key) {
                    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $row->value]);
                }

                DB::table('settings')->where('key', $old)->delete();
            }
        }

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
            echo "Cleared {$cleared} compiled files; the address popup opens straight into the\n"
                ."real list, the checkout header lost 15px of empty bar on phones, and the\n"
                ."summary rows take padding on all four sides.\n";
        }
    }

    public function down(): void {}
};

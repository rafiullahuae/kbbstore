<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * BASKET REMINDERS, ON AS ASKED (the owner, 23 October 2026):
 *
 *   "when user enter the email in the email field, and we silently captured
 *    it ... the abandoned cart email not sending ... make sure such customers
 *    must get abandoned cart emails"
 *
 * A typed address only becomes an email behind three gates, and two of them
 * ship EMPTY (App\Services\CartRecovery's header): the Abandoned Cart Recovery
 * module, an English subject and message, and the "When to send" schedule. So
 * this switches the module on and fills each box that is still BLANK -- a word
 * he has typed is his and is never touched:
 *
 *   subject   "Your basket is waiting 🛍️"     (the shipped Arabic one's twin)
 *   message   the shipped Arabic message, in English
 *   schedule  "1" -- one reminder, an hour after the email was typed
 *
 * and queues the baskets he already lost: an address typed in the last 48
 * hours, on a basket still open and not empty, from a visit not scored as a
 * bot, gets the same single reminder a new one would. Older ones are left
 * alone; a reminder days late reads as spam.
 *
 * Only on a shop that has taken orders (a fresh install's demo catalogue has
 * never sold anything, and these are this shop's words). Every value can be
 * changed or cleared from the admin: Store → Modules → Abandoned Cart
 * Recovery, and Store → Ecommerce → Basket reminders.
 */
return new class extends Migration
{
    private const VALUES = [
        'cart_recovery_subject' => 'Your basket is waiting 🛍️',
        'cart_recovery_body' => 'We noticed you left a few things in your basket, so we kept them for you. Complete your order whenever you like — and if you have any question, just reply to this email.',
        'cart_recovery_schedule' => '1',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasTable('module_toggles')
            || ! Schema::hasTable('orders') || ! DB::table('orders')->exists()) {
            return;
        }

        $now = now();

        DB::table('module_toggles')->updateOrInsert(['module' => 'abandoned_cart'], ['enabled' => true, 'updated_at' => $now]);

        foreach (self::VALUES as $key => $value) {
            $row = DB::table('settings')->where('key', $key)->first(['value']);

            if ($row === null) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
            } elseif (trim((string) $row->value) === '') {
                DB::table('settings')->where('key', $key)->update(['value' => $value, 'updated_at' => $now]);
            }
        }

        $queued = $this->queueRecent($now);

        try {
            foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules'] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable) {
            // A cache that cannot forget expires on its own.
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Basket reminders: on; {$queued} basket(s) from the last 48 hours queued.\n";
        }
    }

    /** The module goes back off; the words and the queued rows stay (they are harmless while it is off). */
    public function down(): void
    {
        if (Schema::hasTable('module_toggles')) {
            DB::table('module_toggles')->where('module', 'abandoned_cart')->update(['enabled' => false]);
        }
    }

    private function queueRecent(\Illuminate\Support\Carbon $now): int
    {
        if (! Schema::hasTable('cart_recoveries') || ! Schema::hasColumn('carts', 'ct_email')) {
            return 0;
        }

        try {
            $threshold = (int) app(\App\Services\CartTracking\CartTrackingSettings::class)->get('bot_threshold');
        } catch (\Throwable) {
            $threshold = 50;
        }

        $carts = DB::table('carts as c')
            ->whereNotNull('c.ct_email')
            ->where('c.status', 'active')
            ->where('c.updated_at', '>=', $now->copy()->subHours(48))
            ->whereRaw('COALESCE(c.ct_bot_score, 0) < ?', [$threshold > 0 ? $threshold : 101])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('cart_items as ci')->whereColumn('ci.cart_id', 'c.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('cart_recoveries as r')->whereColumn('r.cart_id', 'c.id'))
            ->limit(500)
            ->get(['c.id', 'c.ct_email', 'c.updated_at']);

        $n = 0;

        foreach ($carts as $cart) {
            $n += DB::table('cart_recoveries')->insertOrIgnore([
                'cart_id' => (int) $cart->id,
                'email' => strtolower(trim((string) $cart->ct_email)),
                'source' => 'checkout_email',
                'stage' => 0,
                // From when they left, so one already past the hour goes with
                // the next visit to the shop rather than an hour from now.
                'consented_at' => $cart->updated_at,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $n;
    }
};

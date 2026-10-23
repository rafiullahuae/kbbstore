<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Store\CheckoutController;
use App\Rules\StorefrontEmail;
use App\Services\Analytics\Tracker;
use App\Services\CartRecovery;
use App\Services\CartService;
use App\Services\CartTracking\BotSignals;
use App\Services\CartTracking\CartTrackingSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The email a shopper types at checkout, recorded before the order.  (Lane CAP)
 *
 * The owner, 15 October 2026, on Cart Tracking's "Guest, no email": "when user
 * enter their email, i want to record that email in our system, via email the
 * customer account should be created as normal, even user don't create account
 * or place the order." And later: "only the left cart email should go auto.
 * nothing else."
 *
 * WHAT HAPPENS. Leaving the checkout's email field with a valid address sends
 * it once (resources/js/kbb/email-capture.js, on blur, never while typing) to
 * POST /api/checkout-contact. Here, for the visitor's OWN active, non-empty cart
 * (found by the basket cookie the request already carries -- never by an id
 * from the body):
 *
 *   1. the address is written on the cart (carts.ct_email), which is what Cart
 *      Tracking reads instead of "Guest, no email";
 *   2. a customers row is found or made for it through CHECKOUT'S OWN lookup,
 *      CheckoutController::customerForGuestOrder(), so the order placed later
 *      finds the same row and no address ever gets a second one. A row made
 *      here is marked customers.origin = 'checkout_email' and has no password,
 *      no invite, no verification and no marketing flag. An existing row --
 *      registered or not -- is only LINKED (carts.ct_email_customer_id) and
 *      never modified;
 *   3. with "Send the left-cart reminder ..." on and the Abandoned Cart module
 *      on, the cart gets one reminder sequence row (CartRecovery::captureTyped),
 *      which the existing sweep sends ONCE, after the owner's first delay, if
 *      the bag is still left. Nothing else is ever sent from here.
 *
 * WHY NOT carts.customer_id. That column decides whose basket a SIGNED-IN
 * account sees (CartService::resolve). A typed address proves nothing about
 * who is typing: linking a stranger's basket to it would put that basket in
 * the real owner's account the next time they sign in. So the link lives in a
 * column of its own that nothing but the console reads.
 *
 * THE TYPO, THEN THE TAPPED FIX (Lane EH's "Did you mean …@yahoo.com?"). The
 * field is left with yahoo.co -- recorded; the shopper taps the fix -- the
 * field's own `change` fires and yahoo.com is sent. The cart moves to the new
 * address, and the yahoo.co row is DELETED if this capture made it and nothing
 * else has touched it (no order, no address, no other cart, no password). The
 * reminder row, if any, moves to the new address too: only the latest address
 * on a cart can ever be emailed.
 *
 * ABUSE. Its own throttle bucket (routes/cart-trail.php); at most MAX_PER_CART
 * different addresses per cart, the rest ignored and logged; nothing for a
 * bot (the user agent, as Cart Tracking reads it, or the cart's own bot score),
 * a prefetch, a signed-in administrator or a signed-in customer. The answer is
 * always the same empty 204: nothing says whether an address had an account.
 *
 * NOTHING HERE MAY THROW into the shopper's checkout.
 */
final class CheckoutEmailCapture
{
    /** customers.origin for a row this made; cart_recoveries.source for its reminder. */
    public const ORIGIN = 'checkout_email';

    /** Different addresses one cart may record. A typo and its fix is two. */
    public const MAX_PER_CART = 3;

    public const STORED = 'stored';

    private static ?bool $ready = null;

    public function __construct(
        private CartService $carts,
        private CartTrackingSettings $settings,
        private CartRecovery $recovery,
    ) {}

    /** The switch: Cart Tracking → Settings → "Record the email typed at checkout". */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('capture_email');
    }

    /** The reminder switch, which is off whenever the capture switch is. */
    public function remindEnabled(): bool
    {
        return $this->enabled() && (bool) $this->settings->get('capture_reminder');
    }

    /**
     * Does the checkout publish the endpoint (and so load the script)? Only
     * with the switch on and nobody signed in -- a customer's address is
     * already on their account. Reads the settings memo: no query.
     */
    public static function onForPage(?Request $request = null): bool
    {
        try {
            $request ??= request();

            return $request->user('customer') === null && app(self::class)->enabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Record one address for the requester's cart. Returns what happened, for
     * the tests and the log; the controller answers 204 whatever it is.
     */
    public function capture(Request $request, mixed $raw): string
    {
        try {
            return $this->run($request, $raw);
        } catch (\Throwable $e) {
            Log::error('Checkout email capture failed; the checkout is unaffected.', ['exception' => $e::class]);

            return 'error';
        }
    }

    private function run(Request $request, mixed $raw): string
    {
        if (! $this->enabled()) {
            return 'off';
        }

        if (InstantNav::isSpeculative($request) || $request->cookies->has('kbb_ah') || $request->user('customer') !== null) {
            return 'skipped';
        }

        $ua = (string) $request->userAgent();

        if ($ua === '' || preg_match(Tracker::BOT_RE, $ua) === 1 || BotSignals::leaveReason($ua) !== null || BotSignals::isGoodCrawler($ua)) {
            return 'bot';
        }

        $email = is_string($raw) ? trim($raw) : '';

        if ($email === '' || strlen($email) > 254 || ! StorefrontEmail::passes($email)) {
            return 'invalid';
        }

        // The form checkout itself files under: customerForGuestOrder()
        // lower-cases the whole address, and so does CartRecovery.
        $email = mb_strtolower($email);

        if (! self::ready()) {
            return 'unavailable';
        }

        $cart = $this->carts->current($request, create: false);

        if ($cart === null || $cart->status !== 'active' || ! $cart->items()->exists()) {
            return 'no_cart';
        }

        if ((int) $cart->ct_bot_score >= (int) $this->settings->get('bot_threshold')) {
            return 'bot';
        }

        $previous = $cart->ct_email !== null ? (string) $cart->ct_email : null;

        if ($previous === $email) {
            return 'same';
        }

        if ((int) $cart->ct_email_n >= self::MAX_PER_CART) {
            Log::warning('Checkout email capture: this cart has already sent '.self::MAX_PER_CART.' different addresses; the rest are ignored.', ['cart_id' => (int) $cart->getKey()]);

            return 'capped';
        }

        $customer = CheckoutController::customerForGuestOrder($email, '', '', null);

        if ($customer->wasRecentlyCreated) {
            DB::table('customers')->where('id', $customer->getKey())->update(['origin' => self::ORIGIN]);
        }

        $previousCustomer = $cart->ct_email_customer_id !== null ? (int) $cart->ct_email_customer_id : null;

        DB::table('carts')->where('id', $cart->getKey())->update([
            'ct_email' => $email,
            'ct_email_customer_id' => (int) $customer->getKey(),
            'ct_email_n' => (int) $cart->ct_email_n + 1,
        ]);

        if ($previousCustomer !== null && $previousCustomer !== (int) $customer->getKey()) {
            $this->dropIfOnlyCaptured($previousCustomer);
        }

        if ($this->remindEnabled()) {
            // The language of the checkout the address was typed on: its page
            // address (this request is /api, which has no /ar), else the
            // cart's last page. See CartRecovery::localeFromPath().
            $this->recovery->captureTyped((int) $cart->getKey(), $email,
                \App\Services\CartRecovery::localeFromPath($request->headers->get('referer'))
                ?? \App\Services\CartRecovery::localeFromPath($cart->ct_seen_path ?? null));
        }

        return self::STORED;
    }

    /**
     * The typo's row, gone -- but only a row THIS capture made and nothing else
     * has touched since: no password, no order, no address, no cart of any kind
     * pointing at it. Anything else keeps it; a failed delete keeps it too.
     */
    private function dropIfOnlyCaptured(int $id): void
    {
        $row = DB::table('customers')->where('id', $id)->first(['id', 'origin', 'password', 'legacy_password']);

        if ($row === null || $row->origin !== self::ORIGIN || $row->password !== null || $row->legacy_password !== null) {
            return;
        }

        if (DB::table('orders')->where('customer_id', $id)->exists()
            || DB::table('addresses')->where('customer_id', $id)->exists()
            || DB::table('carts')->where(fn ($w) => $w->where('customer_id', $id)->orWhere('ct_email_customer_id', $id))->exists()) {
            return;
        }

        try {
            DB::table('customers')->where('id', $id)->where('origin', self::ORIGIN)
                ->whereNull('password')->whereNull('legacy_password')->delete();
        } catch (\Throwable) {
            // Something else refers to it after all: it stays.
        }
    }

    /**
     * The columns exist (a package's files land seconds before its migration
     * runs). Read from the flag the migration writes once its columns are in,
     * through the settings map the request already holds: zero queries. It
     * used to ask Schema::hasColumn twice per request, which cost the admin
     * customer page two queries over its budget (AdminCustomersTest).
     */
    public static function ready(): bool
    {
        return self::$ready ??= (string) app(\App\Services\SettingsService::class)->get('checkout_email_capture_schema', '') === '1';
    }

    /** For the tests, which migrate between cases. */
    public static function forgetReady(): void
    {
        self::$ready = null;
    }
}

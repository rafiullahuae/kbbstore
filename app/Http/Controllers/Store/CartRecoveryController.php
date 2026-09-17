<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\CartRecovery;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Email me a reminder about this basket." (Lane EN)
 *
 * ── THE TICK IS REQUIRED, AND IT IS CHECKED HERE ───────────────────────────
 *
 * This is the one place in the application that turns a shopper's address into
 * a row in `cart_recoveries`, and it refuses without an explicit, affirmative
 * consent flag. CartRecovery::capture() takes that flag as an argument rather
 * than reading the request itself, precisely so that the decision has exactly
 * one home and cannot be made accidentally by a future caller that happens to
 * have an address in hand.
 *
 * WHAT IS NOT DONE HERE, and each of these is a thing a cart-recovery feature
 * normally does:
 *
 *   - A signed-in customer's account address is NOT used. Having an account is
 *     not asking to be chased about a basket.
 *   - An address typed into the checkout's email field is NOT harvested as the
 *     shopper types. A field they were filling in to place an order is not a
 *     subscription form, and a beacon that captured it would be collecting
 *     consent nobody gave by watching somebody work.
 *   - A pre-ticked box is NOT accepted. `boolean()` is true only for a value
 *     the browser actually sent, and the markup this lane hands over ships
 *     unchecked. A tick the shopper did not make is not a tick.
 *
 * ── THE CART IS THIS BROWSER'S ─────────────────────────────────────────────
 *
 * The cart id is never taken from the request. It is resolved from the cart
 * cookie through CartService, so a post cannot attach an address to somebody
 * else's basket — which would mean a stranger's shopping list arriving in an
 * inbox the stranger did not choose, and, worse, a way to find out whether a
 * given cart id exists.
 *
 * ── ONE ANSWER ─────────────────────────────────────────────────────────────
 *
 * Stored, already captured, opted out, module off: the same sentence. See
 * StockAlertController and Store\SubscribeController for the oracle this
 * closes. It is true in every case: if we are going to remind them, we will
 * remind them once, and there is an unsubscribe link in the message.
 */
class CartRecoveryController extends Controller
{
    public function __construct(
        private CartRecovery $recovery,
        private CartService $carts,
    ) {}

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $data = $request->validate([
                // StorefrontEmail, not `email`. See StockAlertController.
                'email' => ['required', 'string', 'max:160', new \App\Rules\StorefrontEmail],
            ]);
        } catch (ValidationException) {
            return $this->fail($request, 'Please check that email address and try again.');
        }

        /*
         * The tick. `boolean()` is true only for a value that was actually
         * sent — an unchecked checkbox sends nothing at all — so an absent
         * field is a refusal rather than a default.
         */
        if (! $request->boolean('consent')) {
            return $this->fail($request, 'Please tick the box to ask for a reminder.');
        }

        /*
         * `create: false`. A shopper with no cart has no basket to be reminded
         * about, and creating one here would mean a request that hands us an
         * address also gets an empty cart minted for it — a row, and a cookie,
         * for somebody who has not shopped.
         */
        $cart = $this->carts->current($request, false);

        if ($cart === null || $cart->isEmpty()) {
            // The same answer as success. "You have no basket" is true and
            // harmless; it is also one more distinguishable response, and the
            // whole point of this method is that there is only one.
            return $this->done($request);
        }

        $this->recovery->capture((int) $cart->id, (string) $data['email'], true, 'cart');

        return $this->done($request);
    }

    private function done(Request $request): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => CartRecovery::CONFIRM_MESSAGE])
            : back()->with('kbb_cart_reminder', CartRecovery::CONFIRM_MESSAGE);
    }

    private function fail(Request $request, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['ok' => false, 'error' => $message], 422)
            : back()->withInput()->with('kbb_cart_reminder_error', $message);
    }
}

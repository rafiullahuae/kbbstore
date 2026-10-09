<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The checkout's "Sign in" window (Lane CO). The owner, 9 October:
 *
 *   "I need a Sign in text on the right side of the Contact block title ...
 *    upon click it will open a nice on screen popup with the quick login form,
 *    without create account option ... forgot password can be there ... upon
 *    login successfully, all the fields like name, email, addresses etc must
 *    be filled auto."
 *
 * ── NOT A SECOND SIGN-IN ────────────────────────────────────────────────────
 *
 * Both endpoints are thin JSON answers over the code the account pages already
 * run. login() calls CustomerAuthController::attempt() -- the same validation,
 * the same per-address+IP throttle and 300-second lockout, the same WordPress
 * hash upgrade, the same session regeneration and the same guest-basket merge
 * as /my-account/login. forgot() calls PasswordResetController::sendLink() --
 * the same broker, the same two throttles, the same deferred mail. Neither
 * holds a rule of its own that could be weaker than the page it shortcuts.
 *
 * Web group (session, CSRF, the shop firewall), never /api/*: these write a
 * session and send mail.
 */
class CheckoutSignInController extends Controller
{
    public function login(Request $request, CustomerAuthController $auth, CheckoutController $checkout, CartService $carts): JsonResponse
    {
        /*
         * The basket this page was rendered for, BEFORE the merge. If signing
         * in pulls an older account basket into it, the totals on screen are
         * no longer the order's -- and the only honest answer to that is the
         * page the server would now render. See $reload below.
         */
        $before = $this->lines($carts->current($request, create: false));

        $customer = $auth->attempt($request);

        $after = $this->lines(Cart::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'active')
            ->latest('id')
            ->first());

        $country = strtoupper(trim((string) $request->input('country', '')));

        return response()->json([
            'ok' => true,
            /*
             * THE NEW TOKEN. regenerate() rotated the session's CSRF token, and
             * the page still holds the old one in window.KBB.csrf and the
             * form's hidden _token -- Place order would answer 419. The page
             * swaps every copy for this one.
             */
            'csrf' => csrf_token(),
            'fields' => $checkout->signedInFields($customer, preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null),
            /*
             * A FULL RELOAD, in two cases only, because nothing else is
             * correct: the basket changed under the page (an account basket
             * was merged in, so the summary and every total are stale), or
             * the address picker row is on (the saved-address list is drawn
             * on the server and has nothing to fill in place).
             */
            'reload' => $before !== $after || app(\App\Services\CartPage::class)->addressPickerOn(),
        ]);
    }

    public function forgot(Request $request, PasswordResetController $reset): JsonResponse
    {
        $reset->sendLink($request);

        // One answer, whether or not the address has an account.
        return response()->json(['ok' => true, 'message' => PasswordResetController::SENT_MESSAGE]);
    }

    /**
     * A basket as a comparable list: product, variant, quantity per line.
     *
     * @return list<string>
     */
    private function lines(?Cart $cart): array
    {
        if ($cart === null) {
            return [];
        }

        $lines = $cart->items()->get(['product_id', 'product_variant_id', 'quantity'])
            ->map(static fn ($i): string => $i->product_id.':'.(int) $i->product_variant_id.':'.(int) $i->quantity)
            ->all();
        sort($lines);

        return $lines;
    }
}

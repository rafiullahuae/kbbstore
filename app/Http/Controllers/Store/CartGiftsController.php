<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\CartService;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/cart/gifts?s=<fingerprint>  (Lane FGL)
 *
 * The owner, 24 October: "the free gifts box must update silently at backend
 * during customer journey, to see what's actually available as gifts and as
 * per criteria ... i don't want to disturb the customer at all. and neither
 * compromise on the page speed or server load".
 *
 * Answers the box the shopper's basket gets RIGHT NOW (stock, publish state,
 * the owner's gift lists and the band its total falls in), compared with the
 * fingerprint the page already shows (FreeGifts::sig(), data-fgs):
 *
 *   204, no body          unchanged -- nearly every call
 *   200 {s, html}         changed: html is the box partial, or an empty
 *                         <template data-fgs="0"> when no gift applies any more
 *   200 {s: null, html:''} gifts switched off (or this shopper is not a tester):
 *                         the page removes its box and stops asking
 *
 * Read only: the basket is found, never created, and nothing is reconciled or
 * written. The same queries the drawer pays for its gift box (the basket, its
 * lines, one for the gift products) and the settings map once (the request
 * memo). cart.js asks only on the shopper's own moves and at most every 30 s,
 * never on a timer; a throttle of its own keeps a loop from costing anything.
 * The partial escapes every name; html is the same markup the drawer prints.
 */
class CartGiftsController extends Controller
{
    public function __invoke(Request $request, CartService $carts, FreeGifts $gifts, SettingsService $settings): Response
    {
        $cart = $carts->current($request, create: false);
        $totals = $cart !== null && $cart->exists
            ? $carts->totals($cart, (string) $settings->get('store_country', 'AE'))
            : ['item_count' => 0, 'subtotal' => 0, 'discount' => 0];

        $box = $gifts->box($totals);
        $sig = $box['kbbFgSig'] ?? null;
        $headers = ['Cache-Control' => 'no-store, private'];

        if ($sig !== null && hash_equals($sig, (string) $request->query('s', ''))) {
            return response()->noContent(204, $headers);
        }

        return response()->json([
            's' => $sig,
            'html' => $sig === null ? '' : trim(view('partials.free-gifts.box', $box)->render()),
        ], 200, $headers);
    }
}

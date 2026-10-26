<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/cart/token — a fresh CSRF token for a page that has outlived its own.
 *
 * ── THE DEFECT THIS EXISTS FOR ──────────────────────────────────────────────
 *
 * The owner: "when user come back to our website after several hours or after
 * a day, then when user try to add to cart, then add to cart panel freeze and
 * continues grey bars loading coming, until user refresh the whole website."
 *
 * The basket is a 30-day cookie (CartService), so it survives the absence. The
 * CSRF token printed into the page (window.KBB.csrf, layouts/store) does NOT:
 * it belongs to the session, and the session dies after SESSION_LIFETIME
 * (120 minutes). The first Add after that is refused with a 419 before it
 * reaches CartController, and cart.js used to leave the panel's loading
 * skeleton on screen for ever. resources/js/kbb/cart.js now asks here for a
 * token on a 419 and sends the refused request once more, and also asks here
 * quietly when a tab comes back into view after a long time away.
 *
 * ── WHY THIS IS SAFE TO ANSWER ──────────────────────────────────────────────
 *
 * It is the same token the page itself carries, for the session of the browser
 * asking, and only that browser's cookies make a session: a page on another
 * origin cannot read this response (no CORS on it) and cannot ride a victim's
 * session cookie into it usefully. The response carries ONLY the token, and
 * `no-store` so no cache, browser or proxy, ever hands an old one back — a
 * cached token is exactly the stale-token bug again.
 *
 * It costs what any web request costs (the session row) and is reached only
 * after a refusal or a long absence: never on an ordinary page view, never on a
 * timer.
 */
final class CartTokenController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['token' => csrf_token()])
            ->header('Cache-Control', 'no-store, private, max-age=0');
    }
}

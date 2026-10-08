<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CartTracking\BotSignals;
use App\Services\Security\Firewall;
use App\Services\Security\IpBlockList;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door: blocked addresses and bad bots.                        (Lane CT)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT A BLOCKED ADDRESS CAN AND CANNOT DO — the decision, and why
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Default scope "Cart, checkout and forms" (`block_scope = commerce`):
 *
 *   REFUSED  every cart and checkout URL (the /cart page, /api/cart/*,
 *            /checkout, /checkout/*, /api/checkout/*, order-pay), whatever the
 *            method — so no add to cart, no order of any kind, cash on
 *            delivery included, no payment start;
 *            AND every POST / PUT / PATCH / DELETE anywhere on the storefront —
 *            reviews, newsletter, quiz, sign-up, sign-in, contact, "remind me".
 *   ALLOWED  reading the shop: home, categories, products, articles.
 *
 * Why not the whole shop by default: the owner's harm is fake COD ORDERS and
 * cart-spamming bots, and both need the cart and the checkout — this closes
 * both completely. Reading a product page harms nobody, and a /24 block lands
 * on a mobile carrier's shared addresses more often than anyone expects; a
 * real customer caught in that range can still see the shop and the contact
 * details on the page they are refused on, instead of a dead site. The owner
 * can switch the scope to "The whole storefront" in Settings when he wants a
 * scraper gone entirely.
 *
 * NEVER REFUSED, whatever the scope:
 *   - the admin area — any route behind `auth:admin`, and the admin sign-in
 *     and sign-out (named admin.*), so the owner can always get in to unblock;
 *   - payment-gateway webhooks and the import loopback, which are servers,
 *     not visitors.
 *
 * A refused request gets 403 with `Cache-Control: no-store, private`, so no
 * proxy or Varnish in front of the shop can ever serve that refusal to
 * somebody else. JSON callers (the cart drawer) get {ok:false, error:"…"},
 * which cart.js already shows as a message; browsers get a small bilingual
 * page (errors/kbb-blocked) that says what happened and gives a reference.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * "ASK BOTS TO LEAVE" (on by default, the owner's request)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * On cart and checkout URLs only, a request whose user agent no real browser
 * sends — empty, curl / python / Go, a headless browser, a self-named scraper —
 * is answered 403. Search engines and link previews are on an allowlist and are
 * never refused anywhere (BotSignals::isGoodCrawler()). Pages a shopper reads
 * are never touched by this rule.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * COST
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * No query and no cache read: IpBlockList::compiled() include()s one small
 * PHP file (opcache), and a lookup is two isset()s. The route checks below
 * run only when there is something to refuse.
 *
 * THE CLIENT ADDRESS IS $request->ip(), the same answer orders.ip_address,
 * the throttles and the Security trail already use. This application
 * configures no trusted proxies, so that is REMOTE_ADDR — which on Cloudways
 * Apache is the visitor's address, restored from the Nginx front by the
 * stack's own remote-IP module. If that ever broke, every visitor would share
 * the proxy's address; IpRange::UNBLOCKABLE is why such an address can never
 * be blocked, so the failure would be "blocks stop matching", never "shop
 * closed".
 *
 * Registered at the FRONT of the `web` and `api` groups by
 * AppServiceProvider, so a refusal starts no session and runs nothing else.
 */
final class BlockGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $gate = IpBlockList::compiled();

        if ($gate['n'] > 0) {
            $hit = IpBlockList::match($request->ip());

            if ($hit !== null && $this->refusesBlocked($request, (string) ($gate['settings']['block_scope'] ?? 'commerce'))) {
                IpBlockList::recordHit($hit[0]);

                return self::refuse($request, 'blocked', $hit[0], ($gate['settings']['block_scope'] ?? '') === 'site');
            }
        }

        if (! empty($gate['settings']['bots_leave'])) {
            $route = $request->route();

            if ($route instanceof Route && self::isCommerce($route)
                && BotSignals::leaveReason($request->userAgent()) !== null
                && ! self::isAdminArea($route)) {
                return self::refuse($request, 'bot', null, false);
            }
        }

        // Store → Security → Firewall (Lane FW). Off: one array read and
        // nothing else. See App\Services\Security\Firewall for the order.
        $fw = $gate['fw'] ?? null;

        if (! is_array($fw) || ($fw['mode'] ?? 'off') === 'off') {
            return $next($request);
        }

        $refusal = Firewall::before($request, $fw);

        if ($refusal !== null) {
            return self::refuseFirewall($request, $refusal, $fw);
        }

        $response = $next($request);
        Firewall::after($request, $response, $fw);

        return $response;
    }

    /**
     * A firewall refusal. 429 + Retry-After for a ban, 403 otherwise; the same
     * no-store page or JSON as every other refusal here, with the reason as the
     * reference ("F" + a letter) so the owner can match a customer's screenshot
     * to the live view. A Protect refusal carries a fresh page-load proof, so a
     * real shopper whose network changed mid-visit succeeds on the next tap.
     *
     * @param  array{0:string, 1:int, 2:int}  $refusal
     */
    private static function refuseFirewall(Request $request, array $refusal, array $fw): Response
    {
        [$reason, $status, $retry] = $refusal;
        $response = self::refuse($request, $reason === 'country' ? 'blocked' : 'bot', null,
            $reason === 'fake_bot' || ($fw['scope'] ?? '') === 'site', $status, 'F'.strtoupper($reason[0]));

        if ($retry > 0) {
            $response->headers->set('Retry-After', (string) $retry);
        }

        if ($reason === 'no_proof') {
            Firewall::attachProofTo($request, $response);
        }

        return $response;
    }

    /** Does the block scope cover this request? */
    private function refusesBlocked(Request $request, string $scope): bool
    {
        $route = $request->route();

        if (! $route instanceof Route || self::isAdminArea($route) || self::isServerToServer($route)) {
            return false;
        }

        if ($scope === 'site') {
            return true;
        }

        return self::isCommerce($route)
            || ! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * Cart and checkout, by the ROUTE's uri: "cart", "api/cart/add",
     * "checkout/place", "api/checkout/session", "checkout/order-pay" … with or
     * without the KBB_BASE_PATH prefix in front.
     */
    public static function isCommerce(Route $route): bool
    {
        return preg_match('#(^|/)(api/)?(cart|checkout)(/|$)#', $route->uri()) === 1;
    }

    /** The back office, which is never refused. Same test EnforceAdminCapability uses. */
    public static function isAdminArea(Route $route): bool
    {
        $name = (string) $route->getName();

        if ($name === 'admin' || str_starts_with($name, 'admin.')) {
            return true;
        }

        try {
            $middleware = $route->gatherMiddleware();
        } catch (\Throwable) {
            $middleware = $route->middleware();
        }

        foreach ($middleware as $entry) {
            if (is_string($entry) && ($entry === 'auth:admin' || str_starts_with($entry, 'auth:admin,'))) {
                return true;
            }
        }

        return false;
    }

    /** Gateways and the import loopback: servers, never visitors. */
    private static function isServerToServer(Route $route): bool
    {
        $uri = $route->uri();

        return str_contains($uri, 'webhook') || str_contains($uri, 'import-chain');
    }

    private static function refuse(Request $request, string $why, ?int $blockId, bool $wholeSite, int $status = 403, ?string $prefix = null): Response
    {
        $reference = $blockId !== null ? 'B'.$blockId : ($prefix ?? 'R').substr(sha1((string) $request->ip()), 0, 6);
        $message = $why === 'blocked'
            ? __('store.blocked.json')
            : __('store.blocked.bot_json');

        if ($request->expectsJson() || $request->isJson() || str_contains($request->path(), 'api/')) {
            $response = response()->json(['ok' => false, 'error' => $message, 'blocked' => true, 'reference' => $reference], $status);
        } else {
            $response = response()->view('errors.kbb-blocked', ['reference' => $reference, 'why' => $why, 'home' => ! $wholeSite], $status);
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}

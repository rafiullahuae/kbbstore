<?php

declare(strict_types=1);

namespace App\Services\DomainMove;

use App\Services\CartService;
use App\Support\SiteHost;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carry a shopper's guest basket (and wishlist, and recently viewed) across
 * the domain forward. (Lane DS)
 *
 * The owner, 8 October 2026: "make sure, no any data or settings should be
 * gone after domain switch." Everything on the server survives the move by
 * construction -- same database, same APP_KEY. What does not is what lives in
 * the shopper's BROWSER: a cookie belongs to the domain that set it, so the
 * day extrabeauty.ae starts forwarding, a shopper with three things in their
 * basket lands on kbeautybliss.com with an empty one.
 *
 * ── THE DESIGN, AND WHY THE 301 STAYS CLEAN ──────────────────────────────
 *
 * CanonicalHost forwards every GET on an old address with a 301. That 301 is
 * what moves the old domain's ranking across, and it is not touched here:
 *
 *   EVERY request without a restorable basket cookie gets exactly the 301 it
 *   got before -- same status, same Location, same headers. Search engines
 *   crawl without cookies, so this is every request Googlebot or Bingbot ever
 *   makes; the User-Agent check below is a second fence, not the first.
 *
 *   A SHOPPER'S page navigation that carries a guest basket, a wishlist or a
 *   recently-viewed list gets a 302 instead, Cache-Control: no-store, to the
 *   same address with `?kbb_handoff=<token>` appended. 302 and not 301
 *   because a 301 is cacheable by default: a browser (or Varnish) keeping it
 *   would replay a one-time token for ever. No crawler ever sees this hop, so
 *   the permanent-move signal the old domain sends is exactly as strong.
 *
 *   ON THE NEW DOMAIN, a request carrying the token is never rendered. It is
 *   answered with a 302 to the same address WITHOUT the token, no-store and
 *   Referrer-Policy: no-referrer, and -- only if the token is genuine, fresh,
 *   for this host and not used before -- with the cookies restored. A forged,
 *   expired, replayed or wrong-host token gets the same clean 302 and nothing
 *   else. The token therefore never appears in a rendered page's address,
 *   its canonical tag, an analytics hit or a Referer.
 *
 * ── THE TOKEN ────────────────────────────────────────────────────────────
 *
 *   base64url(JSON payload) "." base64url(HMAC-SHA256(payload))
 *
 * keyed by a key derived from APP_KEY (the same on both domains: one server).
 * The payload names the target host, an expiry TTL seconds ahead, a 128-bit
 * nonce, and the basket by its numeric id -- NEVER its token, which is the
 * bearer secret of the basket and must not travel in a URL. Wishlist and
 * recently viewed are product ids, public anyway. The nonce is spent with an
 * atomic Cache::add, so a token works once.
 *
 * ── WHAT IS NOT CARRIED ──────────────────────────────────────────────────
 *
 * Login. A signed-in session crossing domains in a URL is a session that can
 * be stolen from a URL; the shopper signs in again, and a signed-in shopper's
 * basket is tied to their account (CartService finds it by customer id), so
 * it is back the moment they do. A basket that belongs to a customer account
 * is for the same reason never handed to an anonymous browser here.
 *
 * ── COST ─────────────────────────────────────────────────────────────────
 *
 * On a request to the main address with no `kbb_handoff` parameter: one array
 * lookup, nothing else. No query, no settings read, no cookie read. The issue
 * side runs only on a request that is already being forwarded off an old
 * address, and reads the database only when that request carries a basket
 * cookie.
 */
final class DomainHandoff
{
    public const PARAM = 'kbb_handoff';

    /** Seconds a token is valid. A browser follows a redirect in milliseconds. */
    public const TTL = 120;

    /** The cookies carried, and how many ids each may hold (their own caps). */
    public const WISHLIST = 'kbb_wishlist';

    public const VIEWED = 'kbb_viewed';

    private const WISHLIST_MAX = 100;

    private const VIEWED_MAX = 12;

    private const USED_PREFIX = 'kbb.domain_handoff.used.';

    /**
     * Crawlers, link previews and scripted clients. They carry no cookies in
     * practice; this is the belt to that braces, and costs one regex.
     */
    private const BOT = '/bot|crawl|spider|slurp|mediapartners|bingpreview|facebookexternalhit|facebot|embedly|'
        .'whatsapp|telegram|discord|slack|skype|linkedin|pinterest|vkshare|lighthouse|pagespeed|chrome-lighthouse|'
        .'headless|phantom|python|curl|wget|httpclient|okhttp|go-http|java\/|libwww|scrapy|axios|node-fetch|guzzle/i';

    /* ══════════════════════════════════════════════════════════ issue ══ */

    /**
     * The 302 that carries a token, for a shopper being forwarded to $target;
     * null when this request should get the plain 301 (the usual answer).
     */
    public static function forward(Request $request, string $target): ?Response
    {
        if (! self::isShopperNavigation($request)) {
            return null;
        }

        $host = SiteHost::normalise((string) parse_url($target, PHP_URL_HOST));

        if ($host === '') {
            return null;
        }

        $cart = self::guestCartId(self::cookie($request, CartService::COOKIE));
        $wish = self::ids(self::cookie($request, self::WISHLIST), self::WISHLIST_MAX);
        $seen = self::ids(self::cookie($request, self::VIEWED), self::VIEWED_MAX);

        if ($cart === null && $wish === [] && $seen === []) {
            return null;
        }

        $token = self::issue($host, $cart, $wish, $seen);
        $location = $target.(str_contains($target, '?') ? '&' : '?').self::PARAM.'='.$token;

        return self::private(new RedirectResponse($location, 302));
    }

    /** A real person opening a page: GET, a document, not a bot, not a prefetch, not a file. */
    public static function isShopperNavigation(Request $request): bool
    {
        if ($request->getMethod() !== 'GET') {
            return false;
        }

        $purpose = strtolower((string) ($request->headers->get('Sec-Purpose') ?? $request->headers->get('Purpose') ?? ''));

        if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender')) {
            return false;
        }

        $mode = strtolower((string) $request->headers->get('Sec-Fetch-Mode', ''));
        $dest = strtolower((string) $request->headers->get('Sec-Fetch-Dest', ''));

        if (($mode !== '' && $mode !== 'navigate') || ($dest !== '' && $dest !== 'document')) {
            return false;
        }

        if (! str_contains(strtolower((string) $request->headers->get('Accept', '')), 'text/html')) {
            return false;
        }

        $agent = (string) $request->headers->get('User-Agent', '');

        if ($agent === '' || preg_match(self::BOT, $agent) === 1) {
            return false;
        }

        $path = $request->getPathInfo();

        // An address with a file extension is a file (a photo, the sitemap, a
        // script), and /api is never a page.
        return ! str_starts_with($path, '/api/')
            && preg_match('~\.[a-z0-9]{1,8}$~i', $path) !== 1;
    }

    /**
     * @param  list<int>  $wish
     * @param  list<int>  $seen
     */
    public static function issue(string $host, ?int $cart, array $wish, array $seen, ?int $now = null): string
    {
        $payload = self::b64(json_encode([
            'v' => 1,
            'h' => SiteHost::normalise($host),
            'x' => ($now ?? time()) + self::TTL,
            'n' => self::b64(random_bytes(16)),
            'c' => $cart,
            'w' => array_values($wish),
            'r' => array_values($seen),
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.self::b64(self::mac($payload));
    }

    /* ════════════════════════════════════════════════════════ consume ══ */

    /** Does this request carry a token? The only thing a normal page pays. */
    public static function present(Request $request): bool
    {
        return $request->query->has(self::PARAM);
    }

    /**
     * Answer a request that carries a token: a clean 302 to the same address
     * without it, with the cookies restored only when the token is good.
     */
    public static function consume(Request $request): Response
    {
        $response = self::private(new RedirectResponse(self::cleanUrl($request), 302));

        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $response;
        }

        $payload = self::verify((string) $request->query->get(self::PARAM, ''), $request->getHost());

        if ($payload === null) {
            return $response;
        }

        foreach (self::cookiesFor($request, $payload) as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    /**
     * The payload of a genuine, fresh, unused token for this host -- and the
     * token is spent by this call. Null for anything else.
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $token, string $requestHost, ?int $now = null): ?array
    {
        if ($token === '' || strlen($token) > 4096 || substr_count($token, '.') !== 1) {
            return null;
        }

        [$payload, $mac] = explode('.', $token, 2);

        if (! hash_equals(self::b64(self::mac($payload)), $mac)) {
            return null;
        }

        $data = json_decode((string) self::unb64($payload), true);
        $now ??= time();

        if (! is_array($data) || ($data['v'] ?? null) !== 1 || ! is_string($data['n'] ?? null) || ! is_int($data['x'] ?? null)) {
            return null;
        }

        // Expired, or dated further ahead than this server ever issues.
        if ($data['x'] < $now || $data['x'] > $now + self::TTL + 5) {
            return null;
        }

        if (! hash_equals((string) ($data['h'] ?? ''), SiteHost::normalise($requestHost))) {
            return null;
        }

        try {
            // Atomic on every store this shop uses (file locks, a unique key).
            if (! Cache::add(self::USED_PREFIX.hash('sha256', $data['n']), 1, self::TTL + 60)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Cookie>
     */
    private static function cookiesFor(Request $request, array $payload): array
    {
        $out = [];
        $cart = is_int($payload['c'] ?? null) ? $payload['c'] : null;

        // A basket already started on this domain is never replaced.
        if ($cart !== null && self::cookie($request, CartService::COOKIE) === null) {
            $token = DB::table('carts')->where('id', $cart)->where('status', 'active')->whereNull('customer_id')->value('token');

            if (is_string($token) && $token !== '') {
                $out[] = self::make(CartService::COOKIE, $token, 30 * 24 * 60);
            }
        }

        foreach ([[self::WISHLIST, 'w', self::WISHLIST_MAX, 60 * 24 * 365], [self::VIEWED, 'r', self::VIEWED_MAX, 60 * 24 * 30]] as [$name, $key, $max, $minutes]) {
            $carried = self::ids(implode(',', array_map('intval', (array) ($payload[$key] ?? []))), $max);

            if ($carried === []) {
                continue;
            }

            $merged = array_slice(array_values(array_unique([...self::ids(self::cookie($request, $name), $max), ...$carried])), 0, $max);
            $out[] = self::make($name, implode(',', $merged), $minutes);
        }

        return $out;
    }

    /* ═══════════════════════════════════════════════════════ helpers ══ */

    /** The same address without the token, on this host. */
    public static function cleanUrl(Request $request): string
    {
        $query = $request->query->all();
        unset($query[self::PARAM]);
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo().($qs === '' ? '' : '?'.$qs);
    }

    private static function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /** An active guest basket with at least one line, or null. One query, only when a cookie is present. */
    private static function guestCartId(?string $token): ?int
    {
        if ($token === null || ! preg_match('/^[0-9a-f-]{36}$/i', $token)) {
            return null;
        }

        $id = DB::table('carts')->where('token', $token)->where('status', 'active')->whereNull('customer_id')
            ->whereExists(fn ($q) => $q->from('cart_items')->whereColumn('cart_items.cart_id', 'carts.id'))
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return list<int> */
    private static function ids(?string $raw, int $max): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $raw)), fn (int $i) => $i > 0)));

        return array_slice($ids, 0, $max);
    }

    /**
     * A cookie's value as the application sees it. This runs in the GLOBAL
     * middleware stack, before the web group's EncryptCookies, so the value is
     * decrypted here the way that middleware does it.
     */
    private static function cookie(Request $request, string $name): ?string
    {
        $raw = $request->cookies->get($name);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $encrypter = app(Encrypter::class);
            $value = CookieValuePrefix::validate($name, $encrypter->decrypt($raw, false), $encrypter->getAllKeys());
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A cookie encrypted exactly as EncryptCookies would, with the attributes the shop sets. */
    private static function make(string $name, string $value, int $minutes): Cookie
    {
        $encrypter = app(Encrypter::class);
        $sealed = $encrypter->encrypt(CookieValuePrefix::create($name, $encrypter->getKey()).$value, false);

        return cookie($name, $sealed, $minutes, null, null, null, true, false, 'lax');
    }

    private static function mac(string $payload): string
    {
        $key = hash_hmac('sha256', 'kbb.domain-handoff.v1', (string) config('app.key'), true);

        return hash_hmac('sha256', $payload, $key, true);
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unb64(string $text): string|false
    {
        return base64_decode(strtr($text, '-_', '+/'), true);
    }
}

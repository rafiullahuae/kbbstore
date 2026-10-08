<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Http\Middleware\BlockGate;
use App\Support\InstantNav;
use App\Support\IpRange;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Store → Security → Firewall: the request path.                   (Lane FW)
 *
 * The owner: "bots from china, russia, singapore, hongkong ... each time they
 * change the ips and come and attach multiple times in a single second ... i
 * need super super secure, and fully reliable and don't harm to real visitors
 * at all ... site must not slow in any case ... always allow the google and
 * other friendly bots." And, on how: "totally invisible, the visitors must not
 * feel this at all. and it should not give any delay to the user."
 *
 * Called from BlockGate — the existing door, at the front of the `web` and
 * `api` groups — so it is one system with the block list, not a second one:
 * before() may refuse, after() counts and hands out the page-load proof.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * IN ORDER, FOR ONE REQUEST
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *   0. Mode off → nothing (BlockGate does not call in).
 *   1. Never looked at: the admin, webhooks and the import loopback, payment
 *      returns, the owner app, /.well-known, robots, sitemaps, feeds, llms.txt,
 *      the IndexNow key, the site-app files, /storage, health checks, CSP
 *      reports (exempt()). Static assets never reach PHP at all.
 *   2. Never looked at: the always-allow list, and loopback / private / the
 *      proxy / Cloudflare (the compiled `skip` map) — if real-client-IP
 *      restoration ever broke, every shopper would arrive as the proxy, and
 *      the firewall must count nobody rather than ban the shop.
 *   3. A verified good bot (GoodBots) → through, never counted. A FAKE one
 *      (its operator's DNS says no) → refused everywhere: no person's browser
 *      calls itself Googlebot.
 *   4. Country (CountryDb, ~12 µs): Block → refused within the scope; Watch →
 *      logged; Protect → stricter limits and the page-load proof below.
 *   5. An active ban on the address or its /24 (/64) → 429 within the scope.
 *   6. Protect + a write (cart, checkout, any form) without a valid page-load
 *      proof → 403, and a fresh proof is set on that answer.
 *   …  the page runs …
 *   7. after(): count (three bump()s — address/10 s, address/60 s, range/60 s
 *      — or one prefetch bump()), ban on the request that crosses a limit, and
 *      hand a Protect visitor the proof cookie on an HTML page.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE PAGE-LOAD PROOF (Protect countries), and why it is invisible
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Cookie `kbb_pv` = hex(issue time) "." HMAC-SHA256(app key, time | the
 * visitor's /24 or /64), truncated to 128 bits. It is set by the SERVER, as a
 * Set-Cookie header on an ordinary HTML page a Protect visitor opens — no
 * script, no extra request, no byte added to the page, nothing to wait for.
 * A browser stores it and sends it back with the add-to-cart, the checkout
 * and any form. A script that POSTs without having loaded a page (the
 * "attack many times a second from fresh addresses" pattern) does not have
 * one, and is refused. Valid for 12 hours; re-issued on any page once it is
 * six hours old or the visitor's range has changed; and set on the refusal
 * itself, so a real shopper whose phone changed network between page and
 * click succeeds on the next tap.
 *
 * Lighter than the JavaScript token the brief sketched: it needs no change to
 * any script, so it cannot break on a browser where a script fails, and the
 * shop's JS bundles stay byte-identical. What it does not stop — a bot that
 * loads a page with a cookie jar first — the flood limits do.
 *
 * Not a substitute for CSRF, which still guards every `web` write: the proof
 * adds the range binding and the time limit CSRF does not have, and covers
 * the `api` group (POST /api/checkout/session), which has no CSRF at all.
 *
 * It is a RAW cookie on purpose: BlockGate is outside EncryptCookies, so it
 * reads the cookie before decryption and sets it after encryption. The HMAC is
 * the integrity; encryption would add nothing.
 */
final class Firewall
{
    public const COOKIE = 'kbb_pv';

    public const PROOF_TTL = 43200;

    /**
     * URIs the firewall never looks at, matched on the ROUTE's uri with or
     * without a base-path or locale segment in front.
     */
    private const NEVER = '#(^|/)(\.well-known/|robots\.txt$|sitemap[^/]*\.xml$|llms\.txt$|\{key\}\.txt$|feeds/|manifest\.webmanifest$|sw\.js$|site-app\.js$|site-app/icons/|offline$|storage/|mail/font/|email/art/|up$|_kbb-health$|api/csp-report$|checkout/(success|pending)$|checkout/card/(paid|abandon)$)#';

    /** @var array<string, mixed>|null the current request, between before() and after() */
    private static ?array $state = null;

    private static ?string $proofKey = null;

    /**
     * @param  array<string, mixed>  $fw  the compiled firewall (IpBlockList::compiled()['fw'])
     * @return array{0:string, 1:int, 2:int}|null  [reason, status, retry-after] to refuse
     */
    public static function before(Request $request, array $fw): ?array
    {
        self::$state = null;
        $route = $request->route();

        if (! $route instanceof Route || self::exempt($route)) {
            return null;
        }

        $ip = (string) $request->ip();
        $bin = IpRange::pack($ip);

        if ($bin === null || self::inMap($fw['skip'], $bin)) {
            return null;
        }

        FirewallStore::use((string) $fw['store']);
        $enforce = $fw['mode'] === 'enforce';
        $write = ! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
        $ua = (string) $request->userAgent();
        $claimed = false;

        if ($fw['botre'] !== '' && $ua !== '' && preg_match($fw['botre'], $ua, $m) === 1) {
            $family = GoodBots::familyOf($m);
            $verified = $family !== null ? GoodBots::verify($family, $bin, $ip) : null;

            if ($verified === true) {
                return null;
            }

            if ($verified === false && $fw['fake']) {
                FirewallLog::hit('fake_bot', $bin, '', $enforce);

                return $enforce ? ['fake_bot', 403, 0] : null;
            }

            $claimed = true;
        }

        $cc = $fw['countries'] !== [] ? CountryDb::lookup($bin) : '';
        $rule = $fw['countries'][$cc] ?? 'allow';

        // A link preview's GET (TikTok, Telegram, a pending Googlebot) is
        // never held to a country rule; its writes are, like anyone's.
        if ($claimed && ! $write) {
            $rule = 'allow';
        }

        $key = bin2hex($bin);
        $net = self::netOf($bin);
        $covered = $fw['scope'] === 'site' || $write || BlockGate::isCommerce($route);
        self::$state = ['bin' => $bin, 'key' => $key, 'net' => $net, 'cc' => $cc, 'rule' => $rule, 'enforce' => $enforce];

        if ($rule === 'block' && $covered) {
            FirewallLog::hit('country', $bin, $cc, $enforce);

            if ($enforce) {
                return ['country', 403, 0];
            }
        } elseif ($rule === 'watch') {
            FirewallLog::hit('watch', $bin, $cc, false);
        }

        if ($covered) {
            foreach (FirewallStore::many(['fw:b:'.$key, 'fw:b:'.$net]) as $ban) {
                if (is_array($ban) && (int) ($ban['until'] ?? 0) > FirewallStore::now()) {
                    $refuse = $enforce && empty($ban['m']);
                    FirewallLog::hit('banned', $bin, $cc, $refuse);

                    if ($refuse) {
                        return ['banned', 429, max(1, (int) $ban['until'] - FirewallStore::now())];
                    }

                    break;
                }
            }
        }

        if ($rule === 'protect' && $fw['proof'] && $write
            && self::proofAge($request->cookies->get(self::COOKIE), $net) === null) {
            FirewallLog::hit('no_proof', $bin, $cc, $enforce);

            if ($enforce) {
                return ['no_proof', 403, 0];
            }
        }

        return null;
    }

    /** Count the request that went through, and hand out the proof. */
    public static function after(Request $request, Response $response, array $fw): void
    {
        $s = self::$state;
        self::$state = null;

        if ($s === null) {
            return;
        }

        try {
            if ($request->hasSession() && $request->session()->has(Auth::guard('admin')->getName())) {
                return; // a signed-in admin browsing the shop is never counted
            }
        } catch (\Throwable) {
        }

        $t = FirewallStore::now();
        $scale = $s['rule'] === 'protect' ? $fw['protect_pct'] / 100 : 1.0;
        $limit = static fn (int $n): int => max(1, (int) ceil($n * $scale)) + 1;

        if (InstantNav::isSpeculative($request)) {
            // Prefetch has a counter of its own: hovering can never add to the
            // page-view counts, so it can never get a shopper banned.
            if (FirewallStore::bump('fw:p:'.$s['key'].':'.intdiv($t, 10), 20) === $limit($fw['prefetch_10s'])) {
                self::ban($s, $s['key'], inet_ntop($s['bin']), 'prefetch', $fw);
            }
        } else {
            $a = FirewallStore::bump('fw:a:'.$s['key'].':'.intdiv($t, 10), 20);
            $b = FirewallStore::bump('fw:m:'.$s['key'].':'.intdiv($t, 60), 90);
            $c = FirewallStore::bump('fw:n:'.$s['net'].':'.intdiv($t, 60), 90);

            // `===`, not `>=`: a ban is written once, on the request that
            // crosses the line, not on every request after it.
            if ($a === $limit($fw['ip_10s']) || $b === $limit($fw['ip_60s'])) {
                self::ban($s, $s['key'], inet_ntop($s['bin']), $a === $limit($fw['ip_10s']) ? '10s' : '60s', $fw);
            }

            if ($c === $limit($fw['net_60s'])) {
                self::ban($s, $s['net'], $s['net'], 'range', $fw);
            }
        }

        if ($s['rule'] === 'protect' && $fw['proof'] && $request->isMethod('GET')
            && $response->getStatusCode() === 200
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $age = self::proofAge($request->cookies->get(self::COOKIE), $s['net']);

            if ($age === null || $age > self::PROOF_TTL / 2) {
                self::attachProof($request, $response, $s['net']);
            }
        }
    }

    /** The /24 (/64) a refused request came from, for re-issuing the proof. */
    public static function attachProofTo(Request $request, Response $response): void
    {
        $bin = IpRange::pack($request->ip());

        if ($bin !== null) {
            self::attachProof($request, $response, self::netOf($bin));
        }
    }

    private static function attachProof(Request $request, Response $response, string $net): void
    {
        $t = FirewallStore::now();
        $response->headers->setCookie(Cookie::create(
            self::COOKIE, self::proofValue($net, $t), $t + self::PROOF_TTL, '/', null,
            $request->isSecure(), true, true, Cookie::SAMESITE_LAX
        ));
    }

    /* ═══════════════════════════════════════════════ bans ═══ */

    private static function ban(array $s, string $subject, string $label, string $why, array $fw): void
    {
        $strikes = max(1, FirewallStore::bump('fw:s:'.$subject, 86400));
        $minutes = (int) min($fw['ban_max'], $fw['ban'] * (2 ** min(16, $strikes - 1)));
        $until = FirewallStore::now() + $minutes * 60;

        FirewallStore::put('fw:b:'.$subject, [
            'until' => $until, 'm' => $s['enforce'] ? 0 : 1, 'why' => $why, 'strikes' => $strikes,
            'who' => $label, 'cc' => $s['cc'], 'at' => FirewallStore::now(),
        ], $minutes * 60);

        $slot = FirewallStore::bump('fw:bx', 2 * 86400);
        FirewallStore::put('fw:bx:'.($slot % 1000), $subject, 2 * 86400);
        FirewallLog::hit('flood', $s['bin'], $s['cc'], $s['enforce']);
    }

    /**
     * Bans still running, newest first, from the ring of the last 1,000.
     *
     * @return list<array<string, mixed>>
     */
    public static function bans(): array
    {
        $slots = FirewallStore::many(array_map(static fn (int $i): string => 'fw:bx:'.$i, range(0, 999)));
        $subjects = array_values(array_unique(array_filter($slots, 'is_string')));

        if ($subjects === []) {
            return [];
        }

        $out = [];

        foreach (FirewallStore::many(array_map(static fn (string $s): string => 'fw:b:'.$s, $subjects)) as $k => $ban) {
            if (is_array($ban) && (int) ($ban['until'] ?? 0) > FirewallStore::now()) {
                $out[] = [
                    'subject' => substr((string) $k, 5),
                    'who' => (string) ($ban['who'] ?? ''),
                    'why' => (string) ($ban['why'] ?? ''),
                    'country' => (string) ($ban['cc'] ?? ''),
                    'strikes' => (int) ($ban['strikes'] ?? 1),
                    'until' => date(DATE_ATOM, (int) $ban['until']),
                    'monitor' => ! empty($ban['m']),
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp($b['until'], $a['until']));

        return $out;
    }

    public static function unban(string $subject): bool
    {
        if (preg_match('#^([0-9a-f]{8}|[0-9a-f]{32}|[0-9a-f:.]+/\d{1,3})$#', $subject) !== 1) {
            return false;
        }

        $had = FirewallStore::get('fw:b:'.$subject) !== null;
        FirewallStore::forget('fw:b:'.$subject);
        FirewallStore::forget('fw:s:'.$subject);

        return $had;
    }

    /* ═══════════════════════════════════════════════ the proof ═══ */

    public static function proofValue(string $net, int $t): string
    {
        return dechex($t).'.'.self::mac($t, $net);
    }

    /** Seconds since the proof was issued, or null when it is not valid here. */
    public static function proofAge(mixed $cookie, string $net): ?int
    {
        if (! is_string($cookie) || strlen($cookie) > 64 || preg_match('/^([0-9a-f]{1,10})\.([A-Za-z0-9_-]{22})$/', $cookie, $m) !== 1) {
            return null;
        }

        $t = (int) hexdec($m[1]);
        $age = FirewallStore::now() - $t;

        if ($age < -60 || $age > self::PROOF_TTL || ! hash_equals(self::mac($t, $net), $m[2])) {
            return null;
        }

        return max(0, $age);
    }

    private static function mac(int $t, string $net): string
    {
        self::$proofKey ??= hash_hmac('sha256', 'kbb-firewall-proof-v1', (string) config('app.key'), true);

        return substr(rtrim(strtr(base64_encode(hash_hmac('sha256', $t.'|'.$net, self::$proofKey, true)), '+/', '-_'), '='), 0, 22);
    }

    /* ═══════════════════════════════════════════════ helpers ═══ */

    /** Never looked at, whatever the mode: see NEVER and BlockGate. */
    public static function exempt(Route $route): bool
    {
        $uri = $route->uri();

        return preg_match(self::NEVER, $uri) === 1
            || str_contains($uri, 'webhook') || str_contains($uri, 'import-chain')
            || str_starts_with((string) $route->getName(), 'owner-app.')
            || BlockGate::isAdminArea($route);
    }

    public static function netOf(string $bin): string
    {
        $prefix = IpRange::RANGE_PREFIX[strlen($bin) === 4 ? 4 : 6];

        return inet_ntop(IpRange::mask($bin, $prefix)).'/'.$prefix;
    }

    /** @param array{4:array, 6:array} $map  prefix => [network hex => 1] */
    public static function inMap(array $map, string $bin): bool
    {
        foreach ($map[strlen($bin) === 4 ? 4 : 6] as $prefix => $networks) {
            if (isset($networks[bin2hex(IpRange::mask($bin, (int) $prefix))])) {
                return true;
            }
        }

        return false;
    }

    public static function reset(): void
    {
        self::$state = null;
        self::$proofKey = null;
    }
}

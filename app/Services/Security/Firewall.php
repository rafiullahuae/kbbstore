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
            $now = FirewallStore::now();

            foreach ([['a', $bin], ['n', self::netBin($bin)]] as [$kind, $addr]) {
                $r = FirewallCounters::read(self::kind($kind, $bin), $addr);

                if ($r !== null && $r['ban'] > $now) {
                    $refuse = $enforce && ($r['flags'] & 1) === 0;
                    FirewallLog::hit('banned', $bin, $cc, $refuse);

                    if ($refuse) {
                        return ['banned', 429, max(1, $r['ban'] - $now)];
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
        $limit = static fn (int $n): int => max(1, (int) ceil($n * $scale));
        $w10 = intdiv($t, 10);
        $w60 = intdiv($t, 60);
        $banned = [];

        if (InstantNav::isSpeculative($request)) {
            // Prefetch has a counter of its own: hovering can never add to the
            // page-view counts, so it can never get a shopper banned.
            FirewallCounters::update(self::kind('a', $s['bin']), $s['bin'], $t, function (array $r) use ($w10, $limit, $fw, $t, $s, &$banned): array {
                [$r['wp'], $r['np']] = $r['wp'] === $w10 ? [$w10, $r['np'] + 1] : [$w10, 1];

                return $r['np'] > $limit($fw['prefetch_10s']) && $r['ban'] <= $t ? self::ban($r, 4, $t, $s, $fw, $banned) : $r;
            });
        } else {
            FirewallCounters::update(self::kind('a', $s['bin']), $s['bin'], $t, function (array $r) use ($w10, $w60, $limit, $fw, $t, $s, &$banned): array {
                [$r['w10'], $r['n10']] = $r['w10'] === $w10 ? [$w10, $r['n10'] + 1] : [$w10, 1];
                $r = self::slide($r, $w60);

                // Once per ban: not again while this one is running.
                if ($r['ban'] > $t) {
                    return $r;
                }

                if ($r['n10'] > $limit($fw['ip_10s'])) {
                    return self::ban($r, 1, $t, $s, $fw, $banned);
                }

                return self::estimate($r, $t) > $limit($fw['ip_60s']) ? self::ban($r, 2, $t, $s, $fw, $banned) : $r;
            });

            FirewallCounters::update(self::kind('n', $s['bin']), self::netBin($s['bin']), $t, function (array $r) use ($w60, $limit, $fw, $t, $s, &$banned): array {
                $r = self::slide($r, $w60);

                return $r['ban'] <= $t && self::estimate($r, $t) > $limit($fw['net_60s']) ? self::ban($r, 3, $t, $s, $fw, $banned) : $r;
            });
        }

        foreach ($banned as $_) {
            FirewallLog::hit('flood', $s['bin'], $s['cc'], $s['enforce']);
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

    /** Count one request in the current minute, carrying the last minute's count over. */
    private static function slide(array $r, int $w60): array
    {
        if ($r['w60'] === $w60) {
            $r['n60']++;
        } else {
            $r['p60'] = $r['w60'] === $w60 - 1 ? $r['n60'] : 0;
            $r['w60'] = $w60;
            $r['n60'] = 1;
        }

        return $r;
    }

    /**
     * Requests in the last 60 seconds, estimated: this minute's count plus the
     * share of last minute's that still falls inside the window. A fixed
     * minute would let a bot send twice the limit across a minute boundary
     * (measured: 500 requests in 50 s straddling one went unbanned); the
     * estimate does not, and costs one extra integer per record.
     */
    private static function estimate(array $r, int $t): int
    {
        return $r['n60'] + intdiv($r['p60'] * (60 - $t % 60), 60);
    }

    /**
     * Ban the record: 10 minutes (the owner's setting), doubled for each
     * strike in the last 24 hours, up to the longest ban. Inside the record's
     * own update, so it is atomic with the count that crossed the line.
     */
    private static function ban(array $r, int $why, int $t, array $s, array $fw, array &$banned): array
    {
        $r['strikes'] = $r['stk'] > $t ? $r['strikes'] + 1 : 1;
        $r['stk'] = $t + 86400;
        $minutes = (int) min($fw['ban_max'], $fw['ban'] * (2 ** min(16, $r['strikes'] - 1)));
        $r['ban'] = $t + $minutes * 60;
        $r['flags'] = ($s['enforce'] ? 0 : 1) | ($why << 1);
        $banned[] = $why;

        return $r;
    }

    /**
     * Bans still running, longest first — the screen's list.
     *
     * @return list<array<string, mixed>>
     */
    public static function bans(): array
    {
        $now = FirewallStore::now();
        $out = [];

        foreach (FirewallCounters::bans($now) as $r) {
            $v4 = $r['kind'] === 'a' || $r['kind'] === 'n';
            $bin = $v4 ? substr($r['addr'], 0, 4) : $r['addr'];
            $range = $r['kind'] === 'n' || $r['kind'] === 'N';
            $who = (string) inet_ntop($bin).($range ? '/'.IpRange::RANGE_PREFIX[$v4 ? 4 : 6] : '');

            $out[] = [
                'subject' => $r['kind'].'-'.bin2hex($bin),
                'who' => $who,
                'why' => FirewallCounters::WHY[($r['flags'] >> 1) & 7] ?? 'flood',
                'country' => $range ? '' : CountryDb::lookup($bin),
                'strikes' => $r['strikes'],
                'until' => date(DATE_ATOM, $r['ban']),
                'monitor' => ($r['flags'] & 1) === 1,
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp($b['until'], $a['until']));

        return $out;
    }

    /** Lift a ban: "a-<hex>" for an address, "n-<hex>" for a range (bans()' subject). */
    public static function unban(string $subject): bool
    {
        if (preg_match('/^([aAnN])-([0-9a-f]{8}|[0-9a-f]{32})$/', $subject, $m) !== 1) {
            return false;
        }

        if (FirewallCounters::read($m[1], (string) hex2bin($m[2])) === null) {
            return false;
        }

        $had = false;
        FirewallCounters::update($m[1], (string) hex2bin($m[2]), FirewallStore::now(), function (array $r) use (&$had): array {
            $had = $r['ban'] > FirewallStore::now();
            $r['ban'] = 0;
            $r['strikes'] = 0;
            $r['stk'] = 0;
            $r['n10'] = 0;
            $r['n60'] = 0;
            $r['np'] = 0;

            return $r;
        });

        return $had;
    }

    /** The unban subject for an address typed by a person ("203.0.113.7" or "203.0.113.0/24"). */
    public static function subjectFor(string $input): ?string
    {
        $r = IpRange::parse($input);

        if ($r === null) {
            return null;
        }

        $bin = (string) hex2bin($r['network']);
        $kind = $r['prefix'] === ($r['family'] === 4 ? 32 : 128) ? 'a' : 'n';

        return self::kind($kind, $bin).'-'.bin2hex($kind === 'n' ? self::netBin($bin) : $bin);
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

    /**
     * The emergency switch `php artisan kbb:firewall off` also drops a marker
     * file. The compiled settings file is cached by OPcache in PHP-FPM, and a
     * console process cannot clear another process's OPcache — so a
     * console-made "off" could otherwise wait for OPcache to revalidate (or,
     * with timestamp validation off, for a PHP-FPM reload). One stat() a
     * request, only while the firewall is on.
     */
    public static function killed(): bool
    {
        return is_file(self::killSwitch());
    }

    public static function killSwitch(): string
    {
        return storage_path('framework/kbb-firewall-off');
    }

    /** The /24 (/64) network of an address, as packed bytes. */
    public static function netBin(string $bin): string
    {
        return IpRange::mask($bin, IpRange::RANGE_PREFIX[strlen($bin) === 4 ? 4 : 6]);
    }

    /** a/n for IPv4, A/N for IPv6: one table, no collisions between families. */
    private static function kind(string $kind, string $bin): string
    {
        return strlen($bin) === 4 ? $kind : strtoupper($kind);
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

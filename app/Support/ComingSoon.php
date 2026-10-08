<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use App\Services\AdminPathService;
use App\Services\DomainMove\DomainSwitch;
use App\Services\OwnerApp\OwnerAppPath;
use Illuminate\Http\Request;

/**
 * Appearance -> Coming Soon page (Lane CS): the decisions, in one place.
 *
 * The owner: "when i connect the domain to my server ip, and it picks my app,
 * then the domain kbeautybliss.com should not show the site, it should show a
 * beautiful coming soon ... meanwhile i follow the steps of the switching
 * domain, and then i will perform test orders etc on the original domain, and
 * then i will turn off the temporary coming soon page."
 *
 * ── WHAT IS HIDDEN ─────────────────────────────────────────────────────────
 *
 * ONE ADDRESS (and its www twin), or every address. The shipped choice is
 * kbeautybliss.com, so extrabeauty.ae keeps taking orders untouched while the
 * new domain is being set up. It works whether or not that address is yet the
 * main address in Platform -> Site address: the decision is a string compare
 * on the request's own host and reads nothing SiteHost decides.
 *
 * ── WHO STILL SEES THE SHOP THERE ──────────────────────────────────────────
 *
 *   - a signed-in admin (the `admin` guard, on that host's own session);
 *   - whoever opened the secret preview link: an HMAC over the host, an expiry
 *     and a secret that "New link" replaces, so rotating kills every cookie
 *     the old link set. That is how test orders are placed from a phone.
 *
 * ── WHAT IS NEVER BLOCKED ──────────────────────────────────────────────────
 *
 * pathAllowed(): the admin and the owner app at their secret addresses, every
 * payment webhook and return, the APIs, health checks, /.well-known/* (Let's
 * Encrypt issues kbeautybliss.com's certificate WHILE this is on), static
 * files, and the links customers' emails carry. robots.txt answers
 * "Disallow: /" on the hidden address; the sitemap is hidden with the shop.
 *
 * ── WHAT IT COSTS ──────────────────────────────────────────────────────────
 *
 * OFF (how it ships): one array lookup in Setting::map(), the map CanonicalHost
 * already reads on every request -- no query, no extra cache read, nothing in
 * the autoloaded settings map (every key here is written autoload=false).
 * ON: one host compare; only on the hidden host, a path check and a cookie or
 * session check.
 */
final class ComingSoon
{
    public const KEY_ON = 'coming_soon_on';

    public const KEY_SCOPE = 'coming_soon_scope';

    public const KEY_HOST = 'coming_soon_host';

    public const KEY_CONTENT = 'coming_soon_content';

    public const KEY_HOURS = 'coming_soon_preview_hours';

    public const KEY_UNTIL = 'coming_soon_preview_until';

    /** "secret" in the name keeps it out of the audit trail (SecurityModule::SECRET_HINTS). */
    public const KEY_SECRET = 'coming_soon_preview_secret';

    public const SCOPE_HOST = 'host';

    public const SCOPE_ALL = 'all';

    /** The address the owner named, and the default choice: extrabeauty.ae keeps working. */
    public const DEFAULT_HOST = DomainSwitch::DEFAULT_NEW;

    public const COOKIE = 'kbb_cs_preview';

    public const QUERY = 'kbb_preview';

    /** Request attribute: how the gate decided, for the web-group half and the notice. */
    public const ATTR = 'kbb.coming_soon';

    public const PENDING = 'pending';

    public const ADMIN = 'admin';

    public const PREVIEW = 'preview';

    public const BLOCKED = 'blocked';

    public const HOURS = [1, 6, 12, 24, 48, 72, 168];

    public const DEFAULT_HOURS = 24;

    public const RETRY_AFTER = 3600;

    /** The emergency switch, printed on the screen and in the checklist. */
    public const EMERGENCY = 'php artisan kbb:coming-soon off';

    public const LIMITS = ['heading' => 80, 'message' => 300, 'small' => 160];

    public const DEFAULT_TEXT = [
        'en' => [
            'heading' => 'Something new is coming',
            'message' => 'Please visit us again in a few hours.',
            'small' => 'We are getting everything ready for you.',
        ],
        'ar' => [
            'heading' => 'شيء جديد قادم',
            'message' => 'يرجى زيارتنا مرة أخرى بعد بضع ساعات.',
            'small' => 'نحن نجهّز كل شيء من أجلك.',
        ],
    ];

    /** The shop's soft pink (kbb.css --pink-soft). */
    public const DEFAULT_BG = '#FFF0F4';

    /**
     * Paths that answer normally on the hidden address, matched as the path
     * itself or the path followed by `/`. Taken from `php artisan route:list`
     * on 8 October 2026; each line says which routes it keeps alive.
     */
    public const ALLOWED_PREFIXES = [
        '/.well-known',            // acme-challenge (Let's Encrypt), Apple Pay domain association, anything else there
        '/up',                     // Laravel health
        '/_kbb-health',            // UpdateRunner's post-update probe: a 503 here rolls every update back
        '/import-chain',           // the background import's loopback
        '/kbb-recover.php',        // the standalone recovery script
        '/admin-api',              // every admin endpoint, incl. Stripe Connect and Instagram callbacks
        '/api',                    // payment webhooks (/api/payments/webhook/*), checkout session, cart, owner/admin calls
        '/checkout/card',          // Stripe's card confirm/abandon posts
        '/checkout/success',       // every gateway's return (RemoteGateway::returnUrl, Stripe return_url)
        '/checkout/pending',       // Tabby/Tamara cancel and failure returns
        '/checkout/restore-basket',// the pending page's "restore my basket"
        '/storage',                // files served by the app
        '/build',
        '/img-cache',
        '/uploads',
        '/wp-content',             // old picture addresses (LegacyImageRedirect)
        '/site-app/icons',
        '/manifest.webmanifest',   // fetched WITHOUT cookies, so "install the phone app" (checklist step 20) needs it open
        '/my-account/reset',       // password-reset links in emails sent while this is on
        '/my-account/verify',      // email verification links
        '/mail',                   // "view in browser" and the email font
        '/email',                  // marketing click, unsubscribe and artwork
        '/mail-preferences',
        '/newsletter',
    ];

    /** A static file by its name. Apache answers most of these before PHP; the rest reach here. */
    private const ASSET = '/\.(?:css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|bmp|woff2?|ttf|otf|eot|mp4|webm|m4v|mov)$/i';

    /* ═══════════════════════════════════════════════════ the gate's reads ══ */

    /** @param  array<string, mixed>  $map  Setting::map() */
    public static function on(array $map): bool
    {
        return (string) ($map[self::KEY_ON] ?? '0') === '1';
    }

    /** @param  array<string, mixed>  $map */
    public static function scope(array $map): string
    {
        return (string) ($map[self::KEY_SCOPE] ?? self::SCOPE_HOST) === self::SCOPE_ALL ? self::SCOPE_ALL : self::SCOPE_HOST;
    }

    /** The chosen address, bare (no www.). @param  array<string, mixed>  $map */
    public static function host(array $map): string
    {
        $raw = array_key_exists(self::KEY_HOST, $map) ? (string) $map[self::KEY_HOST] : self::DEFAULT_HOST;

        return self::bare($raw);
    }

    /** Does this host show the page (when on)? @param  array<string, mixed>  $map */
    public static function hides(string $host, array $map): bool
    {
        if (self::scope($map) === self::SCOPE_ALL) {
            return true;
        }

        $target = self::host($map);

        return $target !== '' && self::bare($host) === $target;
    }

    /** Lower-case, no port, no trailing dot, no `www.`. */
    public static function bare(string $host): string
    {
        $host = SiteHost::normalise($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** The path without a leading language segment, which SetLocaleFromPath has not yet stripped. */
    public static function stripLocale(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return (string) preg_replace('#^/(?:ar|en)(?=/|$)#', '', $path) ?: '/';
    }

    public static function isArabicPath(string $path): bool
    {
        return (bool) preg_match('#^/ar(?:/|$)#', '/'.ltrim($path, '/'));
    }

    /** Is this path one that must answer normally on the hidden address? */
    public static function pathAllowed(string $path): bool
    {
        $path = self::stripLocale($path);

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (self::under($path, $prefix)) {
                return true;
            }
        }

        // The admin and the owner app at their own, secret addresses. Both are
        // memoised for the process (routes/web.php already asked).
        $admin = trim(AdminPathService::current(), '/');

        if ($admin !== '' && self::under($path, '/'.$admin)) {
            return true;
        }

        $owner = OwnerAppPath::current();

        if (is_string($owner) && trim($owner, '/') !== '' && self::under($path, '/'.trim($owner, '/'))) {
            return true;
        }

        return (bool) preg_match(self::ASSET, $path);
    }

    private static function under(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }

    /**
     * Might this request be a signed-in admin? Only a cookie NAME is looked at
     * here -- the real check runs in the web group, after the session starts.
     */
    public static function mayBeAdmin(Request $request): bool
    {
        if ($request->cookies->has((string) config('session.cookie'))) {
            return true;
        }

        foreach (array_keys($request->cookies->all()) as $name) {
            if (str_starts_with((string) $name, 'remember_admin_')) {
                return true;
            }
        }

        return false;
    }

    /* ═══════════════════════════════════════════════════ the preview link ══ */

    /**
     * `<expiry>.<HMAC>`: bound to the bare host, the expiry and the current
     * secret, keyed with APP_KEY. A different host, a later secret ("New link")
     * or a changed expiry gives a different MAC.
     */
    public static function sign(string $host, int $until, string $secret): string
    {
        $mac = hash_hmac('sha256', 'kbb-coming-soon|v1|'.self::bare($host).'|'.$until.'|'.$secret, self::appKey(), true);

        return $until.'.'.rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /** @param  array<string, mixed>  $map */
    public static function tokenValid(mixed $token, string $host, array $map, ?int $now = null): bool
    {
        $now ??= time();

        if (! is_string($token) || strlen($token) > 80 || preg_match('/^(\d{9,11})\.([A-Za-z0-9_-]{43})$/D', $token, $m) !== 1) {
            return false;
        }

        $secret = (string) ($map[self::KEY_SECRET] ?? '');
        $until = (int) $m[1];

        // No secret, no key, expired, or further ahead than any link this
        // screen can issue: refused before any MAC is compared.
        if (strlen($secret) < 32 || self::appKey() === '' || $until <= $now || $until > $now + max(self::HOURS) * 3600 + 60) {
            return false;
        }

        return hash_equals(self::sign($host, $until, $secret), $token);
    }

    private static function appKey(): string
    {
        return (string) config('app.key');
    }

    /* ═════════════════════════════════════════════════════════ the content ══ */

    /**
     * The stored content, every field present and checked again on the way
     * out -- a hand-edited row cannot put anything on the page the save would
     * have refused.
     *
     * @param  array<string, mixed>  $map
     * @return array{en: array<string,string>, ar: array<string,string>, bg: string, bg_image: string, show_whatsapp: bool, show_instagram: bool}
     */
    public static function content(array $map): array
    {
        $raw = json_decode((string) ($map[self::KEY_CONTENT] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];

        [$clean] = self::cleanContent($raw);

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{0: array{en: array<string,string>, ar: array<string,string>, bg: string, bg_image: string, show_whatsapp: bool, show_instagram: bool}, 1: array<string, string>}
     */
    public static function cleanContent(array $in, array $ownHosts = []): array
    {
        $errors = [];
        $out = [];

        foreach (['en', 'ar'] as $lang) {
            $fields = is_array($in[$lang] ?? null) ? $in[$lang] : [];

            foreach (self::LIMITS as $field => $max) {
                $v = $fields[$field] ?? '';
                $v = is_string($v) ? $v : '';
                $v = trim((string) preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $v));
                $v = (string) preg_replace('/\s{2,}/u', ' ', $v);

                if (mb_strlen($v) > $max) {
                    $errors[$lang.'.'.$field] = 'At most '.$max.' characters.';
                    $v = mb_substr($v, 0, $max);
                }

                $out[$lang][$field] = $v;
            }
        }

        $bg = is_string($in['bg'] ?? null) ? strtoupper(trim($in['bg'])) : '';

        if ($bg !== '' && preg_match('/^#[0-9A-F]{6}$/D', $bg) !== 1) {
            $errors['bg'] = 'A colour like #FFF0F4.';
            $bg = '';
        }

        $out['bg'] = $bg;

        $image = is_string($in['bg_image'] ?? null) ? trim($in['bg_image']) : '';
        $path = self::imagePath($image, $ownHosts);

        if ($image !== '' && $path === '') {
            $errors['bg_image'] = 'Choose a picture from the Media Library (a picture on this shop).';
        }

        $out['bg_image'] = $path;
        $out['show_whatsapp'] = filter_var($in['show_whatsapp'] ?? false, FILTER_VALIDATE_BOOL);
        $out['show_instagram'] = filter_var($in['show_instagram'] ?? false, FILTER_VALIDATE_BOOL);

        return [$out, $errors];
    }

    /**
     * A picture on THIS shop, as a root-relative path, or ''. The page makes
     * no request to anyone else, and the path is printed inside url() in CSS,
     * so its characters are a closed set: no quote, bracket, space or
     * backslash can reach the stylesheet.
     *
     * @param  list<string>  $ownHosts
     */
    public static function imagePath(string $url, array $ownHosts = []): string
    {
        if ($url === '' || strlen($url) > 400) {
            return '';
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            $host = SiteHost::normalise((string) parse_url($url, PHP_URL_HOST));

            if ($host === '' || ! in_array($host, array_map([SiteHost::class, 'normalise'], $ownHosts), true)) {
                return '';
            }

            $url = (string) parse_url($url, PHP_URL_PATH);
        }

        return preg_match('#^/(?!/)[A-Za-z0-9._~\-/%]+\.(?:png|jpe?g|webp|avif|gif|svg)$#iD', $url) === 1 && ! str_contains($url, '..')
            ? $url
            : '';
    }

    /**
     * A bare host name the owner typed, or null. No scheme, path, port, user
     * or query; an international name becomes its ASCII (punycode) form; an IP
     * address, `localhost` and anything with one label are refused.
     */
    public static function cleanHost(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $host = strtolower(trim($raw));

        if ($host === '' || strlen($host) > 253 || preg_match('#[\s/\\\\:@?\#\[\]]#', $host) === 1) {
            return null;
        }

        $host = rtrim($host, '.');

        if (preg_match('/[^\x20-\x7E]/', $host) === 1) {
            if (! function_exists('idn_to_ascii')) {
                return null;
            }

            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

            if (! is_string($ascii) || $ascii === '') {
                return null;
            }

            $host = strtolower($ascii);
        }

        $host = self::bare($host);

        return DomainSwitch::isName($host) ? $host : null;
    }

    /* ═══════════════════════════════════════════════════ what it says ══ */

    /**
     * Every address the shop knows, bare, for the "Only this address" list:
     * the main address, the old addresses, kbeautybliss.com (selectable before
     * it becomes the main address), the saved choice and the one in use now.
     *
     * @param  array<string, mixed>  $map
     * @return list<string>
     */
    public static function knownHosts(array $map, string $requestHost = ''): array
    {
        $out = [];

        foreach ([SiteHost::canonical(), ...SiteHost::aliases(), self::DEFAULT_HOST, self::host($map), $requestHost] as $host) {
            $bare = self::bare((string) $host);

            if ($bare !== '' && DomainSwitch::isName($bare)) {
                $out[$bare] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * The status line at the top of the screen and the Domain switch's line.
     *
     * @param  array<string, mixed>  $map
     * @return array{on: bool, scope: string, host: string, line: string, warnings: list<string>}
     */
    public static function status(array $map, string $requestHost = ''): array
    {
        $on = self::on($map);
        $scope = self::scope($map);
        $host = self::host($map);
        $warnings = [];

        $shown = array_values(array_filter(self::knownHosts($map, $requestHost), fn (string $h) => $h !== $host));

        if (! $on) {
            $line = 'OFF — every address shows the shop.';
        } elseif ($scope === self::SCOPE_ALL) {
            $line = 'ON — every address shows the Coming Soon page. Signed-in admins and the preview link see the shop.';
            $warnings[] = 'Every address is hidden, including the one customers use today. Choose "Only this address" to keep the shop open elsewhere.';
        } elseif ($host === '') {
            $line = 'ON, but no address is chosen — nothing is hidden.';
        } else {
            $line = 'ON — '.$host.' (and www.'.$host.') shows the Coming Soon page'
                .($shown === [] ? '.' : '; '.implode(', ', $shown).' '.(count($shown) === 1 ? 'shows' : 'show').' the shop.');
        }

        if ($on && $scope === self::SCOPE_HOST && $host !== '' && SiteHost::redirectEnabled()) {
            $canonical = SiteHost::canonical();

            if (self::bare($canonical) === $host) {
                $warnings[] = 'Platform → Site address → Forward permanently is ON: visitors to the old addresses are sent to '.$host.', which shows the Coming Soon page. Turn forwarding off until test orders pass, or turn this off.';
            } elseif (in_array($host, array_map([self::class, 'bare'], SiteHost::aliases()), true)) {
                $warnings[] = $host.' is an old address with forwarding ON. Visitors see the Coming Soon page there; a signed-in admin or the preview link is forwarded to '.$canonical.'.';
            }
        }

        return ['on' => $on, 'scope' => $scope, 'host' => $host, 'line' => $line, 'warnings' => $warnings];
    }

    /** For Platform -> Domain switch. @return array{on: bool, line: string} */
    public static function summary(): array
    {
        $map = Setting::map();
        $on = self::on($map);

        if (! $on) {
            return ['on' => false, 'line' => 'Coming Soon page: OFF'];
        }

        return ['on' => true, 'line' => self::scope($map) === self::SCOPE_ALL
            ? 'Coming Soon page: ON for every address'
            : 'Coming Soon page: ON for '.self::host($map)];
    }
}

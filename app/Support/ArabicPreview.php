<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The private Arabic preview link (Lane AR, stage 2).
 *
 * The owner, while Arabic is OFF: he wants to look at the whole Arabic shop on
 * the real site before anybody else can. Translation → Settings → "Private
 * Arabic preview link" makes a link that works for a few days; whoever opens it
 * sees /ar in their own browser and nobody else does.
 *
 * ── HOW IT FAILS CLOSED ──────────────────────────────────────────────────
 *
 *   - A token is `<expiry>.<hmac>`, the HMAC keyed on APP_KEY over the expiry
 *     and a per-shop secret (SETTING_SECRET). Without that row there is no
 *     secret and NO token verifies, so a shop that never made a link has no
 *     preview at all. "Turn off every link" replaces the secret, which kills
 *     every link and every cookie already handed out, at once.
 *   - Anything that does not verify -- a wrong, truncated, expired or forged
 *     token, a token longer than MAX_DAYS -- is ignored, not refused: the
 *     visitor gets the ordinary shop, where /ar is the same 404 it always was.
 *   - Compared with hash_equals; never logged; the link's token is moved into
 *     an HttpOnly cookie on the first visit and the address is redirected clean,
 *     so it does not sit in the address bar, the history or a Referer header.
 *
 * ── HOW IT NEVER REACHES A SEARCH ENGINE OR A VISITOR ────────────────────
 *
 *   - Only a browser holding the cookie is in preview; a crawler never is.
 *   - Every response served in preview carries `X-Robots-Tag: noindex,
 *     nofollow` and `Cache-Control: private, no-store`, so neither a search
 *     engine nor a shared cache (the host's Varnish, a CDN) keeps it.
 *   - Machine documents (sitemap, robots.txt, feeds: any path whose last
 *     segment has a dot) and the back office are never in preview, so the
 *     sitemap and its cache cannot learn about /ar from a previewer.
 *   - Server-side fragment caches are keyed by locale already
 *     (CardFragments, UgcRail), so an /ar render cannot land in an English key.
 *
 * Costs a normal visitor nothing: no cookie, no query parameter, no work --
 * not even a settings read.
 */
final class ArabicPreview
{
    /** The query parameter the link carries, once. */
    public const PARAM = 'kbb_arp';

    /** The cookie the first visit swaps it for. */
    public const COOKIE = 'kbb_arp';

    /** The per-shop secret. Absent = no preview possible. Replaced = every link dead. */
    public const SETTING_SECRET = 'language_ar_preview_secret';

    /** The container flag for THIS request (per app, so it cannot outlive a request or a test). */
    private const FLAG = 'kbb.ar_preview';

    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 30;

    /** Is this request being served to a holder of a valid preview link? */
    public static function active(): bool
    {
        return app()->bound(self::FLAG) && app(self::FLAG) === true;
    }

    /**
     * Decide preview for this request. Called first thing by SetLocaleFromPath.
     *
     * @return Response|null a redirect to the clean address when the link's
     *                       token was just accepted, otherwise null
     */
    public static function begin(Request $request, string $path): ?Response
    {
        app()->instance(self::FLAG, false);

        $fromQuery = $request->query(self::PARAM);
        $fromCookie = $request->cookies->get(self::COOKIE);

        if (! is_string($fromQuery) && ! is_string($fromCookie)) {
            return null;
        }

        [, $rest] = Locale::splitPath($path);
        if (! Locale::localisable($rest) || str_starts_with(ltrim($rest, '/'), 'api/')) {
            return null;
        }

        if (is_string($fromQuery)) {
            $expires = self::verify($fromQuery);
            if ($expires === null) {
                return null;
            }

            // Swap the token for a cookie and drop it from the address.
            $query = $request->query();
            unset($query[self::PARAM]);
            $target = $request->getSchemeAndHttpHost() . $request->getBaseUrl() . $path
                . ($query === [] ? '' : '?' . http_build_query($query));

            $response = new \Illuminate\Http\RedirectResponse($target, 302);
            $response->headers->setCookie(self::cookie($fromQuery, $expires, $request));

            return self::decorate($response);
        }

        if (self::verify((string) $fromCookie) !== null) {
            app()->instance(self::FLAG, true);
        }

        return null;
    }

    /** What every preview response is sent with. */
    public static function decorate(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    /**
     * A new link, valid for $days (1..MAX_DAYS). Creates the shop's secret the
     * first time; never changes an existing one (that is revoke()).
     *
     * @return array{url: string, expires_at: string}
     */
    public static function issue(int $days, string $baseUrl): array
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $expires = time() + $days * 86400;

        $settings = app(\App\Services\SettingsService::class);
        if (self::secret() === '') {
            $settings->set(self::SETTING_SECRET, bin2hex(random_bytes(32)));
        }

        $token = $expires . '.' . self::sign($expires, self::secret());

        return [
            'url' => rtrim($baseUrl, '/') . '/ar/?' . self::PARAM . '=' . $token,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires),
        ];
    }

    /** Turn off every link and every cookie already handed out. */
    public static function revoke(): void
    {
        app(\App\Services\SettingsService::class)->set(self::SETTING_SECRET, bin2hex(random_bytes(32)));
    }

    /** The expiry of a valid token, or null. Constant-time on the signature. */
    public static function verify(string $token): ?int
    {
        if (strlen($token) > 120 || ! preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $token, $m)) {
            return null;
        }

        $expires = (int) $m[1];
        $now = time();
        if ($expires <= $now || $expires > $now + self::MAX_DAYS * 86400 + 60) {
            return null;
        }

        $secret = self::secret();
        if ($secret === '') {
            return null;
        }

        return hash_equals(self::sign($expires, $secret), $m[2]) ? $expires : null;
    }

    private static function sign(int $expires, string $secret): string
    {
        return hash_hmac('sha256', 'kbb-ar-preview|' . $expires . '|' . $secret, (string) config('app.key'));
    }

    private static function secret(): string
    {
        try {
            return (string) app(\App\Services\SettingsService::class)->get(self::SETTING_SECRET, '');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function cookie(string $token, int $expires, Request $request): Cookie
    {
        return Cookie::create(self::COOKIE, $token, $expires, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Pixels;

use Illuminate\Http\Request;

/**
 * What a server event may say about the browser it stands for. (Lane MP)
 *
 * IP address and user agent (all three platforms ask for both, unhashed, to
 * match the server event to the browser one), the page address, and the
 * platforms' own first-party cookies:
 *
 *   _fbp / _fbc   Meta's browser id and click id (fbq sets them)
 *   _ttp          TikTok's browser id (ttq sets it)
 *   _ga, _ga_*    GA4's client id and session (gtag sets them)
 *   kbb_eid       the event id the add-to-cart click listener just used for
 *                 its browser event, so the server copy carries the SAME id
 *                 and the platform keeps one of the two
 *
 * ── WHY THE RAW Cookie HEADER ──────────────────────────────────────────────
 *
 * Laravel's EncryptCookies middleware replaces every cookie it did not write
 * itself with null, and all of these are written by JavaScript. Reading the
 * header directly is the only way to see them, and each value is then
 * shape-checked here, because the header is whatever the client sent.
 */
final class BrowserContext
{
    /**
     * @return array{ip: ?string, ua: ?string, url: ?string, fbp: ?string, fbc: ?string, ttp: ?string, ga_client: ?string, ga_session: ?string, eid: ?string}
     */
    public static function from(?Request $request, ?string $url = null): array
    {
        $out = ['ip' => null, 'ua' => null, 'url' => null, 'fbp' => null, 'fbc' => null, 'ttp' => null,
            'ga_client' => null, 'ga_session' => null, 'eid' => null];

        if ($request === null) {
            return $out;
        }

        $out['ip'] = filter_var((string) $request->ip(), FILTER_VALIDATE_IP) !== false ? (string) $request->ip() : null;
        $ua = trim((string) $request->userAgent());
        $out['ua'] = $ua === '' ? null : mb_substr($ua, 0, 512);

        $page = $url ?? $request->fullUrl();
        $out['url'] = self::httpUrl($page);

        $cookies = self::rawCookies((string) $request->headers->get('cookie', ''));

        $out['fbp'] = self::match($cookies['_fbp'] ?? null, '/^fb\.\d\.\d{10,14}\.\d{1,25}$/');
        $out['fbc'] = self::match($cookies['_fbc'] ?? null, '/^fb\.\d\.\d{10,14}\.[A-Za-z0-9_\-]{8,500}$/');
        $out['ttp'] = self::match($cookies['_ttp'] ?? null, '/^[A-Za-z0-9_\-\.]{8,80}$/');
        $out['eid'] = self::match($cookies['kbb_eid'] ?? null, '/^[a-z0-9\-]{8,64}$/');

        // _ga = GA1.1.<random>.<timestamp>; the client id is the last two parts.
        $ga = self::match($cookies['_ga'] ?? null, '/^GA\d\.\d\.\d{1,12}\.\d{9,11}$/');
        if ($ga !== null) {
            $parts = explode('.', $ga);
            $out['ga_client'] = $parts[2] . '.' . $parts[3];
        }

        // _ga_<STREAM>: "GS1.1.<session>.…" (older) or "GS2.1.s<session>$o…" (newer).
        foreach ($cookies as $name => $value) {
            if (! str_starts_with($name, '_ga_')) {
                continue;
            }
            if (preg_match('/^GS1\.\d\.(\d{9,11})\./', $value, $m) === 1 || preg_match('/^GS2\.\d\.s(\d{9,11})\$/', $value, $m) === 1) {
                $out['ga_session'] = $m[1];
                break;
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    public static function rawCookies(string $header): array
    {
        $out = [];

        foreach (explode(';', $header) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $name = trim(substr($pair, 0, $eq));
            if ($name === '' || isset($out[$name])) {
                continue;
            }
            $out[$name] = trim(rawurldecode(substr($pair, $eq + 1)));
        }

        return $out;
    }

    public static function httpUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? mb_substr($url, 0, 1000) : null;
    }

    private static function match(?string $value, string $pattern): ?string
    {
        return $value !== null && preg_match($pattern, $value) === 1 ? $value : null;
    }
}

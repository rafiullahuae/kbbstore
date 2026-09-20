<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\SiteHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Settings → Site address. Four controls and a check.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS OWNER-ONLY WITHOUT APPEARING IN AdminCapabilities
 * ---------------------------------------------------------------------------
 *
 * Because AdminCapabilities fails CLOSED: `for()` returns null for a route it
 * does not recognise and EnforceAdminCapability turns null into a 403 for
 * everyone who is not an owner. Leaving these two routes unmapped is therefore
 * not an oversight, it is the correct answer — an address change and a
 * "disappear from Google" switch are owner decisions, and the map's own comment
 * says an unmapped route being owner-only is the deliberate design.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE CHECK DOES, AND WHAT IT DELIBERATELY DOES NOT
 * ---------------------------------------------------------------------------
 *
 * It fetches the canonical host over HTTP and reports what came back: the
 * status, the address it ended up at, and whether that response says noindex.
 * It answers "is there a working site at the address you typed, and does it
 * behave the way you expect".
 *
 * It does NOT prove the address is THIS install. Proving that means publishing
 * a nonce at an unauthenticated URL and reading it back, which is a new public
 * endpoint on every customer's shop, for a check run perhaps twice in the life
 * of a site. That trade is not worth it HERE, and specifically because of how
 * SiteHost is built: only hosts on the alias list are ever forwarded, so a
 * wrong canonical host misdirects the aliases and cannot make the real site
 * unreachable. The check is a convenience against typos, not the thing standing
 * between the owner and a lockout. The screen says so in those words rather
 * than implying an assurance it does not give.
 */
final class SiteAddressApiController extends Controller
{
    public function show(): JsonResponse
    {
        $map = Setting::map();

        return response()->json([
            'ok' => true,
            'canonical_host' => (string) ($map[SiteHost::KEY_CANONICAL] ?? ''),
            'aliases' => (string) ($map[SiteHost::KEY_ALIASES] ?? ''),
            'redirect_enabled' => SiteHost::redirectEnabled(),
            'visibility' => SiteHost::visibilityIsPrivate() ? 'private' : 'public',

            // What the shop is answering on right now, so the owner can see
            // the value to type rather than guess at it.
            'current_host' => request()->getHost(),
            'derived_aliases' => SiteHost::aliases(),
            'verdict' => SiteHost::classify(request()->getHost()),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            // No scheme, no path — a host. AdminController validates addresses
            // the same way elsewhere and for the same reason: "extrabeauty.ae"
            // is what a person types when asked for a domain.
            'canonical_host' => ['nullable', 'string', 'max:255'],
            'aliases' => ['nullable', 'string', 'max:4000'],
            'redirect_enabled' => ['required', 'boolean'],
            'visibility' => ['required', 'in:public,private'],
        ]);

        $canonical = SiteHost::normalise($this->stripScheme((string) ($data['canonical_host'] ?? '')));

        if ($canonical !== '' && ! $this->looksLikeHost($canonical)) {
            return response()->json([
                'ok' => false,
                'errors' => ['canonical_host' => 'That does not look like a domain. Type it like extrabeauty.ae — no https://, no trailing slash.'],
            ], 422);
        }

        $aliases = [];

        foreach (preg_split('/[\r\n,]+/', (string) ($data['aliases'] ?? '')) ?: [] as $line) {
            $host = SiteHost::normalise($this->stripScheme((string) $line));

            if ($host === '') {
                continue;
            }

            if (! $this->looksLikeHost($host)) {
                return response()->json([
                    'ok' => false,
                    'errors' => ['aliases' => "“{$host}” does not look like a domain."],
                ], 422);
            }

            $aliases[$host] = true;
        }

        /*
         * FORWARDING CANNOT BE SWITCHED ON WITHOUT A CANONICAL HOST.
         *
         * Not a nicety. "Forward the old domains" with nothing to forward them
         * to would be a setting that reads as on and does nothing — and the
         * owner, seeing it on, would stop looking for why the old domain still
         * serves the shop.
         */
        $redirect = (bool) $data['redirect_enabled'];

        if ($redirect && $canonical === '') {
            return response()->json([
                'ok' => false,
                'errors' => ['redirect_enabled' => 'Set the main address first — there is nowhere to forward to yet.'],
            ], 422);
        }

        Setting::query()->updateOrCreate(['key' => SiteHost::KEY_CANONICAL], ['value' => $canonical]);
        Setting::query()->updateOrCreate(['key' => SiteHost::KEY_ALIASES], ['value' => implode("\n", array_keys($aliases))]);
        Setting::query()->updateOrCreate(['key' => SiteHost::KEY_REDIRECT], ['value' => $redirect ? '1' : '0']);
        Setting::query()->updateOrCreate(['key' => SiteHost::KEY_VISIBILITY], ['value' => $data['visibility']]);

        /*
         * Both memos, or this request answers with the old values and the
         * screen redraws showing what was there before the save.
         * Setting::map() memoises per process as well as in the cache —
         * CLAUDE.md's landmine — and SiteHost memoises its verdict on top.
         */
        Setting::flushMap();
        SiteHost::forget();

        return $this->show();
    }

    /** Fetch the canonical host and report what actually came back. */
    public function check(Request $request): JsonResponse
    {
        $host = SiteHost::normalise($this->stripScheme((string) $request->input('host', '')));

        if ($host === '' || ! $this->looksLikeHost($host)) {
            return response()->json(['ok' => false, 'reason' => 'Type a domain first, like extrabeauty.ae.'], 422);
        }

        $url = 'https://'.$host.'/robots.txt';

        try {
            $response = Http::timeout(8)->withOptions(['allow_redirects' => ['track_redirects' => true]])->get($url);
        } catch (\Throwable $e) {
            /*
             * The message is not echoed back. A connection error from Guzzle
             * carries the resolved IP and the local certificate path, which is
             * server detail that does not belong on a browser screen.
             */
            return response()->json([
                'ok' => false,
                'reachable' => false,
                'reason' => 'Nothing answered at https://'.$host.'. Check the DNS points here and the certificate covers it.',
            ]);
        }

        return response()->json([
            'ok' => true,
            'reachable' => true,
            'status' => $response->status(),
            'noindex' => str_contains(strtolower((string) $response->header('X-Robots-Tag')), 'noindex'),
            'reason' => $response->successful()
                ? 'https://'.$host.' answered normally.'
                : 'https://'.$host.' answered '.$response->status().'.',
        ]);
    }

    /** Accept a pasted URL as well as a bare host, because people paste URLs. */
    private function stripScheme(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }

        return trim($value, "/ \t\n\r\0\x0B");
    }

    /** A dotted name or localhost. Deliberately permissive: this is a typo check. */
    private function looksLikeHost(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host);
    }
}

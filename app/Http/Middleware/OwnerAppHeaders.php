<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On every response from the owner app (Lane MAC), whatever it is:
 *
 *   X-Robots-Tag: noindex, nofollow, noarchive   — never in a search index,
 *       never cached by one. Not a robots.txt line: that would publish the
 *       secret address it is meant to hide.
 *   Cache-Control: no-store                       — no proxy and no browser
 *       cache keeps a copy of an order or a customer. The manifest and the
 *       service worker are `no-cache` instead so an update reaches the phone.
 *   Referrer-Policy: no-referrer                  — the secret path never leaves
 *       in a Referer header, not even to the shop's own image host.
 *   a CSP that allows this origin and nothing else to run, plus images from
 *       https (product photos may sit on a CDN).
 *
 * And it refuses a cross-site write outright: a POST whose Origin is another
 * site, or which the browser itself labels cross-site (Sec-Fetch-Site), never
 * reaches a controller. Writes must also carry `X-OA: 1`, a custom header no
 * HTML form can send and no cross-site fetch can send without a preflight
 * this app never answers.
 */
final class OwnerAppHeaders
{
    public const ROBOTS = 'noindex, nofollow, noarchive';

    /** Read by SecurityHeaders, which then leaves this app's stricter two headers alone. */
    public const ATTRIBUTE = 'owner_app.strict_headers';

    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: https:; "
        ."connect-src 'self'; worker-src 'self'; manifest-src 'self'; font-src 'self'; frame-ancestors 'none'; "
        ."base-uri 'none'; form-action 'self'; object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, true);

        if (! in_array($request->method(), ['GET', 'HEAD'], true) && ($refusal = $this->crossSite($request)) !== null) {
            return $this->headers($refusal, false);
        }

        return $this->headers($next($request), (bool) ($request->route()?->defaults['oa_revalidate'] ?? false));
    }

    private function crossSite(Request $request): ?Response
    {
        $origin = (string) $request->headers->get('Origin', '');
        $site = strtolower((string) $request->headers->get('Sec-Fetch-Site', ''));

        $bad = ($origin !== '' && $origin !== $request->getSchemeAndHttpHost())
            || ($site !== '' && $site !== 'same-origin')
            || $request->headers->get('X-OA') !== '1';

        return $bad ? response()->json(['ok' => false, 'code' => 'cross_site', 'message' => 'Refused.'], 403) : null;
    }

    private function headers(Response $response, bool $revalidate): Response
    {
        $h = $response->headers;
        $h->set('X-Robots-Tag', self::ROBOTS);
        $h->set('Cache-Control', $revalidate ? 'no-cache, max-age=0, must-revalidate' : 'no-store, no-cache, max-age=0, must-revalidate, private');
        $h->set('Pragma', 'no-cache');
        $h->set('Referrer-Policy', 'no-referrer');
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Content-Security-Policy', self::CSP);
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');
        $h->set('Cross-Origin-Resource-Policy', 'same-origin');

        return $response;
    }
}

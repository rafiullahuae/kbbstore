<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Support\ComingSoon;
use App\Support\ComingSoonPage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appearance -> Coming Soon page: the gate (Lane CS). See App\Support\ComingSoon.
 *
 * GLOBAL, BEFORE THE ROUTER, and BEFORE CanonicalHost. Before the router so
 * that an address no route matches -- a 404, or a `redirects` row the 404
 * handler would follow -- answers with the Coming Soon page too, instead of
 * printing the shop's own 404 or announcing a new address. Before
 * CanonicalHost so that a hidden address that is ALSO a forwarded old address
 * shows the page instead of a 301: the owner's case is "kbeautybliss.com shows
 * Coming Soon while extrabeauty.ae is still the main address", and the gate
 * never redirects anywhere, so the two cannot loop. (The one redirect it does
 * make -- the preview link dropping its own ?kbb_preview= -- is to the same
 * host and path, and the next request carries the cookie.)
 *
 * ADMIN DETECTION NEEDS THE SESSION, which the global stack does not have yet.
 * So a request on the hidden address that carries a session (or remember-me)
 * cookie is marked PENDING and allowed on; ComingSoonAdminPass, in the `web`
 * group after StartSession, either confirms a signed-in admin or answers with
 * the page before any controller runs. A request that never reaches it -- a
 * 404, a route outside the `web` group -- is still PENDING on the way back
 * out and is replaced with the page here. Nothing a visitor without the
 * right cookie can reach renders the shop.
 */
final class ComingSoonGate
{
    public function handle(Request $request, Closure $next): Response
    {
        // OFF: one array lookup in the map CanonicalHost reads anyway.
        $map = Setting::map();

        if (! ComingSoon::on($map)) {
            return $next($request);
        }

        // ON: one host compare. Every other address is served untouched.
        if (! ComingSoon::hides($request->getHost(), $map)) {
            return $next($request);
        }

        $path = $request->getPathInfo();

        if (ComingSoon::stripLocale($path) === '/robots.txt') {
            return ComingSoonPage::robots();
        }

        if (ComingSoon::pathAllowed($path)) {
            return $this->noindex($next($request));
        }

        if ($request->query->has(ComingSoon::QUERY)) {
            return $this->consume($request, $map);
        }

        if (ComingSoon::tokenValid($request->cookies->get(ComingSoon::COOKIE), $request->getHost(), $map)) {
            $request->attributes->set(ComingSoon::ATTR, ComingSoon::PREVIEW);

            return $this->shop($next($request), ComingSoon::PREVIEW);
        }

        if (! ComingSoon::mayBeAdmin($request)) {
            return ComingSoonPage::response($request);
        }

        $request->attributes->set(ComingSoon::ATTR, ComingSoon::PENDING);
        $response = $next($request);

        return match ($request->attributes->get(ComingSoon::ATTR)) {
            ComingSoon::ADMIN => $this->shop($response, ComingSoon::ADMIN),
            ComingSoon::BLOCKED => $response,
            // Never confirmed: a 404, or a route the web group does not cover.
            default => ComingSoonPage::response($request),
        };
    }

    /**
     * The preview link. A genuine token sets the cookie and sends the browser
     * to the same address without the token, so it is not left in the address
     * bar, the history or a Referer. Anything else is the page itself.
     *
     * @param  array<string, mixed>  $map
     */
    private function consume(Request $request, array $map): Response
    {
        $token = $request->query->get(ComingSoon::QUERY);

        if (! ComingSoon::tokenValid($token, $request->getHost(), $map)) {
            return ComingSoonPage::response($request);
        }

        $query = $request->query->all();
        unset($query[ComingSoon::QUERY]);
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $target = $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo().($qs !== '' ? '?'.$qs : '');

        $response = new Response('', 302, ['Location' => $target]);
        $response->headers->setCookie(Cookie::create(
            ComingSoon::COOKIE,
            (string) $token,
            (int) strstr((string) $token, '.', true),
            '/',
            null,
            true,       // Secure
            true,       // HttpOnly
            false,
            Cookie::SAMESITE_LAX,
        ));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * The real shop, for an admin or a preview: never cached by Varnish or the
     * browser (it would then be served to visitors), never indexed, and an
     * HTML page carries the small notice.
     */
    private function shop(Response $response, string $who): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response = $this->noindex($response);

        $type = (string) $response->headers->get('Content-Type', '');

        if ($response->getStatusCode() === 200 && str_contains($type, 'text/html')
            && ! $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            && ! $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            $html = (string) $response->getContent();
            $at = strripos($html, '</body>');

            if ($at !== false) {
                $response->setContent(substr($html, 0, $at).ComingSoonPage::notice($who).substr($html, $at));
                $response->headers->remove('Content-Length');
            }
        }

        return $response;
    }

    private function noindex(Response $response): Response
    {
        if (! $response->headers->has('X-Robots-Tag')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}

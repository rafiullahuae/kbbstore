<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Baseline security headers applied to every response. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // The owner app (Lane MAC) has already set DENY and no-referrer, so its
        // secret path never leaves in a Referer header; nothing else changes.
        if ($request->attributes->get(OwnerAppHeaders::ATTRIBUTE) !== true) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');            // clickjacking; admin uses same-origin iframes
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        // Conservative permissions policy; expand if the storefront later needs these features.
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        return $response;
    }
}

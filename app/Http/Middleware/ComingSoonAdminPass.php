<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ComingSoon;
use App\Support\ComingSoonPage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appearance -> Coming Soon page: the half that needs the session (Lane CS).
 *
 * In the `web` group, after StartSession. ComingSoonGate marks a request on
 * the hidden address PENDING when it carries a session cookie; this confirms a
 * signed-in admin (the shop renders, with the notice) or answers with the page
 * BEFORE any controller runs, so a visitor's POST to the cart or the checkout
 * does nothing there. Every other request pays one attribute read.
 */
final class ComingSoonAdminPass
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get(ComingSoon::ATTR) !== ComingSoon::PENDING) {
            return $next($request);
        }

        if (Auth::guard('admin')->check()) {
            $request->attributes->set(ComingSoon::ATTR, ComingSoon::ADMIN);

            return $next($request);
        }

        $request->attributes->set(ComingSoon::ATTR, ComingSoon::BLOCKED);

        return ComingSoonPage::response($request);
    }
}

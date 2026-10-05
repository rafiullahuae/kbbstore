<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\OwnerApp\OwnerAppAuth;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The owner app's guard for everything behind the PIN (Lane MAC).
 *
 * Fails closed in this order: no enrolled device (401 no_device), a device
 * whose member lost access (403 disabled), no live session or one idle past
 * owner_app_idle_hours (401 locked — the PIN pad), and for a write a missing
 * or wrong X-OA-CSRF (419).
 *
 * Then it hands the member's ADMIN account to the admin guard for this one
 * request, in memory only (setUser writes no session — there is no session on
 * these routes at all). That is what lets the app's writes go through the very
 * same controllers and services the admin uses — OrderStatus::moveTo(), the
 * product editor's rules — with the member's name on the order note, and the
 * capability check is done before that, here and per action, against the
 * same AdminRoles::can() the console uses.
 *
 * `oa_passive` routes (the live poll) do not keep the session alive: an app
 * left open on a desk still asks for the PIN after the idle time.
 */
final class OwnerAppSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = OwnerAppAuth::device($request);
        $why = OwnerAppAuth::barred($device);

        if ($why !== null) {
            return response()->json(['ok' => false, 'code' => $why], $why === 'disabled' ? 403 : 401);
        }

        if (! OwnerAppAuth::sessionValid($request, $device)) {
            return response()->json(['ok' => false, 'code' => 'locked'], 401);
        }

        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            $sent = (string) $request->headers->get('X-OA-CSRF', '');
            $want = OwnerAppAuth::csrfFor((string) $request->cookies->get(OwnerAppAuth::SESSION_COOKIE, ''));

            if ($sent === '' || ! hash_equals($want, $sent)) {
                return response()->json(['ok' => false, 'code' => 'csrf', 'message' => 'This page is out of date. Reload the app.'], 419);
            }
        }

        if (! ($request->route()?->defaults['oa_passive'] ?? false)) {
            OwnerAppAuth::touch($device);
        }

        $admin = $device->member->admin;
        Auth::guard('admin')->setUser($admin);

        $request->attributes->set('oa.device', $device);
        $request->attributes->set('oa.member', $device->member);
        $request->attributes->set('oa.admin', $admin);

        return $next($request);
    }
}

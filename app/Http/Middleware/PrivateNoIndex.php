<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PrivateSurfaces;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * `X-Robots-Tag: noindex, nofollow, noarchive` on every private answer
 * (Lane OA3), whatever produced it — a page, JSON, a redirect, a 404 or a 500.
 *
 * GLOBAL, NOT ON THE ROUTE GROUPS. routes/web.php is the integrator's, and a
 * group-level registration only covers the groups somebody remembered; this
 * reaches every admin route in every route file, including ones written after
 * it. Pushed onto the kernel from OwnerAppServiceProvider (bootstrap/ never
 * ships in a package). Being global it also runs OUTSIDE the web group, so it
 * has the last word over NoIndexStaging, which would otherwise rewrite the
 * owner app's header to a weaker `noindex, nofollow` on a staging copy.
 *
 * What is private, cheapest question first:
 *
 *   - a fixed path: /admin-api/…, the token pages a password email links to
 *     (/my-account/forgot, reset, welcome, verify), the import loopback, the
 *     health probes — PrivateSurfaces::isPrivateRoot(), no setting read;
 *   - the ROUTE that answered: the admin console, its login and updates
 *     (named admin / admin.*, or guarded by auth:admin), and every owner-app
 *     route including its catch-all (owner-app.*). By route and not by path
 *     so a wrong spelling of a secret is the shop's ordinary 404, header and
 *     all — nothing here can be used to confirm a guess;
 *   - the owner app's own host, when one is set: everything served there.
 *     Asked only off the shop's own host, so a shop page pays nothing for it.
 */
final class PrivateNoIndex
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->isPrivate($request)) {
            $response->headers->set('X-Robots-Tag', PrivateSurfaces::ROBOTS);
        }

        return $response;
    }

    private function isPrivate(Request $request): bool
    {
        if (PrivateSurfaces::isPrivateRoot($request->path())) {
            return true;
        }

        $route = $request->route();
        if ($route instanceof Route) {
            $name = (string) $route->getName();
            if ($name === 'admin' || str_starts_with($name, 'admin.') || str_starts_with($name, 'owner-app.')) {
                return true;
            }
            if (in_array('auth:admin', $route->gatherMiddleware(), true)) {
                return true;
            }
        }

        return PrivateSurfaces::isPrivateHost($request->getHost());
    }
}

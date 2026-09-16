<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AdminCapabilities;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns `admin_users.role` from a label into a permission.
 *
 * ---------------------------------------------------------------------------
 * WHAT COUNTS AS AN ADMIN ROUTE
 * ---------------------------------------------------------------------------
 *
 * A route carrying `auth:admin`. Not a path prefix, not a controller namespace,
 * not a route-name convention — the guard the route itself declares.
 *
 * That definition is the reason this works without editing routes/web.php,
 * which no lane may touch. It also means the layer covers routes it has never
 * heard of: /api/cart/debug is guarded by auth:admin while living under the
 * storefront's own prefix, and a route file added next month is inside the
 * moment it is required into the admin group. A prefix test would have missed
 * the first and a hand-maintained route list would miss the second.
 *
 * ---------------------------------------------------------------------------
 * WHERE IT RUNS, AND WHY THAT IS SAFE
 * ---------------------------------------------------------------------------
 *
 * Appended to the `web` middleware group in bootstrap/app.php, so it runs after
 * StartSession (the admin guard needs the session to resolve anybody) and
 * before the route's own `auth:admin`.
 *
 * Running BEFORE auth:admin is the part worth stating plainly: this middleware
 * must never be the thing that answers an unauthenticated request. If no admin
 * is signed in it steps aside and lets auth:admin do its job, which is to
 * redirect to the login form. Answering 403 here instead would meet a logged-out
 * owner with a dead end on a host where there is no other way in.
 *
 * ---------------------------------------------------------------------------
 * THE DEFAULT IS DENY
 * ---------------------------------------------------------------------------
 *
 * An admin route the map does not recognise requires a capability of null, and
 * null is held by nobody. Non-owners get a 403; the owner short-circuit above
 * means the owner still gets in and can still use the screen.
 *
 * That is the opposite of CouponService::withRules(), which treated an
 * unrecognised rule set as no restriction and shipped that way. Here an
 * unrecognised route is the maximum restriction that still leaves the site
 * operable, and AdminCapabilityMapTest pins it against a synthetic
 * route that is deliberately absent from the map.
 */
class EnforceAdminCapability
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route === null || ! $this->isAdminRoute($route)) {
            return $next($request);
        }

        $admin = Auth::guard('admin')->user();

        // Not signed in: auth:admin owns this case. See the note above.
        if ($admin === null) {
            return $next($request);
        }

        $role = AdminCapabilities::canonicalRole($admin->role ?? null);

        /*
         * The owner short-circuit, and the reason a mistake in the capability
         * map can cost a manager a screen but can never cost the owner the
         * site. It is deliberately ahead of every lookup: no array access, no
         * pattern match and no null check stands between an owner and the
         * admin panel.
         */
        if ($role === 'owner') {
            return $next($request);
        }

        $capability = AdminCapabilities::for($route);

        if (AdminCapabilities::roleCan($role, $capability)) {
            return $next($request);
        }

        return $this->deny($request, $role, $capability);
    }

    /**
     * Does this route declare the admin guard?
     *
     * gatherMiddleware() folds the route's own middleware in with the group's
     * and the controller's. It is wrapped because controllerMiddleware() has to
     * resolve the controller to ask it, and a route pointing at a class that
     * cannot be constructed would otherwise throw here — from a middleware that
     * runs on every single storefront request. A route whose middleware cannot
     * be read is treated as not-admin, which hands the request straight back to
     * whatever the route actually declared, including auth:admin itself.
     */
    private function isAdminRoute(\Illuminate\Routing\Route $route): bool
    {
        try {
            $middleware = $route->gatherMiddleware();
        } catch (\Throwable) {
            $middleware = $route->middleware();
        }

        foreach ($middleware as $entry) {
            if (is_string($entry) && ($entry === 'auth:admin' || str_starts_with($entry, 'auth:admin,'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 403, in the shape the caller can read.
     *
     * The admin console talks to /admin-api over fetch(), so a JSON body with a
     * named capability turns "the screen went blank" into "your role cannot do
     * this" without anyone opening the network tab. Page requests get the
     * normal 403 view.
     *
     * The response names the capability, not the roles that hold it: telling a
     * support account which roles could have done this is a small map of the
     * privilege ladder, and it is of no use to the person who needs to ask an
     * owner anyway.
     */
    private function deny(Request $request, ?string $role, ?string $capability): Response
    {
        $label = $role ?? 'unknown';
        $message = $capability === null
            ? "Your role ({$label}) cannot use this part of the admin."
            : "Your role ({$label}) does not have the \"{$capability}\" permission.";

        if ($request->is('admin-api/*') || $request->expectsJson()) {
            return response()->json([
                'error' => 'forbidden',
                'capability' => $capability,
                'role' => $label,
                'message' => $message,
            ], 403);
        }

        abort(403, $message);
    }
}

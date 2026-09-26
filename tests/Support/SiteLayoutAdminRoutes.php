<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\NoStoreAdminApi;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Mounts routes/site-layout-admin.php the way the integrator is told to.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the two routes it
 * adds ship in their own file with the require line in that file's header.
 * Nothing dispatches to the controller until somebody else wires it up, which
 * would otherwise leave the capability guard on an endpoint that writes numbers
 * into a stylesheet on every page of the shop untested until a package shipped
 * it.
 *
 * THIS IS THE ALTERNATIVE TO ASSERTING THE ABSENCE OF THE require LINE, which
 * CLAUDE.md's "Rules for parallel work" now forbids by name: that assertion is
 * green in the lane's worktree and goes red the moment the integrator does the
 * one thing the lane asked for. SiteLayoutScreenTest pins the FINISHED state
 * instead — the require line present exactly once, the partial included exactly
 * once — which is green both before and after the wiring and catches the two
 * shapes that are real failures (zero: built and never mounted; two: a sidebar
 * entry registered twice and window.go wrapped around its own wrapper).
 *
 * A sibling of the other Tests\Support route harnesses rather than a shared base
 * class: those files belong to other lanes and are not this one's to change.
 */
final class SiteLayoutAdminRoutes
{
    /** The exact stack routes/web.php's admin-api group applies. */
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        /*
         * Resolve the HTTP kernel first, or the guard is not what it appears:
         * the `auth` middleware ALIAS is registered on the router by
         * Kernel::syncMiddlewareToRouter(), which runs in the kernel's
         * constructor and nowhere else. Without it the pipeline resolves the
         * AuthManager and the request dies with a 500 — still a refusal, so a
         * guard test would pass for the wrong reason.
         */
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            // Idempotent: several cases in one file call this, and registering
            // the same two routes twice makes the name lookup ambiguous.
            if ($existing->uri() === 'admin-api/site-layout') {
                return;
            }
        }

        /*
         * The router is serving a CompiledRouteCollection here — the migration
         * set runs route:cache — and routes added to one of those are not
         * matched. Copying into a plain RouteCollection first is what makes a
         * route registered at runtime actually dispatch.
         */
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/site-layout-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /** @return list<\Illuminate\Routing\Route> */
    public static function registered(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => $r->uri() === 'admin-api/site-layout')
            ->values()
            ->all();
    }

    /**
     * See the long note in tests/Pest.php: the migration set runs config:cache
     * and route:cache, each of which constructs a throwaway Application and
     * points the container, the facade root and Eloquent's connection resolver
     * at it. Nothing puts them back, so anything reaching for app() afterwards
     * can be talking to a discarded application.
     */
    private static function reclaimContainer(Application $app): void
    {
        Container::setInstance($app);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));
    }
}

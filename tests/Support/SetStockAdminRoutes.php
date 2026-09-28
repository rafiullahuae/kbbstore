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
 * Mounts routes/sp-set-stock-admin.php the way the integrator is told to.
 * (Lane SP)
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the two routes it
 * adds ship in their own file with the require line in that file's header.
 * Nothing dispatches to the controller until somebody else wires it up, which
 * would otherwise leave the capability guard on an endpoint that decides how
 * every order in this shop moves inventory untested until a package shipped it.
 *
 * ▲ THIS IS THE RIGHT WAY TO TEST AN UNMOUNTED ROUTE FILE, and the wrong way is
 *   the one CLAUDE.md names: asserting that routes/web.php does NOT require this
 *   file. SetStockWiredTest pins the FINISHED state instead — exactly one
 *   require — which is green here AND after the wiring.
 *
 * A SIBLING OF Tests\Support\SetsAdminRoutes, deliberately, and not a change to
 * it: that file is Lane SET's. The two mount different route files and their
 * idempotence guards key off different URIs, so both can run in one test.
 *
 * ONE middleware() CALL, NOT TWO — RouteRegistrar::middleware() REPLACES the
 * pending middleware rather than appending to it, so chaining two calls
 * registers routes carrying only the second and a guard test written against
 * that harness passes against nothing.
 */
final class SetStockAdminRoutes
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
         * constructor and nowhere else.
         */
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            // Idempotent: several cases call this, and registering the same two
            // routes twice makes the name lookup ambiguous.
            if ($existing->uri() === 'admin-api/set-stock') {
                return;
            }
        }

        // The router is serving a CompiledRouteCollection here — the migration
        // set runs route:cache — and routes added to one of those are not
        // matched. Copying into a plain RouteCollection first is what makes a
        // route registered at runtime actually dispatch.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/sp-set-stock-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * Every route this lane's file added, so a test can assert over all of them
     * rather than a list it has to remember to keep up to date.
     *
     * @return list<\Illuminate\Routing\Route>
     */
    public static function registered(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => $r->uri() === 'admin-api/set-stock')
            ->values()
            ->all();
    }

    /**
     * See the long note in tests/Pest.php: the migration set runs config:cache
     * and route:cache, each of which constructs a throwaway Application and
     * points the container, the facade root and Eloquent's connection resolver
     * at it. Nothing puts them back.
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

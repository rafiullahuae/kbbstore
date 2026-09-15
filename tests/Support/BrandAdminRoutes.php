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
 * Mounts routes/brands-admin.php the way the integrator is told to.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the brand admin
 * routes ship in their own file with the require line in the header. That
 * leaves the same gap Lane D hit with payments: nothing dispatches to the
 * controller until someone else wires it up, so a mistake in the guard would
 * not surface until a package shipped an unauthenticated catalogue editor.
 *
 * This registers the file inside a group with the same middleware as the
 * admin-api group in web.php — `auth:admin` plus NoStoreAdminApi, under the
 * `admin-api` prefix and the `web` stack — so the HTTP tests exercise the real
 * guard rather than a bare route. PaymentRoutesTest separately pins that the
 * group named in the header really does carry `auth:admin`.
 */
final class BrandAdminRoutes
{
    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        $router = RouteFacade::getFacadeRoot();

        // The router is serving a CompiledRouteCollection here — the migration
        // set runs route:cache — and routes added to one of those are not
        // matched. Copying into a plain RouteCollection first is what makes a
        // route registered at runtime actually dispatch; Phase9Routes does the
        // same thing for the same reason.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(['web', 'auth:admin', NoStoreAdminApi::class])
            ->prefix('admin-api')
            ->group(base_path('routes/brands-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /** See the long note in Phase9Routes: the migration set steals the container. */
    private static function reclaimContainer(Application $app): void
    {
        Container::setInstance($app);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));
    }
}

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
 * (Lane VPA) Mounts routes/variant-options-admin.php the way the integrator
 * is told to -- inside `auth:admin` + NoStoreAdminApi under `admin-api` and the
 * `web` stack, where EnforceAdminCapability runs -- so the HTTP tests exercise
 * the real guard rather than a bare route. A copy of CatalogAdminRoutes, not a
 * shared base: that file is another lane's.
 */
final class VpaAdminRoutes
{
    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        $router = RouteFacade::getFacadeRoot();

        // The router is serving a CompiledRouteCollection here — the migration
        // set runs route:cache — and routes added to one of those are not
        // matched. Copying into a plain RouteCollection first is what makes a
        // route registered at runtime actually dispatch; BrandAdminRoutes and
        // Phase9Routes do the same thing for the same reason.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(['web', 'auth:admin', NoStoreAdminApi::class])
            ->prefix('admin-api')
            ->group(base_path('routes/variant-options-admin.php'));

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

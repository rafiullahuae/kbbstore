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
 * Mounts routes/catalog-admin.php the way the integrator is told to.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the category and
 * attribute routes ship in their own file with the require line in the header.
 * That leaves the same gap Lane D hit with payments and Lane E with brands:
 * nothing dispatches to the controller until someone else wires it up, so a
 * mistake in the guard would not surface until a package shipped an
 * unauthenticated catalogue editor — one that can empty an archive page or
 * strip every variant of the term defining it.
 *
 * This registers the file inside a group with the same middleware as the
 * admin-api group in web.php — `auth:admin` plus NoStoreAdminApi, under the
 * `admin-api` prefix and the `web` stack — so the HTTP tests exercise the real
 * guard rather than a bare route. Deliberately a copy of Tests\Support\BrandAdminRoutes
 * rather than a shared base class: that file belongs to another lane and is not
 * this one's to change.
 */
final class CatalogAdminRoutes
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
            ->group(base_path('routes/catalog-admin.php'));

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

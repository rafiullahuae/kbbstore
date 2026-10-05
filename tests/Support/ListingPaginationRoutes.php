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
 * Mounts routes/pagination-admin.php with the real admin-api middleware, for
 * the tests. (Lane PG, after Lane CH's CategoryHeaderRoutes)
 *
 * routes/web.php requires that file — the integrator's line — so once it is
 * wired this finds the routes already there and does nothing. Before then it
 * registers them the way WhatsAppButtonRoutes does. Either way the tests below
 * exercise the real file, and nothing anywhere asserts it is NOT wired.
 */
final class ListingPaginationRoutes
{
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));

        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->uri() === 'admin-api/pagination') {
                return;
            }
        }

        // The migration set runs route:cache, so the router serves a
        // CompiledRouteCollection, and routes added to one of those are never
        // matched. Copy into a plain RouteCollection first.
        $kept = new RouteCollection();
        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }
        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/pagination-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}

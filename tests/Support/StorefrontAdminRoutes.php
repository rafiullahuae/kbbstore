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
 * Registers routes/storefront-admin.php for a test, exactly as the integrator
 * will mount it: inside `auth:admin`, under `admin-api`, behind NoStoreAdminApi.
 * (Lane RA -- the shape of UgcAdminRoutes and BrandAdminRoutes.)
 *
 * Idempotent, and a no-op once routes/web.php requires the file itself: the
 * test then exercises the real mounting rather than a second copy of it.
 */
final class StorefrontAdminRoutes
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

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            if ($existing->uri() === 'admin-api/storefront/context') {
                return;
            }
        }

        // The router serves a CompiledRouteCollection in tests (the migration
        // set runs route:cache); a route added to one of those never matches.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/storefront-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}

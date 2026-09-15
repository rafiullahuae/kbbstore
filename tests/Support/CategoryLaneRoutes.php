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
 * Mounts Lane AQ's routes the way the integrator is told to, plus the archive
 * route the lane proposes replacing.
 *
 * Same shape and the same reason as Tests\Support\CatalogAdminRoutes: CLAUDE.md
 * forbids this lane from editing routes/web.php, so nothing dispatches to these
 * controllers until the integrator wires them up, and a mistake in the guard
 * would not surface until a package shipped an unauthenticated endpoint for
 * dissolving the shop's navigation. Registering them here inside a group
 * carrying the real middleware — `web`, `auth:admin`, NoStoreAdminApi, under
 * the `admin-api` prefix — is what makes the guard assertions mean anything.
 */
final class CategoryLaneRoutes
{
    public static function wire(Application $app): void
    {
        self::reclaim($app);

        $router = self::plainCollection();

        RouteFacade::middleware(['web', 'auth:admin', NoStoreAdminApi::class])
            ->prefix('admin-api')
            ->group(base_path('routes/catalog-admin.php'));

        RouteFacade::middleware(['web', 'auth:admin', NoStoreAdminApi::class])
            ->prefix('admin-api')
            ->group(base_path('routes/categories-brands-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * Register the archive route as this lane proposes it, shadowing web.php's.
     *
     * web.php line 97 already registers /product-category/{path}. Laravel
     * matches the FIRST route that fits, so a second registration alone would
     * never be reached. This drops the original out of the collection and adds
     * the proposed one in its place, which is what lets the path-contract tests
     * assert real HTTP status codes rather than poking the resolver directly.
     *
     * The integrator's change is the body of that closure and nothing else —
     * see the header of routes/categories-storefront.php.
     */
    public static function wireStorefront(Application $app): void
    {
        self::reclaim($app);

        $router = RouteFacade::getFacadeRoot();
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            if ($route->uri() === 'product-category/{path}') {
                continue;
            }

            $kept->add($route);
        }

        $router->setRoutes($kept);

        // Exactly the line the integrator is asked to write — see the header of
        // App\Http\Controllers\Store\CategoryArchiveController. Spelled the
        // same way here so the tests exercise the real registration rather than
        // a copy of its logic.
        RouteFacade::middleware('web')
            ->get('/product-category/{path}', [\App\Http\Controllers\Store\CategoryArchiveController::class, 'show'])
            ->where('path', '.*')
            ->name('category');

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * The router is serving a CompiledRouteCollection here — the migration set
     * runs route:cache — and routes added to one of those are not matched.
     * Copying into a plain RouteCollection first is what makes a route
     * registered at runtime actually dispatch.
     */
    private static function plainCollection()
    {
        $router = RouteFacade::getFacadeRoot();
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        return $router;
    }

    /** See the long note in Pest.php: the migration set steals the container. */
    private static function reclaim(Application $app): void
    {
        Container::setInstance($app);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));
    }
}

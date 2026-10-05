<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Controllers\Store\SeoFilesController;
use App\Http\Middleware\NoStoreAdminApi;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Lane PW: mount routes/site-app.php and routes/site-app-admin.php the way
 * docs/pwa-wiring.json will, for a test running before the integrator has.
 * A no-op once they are wired.
 *
 * The storefront files go in FIRST, ahead of every route already registered,
 * as they will in routes/web.php (inside the stateless group at the top).
 * Appended at the end they would be shadowed: the shop's catch-all page
 * routes would answer /offline before this file was ever asked.
 */
final class SiteAppRoutes
{
    public const ADMIN_STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));

        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        $hasShop = false;
        $hasAdmin = false;
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $hasShop = $hasShop || $route->getName() === 'site-app.manifest';
            $hasAdmin = $hasAdmin || $route->uri() === 'admin-api/site-app';
        }
        if ($hasShop && $hasAdmin) {
            return;
        }

        $existing = [];
        foreach ($router->getRoutes() as $route) {
            $existing[] = $route;
        }

        $router->setRoutes(new RouteCollection());

        if (! $hasShop) {
            RouteFacade::middleware('web')
                ->withoutMiddleware(SeoFilesController::STATELESS)
                ->group(base_path('routes/site-app.php'));
        }

        $kept = $router->getRoutes();
        foreach ($existing as $route) {
            $kept->add($route);
        }

        if (! $hasAdmin) {
            RouteFacade::middleware(self::ADMIN_STACK)
                ->prefix('admin-api')
                ->group(base_path('routes/site-app-admin.php'));
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
        \App\Support\Url::forgetBase();
    }
}

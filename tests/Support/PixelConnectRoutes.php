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
 * Lane MP's two route files, registered for a test exactly as
 * docs/mp-wiring.json mounts them — so the tests run the same before and after
 * the integrator wires them (CLAUDE.md: register in the test, do not assert
 * the absence of the require). Modelled on UgcAdminRoutes; see its notes on
 * the compiled route collection and the reclaimed container.
 */
final class PixelConnectRoutes
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

        $haveAdmin = false;
        $haveFeeds = false;

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            $haveAdmin = $haveAdmin || $existing->uri() === 'admin-api/marketing-pixels/connect';
            $haveFeeds = $haveFeeds || $existing->uri() === 'feeds/meta-catalog.xml';
        }

        if ($haveAdmin && $haveFeeds) {
            return;
        }

        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        if (! $haveAdmin) {
            RouteFacade::middleware(self::STACK)->prefix('admin-api')->group(base_path('routes/marketing-pixels-connect-admin.php'));
        }

        if (! $haveFeeds) {
            RouteFacade::middleware('web')->withoutMiddleware(SeoFilesController::STATELESS)->group(base_path('routes/marketing-catalog-feeds.php'));
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}

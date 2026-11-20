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
 * Registers Lane AP-API's two route files in a test until the integrator has
 * required them from routes/web.php (and is a no-op after), the way
 * PixelConnectRoutes and UgcAdminRoutes do -- so no test has to pin that the
 * files are NOT wired yet (CLAUDE.md, "Do not pin that your own work is NOT
 * wired up yet").
 */
final class AutopilotRoutes
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
        $haveApi = false;

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            $haveAdmin = $haveAdmin || $existing->uri() === 'admin-api/ads-autopilot';
            $haveApi = $haveApi || $existing->uri() === 'autopilot-api/v1/orders';
        }

        if ($haveAdmin && $haveApi) {
            return;
        }

        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        if (! $haveAdmin) {
            RouteFacade::middleware(self::STACK)->prefix('admin-api')->group(base_path('routes/ads-autopilot-admin.php'));
        }

        if (! $haveApi) {
            RouteFacade::middleware('web')->withoutMiddleware(SeoFilesController::STATELESS)->group(base_path('routes/autopilot-api.php'));
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}

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
 * Registers routes/quiqup-admin.php and routes/quiqup-webhooks.php exactly as
 * the integrator will mount them, for the tests (the UgcAdminRoutes pattern):
 *
 *   admin    inside admin-api, stack web + auth:admin + NoStoreAdminApi
 *            (EnforceAdminCapability rides in the web group)
 *   webhook  inside api, the api group (no CSRF)
 *
 * Idempotent, and it never asserts that routes/web.php or routes/api.php does
 * NOT require the files: that would go red the day the integrator wires them.
 */
final class QuiqupRoutes
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

        $hasAdmin = false;
        $hasHook = false;

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            $hasAdmin = $hasAdmin || $existing->uri() === 'admin-api/quiqup-settings';
            $hasHook = $hasHook || $existing->uri() === 'api/shipping/quiqup/webhook/{secret}';
        }

        if ($hasAdmin && $hasHook) {
            return;
        }

        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        if (! $hasAdmin) {
            RouteFacade::middleware(self::STACK)->prefix('admin-api')->group(base_path('routes/quiqup-admin.php'));
        }

        if (! $hasHook) {
            RouteFacade::middleware(['api'])->prefix('api')->group(base_path('routes/quiqup-webhooks.php'));
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}

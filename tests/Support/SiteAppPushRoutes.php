<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Registers routes/site-app-push.php (Lane NT) in the ordinary web group when
 * routes/web.php does not mount it yet, AHEAD of the routes already there so
 * no catch-all answers first. Once the integrator adds the require, the named
 * route exists and this does nothing (CLAUDE.md: register in the test rather
 * than pin the absence of the require).
 */
final class SiteAppPushRoutes
{
    public static function wire(Application $app): void
    {
        SiteAppRoutes::wire($app);   // the manifest / worker / script, and the admin screen's endpoints

        $router = RouteFacade::getFacadeRoot();
        if ($router->getRoutes()->getByName('site-app.push') !== null) {
            return;
        }

        $existing = [];
        foreach ($router->getRoutes() as $route) {
            $existing[] = $route;
        }
        $router->setRoutes(new RouteCollection());
        RouteFacade::middleware('web')->group(base_path('routes/site-app-push.php'));
        $kept = $router->getRoutes();
        foreach ($existing as $route) {
            $kept->add($route);
        }
        $kept->refreshNameLookups();
        $kept->refreshActionLookups();
    }
}
